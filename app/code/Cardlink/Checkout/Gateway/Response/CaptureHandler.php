<?php

namespace Cardlink\Checkout\Gateway\Response;

use Cardlink\Checkout\Logger\Logger;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;

/**
 * Capture Response Handler for Cardlink payment gateway.
 * Handles the response from capture transactions.
 *
 * @author Cardlink S.A.
 */
class CaptureHandler implements HandlerInterface
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
        
        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();

        // Update payment with response data
        $transactionId = $response['txId'] ?? $response['transaction_id'] ?? $payment->getLastTransId();
        
        $payment->setTransactionId($transactionId . '-capture');
        $payment->setIsTransactionClosed(true);
        $payment->setTransactionAdditionalInfo(
            \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS,
            $response
        );

        $this->logger->info(sprintf(
            'Capture completed for order %s, transaction ID: %s',
            $paymentDO->getOrder()->getOrderIncrementId(),
            $transactionId
        ));
    }
}
