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
        'Magento_Checkout/js/action/set-payment-information',
        'Magento_Checkout/js/action/place-order',
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
                template: 'Cardlink_Checkout/checkout-form-googlepay',
                googlePayScriptLoading: false,
                googlePayScriptLoaded: false,
                googlePayReady: false
            },

            initObservable: function () {
                this._super()
                    .observe([
                        'googlePayReady'
                    ]);

                // If this method is already selected when the component renders,
                // selectPaymentMethod won't fire, so initialize proactively.
                var self = this;
                var checkSelected = setInterval(function () {
                    if (self.isChecked() === self.getCode()) {
                        clearInterval(checkSelected);
                        self.initGooglePayScript();
                    }
                }, 500);

                return this;
            },

            getCode: function () {
                return 'cardlink_checkout_googlepay';
            },

            getDescription: function () {
                return window.checkoutConfig.payment.cardlink_checkout_googlepay.description;
            },

            canDisplayLogoInTitle: function () {
                return window.checkoutConfig.payment.cardlink_checkout_googlepay.displayLogoInTitle;
            },

            getLogoUrl: function () {
                return window.checkoutConfig.payment.cardlink_checkout_googlepay.logoUrl;
            },

            getData: function () {
                var data = {
                    'method': this.item.method,
                    'additional_data': {}
                };

                data['additional_data'] = _.extend(data['additional_data'], this.additionalData);
                return data;
            },

            /**
             * @return {jQuery}
             */
            validate: function () {
                var form = 'form[data-role=cardlink-checkout-googlepay-options-form]';
                return $(form).validation() && $(form).validation('isValid');
            },

            redirectAfterPlaceOrder: false,

            /**
             * Initialize the Google Pay Direct script from Cardlink
             */
            initGooglePayScript: function () {
                var self = this;
                var config = window.checkoutConfig.payment.cardlink_checkout_googlepay;

                // Prevent double loading — set flag synchronously before any async call
                if (self.googlePayScriptLoading || self.googlePayScriptLoaded) {
                    return;
                }
                self.googlePayScriptLoading = true;

                // First, get the script info (query string hash) from our backend
                $.ajax({
                    url: config.scriptInfoUrl,
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        mid: config.googlePayGatewayMerchantId
                    }),
                    success: function (data) {
                        if (!data.success) {
                            console.error('Failed to get Google Pay script info');
                            self.googlePayScriptLoading = false;
                            return;
                        }

                        // Clear the container before loading a new script
                        var container = document.getElementById('gpcontainer');
                        if (container) {
                            container.innerHTML = '';
                        }

                        var scriptUrl = config.googlePayDirectScriptUrl + data.queryString;

                        // Load the Cardlink googlepaydirect.js script.
                        // It will load pay.google.com/gp/p/js/pay.js internally via loadGooglePayJsScript().
                        var script = document.createElement('script');
                        script.type = 'text/javascript';
                        script.src = scriptUrl;

                        script.onload = function () {
                            console.log('Google Pay Direct script loaded successfully.');
                            self.googlePayScriptLoaded = true;
                            self.waitForContainerAndInit(data);
                        };

                        script.onerror = function () {
                            console.error('Failed to load Google Pay Direct script.');
                            self.googlePayScriptLoading = false;
                        };

                        document.body.appendChild(script);
                    },
                    error: function (xhr, status, error) {
                        console.error('Failed to fetch Google Pay script info:', error);
                        self.googlePayScriptLoading = false;
                    }
                });
            },

            /**
             * Wait for the #gpcontainer element to exist in the DOM before initializing.
             * Knockout may not have rendered the payment method content yet.
             */
            waitForContainerAndInit: function (initData) {
                var self = this;
                var container = document.getElementById('gpcontainer');

                if (container) {
                    self.initializeGooglePay(initData);
                    return;
                }

                // Poll until the container is rendered by Knockout
                var attempts = 0;
                var maxAttempts = 20;
                var poll = setInterval(function () {
                    attempts++;
                    container = document.getElementById('gpcontainer');
                    if (container) {
                        clearInterval(poll);
                        self.initializeGooglePay(initData);
                    } else if (attempts >= maxAttempts) {
                        clearInterval(poll);
                        console.error('Google Pay container #gpcontainer not found in DOM after waiting.');
                    }
                }, 250);
            },

            /**
             * Initialize the GooglePay class from the loaded script
             */
            initializeGooglePay: function (initData) {
                var self = this;
                var config = window.checkoutConfig.payment.cardlink_checkout_googlepay;

                if (typeof GooglePay === 'undefined') {
                    console.error('GooglePay class is not available after script load.');
                    return;
                }

                try {
                    var orderTotal = parseFloat(quote.totals()['grand_total']).toFixed(2);
                    var currencyCode = quote.totals()['quote_currency_code'] || 'EUR';
                    var xmlVersion = (initData.vposVersion || '2') + '.1';
                    var orderId = self.generateOrderId();

                    // googlepaydirect.js references a global `paymentResponse` variable in its
                    // success/fail branches (from a Web Payments API pattern) but never defines it.
                    // We provide a stub to prevent ReferenceError crashes and handle outcomes.
                    if (typeof window.paymentResponse === 'undefined') {
                        window.paymentResponse = {
                            complete: function (status) {
                                console.log('Google Pay paymentResponse.complete(' + status + ')');
                                if (status === 'success') {
                                    self.handleGooglePaySuccess();
                                } else {
                                    self.handleGooglePayFailure();
                                }
                            }
                        };
                    }

                    // IMPORTANT: googlepaydirect.js declares `let googlePay = null;` at the
                    // script top level. `let` creates a binding in the global lexical environment
                    // (NOT on `window`). We must assign via bare identifier so the script-scoped
                    // `let` variable is updated — `window.googlePay` would be a separate property.
                    googlePay = new GooglePay();
                    googlePay.initGooglePay(
                        xmlVersion,
                        config.walletUrl.replace(/\/+$/, ''),
                        orderId,
                        orderTotal,
                        currencyCode,
                        initData.mid,
                        self.threeDSHandler.bind(self)
                    );

                    googlePay.initGooglePayClient().then(function () {
                        console.log('Google Pay initialized successfully.');
                        self.googlePayReady(true);
                    }).catch(function (error) {
                        console.error('Error initializing Google Pay client:', error);
                    });
                } catch (error) {
                    console.error('Error initializing Google Pay:', error);
                }
            },

            /**
             * 3DS handler callback from Google Pay Direct.
             * Called when VPOS returns status "processing" with 3DS parameters.
             *
             * Flow:
             * 1. Fetch XID from /googlepaycreatexid
             * 2. Sign MPI form data via /googlepaysigndata
             * 3. Submit MPI form to the MPI URL for 3D-Secure authentication
             */
            threeDSHandler: function (resp) {
                var self = this;
                var config = window.checkoutConfig.payment.cardlink_checkout_googlepay;

                console.log('Google Pay 3DS handler called:', JSON.stringify(resp));

                var orderAmount = resp.orderAmount;
                var orderId = resp.orderId;
                var txId = resp.txId;
                var cardEncData = resp.cardEncData;
                var trExtId = resp.trExtId;
                var trMpiCounts = resp.trMpiCounts;

                if (!cardEncData) {
                    console.warn('3DS handler called without cardEncData');
                    self.handleGooglePayFailure();
                    return;
                }

                fullScreenLoader.startLoader();

                // Step 1: Get the XID
                $.ajax({
                    url: config.createXidUrl,
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        trId: txId,
                        trExtId: trExtId,
                        trMpiCounts: trMpiCounts
                    }),
                    dataType: 'text',
                    success: function (xid) {
                        if (!xid) {
                            console.error('XID generation failed');
                            fullScreenLoader.stopLoader();
                            self.handleGooglePayFailure();
                            return;
                        }

                        console.log('XID received:', xid);

                        var mid = config.googlePayGatewayMerchantId;
                        var mpiUrl = config.mpiUrl;
                        var description = 'QUOTE ' + quote.getQuoteId();

                        // Step 2: Sign the MPI form data
                        var signPayload = {
                            mpiVersion: '4.0',
                            pan: '',
                            expiry: '',
                            cardEncData: cardEncData,
                            devCat: '0',
                            purchAmount: orderAmount,
                            exponent: '2',
                            description: description,
                            currMpi: '978',
                            merchantID: mid,
                            xidb64: xid,
                            okUrl: config.threeDsSuccessUrl,
                            failUrl: config.threeDsFailureUrl,
                            recurFreq: null,
                            recurEnd: null,
                            installments: null
                        };

                        $.ajax({
                            url: config.signDataUrl,
                            type: 'POST',
                            contentType: 'application/json',
                            data: JSON.stringify(signPayload),
                            dataType: 'json',
                            success: function (signResp) {
                                if (!signResp.signature || signResp.signature.indexOf('Error') === 0) {
                                    console.error('Invalid signature:', signResp);
                                    fullScreenLoader.stopLoader();
                                    self.handleGooglePayFailure();
                                    return;
                                }

                                console.log('Signature received:', signResp.signature);
                                console.log('Formatted Purchase Amount:', signResp.purchaseAmountFormatted);

                                // Step 3: Build and submit the MPI form
                                var form = document.createElement('form');
                                form.action = mpiUrl;
                                form.method = 'POST';
                                form.name = 'VEReqForm';
                                form.id = 'VEReqForm1';

                                var addField = function (name, value) {
                                    if (value == null || value === '') return;
                                    var input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = name;
                                    input.value = value;
                                    form.appendChild(input);
                                };

                                var actualMpiVersion = signResp.mpiVersion || signPayload.mpiVersion;
                                // MPI v2.x expects field "digest"; v4.x expects "signature"
                                var sigFieldName = (actualMpiVersion.charAt(0) === '2') ? 'digest' : 'signature';

                                addField('version', actualMpiVersion);
                                addField('cardEncData', signPayload.cardEncData);
                                addField('deviceCategory', signPayload.devCat);
                                addField('purchAmount', signResp.purchaseAmountFormatted);
                                addField('exponent', signPayload.exponent);
                                addField('description', signPayload.description);
                                addField('currency', signPayload.currMpi);
                                addField('merchantID', signPayload.merchantID);
                                addField('xid', signPayload.xidb64);
                                addField('okUrl', signPayload.okUrl);
                                addField('failUrl', signPayload.failUrl);
                                addField(sigFieldName, signResp.signature);

                                document.body.appendChild(form);

                                if (config.checkoutInIFrame) {
                                    // Open 3DS in iframe overlay
                                    self.openThreeDSInIFrame();
                                    form.target = 'cardlink_checkout_googlepay_3ds_iframe';
                                } else {
                                    // Full-page redirect to 3DS
                                    fullScreenLoader.startLoader();
                                }
                                form.submit();
                            },
                            error: function (xhr, status, error) {
                                console.error('Failed to sign MPI data:', error);
                                fullScreenLoader.stopLoader();
                                self.handleGooglePayFailure();
                            }
                        });
                    },
                    error: function (xhr, status, error) {
                        console.error('Failed to get XID:', error);
                        fullScreenLoader.stopLoader();
                        self.handleGooglePayFailure();
                    }
                });
            },

            /**
             * Show the 3DS modal and display the iframe.
             */
            openThreeDSInIFrame: function () {
                var modal = document.getElementById('cardlink_checkout_googlepay--modal');
                if (modal) {
                    modal.style.display = 'block';
                }
                fullScreenLoader.stopLoader();
            },

            /**
             * Hide the 3DS modal and clear the iframe.
             */
            closeThreeDSIFrame: function () {
                var modal = document.getElementById('cardlink_checkout_googlepay--modal');
                if (modal) {
                    modal.style.display = 'none';
                }
                var iframe = document.getElementById('cardlink_checkout_googlepay--modal-iframe');
                if (iframe) {
                    iframe.src = '';
                }
            },

            /**
             * Handle successful Google Pay payment
             */
            handleGooglePaySuccess: function () {
                var self = this;

                fullScreenLoader.startLoader();

                // Set the payment information and place the order
                setPaymentInformationAction(self.messageContainer, {
                    method: self.getData().method,
                    additional_data: self.getData().additional_data
                }).done(function () {
                    var redirectUrl = url.build('cardlink_checkout/payment/redirect');
                    window.location.replace(redirectUrl);
                }).fail(function () {
                    self.isPlaceOrderActionAllowed(true);
                    fullScreenLoader.stopLoader();
                });
            },

            /**
             * Handle failed Google Pay payment
             */
            handleGooglePayFailure: function () {
                var self = this;

                self.isPlaceOrderActionAllowed(true);
                fullScreenLoader.stopLoader();
                self.messageContainer.addErrorMessage({
                    message: 'Google Pay payment failed. Please try again or choose a different payment method.'
                });
            },

            /**
             * Generate an order ID for Google Pay using the quote ID.
             *
             * Matches the standard Cardlink format: {quoteId}x{3-char hash}
             * The suffix ensures uniqueness when retrying on the same quote.
             */
            generateOrderId: function () {
                var quoteId = quote.getQuoteId();
                var charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                var suffix = '';
                for (var i = 0; i < 3; i++) {
                    suffix += charset.charAt(Math.floor(Math.random() * charset.length));
                }
                return 'QUOTEx' + quoteId + 'x' + suffix;
            },

            /**
             * Called when the payment method is selected / rendered
             */
            selectPaymentMethod: function () {
                this._super();
                this.initGooglePayScript();
                return true;
            },

            placeOrder: function (data, event) {
                if (event) {
                    event.preventDefault();
                }

                var self = this;

                if (!self.validate() || !additionalValidators.validate()) {
                    return false;
                }

                self.isPlaceOrderActionAllowed(false);

                setPaymentInformationAction(self.messageContainer, {
                    method: self.getData().method,
                    additional_data: self.getData().additional_data
                }).done(function () {
                    var redirectUrl = url.build('cardlink_checkout/payment/redirect');
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
