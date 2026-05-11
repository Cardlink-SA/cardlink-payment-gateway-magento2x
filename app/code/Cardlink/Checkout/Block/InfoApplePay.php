<?php

namespace Cardlink\Checkout\Block;

/**
 * Information block for Apple Pay payment method order display.
 *
 * @author Cardlink S.A.
 */
class InfoApplePay extends \Magento\Payment\Block\Info
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
     * Prepare Apple Pay payment info for display.
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

        if ($this->getInfo()->getMethod() == \Cardlink\Checkout\Model\Config\SettingsApplePay::CODE) {

            if ($payStatus = $this->getInfo()->getCardlinkPayStatus()) {
                $data[(string) __('Payment Status')] = __(strtoupper($payStatus));
            }

            if ($payRef = $this->getInfo()->getCardlinkPayRef()) {
                $data[(string) __('Payment Reference')] = $payRef;
            }

            if ($txId = $this->getInfo()->getCardlinkTxId()) {
                $data[(string) __('Transaction ID')] = $txId;
            }
        }

        return $transport->setData(array_merge($data, $transport->getData()));
    }
}
