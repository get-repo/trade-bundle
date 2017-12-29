<?php

/*
 * Symfony Trade Bundle
 */

namespace GetRepo\TradeBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * AbstractCommand.
 */
abstract class AbstractCommand extends ContainerAwareCommand
{
    /**
     * @var Table
     */
    protected $table;

    /**
     * @var Table
     */
    protected $client;

    /**
     * @return string
     */
    protected abstract function getHelpContent();

    /**
     * @return array
     */
    protected abstract function getActionMap();

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $exchange = explode('\\', get_class($this));
        $name = strtolower(str_replace('Command', '',($exchange = end($exchange))));
        $this
            ->setName("trade:{$name}")
            ->addArgument('action', InputArgument::REQUIRED, 'Action name.')
            ->addArgument('filter', InputArgument::OPTIONAL, 'Optional filter.')
            ->addOption('with-orderbook', null, InputOption::VALUE_NONE, 'Collect data with order book')
            ->setDescription("{$exchange} command line")
            ->setHelp($this->getHelpContent());
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
        foreach ($this->getActionMap() as $method => $aliases) {
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
     * @return array
     */
    protected function parseFilterArgument(InputInterface $input, $default = 200)
    {
        $instrument = $input->getArgument('filter');

        if (!$instrument) {
            throw new RuntimeException('Specify an instrument in filter argument');
        }

        $params = explode(',', $instrument);
        $instrument = strtoupper($params[0]);
        if (!in_array($instrument, $this->client->getInstruments())) {
            throw new RuntimeException("Wrong instrument {$instrument}");
        }

        $params[0] = $instrument;

        return $params;
    }
}
