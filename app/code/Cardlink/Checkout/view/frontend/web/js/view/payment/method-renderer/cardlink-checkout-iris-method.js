/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Checkout/js/action/redirect-on-success',
        'mage/url'
    ],
    function (
        $,
        Component,
        quote,
        additionalValidators,
        fullScreenLoader,
        redirectOnSuccessAction,
        url
    ) {
        'use strict';

        return Component.extend({

            defaults: {
                template: 'Cardlink_Checkout/checkout-form-iris'
            },

            initObservable: function () {
                this._super()
                    .observe([

                    ]);
                return this;
            },

            getCode: function () {
                return 'cardlink_checkout_iris';
            },

            canDisplayLogoInTitle: function () {
                return window.checkoutConfig.payment.cardlink_checkout_iris.displayLogoInTitle;
            },

            getLogoUrl: function () {
                return window.checkoutConfig.payment.cardlink_checkout_iris.logoUrl;
            },

            getData: function () {
                var data = {
                    'method': this.item.method,
                    'additional_data': {
                    }
                };

                data['additional_data'] = _.extend(data['additional_data'], this.additionalData);
                return data;
            },

            /**
             * @return {jQuery}
             */
            validate: function () {
                var form = 'form[data-role=cardlink-checkout-iris-options-form]';

                return $(form).validation() && $(form).validation('isValid');
            },

            /**
             * @override
            */
            /** Process Payment */
            preparePayment: function (context, event) {

                if (!additionalValidators.validate()) {   //Resolve checkout aggreement accept error
                    return false;
                }

                var self = this;

                fullScreenLoader.startLoader();
                //this.messageContainer.clear();

                this.isPaymentProcessing = $.Deferred();

                $.when(this.isPaymentProcessing).done(
                    function () {
                        self.placeOrder();
                    }
                ).fail(
                    function (result) {
                        self.handleError(result);
                    }
                );

                return;
            },

            afterPlaceOrder: function () {
                const redirectHandlerUrl = url.build('cardlink_checkout/payment/redirect');
                this.redirectAfterPlaceOrder = false;

                if (window.checkoutConfig.payment.cardlink_checkout.checkoutInIFrame) {
                    fullScreenLoader.stopLoader();
                    this.openPaymentGatewayInIFrame(redirectHandlerUrl);
                } else {
                    // Redirect to your controller action after place order button click
                    redirectOnSuccessAction.redirectUrl = redirectHandlerUrl;
                    redirectOnSuccessAction.execute();
                }
                return false;
            },

            /** Redirect to payment provider inside an IFRAME */
            openPaymentGatewayInIFrame: function (gatewayurl) {
                const cardlinkCheckoutModal = document.getElementById('cardlink_checkout--modal');
                document.getElementById('cardlink_checkout--modal-iframe').src = gatewayurl;
                cardlinkCheckoutModal.style.display = "block";
            }
        });
    }
);