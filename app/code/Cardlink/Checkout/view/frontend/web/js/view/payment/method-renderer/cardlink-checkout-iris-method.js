/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/full-screen-loader',
        'mage/url',
        'Magento_Checkout/js/action/set-payment-information'
    ],
    function (
        $,
        Component,
        quote,
        additionalValidators,
        fullScreenLoader,
        url,
        setPaymentInformationAction
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

            redirectAfterPlaceOrder: false,

            placeOrder: function (data, event) {
                if (event) {
                    event.preventDefault();
                }

                const self = this;

                setPaymentInformationAction(this.messageContainer, {
                    method: this.getData().method,
                    additional_data: this.getData().additional_data
                }).done(function () {
                    const redirectUrl = url.build('cardlink_checkout/payment/redirect');
                    fullScreenLoader.startLoader();
                    window.location.replace(redirectUrl);
                }).fail(function () {
                    self.isPlaceOrderActionAllowed(true);
                    fullScreenLoader.stopLoader();
                });

                return false;
            }
        });
    }
);