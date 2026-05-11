<?php

namespace Cardlink\Checkout\Gateway\Http\Client;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Lib\CardlinkXmlApi;
use Cardlink\Checkout\Logger\Logger;
use Magento\Framework\Phrase;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;

/**
 * HTTP Client for Cardlink payment gateway API transactions.
 * Handles capture, refund, and void operations via the VPOS XML API.
 *
 * @author Cardlink S.A.
 */
class TransactionClient implements ClientInterface
{
    /**
     * API action for capture transactions
     */
    const ACTION_CAPTURE = 'capture';

    /**
     * API action for refund transactions
     */
    const ACTION_REFUND = 'refund';

    /**
     * API action for void transactions
     */
    const ACTION_VOID = 'void';

    /**
     * API action for status requests
     */
    const ACTION_STATUS = 'status';

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Data
     */
    private $dataHelper;

    /**
     * Constructor.
     *
     * @param Logger $logger
     * @param Data $dataHelper
     */
    public function __construct(
        Logger $logger,
        Data $dataHelper
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
    }

    /**
     * Translate a message.
     *
     * @param string $message
     * @param array $arguments
     * @return string
     */
    private function __($message, array $arguments = []): string
    {
        return (string) new Phrase($message, $arguments);
    }

    /**
     * Places request to gateway.
     *
     * @param TransferInterface $transferObject
     * @return array
     */
    public function placeRequest(TransferInterface $transferObject)
    {
        $request = $transferObject->getBody();
        $response = [];

        // Always log the request for debugging
        $this->logger->debug('=== CARDLINK GATEWAY REQUEST ===');
        $this->logger->debug('Request Data: ' . json_encode($request, JSON_PRETTY_PRINT));

        try {
            $paymentMethodCode = $request['payment_method_code'] ?? 'cardlink_checkout';
            $api = $this->createApiClient($paymentMethodCode);

            // Enable debug logging based on config setting
            if ($this->dataHelper->logDebugInfoEnabled()) {
                $api->setDebug(true, function($message) {
                    $this->logger->debug('[CardlinkXmlApi] ' . $message);
                });
            }

            $action = $request['action'] ?? '';
            $orderId = $request['order_id'] ?? '';
            $amount = (float)($request['amount'] ?? 0);
            $currency = $request['currency'] ?? 'EUR';

            switch ($action) {
                case self::ACTION_CAPTURE:
                    $apiResponse = $api->capture($orderId, $amount, $currency);
                    break;

                case self::ACTION_REFUND:
                    $apiResponse = $this->processRefundWithStatusCheck(
                        $api,
                        $orderId,
                        $amount,
                        $currency,
                        (float)($request['original_amount'] ?? $amount)
                    );
                    break;

                case self::ACTION_VOID:
                    $apiResponse = $api->cancel($orderId, $amount, $currency);
                    break;

                case self::ACTION_STATUS:
                    $apiResponse = $api->status($orderId);
                    break;

                default:
                    throw new \InvalidArgumentException('Unknown action: ' . $action);
            }

            $response = $apiResponse->getData();
            $response['success'] = $apiResponse->isSuccess();
            $response['status'] = $apiResponse->getStatus();
            $response['txId'] = $apiResponse->getTransactionId();
            $response['paymentRef'] = $apiResponse->getPaymentRef();
            $response['description'] = $apiResponse->getDescription();

            if (!$apiResponse->isSuccess()) {
                $response['error'] = $apiResponse->getError();
            }

            // Always log the response for debugging
            $this->logger->debug('=== CARDLINK GATEWAY RESPONSE ===');
            $this->logger->debug('Response Data: ' . json_encode($response, JSON_PRETTY_PRINT));
            $this->logger->debug('=== END GATEWAY RESPONSE ===');

        } catch (\Exception $e) {
            $this->logger->error('Cardlink API Error: ' . $e->getMessage());
            $response = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }

        return $response;
    }

