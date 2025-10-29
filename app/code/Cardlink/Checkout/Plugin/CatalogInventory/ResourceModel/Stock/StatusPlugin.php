<?php

declare(strict_types=1);

namespace Cardlink\Checkout\Plugin\CatalogInventory\ResourceModel\Stock;

use Magento\CatalogInventory\Model\ResourceModel\Stock\Status as Subject;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\DB\Select;

class StatusPlugin
{
    /** Aliases seen in core/MSI/3P joins */
    private const KNOWN_ALIASES = [
        'stock_status_index',     // legacy
        'ssi',                    // some 3P
        'inventory_stock_status', // 3P variants
        'amasty_stock_status',    // Amasty variants
        'at_inventory_in_stock',  // MSI adapted alias
    ];

    /**
     * IMPORTANT: $collectionOrSelect can be a ProductCollection OR a DB Select
     * (MSI adapts certain calls to pass a Select during indexing/search).
     * We accept both and skip re-adding the join if it's already present.
     */
    public function aroundAddStockStatusToSelect(
        Subject $subject,
        callable $proceed,
        $collectionOrSelect,           // <-- no type hint: can be Collection or Select
        $websiteId = null,
        $stockId = null
    ) {
        $from = null;

        if ($collectionOrSelect instanceof ProductCollection) {
            $from = $collectionOrSelect->getSelect()->getPart(Select::FROM);
        } elseif ($collectionOrSelect instanceof Select) {
            $from = $collectionOrSelect->getPart(Select::FROM);
        } else {
            // Unknown type (very unlikely) — just pass through untouched
            return $proceed($collectionOrSelect, $websiteId, $stockId);
        }

        if (is_array($from)) {
            foreach (self::KNOWN_ALIASES as $alias) {
                if (isset($from[$alias])) {
                    // Already joined; return the same value type we received
                    return $collectionOrSelect;
                }
            }
        }

        // Let core/MSI proceed; forward the args exactly as received
        return $proceed($collectionOrSelect, $websiteId, $stockId);
    }

    /**
     * Also guard addStockDataToCollection.
     * Accept extra args for compatibility across versions.
     */
    public function aroundAddStockDataToCollection(
        Subject $subject,
        callable $proceed,
        ProductCollection $collection,
        ...$args
    ) {
        $from = $collection->getSelect()->getPart(Select::FROM);

        foreach (self::KNOWN_ALIASES as $alias) {
            if (isset($from[$alias])) {
                return $collection; // join already there
            }
        }

        return $proceed($collection, ...$args);
    }
}
