<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\Page;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;

/**
 * Redirects the customer to the payment gateway for completing payment.
 * @author CardLink S.A.
 *
 */
class Redirect extends Action
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
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        SessionManagerInterface $coreSession,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper,
        CartRepositoryInterface $quoteRepository,
        QuoteManagement $quoteManagement
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->coreSession = $coreSession;
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;

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

        // Validate quote
        if (!$quote->getId()) {
            throw new LocalizedException(__('Unable to process your order.'));
        }

        try {
            $this->prepareQuote($quote);

            $formData = $isCreateOrderEnabled
                ? $this->processOrderCreation($quote)
                : $this->paymentHelper->getFormDataForQuote($quote, $this->checkoutSession);

            if ($formData !== false) {
                return $this->redirectToPaymentGateway($formData, $isCreateOrderEnabled ? $quote->getPayment()->getMethod() : null);
            }
        } catch (Exception $ex) {
            $this->logger->error('Error during payment redirect: ' . $ex->getMessage());
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
        $quote->collectTotals();
        if (!$quote->getCheckoutMethod()) {
            $quote->setCheckoutMethod(self::METHOD_GUEST);
        }
        if (!$quote->getCustomerEmail()) {
            $quote->setCustomerEmail($quote->getBillingAddress()->getEmail());
        }
        $quote->save();
    }

    /**
     * Processes order creation from the quote and retrieves the form data.
     *
     * @param Quote $quote
     * @return array
     * @throws LocalizedException
     */
    private function processOrderCreation(Quote $quote): array
    {
        // Convert quote to order
        if($this->isMagentoVersionBelow('2.3.0')) {
            $orderId = $this->quoteManagement->placeOrder($quote->getId());
            $order = $this->paymentHelper->getOrderById($orderId);
        } else {
            $order = $this->quoteManagement->submit($quote);
        }
        return $this->paymentHelper->getFormDataForOrder($order);
    }

    /**
     * Redirects to the payment gateway with the form data.
     *
     * @param array $formData
     * @param string|null $paymentMethodCode
     * @return Page
     */
    private function redirectToPaymentGateway(array $formData, string $paymentMethodCode = null): Page
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $block = $resultPage->getLayout()->getBlock('cardlinkcheckout.payment.redirect');

        $block->setData('formData', $formData);
        $block->setData('paymentGatewayUrl', $this->paymentHelper->getPaymentGatewayDataPostUrl($paymentMethodCode));

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
        $diasCode = $this->dataHelper->getDiasCode();
        $enableIrisPayments = $this->dataHelper->isIrisEnabled() && $diasCode != '';
        $IrisCreateOrderEnabled = $this->dataHelper->isIrisCreateOrderEnabled();
        return ($diasCode && $enableIrisPayments && $IrisCreateOrderEnabled);
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

    private function isMagentoVersionBelow($ver): bool
    {
        $objectManager = ObjectManager::getInstance();
        $productMetadata = $objectManager->get(\Magento\Framework\App\ProductMetadataInterface::class);
        $magentoVersion = $productMetadata->getVersion();
        return version_compare($magentoVersion, $ver, '<');
    }
}