    /**
     * Process refund with automatic status check and error handling.
     * - If settlstatus=0 and amount equals original, use void instead of refund.
     * - If refund fails with error code O1 (not refundable) and full amount, retry with void.
     *
     * @param CardlinkXmlApi $api
     * @param string $orderId
     * @param float $amount
     * @param string $currency
     * @param float $originalAmount
     * @return \Cardlink\Checkout\Lib\CardlinkXmlResponse
     */
    private function processRefundWithStatusCheck(
        CardlinkXmlApi $api,
        string $orderId,
        float $amount,
        string $currency,
        float $originalAmount
    ): \Cardlink\Checkout\Lib\CardlinkXmlResponse {
        // Compare amounts with small tolerance for floating point
        $isFullAmount = abs($amount - $originalAmount) < 0.01;
        
        // First, check the transaction status
        $this->logger->debug('Checking transaction status before refund for order: ' . $orderId);
        $statusResponse = $api->status($orderId);

        if (!$statusResponse->isSuccess()) {
            $this->logger->warning('Could not retrieve transaction status, proceeding with refund: ' . $statusResponse->getError());
            return $this->attemptRefundWithVoidFallback($api, $orderId, $amount, $currency, $isFullAmount);
        }

        $settlStatus = $statusResponse->getSettlementStatus();
        $this->logger->debug('Transaction settlement status: ' . ($settlStatus ?? 'unknown'));

        // If transaction is in settlement transit (settlstatus=10)
        if ($settlStatus === 10) {
            // If full amount, try void as the transaction might still be voidable
            if ($isFullAmount) {
                $this->logger->info('Transaction is in settlement transit (settlstatus=10), attempting void for full amount');
                return $api->cancel($orderId, $amount, $currency);
            } else {
                $this->logger->info('Transaction is in settlement transit (settlstatus=10), cannot process partial refund yet');
                $errorMsg = $this->__('Transaction is in settlement transit. Please wait until settlement is complete (usually the next business day) and try again.');
                return new \Cardlink\Checkout\Lib\CardlinkXmlResponse(false, [
                    'error' => $errorMsg,
                    'errorMessage' => $errorMsg,
                    'Status' => 'ERROR',
                    'errorCode' => 'SETTLEMENT_TRANSIT',
                    'settlstatus' => 10
                ]);
            }
        }

        // If not settled (settlstatus=0) and full refund amount, use void/cancel instead
        if ($settlStatus === 0) {
            if ($isFullAmount) {
                $this->logger->info('Transaction not settled (settlstatus=0) and full amount requested - using void/cancel instead of refund');
                return $api->cancel($orderId, $amount, $currency);
            } else {
                // Partial refund on unsettled transaction - not possible
                $this->logger->info('Transaction not settled (settlstatus=0) but partial refund requested - this is not supported');
                $errorMsg = $this->__('Partial refund is not possible on unsettled transactions. You can only void the full amount. Please wait for settlement to complete for partial refunds.');
                return new \Cardlink\Checkout\Lib\CardlinkXmlResponse(false, [
                    'error' => $errorMsg,
                    'errorMessage' => $errorMsg,
                    'Status' => 'ERROR',
                    'errorCode' => 'UNSETTLED_PARTIAL',
                    'settlstatus' => 0
                ]);
            }
        }

        // Transaction is settled (settlstatus >= 20), proceed with refund
        $this->logger->debug('Transaction is settled, proceeding with refund');
        return $this->attemptRefundWithVoidFallback($api, $orderId, $amount, $currency, $isFullAmount);
    }

