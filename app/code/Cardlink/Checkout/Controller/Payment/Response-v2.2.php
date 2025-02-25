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

/**
 * Controller action to handle payment gateway responses.
 * Validates, processes, and redirects based on the payment response.
 *
 * @author Cardlink S.A.
 */
class Response extends Action
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
        $isValidResponse = $this->validatePaymentGatewayResponse($responseData);
        $orderId = 0;
        $message = null;

        if (!$isValidResponse) {
            // Redirect to the cart in case of an invalid response.
            return $this->resultRedirectFactory->create()->setPath('checkout/cart', ['_secure' => true]);
        }

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->debug('Received valid payment gateway response.');
            $this->logger->debug(json_encode($responseData, JSON_PRETTY_PRINT));
        }

        $status = $responseData[ApiFields::Status] ?? null;

        switch ($status) {
            case PaymentStatus::AUTHORIZED:
            case PaymentStatus::CAPTURED:
                [$orderId, $message] = $this->processSuccessfulPayment($responseData);
                $success = true;
                break;
            case PaymentStatus::CANCELED:
            case PaymentStatus::REFUSED:
            case PaymentStatus::ERROR:
                $message = $this->processFailedPayment($responseData);
                $success = false;
                $this->markOrderCanceled($responseData);
                $this->clearSession();
                break;
            default:
                $success = false;
                $message = __('Unknown payment status received.');
                $this->markOrderCanceled($responseData);
                $this->clearSession();
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
        $isValid = $this->paymentHelper->validateResponseData(
            $responseData,
            $this->dataHelper->getSharedSecret()
        );
        if (array_key_exists(ApiFields::XlsBonusDigest, $responseData)) {
            $isValid = $isValid && $this->paymentHelper->validateXlsBonusResponseData(
                    $responseData,
                    $this->dataHelper->getSharedSecret()
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
            if ($quote && $quote->getId()) {
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
        $objectManager = ObjectManager::getInstance();
        $quoteFactory = $objectManager->get(QuoteFactory::class);
        $quoteManagement = $objectManager->get(QuoteManagement::class);
        $quote = $quoteFactory->create()->load($quoteId);
        if (!$quote->getId()) {
            throw new Exception('Quote not found');
        }

        // Convert quote to order
        if($this->isMagentoVersionBelow('2.3.0')) {
            $orderId = $quoteManagement->placeOrder($quote->getId());
            $order = $this->paymentHelper->getOrderById($orderId);
        } else {
            $order = $quoteManagement->submit($quote);
        }

        // Mark the quote as inactive
        $quote->setIsActive(false);
        $quote->save();
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
     * Check the option of Create order and hold stock for IRIS is enabled
     *
     * @return bool
     */
    private function isIrisCreateOrderEnabled(): bool
    {
        $diasCode = $this->dataHelper->getDiasCode();
        $enableIrisPayments = $this->dataHelper->isIrisEnabled() && $diasCode != '';
        $IrisCreateOrderEnabled = $this->dataHelper->isIrisCreateOrderEnabled();
        return ($diasCode && $enableIrisPayments && $IrisCreateOrderEnabled);
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
}
