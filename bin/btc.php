#!/usr/bin/env php
<?php

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Helper\Table;

$loader = require __DIR__.'/../../../autoload.php';
$kernel = new AppKernel('dev', true);
$kernel->boot();
$container = $kernel->getContainer();
$input = new ArgvInput();
$output = new ConsoleOutput();
$formatter = new OutputFormatter(true);
$output->setFormatter($formatter);

if (!isset($container->getParameter('kernel.bundles')['TradeBundle'])) {
    $output->writeln('<error>Bundle "TradeBundle" is not enabled in AppKernel</error>');
    exit;
}

$client = $container->get('trade.client.btc_markets');

$help = $formatter->format('<comment>Usage: btc <action> [args]</comment>
  -b --balance              Show actual balance
  -f --funds                Show funds
  -t --ticks                Show actual currency prices
  -m=BTC --market=BTC,150   Show actual prices
  --cc --clear-cache        Clear the cache
  -h -H --help              Show this help message
');

if ($input->hasParameterOption(['-h', '-H', '--help'])) {
    echo $help;
    exit;
}

if ($input->hasParameterOption(['-b', '--balance'])) {
    $balances = $client->getBalances();
    $table = new Table($output);
    $table->setHeaders([
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
        if ('AUD' !== $instrument) { // && $bal['price']
            $trades = $client->getLastTrades($instrument);
            if ($trades) {
                $tick = $client->getLastPrice($instrument);
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

        $table->setRow(
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

    $table->render();

    $total = $client->getBalanceTotal();
    echo $formatter->format(
        "\n<comment>TOTAL</comment>  \$".number_format($total, 2)
    );

    $funds = $client->getTotalFunds();
    echo $formatter->format(
        "\n<comment>FUNDS</comment>  \$".number_format($funds, 2)
    );

    $diff = number_format($total - $funds, 2);
    $diffFormat = $diff < 0 ? 'error' : 'info';
    echo $formatter->format(
        "\n<comment>DIFF</comment>   <$diffFormat>\${$diff}</$diffFormat>"
    );
    echo "\n";
} elseif ($input->hasParameterOption(['-t', '--ticks'])) {
    $table = new Table($output);
    $cpt = 0;
    foreach ($client->getInstruments() as $instrument) {
        $price = number_format($client->getLastPrice($instrument), 2);
        $table->setRow($cpt, ["<comment>{$instrument}</comment>", $price]);
        ++$cpt;
    }
    $table->render();
} elseif ($input->hasParameterOption(['-f', '--funds'])) {
    $table = new Table($output);
    $table->setHeaders([
        '<comment>Date</comment>',
        '<comment>Type</comment>',
        '<comment>Cur</comment>',
        '<comment>AUD</comment>',
    ]);
    $total = 0;
    foreach ($client->getFunds() as $i => $fund) {
        $isDeposit = 'DEPOSIT' === $fund['transferType'];
        $typeFormat = $isDeposit ? 'fg=cyan' : 'info';
        $table->setRow($i, [
            date('Y-m-d', $fund['creationTime'] / 1000),
            "<{$typeFormat}>{$fund['transferType']}</{$typeFormat}>",
            $fund['currency'],
            number_format($fund['price'], 2),
        ]);
        if ($isDeposit) {
            $total = $total + $fund['price']- $fund['fee'];
        } else {
            $total = $total - $fund['price']- $fund['fee'];
        }
    }

    $table->render();

    $funds = $client->getTotalFunds();
    echo $formatter->format(
        str_repeat(' ', 24)."<comment>TOTAL</comment>  \$".number_format($funds, 2)
    );

    echo "\n\n";
} elseif ($input->hasParameterOption(['-m', '--market'])) {
    $instrument = $input->getParameterOption(['-m', '--market']);
    if (!$instrument) {
        $output->writeln('<error>Specify an instrument (-m=BTC)</error>');
        exit;
    }

    $params = explode(',', $instrument);
    $instrument = $params[0];

    if (!in_array($instrument, $client->getInstruments())) {
        $output->writeln("<error>Wrong instrument {$instrument}</error>");
        exit;
    }
    $max = (isset($params[1]) && $params[1]) ? $params[1] : 200;
    $book = $client->getMarketOrderBook($instrument);

    $table = new Table($output);
    $table->setHeaders([
        '<info>Price</info>',
        '<info>Vol.</info>',
        null,
        '<fg=red>Price</fg=red>',
        '<fg=red>Vol.</fg=red>',
    ]);

    for ($i = 0; $i < $max; ++$i) {
        $table->setRow(
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

    $table->render();
} elseif ($input->hasParameterOption(['--cc', '--clear-cache'])) {
    $client->clearCache();
    echo $formatter->format("<info>Cache cleared</info>\n");
} else {
    echo $help;
    exit;
}