    /**
     * Attempt refund and fallback to void if it fails with error O1 (not refundable).
     * This handles cases where the transaction status check returned unexpected results
     * or the transaction is in a state that allows void but not refund.
     *
     * @param CardlinkXmlApi $api
     * @param string $orderId
     * @param float $amount
     * @param string $currency
     * @param bool $isFullAmount
     * @return \Cardlink\Checkout\Lib\CardlinkXmlResponse
     */
    private function attemptRefundWithVoidFallback(
        CardlinkXmlApi $api,
        string $orderId,
        float $amount,
        string $currency,
        bool $isFullAmount
    ): \Cardlink\Checkout\Lib\CardlinkXmlResponse {
        // Attempt the refund first
        $refundResponse = $api->refund($orderId, $amount, $currency);
        
        // If refund succeeded, return the response
        if ($refundResponse->isSuccess()) {
            return $refundResponse;
        }
        
        // Check if the error is O1 (Original transaction is not refundable)
        $responseData = $refundResponse->getData();
        $errorCode = $responseData['ErrorCode'] ?? '';
        
        if ($errorCode === 'O1' && $isFullAmount) {
            $this->logger->info(
                'Refund failed with error O1 (not refundable) for full amount. ' .
                'Retrying with void/cancel request. Order: ' . $orderId
            );
            
            // Attempt void instead
            $voidResponse = $api->cancel($orderId, $amount, $currency);
            
            if ($voidResponse->isSuccess()) {
                $this->logger->info('Void succeeded after refund failed with O1. Order: ' . $orderId);
                // Add a note indicating this was converted from refund to void
                $data = $voidResponse->getData();
                $data['_converted_from_refund'] = true;
                $data['_original_error'] = $responseData['Description'] ?? 'Original transaction is not refundable';
                return new \Cardlink\Checkout\Lib\CardlinkXmlResponse(true, $data);
            }
            
            $this->logger->error('Both refund and void failed for order: ' . $orderId);
            
            // Return a descriptive error message when both operations fail
            $voidData = $voidResponse->getData();
            $voidError = $voidData['Description'] ?? $voidData['error'] ?? $voidResponse->getError() ?? 'Unknown error';
            $originalError = $responseData['Description'] ?? 'Original transaction is not refundable';
            
            $errorMsg = $this->__(
                'Both refund and void operations failed. Refund error: %1. Void error: %2. Please try again later or contact support.',
                [$originalError, $voidError]
            );
            
            return new \Cardlink\Checkout\Lib\CardlinkXmlResponse(false, [
                'error' => $errorMsg,
                'errorMessage' => $errorMsg,
                'Status' => 'ERROR',
                'ErrorCode' => 'BOTH_FAILED',
                'errorCode' => 'BOTH_FAILED',
                'refund_error' => $originalError,
                'void_error' => $voidError
            ]);
        }
        
        // For partial refunds or other error codes, return the original refund error
        if ($errorCode === 'O1' && !$isFullAmount) {
            $this->logger->warning(
                'Refund failed with error O1 for partial amount. Void is not possible for partial amounts. Order: ' . $orderId
            );
            $errorMsg = $this->__('Original transaction is not refundable and partial void is not supported. Please wait for settlement or create a credit memo for the full amount.');
            return new \Cardlink\Checkout\Lib\CardlinkXmlResponse(false, [
                'error' => $errorMsg,
                'errorMessage' => $errorMsg,
                'Status' => 'ERROR',
                'ErrorCode' => 'O1',
                'errorCode' => 'O1',
                'original_description' => $responseData['Description'] ?? ''
            ]);
        }
        
        // Return the original refund error for other cases
        return $refundResponse;
    }

    /**
     * Create the API client with proper configuration.
     *
     * @param string $paymentMethodCode
     * @return CardlinkXmlApi
     */
    private function createApiClient(string $paymentMethodCode): CardlinkXmlApi
    {
        if ($paymentMethodCode === \Cardlink\Checkout\Model\Config\SettingsIris::CODE) {
            $merchantId = $this->dataHelper->getIrisMerchantId();
            $sharedSecret = $this->dataHelper->getIrisSharedSecret();
            $businessPartner = $this->dataHelper->getIrisBusinessPartner();
            $environment = $this->dataHelper->getIrisTransactionEnvironment();
        } else {
            $merchantId = $this->dataHelper->getMerchantId();
            $sharedSecret = $this->dataHelper->getSharedSecret();
            $businessPartner = $this->dataHelper->getBusinessPartner();
            $environment = $this->dataHelper->getTransactionEnvironment();
        }

        // Map environment values
        $apiEnvironment = ($environment === \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::PRODUCTION_ENVIRONMENT)
            ? CardlinkXmlApi::ENV_PRODUCTION
            : CardlinkXmlApi::ENV_SANDBOX;

        return new CardlinkXmlApi(
            $merchantId,
            $sharedSecret,
            $businessPartner,
            $apiEnvironment
        );
    }
}
