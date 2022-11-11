<?php

namespace Cardlink\Checkout\Block;

use Cardlink\Checkout\Helper\Tokenization;

/**
 * Information block used to display additional data regarding the payment process of the order.
 * 
 * @author Cardlink S.A.
 */
class Info extends \Magento\Payment\Block\Info
{
    /**
     * @var Tokenization
     */
    private $tokenizationHelper;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Cardlink\Checkout\Helper\Tokenization $tokenizationHelper
     * @param \Magento\Payment\Model\Config $paymentConfig
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Cardlink\Checkout\Helper\Tokenization $tokenizationHelper,
        \Magento\Payment\Model\Config $paymentConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->tokenizationHelper = $tokenizationHelper;
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
        if ($this->getInfo()->getMethod() == \Cardlink\Checkout\Model\Config\Settings::CODE) {

            // If installments were selected, display their number.
            if (($installments = $this->getInfo()->getCardlinkInstallments()) > 1) {
                $data[(string)__('Installments')] = $installments;
            }

            // Display the card type used for the payment.
            if ($payMethod = $this->getInfo()->getCardlinkPayMethod()) {
                $data[(string)__('Credit Card Type')] = strtoupper($payMethod);
            }

            // If running in admin environment
            //if (!$this->getIsSecureMode()) {
            // If a stored token was used for the order.
            if (($storedTokenId = $this->getInfo()->getCardlinkStoredToken()) > 0) {
                // Retrieve information on the stored token.
                $storedToken = $this->tokenizationHelper->getStoredToken($storedTokenId);
                $typeName = $this->tokenizationHelper->getCardTypeName($storedToken->type);

                // If the stored token was successfully retrieved.
                if ($storedToken != null) {
                    // Display the last four digits of the card and its expiration date.
                    $data[(string)__('Credit Card Type')] = __(strtoupper($typeName));
                    $data[(string)__('Credit Card Number')] = sprintf('xxxx-%s (%s)', $storedToken->maskedCC, $storedToken->expirationDate);
                }
            }
            //}

            // Display the payment status (if the customer either proceeded with the payment, explicitly canceled or any other payment gateway event).
            if ($payStatus = $this->getInfo()->getCardlinkPayStatus()) {
                $data[(string)__('Payment Status')] = __(strtoupper($payStatus));
            }

            // Display the payment reference number of a successful transaction.
            if ($payRef = $this->getInfo()->getCardlinkPayRef()) {
                $data[(string)__('Payment Reference')] = $payRef;
            }

            // Display the transaction ID, whether successful or not.
            if ($txId = $this->getInfo()->getCardlinkTxId()) {
                $data[(string)__('Transaction ID')] = $txId;
            }
        }

        return $transport->setData(array_merge($data, $transport->getData()));
    }
}
