<?php

declare(strict_types=1);

namespace Cardlink\Checkout\Plugin\InventoryCatalog\ResourceModel;

use Magento\InventoryCatalog\Model\ResourceModel\AddStockDataToCollection as Subject;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Framework\DB\Select;

class AddStockDataToCollectionPlugin
{
    /** Known aliases added by core/MSI/3P for stock status */
    private const ALIASES = [
        'stock_status_index',      // legacy & MSI
        'ssi',                     // 3P variants
        'inventory_stock_status',  // 3P variants
        'amasty_stock_status',     // Amasty
        'at_inventory_in_stock',   // MSI adapted alias
    ];

    /**
     * Make AddStockDataToCollection idempotent and forward any extra args (e.g. $stockId)
     *
     * @param Subject    $subject
     * @param callable   $proceed
     * @param Collection $collection
     * @param mixed      ...$args  (e.g. $isFilterInStock, $stockId)
     * @return Collection
     */
    public function aroundExecute(Subject $subject, callable $proceed, Collection $collection, ...$args)
    {
        $from = $collection->getSelect()->getPart(Select::FROM);

        foreach (self::ALIASES as $alias) {
            if (isset($from[$alias])) {
                // Join already present — skip re-adding
                return $collection;
            }
        }

        // No join yet — let MSI add it; forward all args your version expects
        return $proceed($collection, ...$args);
    }
}
