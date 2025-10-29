<?php

declare(strict_types=1);

namespace Cardlink\Checkout\Plugin\InventoryCatalog\ResourceModel;

use Magento\InventoryCatalog\Model\ResourceModel\AddIsInStockFilterToCollection as Subject;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Select;

class AddIsInStockFilterToCollectionPlugin
{
    private const ALIASES = [
        'stock_status_index',
        'ssi',
        'inventory_stock_status',
        'amasty_stock_status',
        'at_inventory_in_stock',
    ];

    /**
     * If a stock-status join exists, just enforce the WHERE without rejoining.
     * Accept/forward any extra args for compatibility across versions.
     */
    public function aroundExecute(Subject $subject, callable $proceed, Collection $collection, ...$args)
    {
        $from = $collection->getSelect()->getPart(Select::FROM);

        foreach (self::ALIASES as $alias) {
            if (isset($from[$alias])) {
                return $collection->addFieldToFilter('stock_status', 1);
            }
        }

        return $proceed($collection, ...$args);
    }
}
