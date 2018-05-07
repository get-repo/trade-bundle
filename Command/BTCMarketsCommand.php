<?php

/*
 * Symfony Trade Bundle
 */

namespace GetRepo\TradeBundle\Command;

use GetRepo\TradeBundle\Client\BTCMarketsClient;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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
        $instruments = $this->client->getInstruments();

        return[
            'doBalance' => [['b', 'balance']],
            'doFunds' => [['f', 'funds']],
            'doTicks' => [['t', 'ticks']],
            'doMarket' => [
                ['m', 'market'], // alias(es)
                ['instrument' => $instruments], // mandatory args map
            ],
            'doListOpenOrders' => [['o', 'open-orders']],
            'doCancelOpenOrders' => [
                ['co', 'cancel-open-orders'],
                [
                    // instrument or order id
                    'instrument or id' => '/^(' . implode('|', $instruments) . '|\d+)?$/',
                    // pair optional
                    // TODO use method get pair
                    'pair' => ['AUD', 'BTC', null],
                ],
            ],
            'doClearCache' => [
                ['c', 'clear-cache', 'cache-clear', 'clearcache', 'cacheclear'],
            ],
            'doCollectData' => [
                ['cd', 'collect-data', 'collectdata'],
            ],
            'doAlert' => [
                ['a', 'alert'],
                [
                    'instrument' => $instruments,
                    'price' => '/^\d+(\.\d+)?$/'
                ],
            ],
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
  %command.name% balance <info>(b)</info>
<comment>Show your funds:</comment>
  %command.name% funds <info>(f)</info>
<comment>Show the currency prices:</comment>
  %command.name% ticks <info>(t)</info>
<comment>Show the order book for an instrument:</comment>
  <info>Show order book for instrument:</info>
    %command.name% market BTC
  <info>Show lhe last 15 orders for instrument:</info>
    %command.name% m XRP 15
<comment>List open order(s)</comment>
  %command.name% open-orders <info>(o)</info>
<comment>Cancel open order(s)</comment>
  <info>Cancel one open order by id:</info>
    %command.name% cancel-open-orders 123456789
  <info>Cancel all open orders by instrument (all pairs):</info>
    %command.name% co LTC
  <info>Cancel all open orders by instrument with pair:</info>
    %command.name% co ETH USD
<comment>Collect data (cron script):</comment>
  %command.name% collect-data <info>(cd)</info>
<comment>Price alert (cron script):</comment>
  <info>Set an alarm when XRP goes under 1.5:</info>
    %command.name% alert XRP 1.5
  <info>Set an alarm when BTC goes under 10000:</info>
    %command.name% a BTC 10000
<comment>Clear the cache:</comment>
  %command.name% clear-cache <info>(c)</info>
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

                    $netCost = 0;
                    foreach (array_reverse($trades) as $trade) {
                        if ('Bid' === $trade['side']) {
                            $netCost += $trade['amount'];
                        } else {
                            $netCost -= $trade['amount'];
                        }
                    }

                    $diff = ($bal['balance'] * $tick) - $netCost;
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
        $instrument = $input->getArgument('arg1');
        if (!$max = $input->getArgument('arg2')) {
            $max = 200; // TODO put that in constant 200
        }
        // TODO if $max > 200

        $book = $this->client->getMarketOrderBook($instrument);
        $this->table->setHeaders([
            '<info>Price</info>',
            '<info>Vol.</info>',
            null,
            '<fg=red>Price</fg=red>',
            '<fg=red>Vol.</fg=red>',
        ]);

        for ($i = 0; $i < $max; ++$i) {
            if (isset($book['bids'][$i])) {
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
        foreach ($this->client->getOpenOrders() as $instrumentIn => $instOrders) {
            foreach ($instOrders as $instrumentOut => $orders) {
                foreach ($orders as $order) {
                    $sideColor = ('Bid' == $order['orderSide'] ? 'green' : 'red');
                    $this->table->setRow(
                        $nb,
                        [
                            "<comment>{$instrumentIn}/{$instrumentOut}</comment>",
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
        }

        $this->table->render();
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function doCancelOpenOrders(InputInterface $input, OutputInterface $output)
    {
        $filter = $input->getArgument('arg1');
        $pair = $input->getArgument('arg2');
        $helper = $this->getHelper('question');
        $ids = [];

        // cancel all instrument open orders
        if (in_array($filter, $this->client->getInstruments())) {
            $question = new ConfirmationQuestion(
                "Cancel all {$filter}" .
                ($pair ? "/{$pair}" : '') .
                " open orders (Y/n)? ",
                false,
                '/^Y$/'
            );

            if (!$helper->ask($input, $output, $question)) {
                return;
            }

            foreach ($this->client->getOpenOrders($filter, $pair) as $orders) {
                if (!$pair) {
                    foreach ($orders as $order) {
                        $ids[] = (int) $order['id'];
                    }
                } else {
                    $ids[] = (int) $orders['id'];
                }
            }
        }
        // cancel one by open order id
        else {
            $question = new ConfirmationQuestion(
                "Cancel open order {$filter} (Y/n)? ",
                false,
                '/^Y$/'
            );

            if (!$helper->ask($input, $output, $question)) {
                return;
            }
            $ids[] = (int) $filter;
        }

        foreach ($ids as $id) {
            $output->write("  > cancel {$id} ... ");
            try {
                $this->client->cancelOpenOrder($id);
                $output->writeln("<info>[OK]</info>");
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $output->writeln(
                    "<fg=red>[FAILED] {$msg}</fg=red>"
                );
            }
        }
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
        $instrument = $input->getArgument('arg1');
        $limit = (float) $input->getArgument('arg2');
        $run = $input->getArgument('arg3') === 'cron';

        if ($run) {
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
            $output->writeLn(exec(
                "php {$rootDir}/bin/console trade:btc alert {$instrument} {$limit} cron"
            ));
            sleep(30);
        }
    }
}
