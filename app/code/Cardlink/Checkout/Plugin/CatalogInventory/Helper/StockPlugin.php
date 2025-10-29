<?php

declare(strict_types=1);

namespace Cardlink\Checkout\Plugin\CatalogInventory\Helper;

use Magento\CatalogInventory\Helper\Stock as Subject;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Select;

class StockPlugin
{
    private const KNOWN_ALIASES = [
        'stock_status_index',
        'ssi',
        'inventory_stock_status',
        'amasty_stock_status',
        'at_inventory_in_stock', // MSI alias
    ];

    public function aroundAddInStockFilterToCollection(
        Subject $subject,
        callable $proceed,
        Collection $collection
    ) {
        $from = $collection->getSelect()->getPart(Select::FROM);

        // If any stock-status join already exists, don't re-join; just enforce filter.
        foreach (self::KNOWN_ALIASES as $alias) {
            if (isset($from[$alias])) {
                return $collection->addFieldToFilter('stock_status', 1);
            }
        }

        return $proceed($collection);
    }
}
