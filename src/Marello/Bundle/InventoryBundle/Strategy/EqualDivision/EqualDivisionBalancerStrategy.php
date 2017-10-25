<?php

namespace Marello\Bundle\InventoryBundle\Strategy\EqualDivision;

use ArrayAccess;

use Marello\Bundle\InventoryBundle\Strategy\BalancerStrategyInterface;
use Marello\Bundle\ProductBundle\Entity\ProductInterface;

/**
 * Class EqualDivisionBalancerStrategy
 * @package MarelloEnterprise\Bundle\InventoryBundle\Strategy\EqualDivision
 * This balancer will balance the total inventory of a product into equal pieces
 * for the amount of sales channels.
 */
class EqualDivisionBalancerStrategy implements BalancerStrategyInterface
{
    const IDENTIFIER = 'equal_division';

    /**
     * {@inheritdoc}
     */
    public function getIdentifier()
    {
        return self::IDENTIFIER;
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getBalancedResult(
        ProductInterface $product,
        ArrayAccess $salesChannelGroups,
        $inventoryTotal
    ) {
        $totalChannelGroups = count($salesChannelGroups);
        $totalPerChannelRaw = ($inventoryTotal  / $totalChannelGroups);
        $totalPerChannelPrecision = round($totalPerChannelRaw, 0, PHP_ROUND_HALF_DOWN);
        $calculatedResult = ($totalPerChannelPrecision * $totalChannelGroups);
        if ($calculatedResult !== $inventoryTotal) {
            $leftOverTotal = ($inventoryTotal - $calculatedResult);
            if ($leftOverTotal === (float) 0) {
                return $totalPerChannelPrecision;
            }
        }

        return $calculatedResult;
    }
}
