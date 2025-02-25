<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Helper\Payment;
use Cardlink\Checkout\Model\ApiFields;
use Cardlink\Checkout\Model\PaymentStatus;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;

/**
 * Controller action used to cancel the order placed but not successfully paid for.
 *
 * @author Cardlink S.A.
 */
class Cancel extends Action
{
    /**
     *  @var Session
     */
    private $checkoutSession;

    /**
     * @var Payment
     */
    private $paymentHelper;

    /**
     * @var Logger
     */
    private $logger;

    /** @var Data */
    private $dataHelper;

    /** @var UrlInterface */
    private $urlBuilder;

    /**
     * Controller constructor.
     *
     * @param Context $context
     * @param Session $checkoutSession
     * @param Payment $paymentHelper
     * @param Logger $logger
     * @param Data $dataHelper
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        Payment $paymentHelper,
        Logger $logger,
        Data $dataHelper,
        UrlInterface $urlBuilder
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->paymentHelper = $paymentHelper;
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context);
    }

    /**
     * Action execution method.
     * Retrieve the latest order placed, retrieve the quote and the item quantities and then cancel the order.
     * Afterwards, redirect the customer to the checkout cart page.
     */
    public function execute(): ResultInterface
    {
        $responseData = $this->getRequest()->getParams();
        $isValidResponse = $this->validatePaymentGatewayResponse($responseData);
        $orderId = 0;

        if (!$isValidResponse) {
            // Redirect to the cart in case of an invalid response.
            return $this->resultRedirectFactory->create()->setPath('checkout/cart', ['_secure' => true]);
        }

        if ($this->dataHelper->logDebugInfoEnabled()) {
            $this->logger->debug('Received valid payment gateway response in Cancel action.');
            $this->logger->debug(json_encode($responseData, JSON_PRETTY_PRINT));
        }

        $status = $responseData[ApiFields::Status] ?? null;

        switch ($status) {
            case PaymentStatus::CANCELED:
            case PaymentStatus::REFUSED:
            case PaymentStatus::ERROR:
                $message = $this->processFailedPayment($responseData);
                $this->markOrderCanceled($responseData);
                $this->clearSession();
                break;
            default:
                $message = __('Unknown payment status received.');
                $this->markOrderCanceled($responseData);
                $this->clearSession();
                break;
        }

        return $this->handleRedirect($message, $orderId);
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
     * Redirects the user to the success or failure page based on payment result.
     *
     * @param string $message
     * @param int $orderId
     * @return ResultInterface
     */
    private function handleRedirect(string $message, int $orderId): ResultInterface
    {
        if ($this->dataHelper->doCheckoutInIframe()) {
            $redirectUrl = $this->urlBuilder->getUrl('checkout/onepage/failure', ['_secure' => true]);
            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
            $block = $resultPage->getLayout()->getBlock('cardlinkcheckout.payment.response');
            $block->setRedirectUrl($redirectUrl)->setMessage(__($message))->setOrderId($orderId);
            return $resultPage;
        }
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/onepage/failure');
        return $resultRedirect;
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
}
