<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Logger\Logger;
use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Helper\Payment;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Controller action used to redirect the customer to the payment gateway to perform the payment actions for the placed order.
 * 
 * @author Cardlink S.A.
 */
class Redirect extends Action
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     *  @var Session
     */
    private $checkoutSession;

    /**
     * @var SessionManagerInterface
     */
    private $coreSession;

    /**
     * @var Data
     */
    private $dataHelper;

    /**
     * @var Payment
     */
    private $paymentHelper;

    /**
     * Controller constructor.
     * 
     * @param Context $context
     * @param Session $checkoutSession
     * @param SessionManagerInterface $coreSession
     * @param Logger $logger
     * @param Data $dataHelper
     * @param Payment $paymentHelper
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        SessionManagerInterface $coreSession,
        Logger $logger,
        Data $dataHelper,
        Payment $paymentHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->coreSession = $coreSession;
        $this->logger = $logger;
        $this->dataHelper = $dataHelper;
        $this->paymentHelper = $paymentHelper;

        return parent::__construct($context);
    }

    /**
     * Action execution method.
     * Retrieve the latest order placed, create the payment gateway's data redirection form and send the user to the payment gateway.
     */
    public function execute()
    {
        // Retrieve the order.
        $order = $this->checkoutSession->getLastRealOrder();
        $orderId = $order->getIncrementId();

        // Retrieve the order and compile the API request data array.
        $formData = $this->paymentHelper->getFormDataForOrder($order);

        if ($formData !== false) {
            // Retrieve the page layout, get the payment redirection block, assign data to the redirection form and display the page to the customer.
            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
            $block = $resultPage->getLayout()->getBlock('cardlinkcheckout.payment.redirect');
            $block->setData('formData', $formData);
            $block->setData('paymentGatewayUrl', $this->paymentHelper->getPaymentGatewayDataPostUrl());
            return $resultPage;
        } else {
            // Problem found with the data. Redirect the user to the checkout failure page.
            $this->coreSession->setMessage('Invalid payment gateway data');

            if ($this->dataHelper->logDebugInfoEnabled()) {
                $this->logger->debug("Invalid payment gateway data for order {$orderId}");
            }

            // Redirect the customer to the checkout failure page
            $this->_redirect('checkout/onepage/failure', ['_secure' => true]);
        }
    }
}
