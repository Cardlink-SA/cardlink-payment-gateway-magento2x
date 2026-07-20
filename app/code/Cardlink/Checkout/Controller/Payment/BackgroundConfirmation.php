<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\ApiFields;
use Cardlink\Checkout\Model\PaymentStatus;
use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Exception;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Background confirmation controller to handle payment gateway confirmation messages.
 * Returns JSON responses with order status/state. No redirections or user session required.
 *
 * This endpoint is designed for server-to-server (S2S) background notifications from the payment gateway.
 * It does not require user authentication or session cookies.
 *
 * IMPORTANT: This controller requires Magento 2.3.0 or higher due to its use of
 * CsrfAwareActionInterface and HttpPostActionInterface.
 *
 * Endpoint URL: https://<your-magento-store>/cardlink_checkout/payment/backgroundconfirmation
 * Relative Path: /cardlink_checkout/payment/backgroundconfirmation
 * HTTP Method: POST
 *
 * JSON Response Format:
 * {
 *     "success": true|false,
 *     "status": "ok"|"processed"|"already_processed"|"error",
 *     "message": "...",
 *     "order_id": 123,
 *     "order_increment_id": "000000001",
 *     "order_state": "processing"|"canceled"|...,
 *     "order_status": "processing"|"canceled"|...
 * }
 *
 * @author Cardlink S.A.
 */
class BackgroundConfirmation extends Action implements CsrfAwareActionInterface, HttpPostActionInterface
{
    /** @var Logger */
    protected $logger;

    /** @var Data */
    private $dataHelper;

    /** @var Payment */
    private $paymentHelper;

    /** @var OrderFactory */
    protected $orderFactory;

    /** @var QuoteFactory */
    protected $quoteFactory;

    /** @var QuoteManagement */
    protected $quoteManagement;

    /** @var OrderRepositoryInterface */
    protected $orderRepository;

    /**
     * Webhook constructor.
     *
     * @param Context $context
     * @param Logger $logger
     * @param Data $dataHelper
     * @param Payment $paymentHelper
     * @param OrderFactory $orderFactory
     * @param QuoteFactory $quoteFactory
     * @param QuoteManagement $quoteManagement
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        Context $context,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper,
        OrderFactory $orderFactory,
        QuoteFactory $quoteFactory,
        QuoteManagement $quoteManagement,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;
        $this->orderFactory = $orderFactory;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->orderRepository = $orderRepository;

        parent::__construct($context);
    }

    /**
     * Expected user agent from the Cardlink payment gateway
     */
    const EXPECTED_USER_AGENT = 'Modirum HTTPClient';

