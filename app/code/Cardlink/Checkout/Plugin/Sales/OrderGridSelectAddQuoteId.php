<?php
declare(strict_types=1);

namespace Cardlink\Checkout\Plugin\Sales;

use Magento\Sales\Model\ResourceModel\Order\Grid as OrderGrid;
use Magento\Framework\DB\Select;

/**
 * Adds "quote_id" to the select used by the Sales Order Grid indexer.
 * Result: sales_order_grid table will have a populated quote_id column.
 */
class OrderGridSelectAddQuoteId
{
    /**
     * Append quote_id to the columns of the Orders grid select.
     *
     * @param OrderGrid $subject
     * @param Select    $select
     * @return Select
     */
    public function afterGetOrdersSelect(OrderGrid $subject, Select $select): Select
    {
        // Determine the main alias used by Magento in this select (usually "o")
        $from = $select->getPart(Select::FROM);
        $mainAlias = array_key_first($from);

        if ($mainAlias) {
            // Add "quote_id AS quote_id" from main table (sales_order)
            $select->columns(['quote_id' => $mainAlias . '.quote_id']);
        }

        return $select;
    }
}
