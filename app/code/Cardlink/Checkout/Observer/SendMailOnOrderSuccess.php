<?php

namespace Cardlink\Checkout\Observer;

use Magento\Framework\Event\ObserverInterface;

class SendMailOnOrderSuccess implements ObserverInterface
{
    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderModel;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    /**
     * @var \Magento\Checkout\Model\Session $checkoutSession
     */
    protected $checkoutSession;

    /**
     * @var \Cardlink\Checkout\Helper\Payment
     */
    protected $paymentHelper;

    /**
     * @var \Psr\Log\LoggerInterface $logger
     */
    protected $logger;

    /**
     * @param \Magento\Sales\Model\OrderFactory $orderModel
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Magento\Checkout\Model\Session $checkoutSession
     *
     * @codeCoverageIgnore
     */
    public function __construct(
        \Magento\Sales\Model\OrderFactory $orderModel,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Cardlink\Checkout\Helper\Payment $paymentHelper
    ) {
        $this->orderModel = $orderModel;
        $this->orderSender = $orderSender;
        $this->checkoutSession = $checkoutSession;
        $this->paymentHelper = $paymentHelper;

        $this->logger = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Cardlink\Checkout\Logger\Logger::class);
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $orderIds = $observer->getEvent()->getOrderIds();
        if (count($orderIds)) {
            $this->checkoutSession->setForcedOrderId($orderIds[0]);
            $order = $this->paymentHelper->getOrderById($orderIds[0]);

            $paymentMethod = $order->getPayment()->getMethod();

            if ($paymentMethod == 'cardlink_checkout' || $paymentMethod == 'cardlink_checkout_iris') {
                try {
                    $this->orderSender->send($order, true);
                } catch (\Exception $e) {
                    $this->logger->critical($e);
                }
            }
        }
    }
}