    /**
     * Main execution method for processing payment gateway webhook notifications.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $clientIp = $this->getClientIp();
        $userAgent = $this->getRequest()->getHeader('User-Agent') ?: '';

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->info(sprintf(
                'BackgroundConfirmation: Action executed - IP: %s, User-Agent: %s, Method: %s',
                $clientIp,
                $userAgent,
                $this->getRequest()->getMethod()
            ));
        }

        // Security: Only accept requests from the Cardlink payment gateway
        if (strpos($userAgent, self::EXPECTED_USER_AGENT) === false) {
            $this->logger->warning(sprintf(
                'Background confirmation rejected - invalid user agent: "%s" from IP: %s',
                $userAgent,
                $clientIp
            ));
            return $result->setData([
                'success' => false,
                'status' => 'error',
                'message' => 'Access denied',
                'order_id' => null,
                'order_state' => null,
                'order_status' => null
            ])->setHttpResponseCode(403);
        }

        $responseData = $this->getRequest()->getParams();
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $clientIp = $this->getClientIp();

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->debug('Received background confirmation from IP: ' . $clientIp);
            $this->logger->debug(json_encode($responseData, JSON_PRETTY_PRINT));
        }

        // Security: Enforce HTTPS in production
        if (!$this->isSecureRequest() && !$this->isTestMode()) {
            $this->logger->warning('Background confirmation received over non-HTTPS connection from IP: ' . $clientIp);
            return $result->setData([
                'success' => false,
                'status' => 'error',
                'message' => 'Secure connection required',
                'order_id' => null,
                'order_state' => null,
                'order_status' => null
            ])->setHttpResponseCode(403);
        }

        // Security: Validate required fields are present
        $requiredFields = [ApiFields::Status, ApiFields::OrderId, ApiFields::Digest, ApiFields::MerchantId];
        $missingFields = $this->validateRequiredFields($responseData, $requiredFields);
        if (!empty($missingFields)) {
            $this->logger->warning('Background confirmation missing required fields: ' . implode(', ', $missingFields) . ' from IP: ' . $clientIp);
            return $result->setData([
                'success' => false,
                'status' => 'error',
                'message' => 'Missing required fields',
                'order_id' => null,
                'order_state' => null,
                'order_status' => null
            ])->setHttpResponseCode(400);
        }

        // Security: Validate signature for ALL requests (not just successful ones)
        $isValidSignature = $this->validatePaymentGatewayResponse($responseData);
        if (!$isValidSignature) {
            $this->logger->warning('Background confirmation received invalid signature from IP: ' . $clientIp . ' for order: ' . ($responseData[ApiFields::OrderId] ?? 'unknown'));
            return $result->setData([
                'success' => false,
                'status' => 'error',
                'message' => 'Invalid response signature',
                'order_id' => null,
                'order_state' => null,
                'order_status' => null
            ])->setHttpResponseCode(403);
        }

        // Security: Validate merchant ID matches configuration
        if (!$this->validateMerchantId($responseData)) {
            $this->logger->warning('Background confirmation received mismatched merchant ID from IP: ' . $clientIp);
            return $result->setData([
                'success' => false,
                'status' => 'error',
                'message' => 'Invalid merchant ID',
                'order_id' => null,
                'order_state' => null,
                'order_status' => null
            ])->setHttpResponseCode(403);
        }

        $status = $responseData[ApiFields::Status] ?? null;

        try {
            // Check if order has already been processed (by Response or Webhook controller)
            $alreadyProcessed = $this->isOrderAlreadyProcessed($responseData);
            if ($alreadyProcessed !== null) {
                $order = $alreadyProcessed['order'];
                if ($this->dataHelper->logDebugInfoEnabled()) {
                    $this->logger->debug("Webhook: Order {$order->getIncrementId()} already processed, skipping. State: {$order->getState()}");
                }
                return $result->setData([
                    'success' => true,
                    'status' => 'already_processed',
                    'message' => 'Order has already been processed',
                    'order_id' => $order->getId(),
                    'order_increment_id' => $order->getIncrementId(),
                    'order_state' => $order->getState(),
                    'order_status' => $order->getStatus()
                ])->setHttpResponseCode(200);
            }

            switch ($status) {
                case PaymentStatus::AUTHORIZED:
                case PaymentStatus::CAPTURED:
                    $orderData = $this->processSuccessfulPayment($responseData);
                    $this->logger->info('Background confirmation: Successfully processed payment for order ' . ($orderData['order_increment_id'] ?? 'unknown'));
                    return $result->setData([
                        'success' => true,
                        'status' => 'ok',
                        'message' => $orderData['message'],
                        'order_id' => $orderData['order_id'],
                        'order_increment_id' => $orderData['order_increment_id'],
                        'order_state' => $orderData['order_state'],
                        'order_status' => $orderData['order_status']
                    ])->setHttpResponseCode(200);

                case PaymentStatus::CANCELED:
                case PaymentStatus::REFUSED:
                case PaymentStatus::ERROR:
                    $orderData = $this->processFailedPayment($responseData);
                    $this->logger->info('Background confirmation: Processed failed/canceled payment for order ' . ($orderData['order_increment_id'] ?? 'unknown') . ' with status: ' . $status);
                    return $result->setData([
                        'success' => true,
                        'status' => 'processed',
                        'message' => $orderData['message'],
                        'order_id' => $orderData['order_id'],
                        'order_increment_id' => $orderData['order_increment_id'],
                        'order_state' => $orderData['order_state'],
                        'order_status' => $orderData['order_status']
                    ])->setHttpResponseCode(200);

                default:
                    $this->logger->warning('Background confirmation received unknown payment status: ' . $status . ' from IP: ' . $clientIp);
                    return $result->setData([
                        'success' => false,
                        'status' => 'error',
                        'message' => 'Unknown payment status received',
                        'order_id' => null,
                        'order_state' => null,
                        'order_status' => null
                    ])->setHttpResponseCode(400);
            }
        } catch (Exception $e) {
            $this->logger->error('Background confirmation processing error from IP ' . $clientIp . ': ' . $e->getMessage());
            return $result->setData([
                'success' => false,
                'status' => 'error',
                'message' => 'Internal processing error',
                'order_id' => null,
                'order_state' => null,
                'order_status' => null
            ])->setHttpResponseCode(500);
        }
    }

    /**
     * Validates the incoming response from the payment gateway.
     *
     * @param array $responseData
     * @return bool
     */
    private function validatePaymentGatewayResponse(array $responseData): bool
    {
        $payMethod = $responseData['payMethod'] ?? $responseData[ApiFields::PaymentMethod] ?? '';

        if ($payMethod === 'IRIS') {
            $secret = $this->dataHelper->getIrisSharedSecret();
        } elseif ($this->isGooglePayMerchant($responseData)) {
            $secret = $this->dataHelper->getGooglePaySharedSecret();
        } else {
            $secret = $this->dataHelper->getSharedSecret();
        }

        $isValid = $this->paymentHelper->validateResponseData(
            $responseData,
            $secret
        );

        if (array_key_exists(ApiFields::XlsBonusDigest, $responseData)) {
            $isValid = $isValid && $this->paymentHelper->validateXlsBonusResponseData(
                $responseData,
                $secret
            );
        }
        return $isValid;
    }

