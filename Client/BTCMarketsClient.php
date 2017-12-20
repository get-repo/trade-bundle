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
    const PYTHON_CLIENT_PATH = 'bin/api-client-python/main.py';

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
        $this->cache = new FilesystemCache();
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
                $lastPrice = $this->getLastPrice($bal['currency']);
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
     * @param string $currency
     *
     * @return array
     */
    public function getLastTrades($instrument, $currency = 'AUD')
    {
        $bal = $this->getBalances()[$instrument]['balance'];
        $trades = [];

        if ($bal) {
            $trades = $this->call('trade_history', $currency, $instrument, 200, 1)['trades'];

            foreach ($trades as $i => $trade) {
                if ('Bid' === $trade['side']) {
                    $bal = $bal - $trade['volume'];
                } else {
                    $bal = $bal + $trade['volume'];
                }

                $trade['volume'] = $trade['volume'] / pow(10, 8);
                $trade['price'] = $trade['price'] / pow(10, 8);
                $trade['fee'] = $trade['fee'] / pow(10, 8);

                $trades[$i]['amount'] = ($trade['volume'] * $trade['price']) - $trade['fee'];

                if ($bal <= 0) {
                    break;
                }
            }

            $trades = array_slice($trades, 0, $i+1);
        }

        return $trades;
    }

    /**
     * @param string $instrumentOut
     * @param string $instrumentIn
     *
     * @return array
     */
    public function getOpenOrders($instrumentOut, $instrumentIn = 'AUD')
    {
        return $this->call('order_open', $instrumentIn, $instrumentOut, 200, 1)['orders'];
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
                $funds = $this->call('fund_history')['fundTransfers'];
                foreach ($funds as $k => $fund) {
                    $funds[$k]['amount'] = $funds[$k]['amount'] / pow(10, 8);
                    $funds[$k]['fee'] = $funds[$k]['fee'] / pow(10, 8);

                    $funds[$k]['price'] = $funds[$k]['amount'];
                    if ('AUD' !== $fund['currency']) {
                        $funds[$k]['price'] = $funds[$k]['price'] * $this->getLastPrice($fund['currency']);
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
                    $fund['price'] = $fund['price'] * (-1);
                }
                if (isset($fund['fee'])) {
                    $fund['price'] = $fund['price'] - $fund['fee'];
                }

                $total = $total + $fund['price'];
            }
        }

        return $total;
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
