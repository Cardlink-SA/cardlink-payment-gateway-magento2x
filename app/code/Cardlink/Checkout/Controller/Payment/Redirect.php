<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Cardlink\Checkout\Service\OrderLookup;
use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\Page;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Redirects the customer to the payment gateway for completing payment.
 * @author Cardlink S.A.
 *
 */
class Redirect extends Action implements \Magento\Framework\App\PageCache\NotCacheableInterface
{
    const METHOD_GUEST = 'guest';
    const METHOD_REGISTER = 'register';
    const METHOD_CUSTOMER = 'customer';

    protected $logger;
    private $checkoutSession;
    private $coreSession;
    private $dataHelper;
    private $paymentHelper;
    protected $quoteRepository;
    protected $quoteManagement;
    private $payment_method_selected;
    private $orderLookup;
    private $registry;
    private $orderRepository;

    /**
     * Constructor
     *
     * @param Context $context
     * @param Session $checkoutSession
     * @param SessionManagerInterface $coreSession
     * @param Logger $logger
     * @param Data $dataHelper
     * @param Payment $paymentHelper
     * @param CartRepositoryInterface $quoteRepository
     * @param QuoteManagement $quoteManagement
     * @param OrderLookup $orderLookup
     * @param Registry $registry
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        SessionManagerInterface $coreSession,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper,
        CartRepositoryInterface $quoteRepository,
        QuoteManagement $quoteManagement,
        OrderLookup $orderLookup,
        Registry $registry,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->coreSession = $coreSession;
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->orderLookup = $orderLookup;
        $this->registry = $registry;
        $this->orderRepository = $orderRepository;

        parent::__construct($context);
    }

    /**
     * Execute the payment redirection logic.
     *
     * @throws LocalizedException
     */
    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();

        $this->payment_method_selected = $quote->getPayment()->getMethod();

