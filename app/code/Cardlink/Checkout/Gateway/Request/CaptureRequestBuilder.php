<?php

namespace Cardlink\Checkout\Gateway\Request;

use Cardlink\Checkout\Gateway\Http\Client\TransactionClient;
use Cardlink\Checkout\Logger\Logger;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;

/**
 * Capture Request Builder for Cardlink payment gateway.
 * Builds the request data for capture transactions.
 *
 * @author Cardlink S.A.
 */
class CaptureRequestBuilder implements BuilderInterface
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param Logger $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Builds request data.
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $amount = SubjectReader::readAmount($buildSubject);
        
        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        
        // Get the actual Magento order to avoid issues with third-party adapters (e.g., Braintree)
        $order = $payment->getOrder();
        
        // Get the Cardlink order ID stored during payment response
        // This is the order ID that Cardlink's system recognizes (includes 4-char suffix)
        $cardlinkOrderId = $payment->getCardlinkOrderId();
        
        $this->logger->debug('CaptureRequestBuilder: Building capture request');
        $this->logger->debug('CaptureRequestBuilder: cardlink_order_id from payment = ' . ($cardlinkOrderId ?: 'NULL'));
        $this->logger->debug('CaptureRequestBuilder: Magento order increment ID = ' . $order->getIncrementId());
        
        if (empty($cardlinkOrderId)) {
            // Fallback to Magento order increment ID if cardlink_order_id not set
            // WARNING: This may fail as the Cardlink API expects the full order ID with suffix
            $this->logger->warning('CaptureRequestBuilder: cardlink_order_id is empty, falling back to Magento increment ID. This may cause capture to fail.');
            $orderId = $order->getIncrementId();
        } else {
            $orderId = $cardlinkOrderId;
        }
        
        if (empty($orderId)) {
            throw new \InvalidArgumentException('No order ID found for capture operation');
        }

        $this->logger->debug('CaptureRequestBuilder: Using order ID for capture = ' . $orderId);

        return [
            'action' => TransactionClient::ACTION_CAPTURE,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $order->getOrderCurrencyCode(),
            'transaction_id' => $payment->getCardlinkTxId() ?: $payment->getLastTransId(),
            'payment_method_code' => $payment->getMethodInstance()->getCode()
        ];
    }
}
