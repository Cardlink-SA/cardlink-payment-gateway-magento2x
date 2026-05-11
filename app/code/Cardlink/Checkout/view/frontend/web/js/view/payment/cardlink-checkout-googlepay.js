define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';

        var config = window.checkoutConfig.payment.cardlink_checkout_googlepay;
        var isGooglePayEnabled = config && config.enable;

        if (isGooglePayEnabled) {
            rendererList.push(
                {
                    type: 'cardlink_checkout_googlepay',
                    component: 'Cardlink_Checkout/js/view/payment/method-renderer/cardlink-checkout-googlepay-method'
                }
            );
        }

        return Component.extend({});
    }
);
