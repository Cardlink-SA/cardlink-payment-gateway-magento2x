<?php

namespace Cardlink\Checkout\Model\Ui;

use Magento\Vault\Model\Ui\TokenUiComponentProviderInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;


class TokenUiComponentProvider implements TokenUiComponentProviderInterface
{
    /**
     * Get UI component for token
     * @param PaymentTokenInterface $paymentToken
     * @return TokenUiComponentInterface
     */
    public function getComponentForToken(PaymentTokenInterface $paymentToken)
    {
        $jsonDetails = json_decode($paymentToken->getTokenDetails() ?: '{}', true);
        $component = $this->componentFactory->create(
            [
                'config' => [
                    'code' => \Cardlink\Checkout\Model\Config\Settings::CODE,
                    TokenUiComponentProviderInterface::COMPONENT_DETAILS => $jsonDetails,
                    TokenUiComponentProviderInterface::COMPONENT_PUBLIC_HASH => $paymentToken->getPublicHash()
                ],
                'name' => 'Cardlink_Checkout/js/view/payment/method-renderer/vault'
            ]
        );

        return $component;
    }
}
