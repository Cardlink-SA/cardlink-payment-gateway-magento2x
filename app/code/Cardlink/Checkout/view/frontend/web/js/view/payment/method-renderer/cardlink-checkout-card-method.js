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
                template: 'Cardlink_Checkout/checkout-form-card',
                cardlinkTokenizeCard: false,
                cardlinkStoredToken: 1,
                cardlinkInstallments: 1,
                enabledStoredTokens: 0,
                storedTokens: window.checkoutConfig.payment.cardlink_checkout.storedTokens,
            },

            initObservable: function () {
                this._super()
                    .observe([
                        'cardlinkTokenizeCard',
                        'cardlinkStoredToken',
                        'cardlinkInstallments',
                        'storedTokens'
                    ]);
                return this;
            },

            getCode: function () {
                return 'cardlink_checkout';
            },

            acceptsInstallments: function () {
                return window.checkoutConfig.payment.cardlink_checkout.acceptsInstallments;
            },

            allowsTokenization: function () {
                return window.checkoutConfig.payment.cardlink_checkout.allowsTokenization;
            },

            canDisplayLogoInTitle: function () {
                return window.checkoutConfig.payment.cardlink_checkout.displayLogoInTitle;
            },

            getLogoUrl: function () {
                return window.checkoutConfig.payment.cardlink_checkout.logoUrl;
            },

            getMaxInstallments: function () {
                let maxInstallments = 1;
                const orderAmount = parseFloat(quote.totals()['grand_total']);
                const installmentsConfiguration = window.checkoutConfig.payment.cardlink_checkout.installmentsConfiguration;

                if (installmentsConfiguration.length) {

                    installmentsConfiguration.forEach((range) => {
                        const startAmount = parseFloat(range.start_amount);
                        const endAmount = parseFloat(range.end_amount);

                        if (
                            startAmount <= orderAmount
                            && (
                                (endAmount > 0 && endAmount >= orderAmount)
                                || endAmount == 0
                            )
                        ) {
                            maxInstallments = parseInt(range.max_installments);
                        }
                    });
                }

                return maxInstallments;
            },

            getStoredTokenClass: function (storedToken) {
                let ret = ['cardlink_checkout--card-info'];

                if (storedToken.isExpired) {
                    ret.push('cardlink_checkout--card-info-expired');
                }

                return ret.join(' ');
            },

            getDefaultStoredTokenId: function () {
                const activeTokens = this.getActiveStoredTokens();

                if (activeTokens && activeTokens.length) {
                    return activeTokens[0].entityId;
                }
                return 0;
            },

            getCardTypeData: function () {
                return window.checkoutConfig.payment.cardlink_checkout.cardTypeData;
            },

            hasStoredTokens: function () {
                return this.getStoredTokens().length > 0;
            },

            getStoredTokens: function () {
                return window.checkoutConfig.payment.cardlink_checkout.storedTokens;
            },

            getActiveStoredTokens: function () {
                return this.getStoredTokens().filter((token) => token.isExpired == false);
            },

            showStoreTokenOption: function () {
                document.getElementById('cardlink_checkout--tokenize-container').style.cssText = 'display: block;'
            },

            hideStoreTokenOption: function () {
                document.getElementById('cardlink_checkout--tokenize-container').style.cssText = 'display: none;'
            },

            checkStoredTokenSelection: function (storedTokenId) {
                if (this.getStoredTokens().findIndex((token) => storedTokenId == token.entityId) == -1) {
                    this.showStoreTokenOption();
                } else {
                    this.hideStoreTokenOption();
                }
                return true;
            },

            getData: function () {
                var data = {
                    'method': this.item.method,
                    'additional_data': {
                        'cardlink_tokenize_card': $('#cardlink_tokenize_card').val(),
                        'cardlink_stored_token': $('.cardlink_checkout_token_option:checked').val(),
                        'cardlink_installments': $('#cardlink_installments').val()
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
                    if (window.checkoutConfig.payment.cardlink_checkout.checkoutInIFrame) {
                        fullScreenLoader.stopLoader();
                        self.openPaymentGatewayInIFrame(redirectUrl);
                    } else {
                        fullScreenLoader.startLoader();
                        window.location.replace(redirectUrl);
                    }
                }).fail(function () {
                    self.isPlaceOrderActionAllowed(true);
                    fullScreenLoader.stopLoader();
                });

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