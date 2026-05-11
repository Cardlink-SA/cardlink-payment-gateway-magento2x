define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';

        var config = window.checkoutConfig.payment.cardlink_checkout_applepay;
        var isApplePayEnabled = config && config.enable;

        if (isApplePayEnabled) {
            rendererList.push(
                {
                    type: 'cardlink_checkout_applepay',
                    component: 'Cardlink_Checkout/js/view/payment/method-renderer/cardlink-checkout-applepay-method'
                }
            );
        }

        return Component.extend({});
    }
);
