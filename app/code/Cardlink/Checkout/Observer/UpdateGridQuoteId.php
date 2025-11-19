<?php
declare(strict_types=1);

namespace Cardlink\Checkout\Observer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

class UpdateGridQuoteId implements ObserverInterface
{
    public function __construct(private ResourceConnection $resource) {}

    public function execute(Observer $observer): void
    {
        /** @var OrderInterface|null $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order || !$order->getEntityId()) {
            return;
        }

        $quoteId = (int) $order->getQuoteId();
        $entityId = (int) $order->getEntityId();

        $conn = $this->resource->getConnection();
        $sog  = $conn->getTableName('sales_order_grid');

        // Upsert the quote_id into the grid row for this order
        $conn->update($sog, ['quote_id' => $quoteId], ['entity_id = ?' => $entityId]);
    }
}
