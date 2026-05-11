<?php

namespace Cardlink\Checkout\Gateway\Response;

use Cardlink\Checkout\Logger\Logger;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;

/**
 * Refund Response Handler for Cardlink payment gateway.
 * Handles the response from refund transactions.
 *
 * @author Cardlink S.A.
 */
class RefundHandler implements HandlerInterface
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
     * Handles response.
     *
     * @param array $handlingSubject
     * @param array $response
     * @return void
     */
    public function handle(array $handlingSubject, array $response)
    {
        $paymentDO = SubjectReader::readPayment($handlingSubject);
        $amount = SubjectReader::readAmount($handlingSubject);
        
        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();

        // Update payment with response data
        $transactionId = $response['txId'] ?? $response['transaction_id'] ?? $payment->getLastTransId();
        
        $payment->setTransactionId($transactionId . '-refund');
        $payment->setIsTransactionClosed(true); // Refund transactions are always closed
        
        // Only close parent capture transaction if this is a full refund
        // This allows partial refunds to be processed multiple times
        $totalPaid = (float)$order->getTotalPaid();
        $totalRefunded = (float)$order->getTotalRefunded() + (float)$amount;
        $shouldCloseParent = ($totalRefunded >= $totalPaid);
        $payment->setShouldCloseParentTransaction($shouldCloseParent);
        
        $payment->setTransactionAdditionalInfo(
            \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS,
            $response
        );

        $this->logger->info(sprintf(
            'Refund completed for order %s, transaction ID: %s',
            $paymentDO->getOrder()->getOrderIncrementId(),
            $transactionId
        ));
    }
}
