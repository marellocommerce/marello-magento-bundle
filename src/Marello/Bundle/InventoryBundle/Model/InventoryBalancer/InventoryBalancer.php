<?php

namespace Marello\Bundle\InventoryBundle\Model\InventoryBalancer;

use Doctrine\Common\Collections\ArrayCollection;

use Marello\Bundle\ProductBundle\Entity\Product;
use Marello\Bundle\InventoryBundle\Entity\Warehouse;
use Marello\Bundle\InventoryBundle\Entity\InventoryItem;
use Marello\Bundle\InventoryBundle\Entity\InventoryLevel;
use Marello\Bundle\InventoryBundle\Entity\WarehouseGroup;
use Marello\Bundle\InventoryBundle\Manager\InventoryItemManager;
use Marello\Bundle\InventoryBundle\Entity\WarehouseChannelGroupLink;
use Marello\Bundle\InventoryBundle\Strategy\BalancerStrategyInterface;
use Marello\Bundle\InventoryBundle\Strategy\BalancerStrategiesRegistry;
use Marello\Bundle\InventoryBundle\Provider\WarehouseTypeProviderInterface;
use Marello\Bundle\InventoryBundle\Model\InventoryBalancer\VirtualInventoryHandler;

class InventoryBalancer
{
    const DEFAULT_BALANCER_STRATEGY = 'equal_division';

    /** @var BalancerStrategiesRegistry $balancerStrategyRegistry */
    protected $balancerStrategyRegistry;

    /** @var VirtualInventoryHandler $virtualInventoryHandler */
    protected $virtualInventoryHandler;

    /** @var InventoryItemManager $inventoryItemManager */
    protected $inventoryItemManager;

    /**
     * @param BalancerStrategiesRegistry $balancerRegistry
     * @param InventoryItemManager $inventoryItemManager
     * @param VirtualInventoryHandler $virtualInventoryHandler
     */
    public function __construct(
        BalancerStrategiesRegistry $balancerRegistry,
        InventoryItemManager $inventoryItemManager,
        VirtualInventoryHandler $virtualInventoryHandler
    ) {
        $this->balancerStrategyRegistry = $balancerRegistry;
        $this->inventoryItemManager = $inventoryItemManager;
        $this->virtualInventoryHandler = $virtualInventoryHandler;
    }

    /**
     * Balance inventory for a product
     * @param Product $product
     * @param bool $isFixed
     */
    public function balanceInventory(Product $product, $isFixed = false)
    {
        /** @var InventoryItem $inventoryItem */
        $inventoryItem = $this->getInventoryItemFromProduct($product);

        if (!$inventoryItem) {
            return;
        }

        $inventoryLevels = $inventoryItem->getInventoryLevels();
        $filteredInventoryLevels = $this->filterInventoryLevels($inventoryLevels, $isFixed);
        $sortedWhgLevels = $this->sortInventoryLevels($filteredInventoryLevels, $isFixed);
        $linkedWhgToScgs = $this->getLinkedWarehouseGroupsToSalesChannelGroups($filteredInventoryLevels);
        $this->generateResult($linkedWhgToScgs, $sortedWhgLevels, $product);
    }

    /**
     * Filter inventory levels by warehouse type (fixed and non-fixed)
     * @param $inventoryLevels
     * @param $isFixed
     * @return ArrayCollection|InventoryItem[]
     */
    protected function filterInventoryLevels($inventoryLevels, $isFixed)
    {
        /** @var InventoryItem[]|ArrayCollection $inventoryLevels */
        $inventoryLevels = $inventoryLevels->filter(function($level) use ($isFixed) {
            return ($this->filterInventoryLevelByWarehouseType($level, $isFixed) && $this->hasWarehouseChannelGroupLink($level));
        });

        return $inventoryLevels;
    }

    /**
     * Sort inventory levels by warehouse group and get available inventory quantity
     * @param ArrayCollection $inventoryLevels
     * @param $isFixed
     * @return array
     */
    protected function sortInventoryLevels($inventoryLevels, $isFixed)
    {
        $sortedWhgLevels = [];
        $inventoryLevels->map(function($level) use (&$sortedWhgLevels, $isFixed) {
            /** @var InventoryLevel $level */
            /** @var Warehouse $warehouse */
            $warehouse = $level->getWarehouse();
            /** @var WarehouseGroup $warehouseGroup */
            $warehouseGroup = $this->getGroup($warehouse);
            if (!$isFixed) {
                if (!array_key_exists($warehouseGroup->getId(), $sortedWhgLevels)) {
                    $sortedWhgLevels[$warehouseGroup->getId()] = $level->getVirtualInventoryQty();
                } else {
                    $sortedWhgLevels[$warehouseGroup->getId()] += $level->getVirtualInventoryQty();
                }
            } else {
                $sortedWhgLevels[$warehouseGroup->getId()] = $level->getVirtualInventoryQty();
            }
        });

        return $sortedWhgLevels;
    }

