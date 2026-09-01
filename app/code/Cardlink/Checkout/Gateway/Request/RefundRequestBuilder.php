<?php

namespace Cardlink\Checkout\Gateway\Request;

use Cardlink\Checkout\Gateway\Http\Client\TransactionClient;
use Cardlink\Checkout\Service\GatewayOrderId;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;

/**
 * Refund Request Builder for Cardlink payment gateway.
 * Builds the request data for refund transactions.
 *
 * @author Cardlink S.A.
 */
class RefundRequestBuilder implements BuilderInterface
{
    /**
     * @var GatewayOrderId
     */
    private $gatewayOrderId;

    /**
     * Constructor.
     *
     * @param GatewayOrderId $gatewayOrderId
     */
    public function __construct(GatewayOrderId $gatewayOrderId)
    {
        $this->gatewayOrderId = $gatewayOrderId;
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
        
        // The gateway only recognises the orderid sent with the original payment request,
        // which is never the Magento increment ID.
        $orderId = $this->gatewayOrderId->requireForPayment($payment, 'refund');

        // Get the transaction ID for reference
        $transactionId = $payment->getCardlinkTxId() 
            ?: $payment->getParentTransactionId() 
            ?: $payment->getLastTransId();
        
        // Remove suffixes if present
        if ($transactionId) {
            $transactionId = str_replace(['-capture', '-refund', '-void'], '', $transactionId);
        }

        // Get original order amount for comparison (to detect full vs partial refund)
        // Use the payment's order (Sales\Model\Order) which has getGrandTotal(),
        // not the OrderAdapter from Payment Gateway which doesn't have this method
        $originalAmount = (float)$payment->getOrder()->getGrandTotal();

        return [
            'action' => TransactionClient::ACTION_REFUND,
            'order_id' => $orderId,
            'amount' => $amount,
            'original_amount' => $originalAmount,
            'currency' => $order->getOrderCurrencyCode(),
            'transaction_id' => $transactionId,
            'payment_method_code' => $payment->getMethodInstance()->getCode()
        ];
    }
}