    /**
     * Validate that required fields are present in the response data.
     *
     * @param array $responseData
     * @param array $requiredFields
     * @return array List of missing fields (empty if all present)
     */
    private function validateRequiredFields(array $responseData, array $requiredFields): array
    {
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (!isset($responseData[$field]) || $responseData[$field] === '') {
                $missingFields[] = $field;
            }
        }
        return $missingFields;
    }

    /**
     * Validate that the merchant ID in the response matches our configuration.
     *
     * @param array $responseData
     * @return bool
     */
    private function validateMerchantId(array $responseData): bool
    {
        $responseMerchantId = $responseData[ApiFields::MerchantId] ?? '';
        $payMethod = $responseData[ApiFields::PaymentMethod] ?? '';

        if ($payMethod == 'IRIS') {
            $configuredMerchantId = $this->dataHelper->getIrisMerchantId();
        } elseif ($this->isGooglePayMerchant($responseData)) {
            $configuredMerchantId = $this->dataHelper->getGooglePayMerchantId();
        } else {
            $configuredMerchantId = $this->dataHelper->getMerchantId();
        }

        return $responseMerchantId === $configuredMerchantId;
    }

    /**
     * Get the client's IP address, handling proxy scenarios.
     *
     * @return string
     */
    private function getClientIp(): string
    {
        $request = $this->getRequest();

        // Check for proxy headers (in order of preference)
        $proxyHeaders = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP'
        ];

        foreach ($proxyHeaders as $header) {
            $ip = $request->getServer($header);
            if ($ip) {
                // X-Forwarded-For can contain multiple IPs, take the first one
                $ips = explode(',', $ip);
                return trim($ips[0]);
            }
        }

        return $request->getServer('REMOTE_ADDR') ?? 'unknown';
    }

    /**
     * Check if the current request is over HTTPS.
     *
     * @return bool
     */
    private function isSecureRequest(): bool
    {
        $request = $this->getRequest();

        // Check standard HTTPS indicator
        if ($request->getServer('HTTPS') === 'on' || $request->getServer('HTTPS') === '1') {
            return true;
        }

        // Check for proxy/load balancer headers
        if ($request->getServer('HTTP_X_FORWARDED_PROTO') === 'https') {
            return true;
        }

        // Check server port
        if ($request->getServer('SERVER_PORT') == 443) {
            return true;
        }

        return false;
    }

    /**
     * Process a successful payment response.
     *
     * @param array $responseData
     * @return array Order data with status information
     * @throws LocalizedException
     * @throws Exception
     */
    private function processSuccessfulPayment(array $responseData): array
    {
        $orderIdStr = $responseData[ApiFields::OrderId];
        $message = $responseData[ApiFields::Message] ?? '';
        $orderId = substr($orderIdStr, 0, strlen($orderIdStr) - ApiFields::OrderId_SuffixLength);

        // Attempt to load the order by its increment ID
        $order = $this->paymentHelper->getOrderByIncrementId($orderId);

        if ($order && $order->getId()) {
            $quoteId = $order->getQuoteId();
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quoteFactory = $objectManager->get(\Magento\Quote\Model\QuoteFactory::class);
            $quote = $quoteFactory->create()->load($quoteId);

            $this->paymentHelper->markSuccessfulPayment($order, $responseData);
        } else {
            // If no order is found, treat it as a quote
            $quote = $this->paymentHelper->getQuoteById($orderId);
            if ($quote && $quote->getIsActive() && $quote->getId()) {
                $order = $this->createOrderFromQuote($quote->getId());
                $this->paymentHelper->markSuccessfulPayment($order, $responseData);
            }
        }

        if (isset($order) && isset($quote)) {
            $quote->setIsActive(false);
            $quote->save();
        }

        return [
            'order_id' => $order ? $order->getId() : null,
            'order_increment_id' => $order ? $order->getIncrementId() : $orderId,
            'order_state' => $order ? $order->getState() : null,
            'order_status' => $order ? $order->getStatus() : null,
            'message' => $message
        ];
    }

    /**
     * Processes a failed payment response.
     *
     * @param array $responseData
     * @return array Order data with status information
     */
    private function processFailedPayment(array $responseData): array
    {
        $message = $responseData[ApiFields::Message] ?? 'The payment was canceled or declined.';

        $this->markOrderCanceled($responseData);

        $orderIdStr = $responseData[ApiFields::OrderId] ?? '';
        $orderId = substr($orderIdStr, 0, strlen($orderIdStr) - ApiFields::OrderId_SuffixLength);
        $order = $this->paymentHelper->getOrderByIncrementId($orderId);

        return [
            'order_id' => $order ? $order->getId() : null,
            'order_increment_id' => $order ? $order->getIncrementId() : $orderId,
            'order_state' => $order ? $order->getState() : null,
            'order_status' => $order ? $order->getStatus() : null,
            'message' => $message
        ];
    }

    /**
     * Create an order from a quote.
     *
     * @param string $quoteId
     * @return Order
     * @throws Exception
     */
    private function createOrderFromQuote(string $quoteId): Order
    {
        $quote = $this->quoteFactory->create()->load($quoteId);
        if (!$quote->getId()) {
            $this->logger->error("Webhook: Quote not found for quoteId={$quoteId}");
            throw new \Exception('Quote not found');
        }

        $this->logger->info("Webhook: Loaded quote {$quote->getId()} (reserved order ID: {$quote->getReservedOrderId()})");

        // Ensure guest settings are properly configured before order creation
        $this->ensureQuoteIsReady($quote);

        // Ensure we don't reuse a previously reserved order ID
        if ($quote->getReservedOrderId()) {
            $this->logger->warning("Webhook: Quote {$quote->getId()} already had reserved order ID {$quote->getReservedOrderId()}, resetting.");
        }

        $quote->setReservedOrderId(null);
        $quote->reserveOrderId();

        $this->logger->info("Webhook: Reserved new order ID {$quote->getReservedOrderId()} for quote {$quote->getId()}");

        // Convert quote to order
        if ($this->isMagentoVersionBelow('2.3.0')) {
            $orderId = $this->quoteManagement->placeOrder($quote->getId());
            $order = $this->orderRepository->get($orderId);
        } else {
            $order = $this->quoteManagement->submit($quote);
        }

        // Deactivate the quote so it can't be reused
        $quote->setIsActive(false)->save();

        $this->logger->info("Webhook: Created order {$order->getIncrementId()} from quote {$quote->getId()}");

        return $order;
    }

    /**
     * CSRF validation override to always allow execution.
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }

    /**
     * CSRF exception creation override.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Check the option of Create order and hold stock for IRIS is enabled
     *
     * @return bool
     */
    private function isIrisCreateOrderEnabled(): bool
    {
        $enableIrisPayments = $this->dataHelper->isIrisEnabled();
        $IrisCreateOrderEnabled = $this->dataHelper->isIrisCreateOrderEnabled();
        return ($enableIrisPayments && $IrisCreateOrderEnabled);
    }

    /**
     * Check the option of Create order and hold stock is enabled
     *
     * @return bool
     */
    private function isCreateOrderEnabled(): bool
    {
        $cardlinkEnabled = $this->dataHelper->isEnabled();
        $CreateOrderEnabled = $this->dataHelper->isCreateOrderEnabled();
        return ($cardlinkEnabled && $CreateOrderEnabled);
    }

    /**
     * Mark an order as cancelled.
     *
     * @param array $responseData
     * @return void
     */
    private function markOrderCanceled(array $responseData): void
    {
        $isCreateOrderEnabled = $this->isCreateOrderEnabled() || $this->isIrisCreateOrderEnabled();
        $orderIdStr = $responseData[ApiFields::OrderId] ?? '';
        $orderId = substr($orderIdStr, 0, strlen($orderIdStr) - ApiFields::OrderId_SuffixLength);
        $order = $this->paymentHelper->getOrderByIncrementId($orderId);
        if ($isCreateOrderEnabled && $order && $order->getId()) {
            $this->paymentHelper->markCanceledPayment($order, $responseData);
        }
    }

    /**
     * Check if current Magento version is below specified version.
     *
     * @param string $ver
     * @return bool
     */
    private function isMagentoVersionBelow($ver): bool
    {
        $objectManager = ObjectManager::getInstance();
        $productMetadata = $objectManager->get(\Magento\Framework\App\ProductMetadataInterface::class);
        $magentoVersion = $productMetadata->getVersion();
        return version_compare($magentoVersion, $ver, '<');
    }

    /**
     * Check if the order has already been processed (payment marked or order canceled).
     *
     * @param array $responseData
     * @return array|null Returns array with 'order' and 'success' keys if already processed, null otherwise
     */
    private function isOrderAlreadyProcessed(array $responseData): ?array
    {
        $orderIdStr = $responseData[ApiFields::OrderId] ?? '';
        if (empty($orderIdStr)) {
            return null;
        }

        $orderId = substr($orderIdStr, 0, strlen($orderIdStr) - ApiFields::OrderId_SuffixLength);
        $order = $this->paymentHelper->getOrderByIncrementId($orderId);

        if (!$order || !$order->getId()) {
            return null;
        }

        $state = $order->getState();

        // Order is in a final successful state (processing or complete)
        if (in_array($state, [Order::STATE_PROCESSING, Order::STATE_COMPLETE])) {
            return ['order' => $order, 'success' => true];
        }

        // Order is in a final failed state (canceled or closed)
        if (in_array($state, [Order::STATE_CANCELED, Order::STATE_CLOSED])) {
            return ['order' => $order, 'success' => false];
        }

        return null;
    }

    /**
     * Check if the module is running in test/sandbox mode.
     *
     * @return bool
     */
    private function isTestMode(): bool
    {
        $environment = $this->dataHelper->getTransactionEnvironment();
        $irisEnvironment = $this->dataHelper->getIrisTransactionEnvironment();

        // Consider test mode if any payment method is in sandbox
        return $environment === \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::SANDBOX_ENVIRONMENT
            || $irisEnvironment === \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::SANDBOX_ENVIRONMENT
            || $this->dataHelper->getGooglePayTransactionEnvironment() === \Cardlink\Checkout\Model\Config\Source\TransactionEnvironments::SANDBOX_ENVIRONMENT;
    }

    /**
     * Check if the response is from a Google Pay transaction by matching merchant ID.
     *
     * @param array $responseData
     * @return bool
     */
    private function isGooglePayMerchant(array $responseData): bool
    {
        $responseMerchantId = $responseData[ApiFields::MerchantId] ?? '';
        $googlePayMerchantId = $this->dataHelper->getGooglePayMerchantId();
        return $responseMerchantId && $googlePayMerchantId && $responseMerchantId === $googlePayMerchantId;
    }

    /**
     * Ensure quote is ready for order submission by setting required guest fields.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return void
     */
    private function ensureQuoteIsReady(\Magento\Quote\Model\Quote $quote): void
    {
        // Resolve customer email (existing quote value, then billing, then shipping).
        $email = $quote->getCustomerEmail();
        if (!$email) {
            $billingAddress = $quote->getBillingAddress();
            if ($billingAddress && $billingAddress->getEmail()) {
                $email = $billingAddress->getEmail();
            } else {
                $shippingAddress = $quote->getShippingAddress();
                if ($shippingAddress && $shippingAddress->getEmail()) {
                    $email = $shippingAddress->getEmail();
                }
            }
        }

        // For a quote without a logged-in customer, force a clean guest checkout.
        // Without the is-guest flag and NOT_LOGGED_IN group, QuoteManagement::submit()
        // runs CustomerManagement::populateCustomerInfo() and tries to create a Customer
        // account from the quote's empty customer data object, failing with
        // "The customer email is missing."
        if (!$quote->getCustomerId()) {
            $quote->setCheckoutMethod('guest');
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);

            if ($email) {
                $quote->setCustomerEmail($email);
                $customer = $quote->getCustomer();
                if ($customer) {
                    $customer->setEmail($email);
                    $customer->setGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
                    $quote->setCustomer($customer);
                }
                if ($this->dataHelper->logDebugInfoEnabled()) {
                    $this->logger->debug("Webhook: Set guest customer email to {$email} for quote {$quote->getId()}");
                }
            } else {
                $this->logger->warning("Webhook: Unable to determine customer email for quote {$quote->getId()}");
            }
        }
        $quote->save();
    }
}
