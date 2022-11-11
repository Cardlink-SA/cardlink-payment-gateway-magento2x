<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Helper\Payment;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;

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
     * @var Cardlink\Checkout\Helper\Payment
     */
    private $paymentHelper;

    /**
     * Controller constructor.
     * 
     * @param Context $context
     * @param Session $checkoutSession
     * @param Payment $paymentHelper
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        Payment $paymentHelper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->paymentHelper = $paymentHelper;

        return parent::__construct($context);
    }

    /**
     * Action execution method.
     * Retrieve the latest order placed, retrieve the quote and the item quantities and then cancel the order.
     * Afterwards, redirect the customer to the checkout cart page.
     */
    public function execute()
    {
        // Retrieve the order.
        $order = $this->checkoutSession->getLastRealOrder();

        if ($order) {
            // Cancel order and revert cart contents.
            $this->checkoutSession->setQuoteId($order->getQuoteId());
            $this->paymentHelper->markCanceledPayment($order);
        }

        // Redirect the customer to the checkout cart page.
        $this->_redirect('checkout/cart', array('_secure' => true));
    }
}
