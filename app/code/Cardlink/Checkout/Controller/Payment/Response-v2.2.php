<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\ApiFields;
use Cardlink\Checkout\Model\PaymentStatus;
use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Controller action to handle payment gateway responses.
 * Validates, processes, and redirects based on the payment response.
 *
 * IMPORTANT: This is the Magento 2.2.x-compatible variant. It is functionally
 * identical to Response.php but does not implement CsrfAwareActionInterface or
 * HttpPostActionInterface, since those interfaces were introduced in Magento 2.3.0.
 * For Magento 2.3.0+ installations, use Response.php instead.
 *
 * @author Cardlink S.A.
 */
class Response extends Action implements \Magento\Framework\App\PageCache\NotCacheableInterface
{
    /** @var FormKey */
    protected $formKey;

    /** @var Logger */
    protected $logger;

    /** @var UrlInterface */
    private $urlBuilder;

    /** @var Session */
    private $checkoutSession;

    /** @var ManagerInterface */
    protected $messageManager;

    /** @var Data */
    private $dataHelper;

    /** @var Payment */
    private $paymentHelper;

    /** @var RedirectFactory */
    protected $resultRedirectFactory;

    /** @var OrderFactory */
    protected $orderFactory;

    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;

    /**
     * @var QuoteManagement
     */
    protected $quoteManagement;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * Response constructor.
     *
     * @param Context $context
     * @param Session $checkoutSession
     * @param ManagerInterface $messageManager
     * @param UrlInterface $urlBuilder
     * @param RedirectFactory $resultRedirectFactory
     * @param Logger $logger
     * @param Data $dataHelper
     * @param Payment $paymentHelper
     * @param OrderFactory $orderFactory
     * @param FormKey $formKey
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        ManagerInterface $messageManager,
        UrlInterface $urlBuilder,
        RedirectFactory $resultRedirectFactory,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper,
        OrderFactory $orderFactory,
        QuoteFactory $quoteFactory,
        QuoteManagement $quoteManagement,
        OrderRepositoryInterface $orderRepository,
        FormKey $formKey
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->messageManager = $messageManager;
        $this->urlBuilder = $urlBuilder;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;
        $this->orderFactory = $orderFactory;
        $this->quoteFactory = $quoteFactory;
        $this->quoteManagement = $quoteManagement;
        $this->orderRepository = $orderRepository;
        $this->formKey = $formKey;

