<?php

namespace Cardlink\Checkout\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Payment\Gateway\Command\CommandException;
use Cardlink\Checkout\Logger\Logger;

/**
 * General Response Validator for Cardlink payment gateway.
 * Validates API responses for capture, refund, and void transactions.
 *
 * @author Cardlink S.A.
 */
class ResponseValidator extends AbstractValidator
{
    /**
     * Success status codes from Cardlink API
     */
    const SUCCESS_STATUSES = ['0', '00', 'APPROVED', 'SUCCESS', 'CAPTURED', 'AUTHORIZED', 'SETTLED', 'REFUNDED', 'CANCELED', 'CANCELLED', 'VOIDED'];

    /**
     * @var Logger
     */
    private $logger;

    /**
     * Constructor.
     *
     * @param ResultInterfaceFactory $resultFactory
     * @param Logger $logger
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        Logger $logger
    ) {
        parent::__construct($resultFactory);
        $this->logger = $logger;
    }

    /**
     * Validates response.
     *
     * @param array $validationSubject
     * @return ResultInterface
     * @throws CommandException
     */
    public function validate(array $validationSubject)
    {
        $response = $validationSubject['response'] ?? [];
        
        $isValid = $this->isSuccessfulResponse($response);
        $errorCodes = [];
        $errorMessages = [];

        if (!$isValid) {
            $errorCode = $response['errorCode'] ?? $response['ErrorCode'] ?? $response['status'] ?? 'UNKNOWN';
            $errorMessage = $response['errorMessage'] ?? $response['error'] ?? $response['message'] ?? $response['Description'] ?? 'Transaction failed';
            
            // Use the error message directly if it's already descriptive (from our custom responses)
            // Otherwise, generate a descriptive message
            if ($this->isCustomErrorMessage($errorMessage)) {
                $userFriendlyMessage = $errorMessage;
            } else {
                $userFriendlyMessage = $this->getDescriptiveErrorMessage($errorCode, $errorMessage);
            }
            
            $errorCodes[] = $errorCode;
            $errorMessages[] = __($userFriendlyMessage);
            
            $this->logger->error(sprintf(
                'Cardlink transaction validation failed. Error: %s, Code: %s, Response: %s',
                $errorMessage,
                $errorCode,
                json_encode($response)
            ));
            
            // Throw exception directly to ensure the message is displayed to the user
            // This bypasses Magento's generic "Transaction has been declined" message
            throw new CommandException(__($userFriendlyMessage));
        }

        return $this->createResult($isValid, $errorMessages, $errorCodes);
    }

    /**
     * Check if the error message is already a custom descriptive message.
     *
     * @param string $message
     * @return bool
     */
    private function isCustomErrorMessage(string $message): bool
    {
        // Messages that start with known custom prefixes or contain specific keywords
        $customIndicators = [
            'Partial refund is not possible',
            'Transaction is in settlement transit',
            'Original transaction is not refundable and partial void',
            'Please wait until settlement',
            'Please wait for settlement',
            'settlstatus=',
            'Both refund and void operations failed',
            'create a credit memo for the full amount',
        ];
        
        foreach ($customIndicators as $indicator) {
            if (strpos($message, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if the response indicates a successful transaction.
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessfulResponse(array $response)
    {
        // Check HTTP status
        if (isset($response['http_code']) && ($response['http_code'] < 200 || $response['http_code'] >= 300)) {
            return false;
        }

        // Check for explicit success flag (most reliable indicator)
        if (isset($response['success'])) {
            return $response['success'] === true;
        }

        // Check for explicit error indicators
        if (isset($response['error']) && !empty($response['error'])) {
            return false;
        }

        // Check response status/code
        $status = $response['status'] ?? $response['Status'] ?? $response['responseCode'] ?? $response['result'] ?? null;
        
        if ($status !== null) {
            $status = strtoupper(trim((string)$status));
            return in_array($status, self::SUCCESS_STATUSES, true) 
                || strpos($status, 'APPROVED') !== false
                || strpos($status, 'SUCCESS') !== false
                || strpos($status, 'CAPTURED') !== false
                || strpos($status, 'AUTHORIZED') !== false;
        }

        // If we have an error message/code, it's not successful
        if (isset($response['errorCode']) || isset($response['errorMessage'])) {
            return false;
        }

        // If HTTP was successful and no error indicators, consider it valid
        return isset($response['http_code']) && $response['http_code'] >= 200 && $response['http_code'] < 300;
    }

    /**
     * Get a descriptive error message for known error codes.
     *
     * @param string $errorCode
     * @param string $originalMessage
     * @return string
     */
    private function getDescriptiveErrorMessage(string $errorCode, string $originalMessage): string
    {
        // Check for settlement status in the error message
        if (strpos($originalMessage, 'settlstatus=10') !== false) {
            return 'This transaction is currently being processed for settlement and cannot be modified. '
                . 'Please wait until settlement is complete (usually the next business day) and try again. '
                . 'Once settled, you can process a refund instead.';
        }

        if (strpos($originalMessage, 'settlstatus=0') !== false) {
            return 'This transaction has not been settled yet. '
                . 'Use void/cancel operation instead of refund for unsettled transactions.';
        }

        // Map known Cardlink error codes to descriptive messages
        $errorMessages = [
            'O1' => sprintf(
                'The original transaction cannot be processed: %s. '
                . 'This may be due to the transaction being in settlement transit or already processed.',
                $originalMessage
            ),
            'M2' => 'Merchant authentication failed. Please verify your API credentials in the Cardlink configuration.',
            'M1' => 'Invalid merchant configuration. Please contact Cardlink support.',
            'T1' => 'Transaction not found. The order may not exist in Cardlink\'s system.',
            'T2' => 'Transaction already processed. This operation has already been completed.',
            'A1' => 'Invalid amount. The refund/capture amount may exceed the original transaction amount.',
        ];

        if (isset($errorMessages[$errorCode])) {
            return $errorMessages[$errorCode];
        }

        // Default message with original error info
        return sprintf('Cardlink Gateway Error: %s (Code: %s)', $originalMessage, $errorCode);
    }
}
