<?php

namespace Cardlink\Checkout\Block;

use Cardlink\Checkout\Helper\Tokenization;

/**
 * Information block used to display additional data regarding the payment process of the order.
 * 
 * @author Cardlink S.A.
 */
class InfoIris extends \Magento\Payment\Block\Info
{
    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Payment\Model\Config $paymentConfig
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Prepare credit card related payment info for display.
     *
     * @param \Magento\Framework\DataObject|array $transport
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        if (null !== $this->_paymentSpecificInformation) {
            return $this->_paymentSpecificInformation;
        }
        $transport = parent::_prepareSpecificInformation($transport);
        $data = [];

        // Only execute the block code if the Cardlink Checkout payment method was used for the order.
        if ($this->getInfo()->getMethod() == \Cardlink\Checkout\Model\Config\SettingsIris::CODE) {

            // Display the payment status (if the customer either proceeded with the payment, explicitly canceled or any other payment gateway event).
            if ($payStatus = $this->getInfo()->getCardlinkPayStatus()) {
                $data[(string) __('Payment Status')] = __(strtoupper($payStatus));
            }

            // Display the payment reference number of a successful transaction.
            if ($payRef = $this->getInfo()->getCardlinkPayRef()) {
                $data[(string) __('Payment Reference')] = $payRef;
            }

            // Display the transaction ID, whether successful or not.
            if ($txId = $this->getInfo()->getCardlinkTxId()) {
                $data[(string) __('Transaction ID')] = $txId;
            }
        }

        return $transport->setData(array_merge($data, $transport->getData()));
    }
}