<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Model\ApiFields;
use Cardlink\Checkout\Model\PaymentStatus;
use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\CsrfAwareActionInterface;

/**
 * Controller action used to handle responses from the payment gateway.
 * 
 * @author Cardlink S.A.
 */
class Response extends Action implements CsrfAwareActionInterface
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     *  @var Session
     */
    private $checkoutSession;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @var Cardlink\Checkout\Helper\Data
     */
    private $dataHelper;

    /**
     * @var Cardlink\Checkout\Helper\Payment
     */
    private $paymentHelper;

    /**
     * @var RedirectFactory
     */
    protected $resultRedirectFactory;

    /**
     * Controller constructor.
     * 
     * @param Context $context
     * @param Session $checkoutSession
     * @param ManagerInterface $messageManager
     * @param UrlInterface $urlBuilder
     * @param Logger $logger
     * @param Data $dataHelper
     * @param Payment $paymentHelper
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        ManagerInterface $messageManager,
        UrlInterface $urlBuilder,
        RedirectFactory $resultRedirectFactory,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper

    ) {
        $this->checkoutSession = $checkoutSession;
        $this->messageManager = $messageManager;
        $this->urlBuilder = $urlBuilder;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->logger = $logger;

        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;

        return parent::__construct($context);
    }

    /**
     * Action execution method.
     * Handle incoming responses from the payment gateway.
     */
    public function execute()
    {
        $orderId = 0;
        $success = false;
        $responseData = $this->getRequest()->getParams();

        // Verify that the response is coming from the payment gateway.
        $isValidPaymentGatewayResponse = $this->paymentHelper->validateResponseData(
            $responseData,
            $this->dataHelper->getSharedSecret()
        );

        $isValidXlsBonusPaymentGatewayResponse = true;

        // If performing a Bonus transaction, validate the xlsbonusdigest field
        if (array_key_exists(ApiFields::XlsBonusDigest, $responseData)) {
            $isValidXlsBonusPaymentGatewayResponse = $this->paymentHelper->validateXlsBonusResponseData(
                $responseData,
                $this->dataHelper->getSharedSecret()
            );
        }

        $message = null;

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $quoteFactory = $objectManager->get(\Magento\Quote\Model\QuoteFactory::class);
        $quoteManagement = $objectManager->get(\Magento\Quote\Model\QuoteManagement::class);
        $orderSender = $objectManager->get(\Magento\Sales\Model\Order\Email\Sender\OrderSender::class);

        if (!$isValidPaymentGatewayResponse || !$isValidXlsBonusPaymentGatewayResponse) {
            // The response data could not be verified.
            $this->_redirect('checkout/cart', ['_secure' => true]);
            return;
        } else {
            // If the response came from the payment gateway
            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug("Received valid payment gateway response");
                $this->logger->debug(json_encode($responseData, JSON_PRETTY_PRINT));
            }

            // If the response identifies the transaction as either AUTHORIZED or CAPTURED.
            if (
                $responseData[ApiFields::Status] === PaymentStatus::AUTHORIZED
                || $responseData[ApiFields::Status] === PaymentStatus::CAPTURED
            ) {
                $quoteIdStr = $responseData[ApiFields::OrderId];
                $quoteId = substr($quoteIdStr, 0, strlen($quoteIdStr) - ApiFields::OrderId_SuffixLength);

                $quote = $quoteFactory->create()->load($quoteId);
                if (!$quote->getId()) {
                    throw new \Exception('Quote not found');
                }

                $billingAddress = $quote->getBillingAddress();
                $quote->setCustomerEmail($billingAddress->getEmail());

                // Convert quote to order
                $order = $quoteManagement->submit($quote);

                // Save the order
                $order->save();

                $orderId = $order->getIncrementId();

                // Mark the payment as successful and remove the quote from the customer's session.
                $this->paymentHelper->markSuccessfulPayment($order, $responseData);

                $this->checkoutSession->unsQuoteId();
                $this->checkoutSession->setLastQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
                $this->checkoutSession->setLastOrderId($order->getId());
                $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
                $this->checkoutSession->setLastOrderStatus($order->getStatus());

                $message = $responseData[ApiFields::Message];
                $success = true;
            } else if (
                // The payment was either canceled by the customer, refused by the payment gateway or an error occured.
                $responseData[ApiFields::Status] === PaymentStatus::CANCELED
                || $responseData[ApiFields::Status] === PaymentStatus::REFUSED
                || $responseData[ApiFields::Status] === PaymentStatus::ERROR
            ) {
                $this->checkoutSession->unsLastQuoteId();
                $this->checkoutSession->unsLastSuccessQuoteId();
                $this->checkoutSession->unsLastOrderId();
                $this->checkoutSession->unsLastRealOrderId();
                $this->checkoutSession->unsLastOrderStatus();

                // If the response identifies the transaction as either CANCELED, REFUSED or ERROR add an error message.
                if (array_key_exists(ApiFields::Message, $responseData)) {
                    $message = $responseData[ApiFields::Message];
                } else {
                    $message = 'The payment was canceled by you or declined by the bank. Your order has been canceled.';
                }

                $this->messageManager->addErrorMessage(__($message));
            }
        }

        // If the payment flow executed inside the IFRAME, send out a redirection form page to force open the final response page in the parent frame (store window/tab).
        if ($this->dataHelper->doCheckoutInIframe()) {
            $redirectUrl = $success
                ? $this->urlBuilder->getUrl('checkout/onepage/success', ['_secure' => true])
                : $this->urlBuilder->getUrl('checkout/onepage/failure', ['_secure' => true]);

            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
            $block = $resultPage->getLayout()->getBlock('cardlinkcheckout.payment.response');
            $block->setRedirectUrl($redirectUrl);
            if (isset($message)) {
                $block->setMessage(__($message));
            }
            $block->setOrderId($orderId);
            return $resultPage;
        } else {
            $resultRedirect = $this->resultRedirectFactory->create();

            if ($success) {
                $resultRedirect->setPath('checkout/onepage/success');
            } else {
                $resultRedirect->setPath('checkout/onepage/failure');
            }

            return $resultRedirect;
        }
    }

    /**
     * Skip CSRF checks for the requests to this action.
     * 
     * @param RequestInterface $request
     *
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }

    /**
     * @param RequestInterface $request
     *
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): InvalidRequestException
    {
        return null;
    }

}