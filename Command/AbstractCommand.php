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
    const ARGS_REGEXP = '/^arg\d+$/';
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
            ->addArgument('arg1', InputArgument::OPTIONAL)
            ->addArgument('arg2', InputArgument::OPTIONAL)
            ->addArgument('arg3', InputArgument::OPTIONAL)
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
        $args = array_values($this->getArguments($input));
        $this->client = $container->get('trade.client.btc_markets');

        foreach ($this->getActionMap() as $method => $conf) {
            $aliases = (array) $conf[0];

            foreach ($aliases as $alias) {
                if (trim($action) === trim($alias)) {
                    if (!method_exists($this, $method)) {
                        throw new RuntimeException(
                            "Method '{$method}' does not exists."
                        );
                    }

                    // nb args
                    if (isset($conf[1]) && ($nbArgs = count($argsMap = (array) $conf[1]))) {
                        $i = 0;
                        foreach ($argsMap as $argName => $validation) {
                            $argValue = isset($args[$i]) ? $args[$i] : null;
                            if ($validation) {
                                switch (gettype($validation)) {
                                    case 'array':
                                        if (!in_array($argValue, $validation)) {
                                            $i = $i+2;
                                            $values = '';
                                            foreach ($validation as $value) {
                                                $values .= var_export($value, true) . ', ';
                                            }
                                            $values = trim($values, ', ');

                                            throw new RuntimeException(
                                                "Argument #{$i} '{$argName}' value '{$argValue}' is invalid.\n" .
                                                "Choose a value between: {$values}"
                                            );
                                        }
                                        break;

                                    case 'string':
                                        $res = @preg_match($validation, $argValue);
                                        // regex validation
                                        if (0 === $res) {
                                            $i = $i+2;
                                            throw new RuntimeException(
                                                "Argument #{$i} '{$argName}' value '{$argValue}' is invalid.\n" .
                                                "Value does not match."
                                            );
                                        // string validation
                                        } elseif (false === $res && $validation != $argValue) {
                                            $i = $i+2;
                                            throw new RuntimeException(
                                                "Argument #{$i} '{$argName}' value '{$argValue}' is invalid.\n" .
                                                "Value is not equal to '{$validation}'."
                                            );
                                        }
                                        break;
                                }
                            }
                            $i++;
                        }
                    }

                    $this->table = new Table($output);

                    return $this->$method($input, $output);
                }
            }
        }

        throw new RuntimeException("Action '{$action}' does not exists.");
    }

    /**
     * @return array
     */
    private function getArguments(InputInterface $input)
    {
        $args = $input->getArguments();

        return array_filter(
            array_intersect_key(
                $args,
                array_flip(preg_grep(self::ARGS_REGEXP, array_keys($args)))
            )
        );
    }
}