        $isCreateOrderEnabled = $this->isCardSelected() ? $this->isCreateOrderEnabled()
            : ($this->isIrisSelected() && $this->isIrisCreateOrderEnabled());

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->debug($isCreateOrderEnabled ? "Order creation selected." : "Quote selected.");
        }

        try {
            // Validate quote
            if (!$quote->getId()) {
                throw new LocalizedException(__('Unable to process your order.'));
            }

            $this->prepareQuote($quote);

            $formData = $isCreateOrderEnabled
                ? $this->processOrderCreation($quote)
                : $this->paymentHelper->getFormDataForQuote($quote, $this->checkoutSession);

            if ($formData !== false) {
                return $this->redirectToPaymentGateway($formData, $quote->getPayment()->getMethod());
            }
        } catch (Exception $ex) {
            $this->logger->error('Error during payment redirect: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
            $this->handleRedirectFailure();
        }
    }

    /**
     * Prepares the quote by collecting totals and setting necessary details.
     *
     * @param Quote $quote
     * @throws Exception
     */
    private function prepareQuote(Quote $quote): void
    {
        $quote->getShippingAddress()->setCollectShippingRates(true);

        // Resolve the customer email (form / session / addresses).
        $email = $quote->getCustomerEmail() ?: $this->getEmailFromCheckout($quote);

        // For a quote without a logged-in customer, force a clean guest checkout.
        // Without the is-guest flag and NOT_LOGGED_IN group, QuoteManagement::submit()
        // runs the customer-account path and tries to save a Customer entity built from
        // the quote's (empty) customer data object, failing with "customer email is missing".
        if (!$quote->getCustomerId()) {
            $quote->setCheckoutMethod(self::METHOD_GUEST);
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);

            if ($email) {
                $quote->setCustomerEmail($email);
                // Keep the customer data object in sync so the guest order is built correctly.
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

        try {
            $quote->save();
        } catch (\Exception $e) {
            $this->logger->warning("Quote save failed during prepareQuote, but continuing: " . $e->getMessage());
        }
    }

    /**
     * Get customer email from checkout form or address
     *
     * @param Quote $quote
     * @return string|null
     */
    private function getEmailFromCheckout(Quote $quote): ?string
    {
        $email = null;

        // First: try checkout session (where Magento stores guest email)
        if ($this->checkoutSession->hasQuote()) {
            $sessionQuote = $this->checkoutSession->getQuote();
            $email = $sessionQuote->getCustomerEmail();
            if ($email && $this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug('[getEmailFromCheckout] Got email from session quote: ' . $email);
            }
        }

        // Second: try billing/shipping addresses on the current quote
        if (!$email) {
            $billingAddress = $quote->getBillingAddress();
            if ($billingAddress && $billingAddress->getEmail()) {
                $email = $billingAddress->getEmail();
                if ($this->dataHelper->logDebugInfoEnabled()) {
                    $this->logger->debug('[getEmailFromCheckout] Got email from billing address: ' . $email);
                }
            } elseif ($quote->getShippingAddress() && $quote->getShippingAddress()->getEmail()) {
                $email = $quote->getShippingAddress()->getEmail();
                if ($this->dataHelper->logDebugInfoEnabled()) {
                    $this->logger->debug('[getEmailFromCheckout] Got email from shipping address: ' . $email);
                }
            }
        }

        // Third: try request POST (checkout form submission)
        if (!$email) {
            $request = $this->getRequest();
            $email = $request->getPost('customer_email') ?? $request->getPost('email');

            if (!$email && $request->getPost('shippingAddress')) {
                $shippingData = $request->getPost('shippingAddress');
                $email = $shippingData['email'] ?? null;
            }

            if (!$email && $request->getPost('billingAddress')) {
                $billingData = $request->getPost('billingAddress');
                $email = $billingData['email'] ?? null;
            }

            if ($email && $this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug('[getEmailFromCheckout] Got email from request POST: ' . $email);
            }
        }

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->debug('[getEmailFromCheckout] Quote ID: ' . $quote->getId() . ', Final email: ' . ($email ?: 'NOT FOUND'));
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * Processes order creation from the quote and retrieves the form data.
     *
     * @param Quote $quote
     * @return array
     * @throws LocalizedException
     */
    // private function processOrderCreation(Quote $quote): array
    // {
    //     // Convert quote to order
    //     if ($this->isMagentoVersionBelow('2.3.0')) {
    //         $orderId = $this->quoteManagement->placeOrder($quote->getId());
    //         $order = $this->paymentHelper->getOrderById($orderId);
    //     } else {
    //         $quoteId = (int)$quote->getId();

    //         // If an order already exists for this quote, do not reuse it
    //         if ($existing = $this->orderLookup->getLatestByQuoteId($quoteId, true)) {
    //             $this->logger->error('An existing order was found for the quote. Quote ID: ' . $quoteId . ', Order ID: ' . $existing->getId());
    //             try {
    //                 // Clear old reserved increment id to avoid reuse
    //                 $quote->setReservedOrderId(null);
    //                 // Make sure totals are correct
    //                 $quote->collectTotals();
    //             } catch (\Throwable $e) {
    //                 // log and surface the original cause without masking it
    //                 $this->logger->error('[retry submit] ' . $e->getMessage(), ['exception' => $e]);
    //                 throw new \Magento\Framework\Exception\LocalizedException(
    //                     __('Unable to process your order at this time.')
    //                 );
    //             }
    //         }

    //         // // Submit and create a fresh order with a new increment id
    //         $order = $this->quoteManagement->submit($quote);
    //     }
    //     return $this->paymentHelper->getFormDataForOrder($order);
    // }

    private function processOrderCreation(Quote $quote): array
    {
        if ($this->isMagentoVersionBelow('2.3.0')) {
            $orderId = $this->quoteManagement->placeOrder((int)$quote->getId());
            $order   = $this->paymentHelper->getOrderById($orderId);
            $this->setInitialOrderStateAndStatus($order);
            return $this->paymentHelper->getFormDataForOrder($order);
        }

        $quoteId      = (int) $quote->getId();
        $prevReserved = (string) $quote->getReservedOrderId();

        // We do NOT reuse an existing order; just log if one exists.
        if ($this->orderLookup->existsForQuoteId($quoteId, true)) {
            $this->logger->warning("Order already exists for quote {$quoteId}; forcing a NEW order.");
        }

        // Reload a fresh quote instance, clear reserved increment, and recollect safely
        $quote = $this->quoteRepository->get($quoteId);
        $quote->setIsActive(true);
        $quote->setIsMultiShipping(false);
        $quote->setTotalsCollectedFlag(false);
        $quote->setReservedOrderId(null);

        foreach ($quote->getAllAddresses() as $addr) {
            $addr->setCollectShippingRates(true);
        }

        // Re-apply guest settings after reload to ensure email, checkout method and
        // guest flags are set correctly before the quote is converted to an order.
        $this->prepareQuote($quote);

        try {
            $quote->collectTotals();
            $this->quoteRepository->save($quote);
        } catch (\Throwable $e) {
            $this->logger->error('[quote prepare] ' . $e->getMessage(), ['exception' => $e]);
            throw new LocalizedException(__('Unable to process your order at this time.'));
        }

        // Submit a NEW order but suppress failure observers in this window
        try {
            $this->registry->register('cardlink_skip_submit_failure_observers', true, true);
            $order = $this->quoteManagement->submit($quote);
        } catch (\Throwable $e) {
            $this->logger->error('[submit quote] ' . $e->getMessage(), ['exception' => $e]);
            throw new LocalizedException(__('Unable to process your order at this time.'));
        } finally {
            if ($this->registry->registry('cardlink_skip_submit_failure_observers')) {
                $this->registry->unregister('cardlink_skip_submit_failure_observers');
            }
        }

        // Set the correct order state and status based on configured order_status
        // This ensures the state matches what the configured status belongs to
        $this->setInitialOrderStateAndStatus($order);

        return $this->paymentHelper->getFormDataForOrder($order);
    }

    /**
     * Set the initial order state and status based on payment method configuration.
     *
     * Ensures the order state matches the state that the configured status belongs to,
     * supporting statuses from both "new" and "pending_payment" states.
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return void
     */
    private function setInitialOrderStateAndStatus($order): void
    {
        $paymentMethod = $order->getPayment()->getMethod();
        
        if ($paymentMethod === \Cardlink\Checkout\Model\Config\SettingsIris::CODE) {
            $stateStatus = $this->dataHelper->getIrisNewOrderStateAndStatus();
        } elseif ($paymentMethod === \Cardlink\Checkout\Model\Config\SettingsGooglePay::CODE) {
            $stateStatus = $this->dataHelper->getGooglePayNewOrderStateAndStatus();
        } else {
            $stateStatus = $this->dataHelper->getNewOrderStateAndStatus();
        }

        $state = $stateStatus['state'];
        $status = $stateStatus['status'];

        // Only update if different from current
        if ($order->getState() !== $state || $order->getStatus() !== $status) {
            $order->setState($state);
            $order->setStatus($status);
            $this->orderRepository->save($order);
            
            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug("Set order {$order->getIncrementId()} to state={$state}, status={$status}");
            }
        }
    }

    /**
     * Redirects to the payment gateway with the form data.
     *
     * @param array $formData
     * @param string|null $paymentMethodCode
     * @return Page
     */
    private function redirectToPaymentGateway(array $formData, ?string $paymentMethodCode = null): Page
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $block = $resultPage->getLayout()->getBlock('cardlinkcheckout.payment.redirect');

        $block->setData('formData', $formData);
        $block->setData('paymentGatewayUrl', $this->paymentHelper->getPaymentGatewayDataPostUrl($paymentMethodCode));

        // One-liner to add the right no-cache headers
        $this->getResponse()->setNoCacheHeaders();

        return $resultPage;
    }

    /**
     * Handles redirection to the failure page in case of errors.
     */
    private function handleRedirectFailure(): void
    {
        $this->coreSession->setMessage('Invalid payment gateway data');
        $this->_redirect('checkout/onepage/failure', ['_secure' => true]);
    }

    private function isIrisCreateOrderEnabled(): bool
    {
        $enableIrisPayments = $this->dataHelper->isIrisEnabled();
        $IrisCreateOrderEnabled = $this->dataHelper->isIrisCreateOrderEnabled();
        return ($enableIrisPayments && $IrisCreateOrderEnabled);
    }

    private function isCreateOrderEnabled(): bool
    {
        $cardlinkEnabled = $this->dataHelper->isEnabled();
        $CreateOrderEnabled = $this->dataHelper->isCreateOrderEnabled();
        return ($cardlinkEnabled && $CreateOrderEnabled);
    }

    private function isCardSelected(): bool
    {
        $method = $this->payment_method_selected;
        $code = \Cardlink\Checkout\Model\Config\Settings::CODE;
        return ($method === $code);
    }

    private function isIrisSelected(): bool
    {
        $method = $this->payment_method_selected;
        $code = \Cardlink\Checkout\Model\Config\SettingsIris::CODE;
        return ($method === $code);
    }

    private function isGooglePaySelected(): bool
    {
        $method = $this->payment_method_selected;
        $code = \Cardlink\Checkout\Model\Config\SettingsGooglePay::CODE;
        return ($method === $code);
    }

    private function isMagentoVersionBelow($ver): bool
    {
        $objectManager = ObjectManager::getInstance();
        $productMetadata = $objectManager->get(\Magento\Framework\App\ProductMetadataInterface::class);
        $magentoVersion = $productMetadata->getVersion();
        return version_compare($magentoVersion, $ver, '<');
    }
}
