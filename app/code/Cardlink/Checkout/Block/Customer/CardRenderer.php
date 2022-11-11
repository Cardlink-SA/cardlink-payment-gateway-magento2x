<?php

namespace Cardlink\Checkout\Block\Customer;

use Magento\Vault\Block\AbstractCardRenderer;
use Magento\Vault\Api\Data\PaymentTokenInterface;

class CardRenderer extends AbstractCardRenderer
{
    /**
     * Can render specified token
     *
     * @param PaymentTokenInterface $token
     * @return boolean
     */
    public function canRender(PaymentTokenInterface $token)
    {
        return $token->getPaymentMethodCode() === \Cardlink\Checkout\Model\Config\Settings::CODE;
    }

    /**
     * @return string
     */
    public function getNumberLast4Digits()
    {
        return $this->getTokenDetails()['maskedCC'];
    }

    /**
     * @return string
     */
    public function getExpDate()
    {
        return $this->getTokenDetails()['expirationDate'];
    }

    /**
     * @return string
     */
    public function getIconUrl()
    {
        try {
            return $this->getIconForType($this->getTokenDetails()['type'])['url'];
        } catch (\Exception $ex) {
            return null;
        }
    }

    /**
     * @return int
     */
    public function getIconHeight()
    {
        try {
            return $this->getIconForType($this->getTokenDetails()['type'])['height'];
        } catch (\Exception $ex) {
            return null;
        }
    }

    /**
     * @return int
     */
    public function getIconWidth()
    {
        try {
            return $this->getIconForType($this->getTokenDetails()['type'])['width'];
        } catch (\Exception $ex) {
            return null;
        }
    }
}
