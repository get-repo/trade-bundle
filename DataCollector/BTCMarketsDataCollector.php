<?php

/*
 * Symfony Trade Bundle
 */

namespace GetRepo\TradeBundle\DataCollector;

use GetRepo\TradeBundle\Client\BTCMarketsClient;
use Symfony\Component\Cache\Simple\FilesystemCache;

/**
 * BTCMarketsClient.
 */
class BTCMarketsDataCollector
{
    /**
     * @var BTCMarketsClient
     */
    private $client;

    /**
     * @var FilesystemCache
     */
    private $data;

    /**
     * @param BTCMarketsClient $client
     */
    public function __construct(BTCMarketsClient $client)
    {
        $this->client = $client;
        $this->data = new FilesystemCache('btc.data');
    }

    /**
     * Collect data.
     *
     * @return true
     */
    public function collectAll()
    {
        foreach ($this->client->getInstruments() as $instrument) {
            $this->collect($instrument);
        }

        return true;
    }

    /**
     * Collect data.
     *
     * @return true
     */
    public function collect($instrument)
    {
        $key = "btc.data.{$instrument}";
        if (!$this->data->has($key)) {
            $this->data->set($key, [['Time', $instrument]]);
        }

        $data = $this->data->get($key);
        $data[] = [date('H:i:s'), $this->client->getLastPrice($instrument)];
        $this->data->set($key, $data);

        return true;
    }

    /**
     * Get data for instrument.
     *
     * @return array
     */
    public function getData($instrument)
    {
        return $this->data->get("btc.data.{$instrument}");
    }
}
