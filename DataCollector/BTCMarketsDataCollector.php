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
     * @param string           $dataPath
     */
    public function __construct(BTCMarketsClient $client, $dataPath)
    {
        $this->client = $client;
        $this->data = new FilesystemCache('btc.data', 0, $dataPath);
    }

    /**
     * @param bool $orderBook
     *
     * @return true
     */
    public function collectAll($orderBook = false)
    {
        foreach ($this->client->getInstruments() as $instrument) {
            $this->collect($instrument, $orderBook);
        }

        return true;
    }

    /**
     * @param string $instrument
     * @param bool   $orderBook
     *
     * @return bool
     */
    public function collect($instrument, $orderBook = false)
    {
        $key = "btc.data.{$instrument}";
        $collected = [];
        if ($this->data->has($key)) {
            $collected = $this->data->get($key);
        }

        if ($orderBook) {
            $book = $this->client->getMarketOrderBook($instrument);
            $data['order_book'] = $book;
            $data['price'] = $book['bids'][0][0];
        } else {
            $data['order_book'] = [];
            $data['price'] = $this->client->getLastPrice($instrument);
        }

        $collected[time()] = $data;
        $this->data->set($key, $collected);

        return true;
    }

    /**
     * Get all collected data.
     *
     * @return array
     */
    public function getAllData()
    {
        $data = [];
        foreach ($this->client->getInstruments() as $instrument) {
            $data[$instrument] = $this->getData($instrument);
        }

        return array_filter($data);
    }

    /**
     * Get collected data for instrument.
     *
     * @param string $instrument
     *
     * @return array
     */
    public function getData($instrument)
    {
        return $this->data->get("btc.data.{$instrument}");
    }

    /**
     * Get all charts data.
     *
     * @return array
     */
    public function getAllChartsData()
    {
        $chartData = [];
        foreach ($this->getAllData() as $instrument => $data) {
            $chartData[$instrument] = [['Time', 'Price']];
            foreach ($data as $time => $values) {
                $chartData[$instrument][] = [$time, $values['price']];
            }
        }

        return $chartData;
    }
}
