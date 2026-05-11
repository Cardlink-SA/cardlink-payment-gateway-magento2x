<?php

namespace Cardlink\Checkout\Gateway\Request;

use Cardlink\Checkout\Gateway\Http\Client\TransactionClient;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;

/**
 * Void Request Builder for Cardlink payment gateway.
 * Builds the request data for void transactions.
 *
 * @author Cardlink S.A.
 */
class VoidRequestBuilder implements BuilderInterface
{
    /**
     * Builds request data.
     *
     * @param array $buildSubject
     * @return array
     */
    public function build(array $buildSubject)
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        
        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        $orderAdapter = $paymentDO->getOrder();
        
        // Get the actual Magento order to avoid issues with third-party adapters
        $order = $payment->getOrder();
        
        // Get the Cardlink order ID stored during payment response
        // This is the order ID that Cardlink's system recognizes
        $orderId = $payment->getCardlinkOrderId();
        
        if (empty($orderId)) {
            // Fallback to Magento order increment ID if cardlink_order_id not set
            $orderId = $order->getIncrementId();
        }
        
        // Get amount from the actual order object to avoid adapter compatibility issues
        $amount = (float) $order->getGrandTotal();
        
        if (empty($orderId)) {
            throw new \InvalidArgumentException('No order ID found for void operation');
        }

        // Get the transaction ID for reference
        $transactionId = $payment->getCardlinkTxId() 
            ?: $payment->getParentTransactionId() 
            ?: $payment->getLastTransId();
        
        // Remove suffixes if present
        if ($transactionId) {
            $transactionId = str_replace(['-capture', '-refund', '-void'], '', $transactionId);
        }

        return [
            'action' => TransactionClient::ACTION_VOID,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $order->getOrderCurrencyCode(),
            'transaction_id' => $transactionId,
            'payment_method_code' => $payment->getMethodInstance()->getCode()
        ];
    }
}
