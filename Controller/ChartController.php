<?php

/*
 * Symfony Trade Bundle
 */

namespace GetRepo\TradeBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

/**
 * Chart Controller.
 */
class ChartController extends Controller
{
    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function showAction(Request $request)
    {
        $collector = $this->container->get('trade.data_collector.btc_markets');
        $data = $collector->getAllChartsData();

        if ($filter = $request->query->all()) {
            if ($filter = array_filter(explode(',', current($filter)))) {
                $filter = array_flip(array_map('trim', array_map('strtoupper', $filter)));
                $data = array_intersect_key($data, $filter);
            }
        }

        return $this->render('@TradeBundle/Resources/views/chart.html.twig', [
            'data' => $data,
        ]);
    }
}
