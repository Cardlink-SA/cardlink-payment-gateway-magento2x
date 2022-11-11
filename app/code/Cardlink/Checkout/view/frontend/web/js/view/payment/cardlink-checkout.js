define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';

        const isEnabled = window.checkoutConfig.payment.cardlink_checkout.enable;

        if (isEnabled) {
            rendererList.push(
                {
                    type: 'cardlink_checkout',
                    component: 'Cardlink_Checkout/js/view/payment/method-renderer/cardlink-checkout-method'
                }
            );
        }
        return Component.extend({});
    }
);
