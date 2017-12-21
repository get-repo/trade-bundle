<?php

/*
 * Symfony Trade Bundle
 */

namespace GetRepo\TradeBundle\Command;

use GetRepo\TradeBundle\Client\BTCMarketsClient;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * BTCMarkets command line.
 */
class BTCMarketsCommand extends ContainerAwareCommand
{
    /**
     * @var array
     */
    private $actionsMap = [
        'doBalance' => ['b', 'balance'],
        'doFunds' => ['f', 'funds'],
        'doTicks' => ['t', 'ticks'],
        'doMarket' => ['m', 'market', 'm'],
        'doClearCache' => ['clear-cache', 'cache-clear', 'clearcache', 'cacheclear', 'cc'],
        'doCollectData' => ['collect-data', 'collectdata', 'cd'],
    ];

    /**
     * @var BTCMarketsClient
     */
    private $client;

    /**
     * @var Table
     */
    private $table;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('trade:btc')
            ->addArgument('action', InputArgument::REQUIRED, 'Action name.')
            ->addArgument('filter', InputArgument::OPTIONAL, 'Optional filter.')
            ->setDescription('BTC markets command line')
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> manage BTC market trades

<comment>Show your blance:</comment>
  <info>php %command.full_name% balance (alias: b)</info>
<comment>Show your funds:</comment>
  <info>php %command.full_name% funds (alias: f)</info>
<comment>Show the currency prices:</comment>
  <info>php %command.full_name% ticks (alias: t)</info>
<comment>Show the order book for an instrument:</comment>
  <info>php %command.full_name% market (alias: m) BTC</info>
  <info>php %command.full_name% m XRP,15</info>
<comment>Clear the cache:</comment>
  <info>php %command.full_name% clear-cache (alias: cc)</info>

EOF
            );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $bundles = $container->getParameter('kernel.bundles');
        if (!isset($bundles['TradeBundle'])) {
            throw new RuntimeException('Bundle "TradeBundle" is not enabled in AppKernel');
        }

        $action = $input->getArgument('action');

        foreach ($this->actionsMap as $method => $aliases) {
            foreach ($aliases as $alias) {
                if (trim($action) === trim($alias)) {
                    if (!method_exists($this, $method)) {
                        throw new RuntimeException("Method '{$method}' does not exists.");
                    }

                    $this->table = new Table($output);
                    $this->client = $container->get('trade.client.btc_markets');

                    return $this->$method($input, $output);
                }
            }
        }

        throw new RuntimeException("Action '{$action}' does not exists.");
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    private function doBalance(InputInterface $input, OutputInterface $output)
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
            $diff = '';
            $tick = '';
            $total = '';

            if ('AUD' !== $instrument) {
                if (!$bal['balance']) {
                    continue;
                }
                $trades = $this->client->getLastTrades($instrument);
                if ($trades) {
                    $tick = $this->client->getBestBid($instrument);
                    $diff = $bal['balance'] * $tick;
                    $total = number_format($diff, 2);
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
                    $total,
                    ($diff ? "<{$diffFormat}>{$diff}</{$diffFormat}>" : $diff),
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
    private function doFunds(InputInterface $input, OutputInterface $output)
    {
        $this->table->setHeaders([
            '<comment>Date</comment>',
            '<comment>Type</comment>',
            '<comment>Cur</comment>',
            '<comment>AUD</comment>',
        ]);

        $total = 0;
        foreach ($this->client->getFunds() as $i => $fund) {
            $isDeposit = 'DEPOSIT' === $fund['transferType'];
            $typeFormat = $isDeposit ? 'fg=cyan' : 'info';
            $this->table->setRow($i, [
                date('Y-m-d', $fund['creationTime'] / 1000),
                "<{$typeFormat}>{$fund['transferType']}</{$typeFormat}>",
                $fund['currency'],
                number_format($fund['price'], 2),
            ]);
            if ($isDeposit) {
                $total = $total + $fund['price'] - $fund['fee'];
            } else {
                $total = $total - $fund['price'] - $fund['fee'];
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
    private function doTicks(InputInterface $input, OutputInterface $output)
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
    private function doMarket(InputInterface $input, OutputInterface $output)
    {
        $defaultMax = 200;
        $instrument = $input->getArgument('filter');

        if (!$instrument) {
            throw new RuntimeException('Specify an instrument in filter argument');
        }

        $params = explode(',', $instrument);
        $instrument = strtoupper($params[0]);
        if (!in_array($instrument, $this->client->getInstruments())) {
            throw new RuntimeException("Wrong instrument {$instrument}</error>");
        }

        $max = (isset($params[1]) && $params[1]) ? $params[1] : $defaultMax;

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
    private function doClearCache(InputInterface $input, OutputInterface $output)
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
    private function doCollectData(InputInterface $input, OutputInterface $output)
    {
    }
}