    /**
     * Get linked saleschannel groups by warehouse
     * @param ArrayCollection $inventoryLevels
     * @return array
     */
    protected function getLinkedWarehouseGroupsToSalesChannelGroups($inventoryLevels)
    {
        $linkedWhgToScgs = [];
        $inventoryLevels->map(function($level) use (&$linkedWhgToScgs) {
            /** @var InventoryLevel $level */
            /** @var Warehouse $warehouse */
            $warehouse = $level->getWarehouse();
            /** @var WarehouseGroup $warehouseGroup */
            $warehouseGroup = $this->getGroup($warehouse);
            $linkedWhgToScgs[$warehouseGroup->getId()] = $this->getWarehouseChannelGroupLink($warehouse)->getSalesChannelGroups();
        });

        return $linkedWhgToScgs;
    }

    /**
     * Check if a inventory level has a warehouse with a group link associated
     * @param InventoryLevel $level
     * @return WarehouseChannelGroupLink
     */
    protected function hasWarehouseChannelGroupLink(InventoryLevel $level)
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->getWarehouseFromInventoryLevel($level);
        return $this->getWarehouseChannelGroupLink($warehouse);
    }

    /**
     * Filter the inventory level by warehouse type and check if a warehouse
     * @param InventoryLevel $level
     * @param $isFixed
     * @return bool
     */
    protected function filterInventoryLevelByWarehouseType(InventoryLevel $level, $isFixed)
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->getWarehouseFromInventoryLevel($level);
        $warehouseType = $warehouse->getWarehouseType();

        if ($isFixed) {
            return ($warehouseType->getName() === WarehouseTypeProviderInterface::WAREHOUSE_TYPE_FIXED);
        }

        return ($warehouseType->getName() !== WarehouseTypeProviderInterface::WAREHOUSE_TYPE_FIXED);
    }

    /**
     * Get warehouse channelgroup link
     * @param Warehouse $warehouse
     * @return WarehouseChannelGroupLink
     */
    protected function getWarehouseChannelGroupLink(Warehouse $warehouse)
    {
        /** @var WarehouseGroup $warehouseGroup */
        $warehouseGroup = $this->getGroup($warehouse);
        return $warehouseGroup->getWarehouseChannelGroupLink();
    }

    /**
     * Get warehouse group
     * @param Warehouse $warehouse
     * @return WarehouseGroup
     */
    protected function getGroup(Warehouse $warehouse)
    {
        return $warehouse->getGroup();
    }

    /**
     *
     * @param $linkedWhgToScgs
     * @param $sortedWhgLevels
     * @param $product
     */
    protected function generateResult($linkedWhgToScgs, $sortedWhgLevels, $product)
    {
        $strategy = $this->getStrategy();
        foreach ($linkedWhgToScgs as $whgId => $scgs) {
            $inventoryTotalForWhg = $sortedWhgLevels[$whgId];
            $result = $strategy->getBalancedResult($product, $scgs, $inventoryTotalForWhg);
            foreach ($scgs as $group) {
                $virtualLevel = $this->virtualInventoryHandler->createVirtualInventory($product, $group, $result);
                $this->virtualInventoryHandler->saveVirtualInventory($virtualLevel);
            }
        }
    }

    /**
     * Get Warehouse from inventoryLevel
     * @param InventoryLevel $inventoryLevel
     * @return Warehouse
     */
    protected function getWarehouseFromInventoryLevel(InventoryLevel $inventoryLevel)
    {
        return $inventoryLevel->getWarehouse();
    }

    /**
     * @param string $strategyIdentifier
     * @return BalancerStrategyInterface|null
     */
    protected function getStrategy($strategyIdentifier = self::DEFAULT_BALANCER_STRATEGY)
    {
        return $this->balancerStrategyRegistry->getStrategy($strategyIdentifier);
    }

    /**
     * Get InventoryItem from Product
     * @param Product $product
     * @return null|object
     */
    protected function getInventoryItemFromProduct(Product $product)
    {
        return $this->inventoryItemManager->getInventoryItem($product);
    }
}
