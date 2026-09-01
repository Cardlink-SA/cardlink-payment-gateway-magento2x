<?php

namespace Cardlink\Checkout\Gateway\Request;

use Cardlink\Checkout\Gateway\Http\Client\TransactionClient;
use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Service\GatewayOrderId;
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
     * @var GatewayOrderId
     */
    private $gatewayOrderId;

    /**
     * Constructor.
     *
     * @param Logger $logger
     * @param GatewayOrderId $gatewayOrderId
     */
    public function __construct(Logger $logger, GatewayOrderId $gatewayOrderId)
    {
        $this->logger = $logger;
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
        
        $this->logger->debug('CaptureRequestBuilder: Building capture request');
        $this->logger->debug('CaptureRequestBuilder: Magento order increment ID = ' . $order->getIncrementId());

        // The gateway only recognises the orderid sent with the original payment request,
        // which is never the Magento increment ID.
        $orderId = $this->gatewayOrderId->requireForPayment($payment, 'capture');

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
