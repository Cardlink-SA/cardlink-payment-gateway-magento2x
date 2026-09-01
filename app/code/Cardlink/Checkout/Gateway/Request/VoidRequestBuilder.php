<?php

namespace Cardlink\Checkout\Gateway\Request;

use Cardlink\Checkout\Gateway\Http\Client\TransactionClient;
use Cardlink\Checkout\Service\GatewayOrderId;
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
        
        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        $orderAdapter = $paymentDO->getOrder();
        
        // Get the actual Magento order to avoid issues with third-party adapters
        $order = $payment->getOrder();
        
        // The gateway only recognises the orderid sent with the original payment request,
        // which is never the Magento increment ID.
        $orderId = $this->gatewayOrderId->requireForPayment($payment, 'void');

        // Get amount from the actual order object to avoid adapter compatibility issues
        $amount = (float) $order->getGrandTotal();

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
