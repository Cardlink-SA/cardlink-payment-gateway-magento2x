define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';

        var cardConfig = window.checkoutConfig.payment.cardlink_checkout;
        const isCardEnabled = cardConfig && cardConfig.enable;

        if (isCardEnabled) {
            rendererList.push(
                {
                    type: 'cardlink_checkout',
                    component: 'Cardlink_Checkout/js/view/payment/method-renderer/cardlink-checkout-card-method'
                }
            );
        }

        return Component.extend({});
    }
);
