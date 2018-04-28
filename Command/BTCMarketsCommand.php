<?php

/*
 * Symfony Trade Bundle
 */

namespace GetRepo\TradeBundle\Command;

use GetRepo\TradeBundle\Client\BTCMarketsClient;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * BTCMarkets command line.
 */
class BTCMarketsCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function getActionMap()
    {
        return[
            'doBalance' => ['b', 'balance'],
            'doFunds' => ['f', 'funds'],
            'doTicks' => ['t', 'ticks'],
            'doMarket' => ['m', 'market', 'm'],
            'doListOpenOrders' => ['o', 'open-orders'],
            'doCancelOpenOrders' => ['co', 'cancel-open-orders'],
            'doClearCache' => ['c', 'clear-cache', 'cache-clear', 'clearcache', 'cacheclear'],
            'doCollectData' => ['cd', 'collect-data', 'collectdata'],
            'doAlert' => ['a', 'alert'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getHelpContent()
    {
        return <<<'HELP'
The <info>php bin/console %command.name%</info> manage BTC market trades

<comment>Show your balance:</comment>
  <info>%command.name% balance (alias: b)</info>
<comment>Show your funds:</comment>
  <info>%command.name% funds (alias: f)</info>
<comment>Show the currency prices:</comment>
  <info>%command.name% ticks (alias: t)</info>
<comment>Show the order book for an instrument:</comment>
  <info>%command.name% market (alias: m) BTC</info>
  <info>%command.name% m XRP,15</info>
<comment>List open order(s)</comment>
  <info>%command.name% open-orders (alias: o)</info>
<comment>Cancel open order(s)</comment>
  <info>%command.name% cancel-open-orders (alias: co) 123456789</info>
  <info>%command.name% cancel-open-orders (alias: co) LTC</info>
<comment>Collect data (cron script):</comment>
  <info>%command.name% collect-data (alias: cd)</info>
<comment>Price alert (cron script):</comment>
  <info>%command.name% alert (alias: a) XRP,1.5</info>
<comment>Clear the cache:</comment>
  <info>%command.name% clear-cache (alias: c)</info>
HELP;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function doBalance(InputInterface $input, OutputInterface $output)
    {
        $balances = $this->client->getBalances();

        $this->table->setHeaders([
            null,
            '<comment>Quantity</comment>',
            '<comment>Price</comment>',
            '<comment>AUD</comment>',
            '<comment>+/-</comment>',
        ]);

        $nb = 0;
        foreach ($balances as $instrument => $bal) {
            $tick = null;
            $diff = null;

            if ('AUD' !== $instrument) {
                if (!$bal['balance']) {
                    continue;
                }
                $tick = $this->client->getBestBid($instrument);
                $trades = $this->client->getLastTrades($instrument);
                if ($trades && count($trades) < BTCMarketsClient::MAX_RESULTS) {
                    $diff = $bal['balance'] * $tick;

                    foreach ($trades as $trade) {
                        if ('Bid' === $trade['side']) {
                            $diff = $diff - $trade['amount'];
                        } else {
                            $diff = $diff + $trade['amount'];
                        }
                    }
                    $diffFormat = $diff > 0 ? 'info' : 'fg=red';
                    $diff = number_format($diff, 2);
                }
            }

            $this->table->setRow(
                $nb,
                [
                    "<comment>{$instrument}</comment>",
                    $bal['balance'],
                    $tick,
                    $bal['price'],
                    ($diff ? "<{$diffFormat}>{$diff}</{$diffFormat}>" : ''),
                ]
            );
            ++$nb;
        }

        $this->table->render();

        $total = $this->client->getBalanceTotal();
        echo $output->writeln(
            '<comment>TOTAL</comment>  $'.number_format($total, 2)
        );

        $funds = $this->client->getTotalFunds();
        echo $output->writeln(
            '<comment>FUNDS</comment>  $'.number_format($funds, 2)
        );

        $diff = number_format($total - $funds, 2);
        $diffFormat = $diff < 0 ? 'error' : 'info';
        echo $output->writeln(
            "<comment>DIFF</comment>   <{$diffFormat}>\${$diff}</{$diffFormat}>"
        );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function doFunds(InputInterface $input, OutputInterface $output)
    {
        $this->table->setHeaders([
            '<comment>Date</comment>',
            '<comment>Type</comment>',
            '<comment>AUD</comment>',
        ]);

        $total = 0;
        foreach ($this->client->getFunds() as $i => $fund) {
            $isDeposit = 'DEPOSIT' === $fund['transferType'];
            $typeFormat = $isDeposit ? 'fg=cyan' : 'info';

            $this->table->setRow($i, [
                date('Y-m-d', $fund['creationTime'] / 1000),
                "<{$typeFormat}>{$fund['transferType']}</{$typeFormat}>",
                number_format($fund['amount'], 2),
            ]);
            if ($isDeposit) {
                $total = $total + $fund['amount'] - $fund['fee'];
            } else {
                $total = $total - $fund['amount'] - $fund['fee'];
            }
        }

        $this->table->render();
        $funds = $this->client->getTotalFunds();

        echo $output->writeLn(
            '<comment>TOTAL</comment>  $'.number_format($funds, 2)
        );
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function doTicks(InputInterface $input, OutputInterface $output)
    {
        $cpt = 0;
        foreach ($this->client->getInstruments() as $instrument) {
            $price = number_format($this->client->getLastPrice($instrument), 2);
            $this->table->setRow($cpt, ["<comment>{$instrument}</comment>", $price]);
            ++$cpt;
        }

        $this->table->render();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws RuntimeException
     */
    protected function doMarket(InputInterface $input, OutputInterface $output)
    {
        $params = $this->parseFilterArgument($input);
        $instrument = $params[0];
        $max = (isset($params[1]) && $params[1]) ? $params[1] : 200;

        $book = $this->client->getMarketOrderBook($instrument);
        $this->table->setHeaders([
            '<info>Price</info>',
            '<info>Vol.</info>',
            null,
            '<fg=red>Price</fg=red>',
            '<fg=red>Vol.</fg=red>',
        ]);

        for ($i = 0; $i < $max; ++$i) {
            $this->table->setRow(
                $i,
                [
                    "<info>{$book['bids'][$i][0]}</info>",
                    "<info>{$book['bids'][$i][1]}</info>",
                    null,
                    "<fg=red>{$book['asks'][$i][0]}</fg=red>",
                    "<fg=red>{$book['asks'][$i][1]}</fg=red>",
                ]
            );
        }

        $this->table->render();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function doListOpenOrders(InputInterface $input, OutputInterface $output)
    {
        $this->table->setHeaders([
            null,
            '<comment>Id</comment>',
            '<comment>Date</comment>',
            '<comment>Type</comment>',
            '<comment>Volume</comment>',
            '<comment>Price</comment>',
            '<comment>Status</comment>',
        ]);

        $nb = 0;
        foreach ($this->client->getOpenOrders() as $instrument => $orders) {
            foreach ($orders as $order) {
                $sideColor = ('Bid' == $order['orderSide'] ? 'green' : 'red');
                $this->table->setRow(
                    $nb,
                    [
                        "<comment>{$instrument}</comment>",
                        $order['id'],
                        date('Y-m-d', $order['creationTime'] / 1000),
                        "<fg={$sideColor}>" . $order['orderSide'] . "</fg={$sideColor}>",
                        $order['volume'],
                        $order['price'],
                        $order['status'],
                    ]
                );
                $nb++;
            }
        }

        $this->table->render();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function doCancelOpenOrders(InputInterface $input, OutputInterface $output)
    {
        $params = $this->parseFilterArgument($input);
        p($params[0]);

        $this->client->cancelOpenOrder();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function doClearCache(InputInterface $input, OutputInterface $output)
    {
        $this->client->clearCache();
        $output->writeln('<info>Cache cleared successfully!</info>');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws RuntimeException
     */
    protected function doCollectData(InputInterface $input, OutputInterface $output)
    {
        $orderBook = $input->getOption('with-orderbook');
        $collector = $this->getContainer()->get('trade.data_collector.btc_markets');
        $collector->collectAll($orderBook);
        $orderBook = $orderBook ? 'with orderbooks ' : '';
        $output->writeln("<info>Data collected {$orderBook}successfully!</info>");
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @throws RuntimeException
     */
    protected function doAlert(InputInterface $input, OutputInterface $output)
    {
        $params = $this->parseFilterArgument($input);

        if (!isset($params[1])) {
            throw new RuntimeException('Specify a value after the filter (e.g.: XRP,1.5)');
        }

        $instrument = $params[0];
        $limit = (float) $params[1];

        if (isset($params[2]) && $params[2] == 'cron') {
            $beep = function ($nb, $delay = 1) {
                for ($i = 0; $i < $nb; ++$i) {
                    exec('play -q ' . __DIR__ . '/../Resources/sounds/beep.wav');
                    usleep($delay * 100000);
                }
            };

            if (!$price = $this->client->getLastPrice('XRP')) {
                $output->writeln("ERROR Can't get price");
                $beep(1);
                exit;
            }

            $output->write(date('H:i:s '));
            if ($price <= $limit) {
                $output->write("LIMIT {$price}");
                $beep(7, 6);
            } else {
                $output->write($price);
            }

            exit;
        }

        $rootDir = $this->getContainer()->getParameter('kernel.root_dir') . '/..';
        while (true) {
            $output->writeLn(exec("php {$rootDir}/bin/console trade:btc alert {$instrument},{$limit},cron"));
            sleep(30);
        }
    }
}
