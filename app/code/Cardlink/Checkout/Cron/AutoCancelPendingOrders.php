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

    private $newOrderStatus;

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
        $this->debug("[AutoCancelPendingOrders] Cron started.");
        try {
            $minCancelledOrderExpiration = 10;
            $configs = [
                'card' => [
                    'enabled' => $this->dataHelper->isCanceledOrderExpirationEnabled(),
                    'expiration_time' => (int) max($minCancelledOrderExpiration, (int)$this->dataHelper->getCanceledOrderExpiration()),
                    'payment_method' => 'cardlink_checkout'
                ],
                'iris' => [
                    'enabled' => $this->dataHelper->isIrisCanceledOrderExpirationEnabled(),
                    'expiration_time' => (int) max($minCancelledOrderExpiration, (int)$this->dataHelper->getIrisCanceledOrderExpiration()),
                    'payment_method' => 'cardlink_checkout_iris'
                ]
            ];
            $this->newOrderStatus = $this->dataHelper->getNewOrderStatus();

            foreach ($configs as $type => $config) {
                if (!$config['enabled']) {
                    continue;
                }
                $this->processCancellations($config['expiration_time'], $config['payment_method']);
            }
        } catch (\Exception $e) {
            $this->debug('Error in order cancellation cron: ' . $e->getMessage());
        }
        return $this;
    }

    /**
     * Process order cancellations for a specific configuration
     *
     * @param int $expirationTime Minutes before cancellation
     * @param string $paymentMethod Payment method code
     */
    private function processCancellations(int $expirationTime, string $paymentMethod): void
    {
        // expirationTime is in minutes
        $cancelBeforeTime = $this->calculateExpirationTime($expirationTime);
        if (!$cancelBeforeTime) return;
        // find the records
        $orderCollection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('created_at', ['lteq' => $cancelBeforeTime])
            ->addFieldToFilter('status', ['in' => [$this->newOrderStatus]])
            ->addFieldToFilter('sop.method', ['in' => [$paymentMethod]]);
        $orderCollection->getSelect()->join(["sop" => "sales_order_payment"], 'main_table.entity_id = sop.parent_id', ['method']);
        // Process in batches to handle large order sets
        $pageSize = 100;
        $orderCollection->setPageSize($pageSize);
        $lastPage = $orderCollection->getLastPageNumber();

        for ($currentPage = 1; $currentPage <= $lastPage; $currentPage++) {
            try {
                $orderCollection->setCurPage($currentPage);
                foreach ($orderCollection as $order) {
                    try {
                        $this->debug(
                            ['Order create time' => $order->getCreatedAt(), 'Order ID' => $order->getIncrementId()]
                        );
                        $order->addCommentToStatusHistory('Order has been canceled automatically', Order::STATE_CANCELED, true);
                        $order->cancel();
                        $order->save();
                        $this->debug(sprintf('Successfully canceled order #%s via cron (payment method: %s)', $order->getIncrementId(), $paymentMethod));
                    } catch (\Exception $e) {
                        $this->debug(sprintf('Failed to cancel order #%s: %s', $order->getIncrementId(), $e->getMessage()));
                    }
                }
                $orderCollection->clear();
            } catch (\Exception $e) {
                $this->debug('Error processing order batch: ' . $e->getMessage());
            }
        }
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
            $this->debug([
                'Current UTC time' => $currentTime->format('Y-m-d H:i:s'),
                'Expiration time UTC' => $formattedTime,
                'Expiration in minutes' => $expirationMinutes
            ]);
            return $formattedTime;
        } catch (\Exception $e) {
            $this->debug('Error calculating cutoff time: ' . $e->getMessage());
        }
        return '';
    }

    /**
     * Log debug information dynamically based on context.
     *
     * @param mixed ...$data Any number of arguments to log dynamically.
     * @return void
     */
    private function debug(...$data): void
    {
        if (!$this->dataHelper->logDebugInfoEnabled()) return;
        foreach ($data as $index => $item) $this->logger->debug(
            (
                is_array($item) || is_object($item) ? json_encode($item, JSON_PRETTY_PRINT) : (is_scalar($item) || $item === null ? (string)$item : var_export($item, true))
            )
        );
    }
}
