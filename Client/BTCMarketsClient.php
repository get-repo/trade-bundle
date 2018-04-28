<?php

/*
 * Symfony Trade Bundle
 */

namespace GetRepo\TradeBundle\Client;

use Symfony\Component\Cache\Simple\FilesystemCache;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * BTCMarketsClient.
 */
class BTCMarketsClient
{
    /**
     * @var string
     */
    const PYTHON_CLIENT_PATH = 'bin/btc-api-client-python/main.py';

    /**
     * @var int
     */
    const MAX_RESULTS = 200;

    /**
     * @var string
     */
    const FUND_STATUS_COMPLETE = 'Complete';

    use ContainerAwareTrait;

    /**
     * @var array
     */
    private $config;

    /**
     * @var FilesystemCache
     */
    private $cache;

    /**
     * @var array
     */
    private $data = [];

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->setContainer($container);
        $this->config = $container->getParameter('trade.config')['btc_markets'];
        $this->cache = new FilesystemCache('btc.client');
    }

    /**
     * Clear cache.
     *
     * @return bool
     */
    public function clearCache()
    {
        return $this->cache->clear();
    }

    /**
     * @return array
     */
    public function getInstruments()
    {
        $key = 'btc.instruments';
        if (!$this->cache->has($key)) {
            $this->data['balances'] = $this->call('account_balance');

            if (!$this->data['balances']) {
                throw new \Exception('No balances could be found');
            }

            $instruments = [];
            foreach ($this->data['balances'] as $bal) {
                if ('AUD' !== $bal['currency']) {
                    $instruments[] = $bal['currency'];
                }
            }

            $this->cache->set($key, $instruments);
        }

        return $this->cache->get($key);
    }

    /**
     * Get instrument balances.
     *
     * @return array
     */
    public function getBalances()
    {
        if (!isset($this->data['balances'])) {
            $this->data['balances'] = $this->call('account_balance');
        }

        if (!$this->data['balances']) {
            throw new \Exception('No balances could be found');
        }

        foreach ($this->data['balances'] as $bal) {
            $bal['balance'] = round($bal['balance'] / pow(10, 8), 7);
            $bal['price'] = $bal['balance'];
            if ($bal['price'] && in_array($bal['currency'], $this->getInstruments())) {
                $lastPrice = $this->getBestBid($bal['currency']);
                $bal['price'] = $lastPrice * $bal['balance'];
            }
            $bal['price'] = round($bal['price'], 2);
            $balances[$bal['currency']] = $bal;
        }

        return $balances;
    }

    /**
     * @return int
     */
    public function getBalanceTotal()
    {
        $total = 0;

        foreach ($this->getBalances() as $bal) {
            $total = $total + $bal['price'];
        }

        return round($total, 2);
    }

    /**
     * @param string $instrumentIn
     * @param string $instrumentOut
     *
     * @return array
     */
    public function getTick($instrumentIn, $instrumentOut = 'AUD')
    {
        $key = "{$instrumentIn}-{$instrumentOut}";
        if (!isset($this->data['ticks'][$key])) {
            $this->data['ticks'][$key] = $this->call('get_market_tick', $instrumentIn, $instrumentOut);
        }

        return $this->data['ticks'][$key];
    }

    /**
     * @param string $instrument
     *
     * @return float|false
     */
    public function getLastPrice($instrument)
    {
        if ($tick = $this->getTick($instrument)) {
            return $tick['lastPrice'];
        }

        return false;
    }

    /**
     * @param string $instrument
     *
     * @return float|false
     */
    public function getBestBid($instrument)
    {
        if ($tick = $this->getTick($instrument)) {
            return $tick['bestBid'];
        }

        return false;
    }

    /**
     * @param string $instrument
     * @param string $currency
     *
     * @return array
     */
    public function getLastTrades($instrument, $currency = 'AUD')
    {
        $bal = $this->getBalances()[$instrument]['balance'];
        $trades = [];

        if ($bal) {
            $trades = $this->call('trade_history', $currency, $instrument, self::MAX_RESULTS, 1)['trades'];

            $grouped = [];
            foreach ($trades as $i => $trade) {
                $orderId = $trade['orderId'];
                $trade['volume'] = $trade['volume'] / pow(10, 8);
                $trade['price'] = $trade['price'] / pow(10, 8);
                $trade['fee'] = $trade['fee'] / pow(10, 8);

                if (isset($grouped[$orderId])) {
                    if (isset($grouped[$orderId]['_combined'])) {
                        $grouped[$orderId]['_combined']++;
                    } else {
                        $grouped[$orderId]['_combined'] = 2;
                    }
                    foreach (['volume', 'fee'] as $k) {
                        $grouped[$orderId][$k] += $trade[$k];
                    }
                } else {
                    $grouped[$orderId] = $trade;
                }
            }

            $trades = array_values($grouped);
            foreach ($trades as $i => $trade) {
                if ('Bid' === $trade['side']) {
                    $bal -= $trade['volume'];
                } else {
                    $bal +=  $trade['volume'];
                }

                $trades[$i]['amount'] = ($trade['volume'] * $trade['price']) - $trade['fee'];
                if (!$bal) {
                    break;
                }
            }

            $trades = array_slice($trades, 0, $i + 1);
        }

        return $trades;
    }

    /**
     * @param string $id
     *
     * @return array
     */
    public function getOrderDetails($id)
    {
        return current($this->call('order_detail', $id)['orders']);
    }

    /**
     * @param string $instrumentOut
     * @param string $instrumentIn
     *
     * @return array
     */
    public function getMarketOrderBook($instrumentOut, $instrumentIn = 'AUD')
    {
        return $this->call('get_market_orderbook', $instrumentOut, $instrumentIn);
    }

    /**
     * @param string $instrumentOut
     * @param string $instrumentIn
     *
     * @return array
     */
    public function getMarketTrades($instrumentOut, $instrumentIn = 'AUD')
    {
        return $this->call('get_market_trades', $instrumentOut, $instrumentIn);
    }

    /**
     * @return array
     */
    public function getFunds()
    {
        $key = 'btc.funds';
        if (!$this->cache->has($key)) {
            if (!isset($this->data['funds'])) {
                $funds = [];
                foreach ($this->call('fund_history')['fundTransfers'] as $k => $fund) {
                    if ($fund['status'] == self::FUND_STATUS_COMPLETE && 'AUD' === $fund['currency']) {
                        $funds[] = array_merge($fund, [
                            'amount' => ($fund['amount'] / pow(10, 8)),
                            'fee' => ($fund['fee'] / pow(10, 8)),
                        ]);
                    }
                }

                $this->data['funds'] = $funds;
            }

            $this->cache->set($key, $this->data['funds']);
        }

        return $this->cache->get($key);
    }

    /**
     * @return int
     */
    public function getTotalFunds()
    {
        $total = 0;
        foreach ($this->getFunds() as $fund) {
            if ('Complete' === $fund['status']) {
                if ('WITHDRAW' === $fund['transferType']) {
                    $fund['amount'] = $fund['amount'] * (-1);
                }
                if (isset($fund['fee'])) {
                    $fund['amount'] = $fund['amount'] - $fund['fee'];
                }

                $total = $total + $fund['amount'];
            }
        }

        return $total;
    }

    public function getOpenOrders($instrumentInFilter = null, $instrumentOutFilter = null)
    {
        foreach(['AUD', 'BTC'] as $instrumentOut) {
            foreach($this->getInstruments() as $instrumentIn) {
                $orders = $this->call('order_open', $instrumentOut, $instrumentIn, 200, 1)['orders'];

                foreach ($orders as $k => $order) {
                    $orders[$k] = array_merge($order, [
                        'volume' => ($order['volume'] / pow(10, 8)),
                        'openVolume' => ($order['openVolume'] / pow(10, 8)),
                        'price' => ($order['price'] / pow(10, 8)),
                    ]);
                }

                $res["{$instrumentIn}/{$instrumentOut}"] = $orders;
            }
        }

        return $res;
    }

    public function cancelOpenOrder($orderId)
    {
        p($orderId);
    }


    /**
     * Get instrument balances.
     *
     * @return array
     */
    private function createOrder($type, $instrumentOut, $price, $volume, $instrumentIn)
    {
        throw new \Exception('TODO');

        /*
        $res = $this->call(
            'order_create',
            $instrumentIn,
            $instrumentOut,
            ($price * pow(10, 8)), // TODO create method for conversion: https://github.com/BTCMarkets/API/wiki/Trading-API#number-conversion
            ($volume * pow(10, 8)),
            $type,
            'Limit',
            'test-456v4fsd65vdf-45v6f' // TODO create desc per date and instrument
        );
        p($res);

        $res = $this->call('order_open', 'AUD', 'XRP', 200, 1);
        p($res, 0);
        sleep(2);

        $res = $this->call('order_cancel', $res['orders'][0]['id']);
        p($res, 0);
        sleep(2);


        $res = $this->call('order_open', 'AUD', 'XRP', 200, 1);
        p($res);
        */
    }



    /**
     * Call python library.
     *
     * @return string
     */
    private function call()
    {
        $args = func_get_args();
        $method = array_shift($args);

        $cmd = sprintf(
            '%s %s/../%s "%s" "%s" "%s" %s',
            $this->config['python_bin_path'],
            __DIR__,
            self::PYTHON_CLIENT_PATH,
            $this->config['api_key'],
            $this->config['private_key'],
            $method,
            ($args ? '"'.implode('" "', $args).'"' : '')
        );

        //p("\n{$method}\n", 0);
        //p("\n{$cmd}\n", 0);
        $json = exec($cmd);
        $json = str_replace(
            ["u'", "':", "',", "'}", ' None', ' True', ' False'],
            ['"', '":', '",', '"}', ' null', ' true', ' false'],
            $json
        );

        $response = @json_decode($json, true);

        if (isset($response['success']) && !$response['success']) {
            $message = 'Response error:'.var_export($json, true);
            if (isset($response['errorMessage'])) {
                $message = $response['errorMessage'];
            }

            throw new \Exception("{$method} failed: {$message}");
        }

        return $response;
    }
}
