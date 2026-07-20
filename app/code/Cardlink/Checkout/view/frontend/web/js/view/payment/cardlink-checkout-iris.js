define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';

        var irisConfig = window.checkoutConfig.payment.cardlink_checkout_iris;
        const isIrisEnabled = irisConfig && irisConfig.enable;

        if (isIrisEnabled) {
            rendererList.push(
                {
                    type: 'cardlink_checkout_iris',
                    component: 'Cardlink_Checkout/js/view/payment/method-renderer/cardlink-checkout-iris-method'
                }
            );
        }
        
        return Component.extend({});
    }
);
