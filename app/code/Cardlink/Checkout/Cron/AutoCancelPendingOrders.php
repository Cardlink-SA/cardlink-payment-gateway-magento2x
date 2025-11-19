<?php

namespace Cardlink\Checkout\Cron;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Logger\Logger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

/**
 * Cronjob used to remove pending orders created by this module after a specified timeframe in module options.
 *
 * @author Cardlink S.A.
 */
class AutoCancelPendingOrders
{
    /**
     * @var CollectionFactory
     */
    private $orderCollectionFactory;

    /**
     * @var Data
     */
    private $dataHelper;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @param CollectionFactory $orderCollectionFactory
     * @param Data $dataHelper
     * @param Logger $logger
     */
    public function __construct(
        CollectionFactory $orderCollectionFactory,
        Data $dataHelper,
        Logger $logger
    ) {
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->dataHelper = $dataHelper;
        $this->logger = $logger;
    }

    /**
     * Action execution method.
     */
    public function execute(): self
    {
        try {
            $minCancelledOrderExpiration = 10;
            $configs = [
                'card' => [
                    'enabled' => $this->dataHelper->isCanceledOrderExpirationEnabled(),
                    'expiration_time' => (int) max($minCancelledOrderExpiration, (int)$this->dataHelper->getCanceledOrderExpiration()),
                    'payment_method' => 'cardlink_checkout',
                    'new_order_status' => $this->dataHelper->getNewOrderStatus()
                ],
                'iris' => [
                    'enabled' => $this->dataHelper->isIrisCanceledOrderExpirationEnabled(),
                    'expiration_time' => (int) max($minCancelledOrderExpiration, (int)$this->dataHelper->getIrisCanceledOrderExpiration()),
                    'payment_method' => 'cardlink_checkout_iris',
                    'new_order_status' => $this->dataHelper->getIrisNewOrderStatus()
                ]
            ];

            foreach ($configs as $type => $config) {
                $this->processCancellations($config);
            }
        } catch (\Exception $e) {
            $this->logger->debug('Error in order cancellation cron: ' . $e->getMessage());
        }
        return $this;
    }

    private function processCancellations(array $config): void
    {
        if (!$config['enabled']) {
            $this->logger->debug('Auto-cancel for payment method ' . $config['payment_method'] . ' is disabled, skipping.');
            return;
        }

        $paymentMethod = $config['payment_method'];
        $expirationTime = $config['expiration_time'];
        $newOrderStatus = $config['new_order_status'];

        $cancelBeforeTime = $this->calculateExpirationTime($expirationTime);
        if (!$cancelBeforeTime) {
            $this->logger->debug('Unable to calculate cancellation cutoff time, skipping cancellations.');
            return;
        }

        $collection = $this->orderCollectionFactory->create();

        // JOIN payment first, then filter by method
        $collection->getSelect()->join(
            ['sop' => $collection->getTable('sales_order_payment')],
            'main_table.entity_id = sop.parent_id',
            ['method']
        );

        // Only 'new' (or your configured) orders older than the cutoff, for this method
        $collection
            ->addFieldToFilter('main_table.created_at', ['lteq' => $cancelBeforeTime])
            ->addFieldToFilter('status', ['in' => [$newOrderStatus]])
            ->addFieldToFilter('sop.method', ['eq' => $paymentMethod])

            // Exclude orders that are already paid or settled
            // (any of these indicates payment happened)
            ->addFieldToFilter('base_total_paid', [['null' => true], ['eq' => 0]])  // unpaid
            ->addFieldToFilter('total_due', ['gt' => 0]);                           // still due

        // Optional: exclude orders that already have invoices
        // (LEFT JOIN and require invoice NULL)
        $select     = $collection->getSelect();
        $select->joinLeft(
            ['si' => $collection->getTable('sales_invoice')],
            'si.order_id = main_table.entity_id',
            []
        )
            ->where('si.entity_id IS NULL');

        // Batch through the collection
        $pageSize = 100;
        $collection->setPageSize($pageSize);
        $lastPage = max(1, (int)$collection->getLastPageNumber());

        for ($currentPage = 1; $currentPage <= $lastPage; $currentPage++) {
            try {
                $collection->setCurPage($currentPage);

                foreach ($collection as $order) {

                    $this->logger->debug('Processing order #' . $order->getIncrementId() . ' for cancellation (payment method: ' . $paymentMethod . ').');

                    try {
                        // FINAL GUARD — skip if paid or cannot cancel
                        if ($this->isPaid($order) || !$order->canCancel()) {
                            $this->logger->debug(sprintf(
                                'Skip cancel for order #%s (paid or cannot cancel).',
                                $order->getIncrementId()
                            ));
                            continue;
                        }

                        // cancel (no customer email)
                        $order->addCommentToStatusHistory(
                            'Order has been canceled automatically',
                            \Magento\Sales\Model\Order::STATE_CANCELED,
                            false
                        );
                        $order->cancel();
                        $order->save();

                        $this->logger->debug(sprintf(
                            'Successfully canceled order #%s via cron (payment method: %s)',
                            $order->getIncrementId(),
                            $paymentMethod
                        ));
                    } catch (\Exception $e) {
                        $this->logger->debug(sprintf(
                            'Failed to cancel order #%s: %s',
                            $order->getIncrementId(),
                            $e->getMessage()
                        ));
                    }
                }

                $collection->clear();
            } catch (\Exception $e) {
                $this->logger->debug('Error processing order batch: ' . $e->getMessage());
            }
        }
    }

    /**
     * Consider an order "paid" if any paid/settled signals are present.
     */
    private function isPaid(Order $order): bool
    {
        return
            (float)$order->getBaseTotalPaid() > 0 ||
            (float)$order->getTotalPaid() > 0 ||
            (float)$order->getTotalDue() <= 0 ||
            $order->hasInvoices() ||
            in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true);
    }


    /**
     * @param int $expirationMinutes Minutes to subtract from current time
     * @return string Returns formatted datetime string in UTC
     */
    private function calculateExpirationTime(int $expirationMinutes): string
    {
        try {
            $currentTime = new \DateTime('now', new \DateTimeZone('UTC'));
            $cancelBeforeTime = clone $currentTime;
            $cancelBeforeTime->modify("-{$expirationMinutes} minutes");
            $formattedTime = $cancelBeforeTime->format('Y-m-d H:i:s');
            // $this->logger->debug(json_encode([
            //     'Current UTC time' => $currentTime->format('Y-m-d H:i:s'),
            //     'Expiration time UTC' => $formattedTime,
            //     'Expiration in minutes' => $expirationMinutes
            // ]));
            return $formattedTime;
        } catch (\Exception $e) {
            $this->logger->debug('Error calculating cutoff time: ' . $e->getMessage());
        }
        return '';
    }
}
