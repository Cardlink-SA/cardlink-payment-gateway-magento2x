<?php

namespace Cardlink\Checkout\Plugin\Sales\Order\Email\Container;

class OrderIdentityPlugin
{
    /**
     * @var \Magento\Checkout\Model\Session $checkoutSession
     */
    protected $checkoutSession;

    /**
     * @var \Psr\Log\LoggerInterface $logger
     */
    protected $logger;

    /**
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Psr\Log\LoggerInterface $logger
     *
     * @codeCoverageIgnore
     */
    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->logger = \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Cardlink\Checkout\Logger\Logger::class);
    }

    /**
     * @param \Magento\Sales\Model\Order\Email\Container\OrderIdentity $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundIsEnabled(\Magento\Sales\Model\Order\Email\Container\OrderIdentity $subject, callable $proceed)
    {
        $returnValue = $proceed();

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        $quoteId = $this->checkoutSession->getQuoteId();
        $quote = $objectManager->create('Magento\Quote\Model\Quote')->loadByIdWithoutStore($quoteId);
        $paymentMethodData = $quote->getPayment()->getData();
        $paymentMethod = array_key_exists('method', $paymentMethodData) ? $paymentMethodData['method'] : null;

        $orderId = $this->checkoutSession->getForcedOrderId();

        if ($orderId == null && ($paymentMethod == 'cardlink_checkout' || $paymentMethod == 'cardlink_checkout_iris')) {
            $returnValue = false;
        } else if ($orderId != null && $paymentMethod == null) {
            $order = $objectManager->create('Magento\Sales\Api\Data\OrderInterface')->load($orderId);

            if ($order) {
                $paymentMethodData = $order->getPayment()->getData();
                $paymentMethod = array_key_exists('method', $paymentMethodData) ? $paymentMethodData['method'] : null;

                $this->logger->error('$paymentMethod(2)=' . json_encode($paymentMethod, JSON_PRETTY_PRINT));
                if (($paymentMethod == 'cardlink_checkout' || $paymentMethod == 'cardlink_checkout_iris')) {
                    $this->logger->error('FORCING EMAIL SEND');
                    $returnValue = true;
                }
            }
        }

        return $returnValue;
    }
}