        parent::__construct($context);
    }

    /**
     * Main execution method for processing payment gateway responses.
     *
     * @return ResultInterface
     * @throws Exception
     */
    public function execute(): ResultInterface
    {
        $responseData = $this->getRequest()->getParams();
        $orderId = 0;
        $message = null;
        $clientIp = $this->getClientIp();

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->debug('Received payment gateway response from IP: ' . $clientIp);
            $this->logger->debug(json_encode($responseData, JSON_PRETTY_PRINT));
        }

        // Security: Enforce HTTPS in production
        if (!$this->isSecureRequest() && !$this->isTestMode()) {
            $this->logger->warning('Payment response received over non-HTTPS connection from IP: ' . $clientIp);
            $this->messageManager->addErrorMessage(__('Secure connection required.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart', ['_secure' => true]);
        }

        // Security: Validate required fields are present
        $requiredFields = [ApiFields::Status, ApiFields::OrderId, ApiFields::Digest, ApiFields::MerchantId];
        $missingFields = $this->validateRequiredFields($responseData, $requiredFields);
        if (!empty($missingFields)) {
            $this->logger->warning('Payment response missing required fields: ' . implode(', ', $missingFields) . ' from IP: ' . $clientIp);
            $this->messageManager->addErrorMessage(__('Invalid payment response received.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart', ['_secure' => true, 'error' => 'invalid-response']);
        }

        // Security: Validate signature for ALL requests upfront
        $isValidSignature = $this->validatePaymentGatewayResponse($responseData);
        if (!$isValidSignature) {
            $this->logger->warning('Payment response received invalid signature from IP: ' . $clientIp . ' for order: ' . ($responseData[ApiFields::OrderId] ?? 'unknown'));
            $this->messageManager->addErrorMessage(__('Invalid payment response received.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart', ['_secure' => true, 'error' => 'invalid-response']);
        }

        // Security: Validate merchant ID matches configuration
        if (!$this->validateMerchantId($responseData)) {
            $this->logger->warning('Payment response received mismatched merchant ID from IP: ' . $clientIp);
            $this->messageManager->addErrorMessage(__('Invalid payment response received.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart', ['_secure' => true, 'error' => 'invalid-response']);
        }

        $status = $responseData[ApiFields::Status] ?? null;

        // Check if order has already been processed (by Response or Webhook controller)
        $alreadyProcessed = $this->isOrderAlreadyProcessed($responseData);
        if ($alreadyProcessed !== null) {
            $order = $alreadyProcessed['order'];
            $this->setSessionFromOrder($order);
            $success = $alreadyProcessed['success'];
            $orderId = $order->getIncrementId();
            $message = $alreadyProcessed['success']
                ? __('Payment has already been processed.')
                : __('Order has already been canceled.');
            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug("Order {$orderId} already processed, skipping. State: {$order->getState()}");
            }
            return $this->handleRedirect($success, $message, $orderId);
        }

        switch ($status) {
            case PaymentStatus::AUTHORIZED:
            case PaymentStatus::CAPTURED:
                $result  = $this->processSuccessfulPayment($responseData);
                $orderId = $result[0];
                $message = $result[1];
                $success = true;
                $this->logger->info('Payment response: Successfully processed payment for order ' . $orderId);
                break;
            case PaymentStatus::CANCELED:
            case PaymentStatus::REFUSED:
            case PaymentStatus::ERROR:
                $message = $this->processFailedPayment($responseData);
                $success = false;
                $this->markOrderCanceled($responseData);
                $this->clearSession();
                $this->logger->info('Payment response: Processed failed/canceled payment with status: ' . $status);
                break;
            default:
                $success = false;
                $message = __('Unknown payment status received.');
                $this->markOrderCanceled($responseData);
                $this->clearSession();
                $this->logger->warning('Payment response received unknown status: ' . $status . ' from IP: ' . $clientIp);
                break;
        }

        return $this->handleRedirect($success, $message, $orderId);
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
     * Process a successful payment response.
     *
     * @param array $responseData
     * @return array [Order ID, message]
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
            // Set session variables
            $this->checkoutSession->setLastQuoteId($quote->getId())
                ->setLastSuccessQuoteId($quote->getId())
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId())
                ->setLastOrderStatus($order->getStatus());
            $this->paymentHelper->markSuccessfulPayment($order, $responseData);
        } else {
            // If no order is found, treat it as a quote
            $quote = $this->paymentHelper->getQuoteById($orderId);
            if ($quote && $quote->getIsActive() && $quote->getId()) {
                $order = $this->createOrderFromQuote($quote->getId());
                // Set session variables
                $this->checkoutSession->setLastQuoteId($quote->getId())
                    ->setLastSuccessQuoteId($quote->getId())
                    ->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());
                $this->paymentHelper->markSuccessfulPayment($order, $responseData);
            }
        }

        if (isset($order) && isset($quote)) {
            $quote->setIsActive(false);
            $quote->save();
        }

        return [$orderId, $message];
    }

    /**
     * Processes a failed payment response.
     *
     * @param array $responseData
     * @return string
     */
    private function processFailedPayment(array $responseData): string
    {
        $message = $responseData[ApiFields::Message] ?? 'The payment was canceled or declined.';
        $this->messageManager->addErrorMessage(__($message));
        return $message;
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
            $this->logger->error("Quote not found for quoteId={$quoteId}");
            throw new \Exception('Quote not found');
        }

        $this->logger->info("Loaded quote {$quote->getId()} (reserved order ID: {$quote->getReservedOrderId()})");

        // Ensure guest settings are properly configured before order creation
        $this->ensureQuoteIsReady($quote);

        // Ensure we don't reuse a previously reserved order ID
        if ($quote->getReservedOrderId()) {
            $this->logger->warning("Quote {$quote->getId()} already had reserved order ID {$quote->getReservedOrderId()}, resetting.");
        }

        $quote->setReservedOrderId(null);
        $quote->reserveOrderId();

        $this->logger->info("Reserved new order ID {$quote->getReservedOrderId()} for quote {$quote->getId()}");

        // Convert quote to order
        if ($this->isMagentoVersionBelow('2.3.0')) {
            $orderId = $this->quoteManagement->placeOrder($quote->getId());
            $order = $this->orderRepository->get($orderId);
        } else {
            $order = $this->quoteManagement->submit($quote);
        }

        // Deactivate the quote so it can't be reused
        $quote->setIsActive(false)->save();

        $this->logger->info("Created order {$order->getIncrementId()} from quote {$quote->getId()}");

        return $order;
    }

    /**
     * Redirects the user to the success or failure page based on payment result.
     *
     * @param bool $success
     * @param string $message
     * @param int $orderId
     * @return ResultInterface
     */
    private function handleRedirect(bool $success, string $message, int $orderId): ResultInterface
    {
        if ($this->dataHelper->doCheckoutInIframe()) {
            $redirectUrl = $success
                ? $this->urlBuilder->getUrl('checkout/onepage/success', ['_secure' => true])
                : $this->urlBuilder->getUrl('checkout/onepage/failure', ['_secure' => true]);

            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
            $block = $resultPage->getLayout()->getBlock('cardlinkcheckout.payment.response');
            $block->setRedirectUrl($redirectUrl)
                ->setMessage(__($message))
                ->setOrderId($orderId);

            return $resultPage;
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath($success ? 'checkout/onepage/success' : 'checkout/onepage/failure');
        return $resultRedirect;
    }

    /**
     * CSRF validation bypass for payment gateway callbacks.
     *
     * This method is retained for parity with Response.php but has no effect in
     * Magento 2.2.x, since this class does not implement CsrfAwareActionInterface
     * (introduced in Magento 2.3.0).
     *
     * @param RequestInterface $request
     * @return bool Always returns true to bypass CSRF validation
     * @see validatePaymentGatewayResponse() for actual security validation
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }

    /**
     * CSRF exception creation override for payment gateway callbacks.
     *
     * This method is retained for parity with Response.php but has no effect in
     * Magento 2.2.x, since this class does not implement CsrfAwareActionInterface
     * (introduced in Magento 2.3.0).
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null Always returns null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Clear the session regarding orders and quotes.
     */
    private function clearSession(): void
    {
        $this->checkoutSession->unsLastQuoteId();
        $this->checkoutSession->unsLastSuccessQuoteId();
        $this->checkoutSession->unsLastOrderId();
        $this->checkoutSession->unsLastRealOrderId();
        $this->checkoutSession->unsLastOrderStatus();
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
     * Set checkout session variables from an existing order.
     *
     * @param Order $order
     * @return void
     */
    private function setSessionFromOrder(Order $order): void
    {
        $this->checkoutSession->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus());
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
        $orderIdStr = $responseData[ApiFields::OrderId];
        $orderId = substr($orderIdStr, 0, strlen($orderIdStr) - ApiFields::OrderId_SuffixLength);
        $order = $this->paymentHelper->getOrderByIncrementId($orderId);
        if ($isCreateOrderEnabled && $order && $order->getId()) {
            $this->paymentHelper->markCanceledPayment($order, $responseData);
        }
    }

    private function isMagentoVersionBelow($ver): bool
    {
        $objectManager = ObjectManager::getInstance();
        $productMetadata = $objectManager->get(\Magento\Framework\App\ProductMetadataInterface::class);
        $magentoVersion = $productMetadata->getVersion();
        return version_compare($magentoVersion, $ver, '<');
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
                    $this->logger->debug("Set guest customer email to {$email} for quote {$quote->getId()}");
                }
            } else {
                $this->logger->warning("Unable to determine customer email for quote {$quote->getId()}");
            }
        }
        $quote->save();
    }
}
