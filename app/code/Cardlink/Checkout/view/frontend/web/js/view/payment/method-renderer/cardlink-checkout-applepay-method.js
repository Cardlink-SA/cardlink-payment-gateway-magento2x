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
                template: 'Cardlink_Checkout/checkout-form-applepay',
                applePayScriptLoading: false,
                applePayScriptLoaded: false,
                applePayReady: false,
                applePayBrowserErrorHandlerAttached: false,
                applePayUnavailableNotified: false,
                _saleResponseHandled: false,
                _xhrInterceptorInstalled: false
            },

            initObservable: function () {
                this._super()
                    .observe([
                        'applePayReady'
                    ]);

                // If this method is already selected when the component renders,
                // selectPaymentMethod won't fire, so initialize proactively.
                var self = this;
                var checkSelected = setInterval(function () {
                    if (self.isChecked() === self.getCode()) {
                        clearInterval(checkSelected);
                        self.initApplePayScript();
                    }
                }, 500);

                return this;
            },

            getCode: function () {
                return 'cardlink_checkout_applepay';
            },

            getDescription: function () {
                return window.checkoutConfig.payment.cardlink_checkout_applepay.description;
            },

            canDisplayLogoInTitle: function () {
                return window.checkoutConfig.payment.cardlink_checkout_applepay.displayLogoInTitle;
            },

            getLogoUrl: function () {
                return window.checkoutConfig.payment.cardlink_checkout_applepay.logoUrl;
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
                var form = 'form[data-role=cardlink-checkout-applepay-options-form]';
                return $(form).validation() && $(form).validation('isValid');
            },

            redirectAfterPlaceOrder: false,

            /**
             * Detect whether Apple Pay can be used in this browser/runtime.
             *
             * @return {string|null} Message when unsupported, otherwise null
             */
            getApplePayEnvironmentIssue: function () {
                var userAgent = (window.navigator && window.navigator.userAgent)
                    ? window.navigator.userAgent.toLowerCase()
                    : '';
                var isAutomatedBrowser = !!(window.navigator && window.navigator.webdriver);
                var isBrowserStack = userAgent.indexOf('browserstack') !== -1
                    || typeof window.browserstack_executor === 'function'
                    || typeof window._bStack === 'object';

                if (isAutomatedBrowser || isBrowserStack) {
                    return 'Apple Pay is unavailable in this test environment. Browser automation/remote sessions may block the trusted user activation required by Apple Pay. Please test on a real Safari device with Apple Wallet configured.';
                }

                if (typeof window.ApplePaySession === 'undefined') {
                    return 'Apple Pay is not supported in this browser. Please use Safari on an Apple device.';
                }

                try {
                    if (typeof window.ApplePaySession.canMakePayments === 'function'
                        && !window.ApplePaySession.canMakePayments()) {
                        return 'Apple Pay is not available on this device/session. Please make sure Apple Wallet is set up and try again.';
                    }
                } catch (error) {
                    return 'Apple Pay could not be initialized in this browser session. Please try again on a real Safari device.';
                }

                return null;
            },

            /**
             * Attach one global browser error listener to convert user-activation
             * security errors into a clear checkout message.
             */
            attachApplePayBrowserErrorHandler: function () {
                var self = this;

                if (self.applePayBrowserErrorHandlerAttached) {
                    return;
                }

                window.addEventListener('error', function (event) {
                    var message = (event && event.message) ? event.message : '';

                    if (message.indexOf('show() must be triggered by user activation') !== -1) {
                        self.handleApplePayUnavailable(
                            'Apple Pay requires a direct user tap in Safari. Remote or automated test environments can block this browser security requirement. Please test on a real Apple device with Apple Wallet configured.'
                        );
                    }
                });

                self.applePayBrowserErrorHandlerAttached = true;
            },

            /**
             * Show a specific message when Apple Pay is unavailable in the
             * current browser/runtime environment.
             *
             * @param {string} message
             */
            handleApplePayUnavailable: function (message) {
                var self = this;

                self.applePayReady(false);
                self.applePayScriptLoading = false;
                self.isPlaceOrderActionAllowed(true);
                fullScreenLoader.stopLoader();

                self.renderApplePayUnavailableNotice(message);

                if (self.applePayUnavailableNotified) {
                    return;
                }

                self.applePayUnavailableNotified = true;
                self.messageContainer.addErrorMessage({
                    message: message || 'Apple Pay is unavailable in this environment. Please use a supported Safari device with Apple Wallet configured.'
                });
            },

            /**
             * Render an inline message in the Apple Pay area when the
             * environment does not support Apple Pay execution.
             *
             * @param {string} message
             */
            renderApplePayUnavailableNotice: function (message) {
                var container = document.getElementById('applePayDiv');

                if (!container) {
                    return;
                }

                container.innerHTML = '';

                var notice = document.createElement('div');
                notice.className = 'message error cardlink-applepay-unavailable';
                notice.textContent = message || 'Apple Pay is unavailable in this environment. Please use a supported Safari device with Apple Wallet configured.';

                container.appendChild(notice);
            },

            /**
             * Initialize the Apple Pay Direct script from Cardlink
             */
            initApplePayScript: function () {
                var self = this;
                var config = window.checkoutConfig.payment.cardlink_checkout_applepay;

                self.attachApplePayBrowserErrorHandler();

                var environmentIssue = self.getApplePayEnvironmentIssue();
                if (environmentIssue) {
                    self.handleApplePayUnavailable(environmentIssue);
                    return;
                }

                // Prevent double loading
                if (self.applePayScriptLoading || self.applePayScriptLoaded) {
                    return;
                }
                self.applePayScriptLoading = true;

                // First, get the script info (query string hash) from our backend
                $.ajax({
                    url: config.scriptInfoUrl,
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        mid: config.applePayGatewayMerchantId
                    }),
                    success: function (data) {
                        if (!data.success) {
                            console.error('Failed to get Apple Pay script info');
                            self.applePayScriptLoading = false;
                            return;
                        }

                        // Clear the container before loading a new script
                        var container = document.getElementById('applePayDiv');
                        if (container) {
                            container.innerHTML = '';
                        }

                        var scriptUrl = config.applePayDirectScriptUrl + data.queryString;

                        // Load the Cardlink applepaydirect.js script
                        var script = document.createElement('script');
                        script.type = 'text/javascript';
                        script.src = scriptUrl;

                        script.onload = function () {
                            console.log('Apple Pay Direct script loaded successfully.');
                            self.applePayScriptLoaded = true;
                            self.waitForContainerAndInit(data);
                        };

                        script.onerror = function () {
                            console.error('Failed to load Apple Pay Direct script.');
                            self.applePayScriptLoading = false;
                        };

                        document.body.appendChild(script);
                    },
                    error: function (xhr, status, error) {
                        console.error('Failed to fetch Apple Pay script info:', error);
                        self.applePayScriptLoading = false;
                    }
                });
            },

            /**
             * Wait for the #applePayDiv element to exist in the DOM before initializing.
             * Knockout may not have rendered the payment method content yet.
             */
            waitForContainerAndInit: function (initData) {
                var self = this;
                var container = document.getElementById('applePayDiv');

                if (container) {
                    self.initializeApplePay(initData);
                    return;
                }

                // Poll until the container is rendered by Knockout
                var attempts = 0;
                var maxAttempts = 20;
                var poll = setInterval(function () {
                    attempts++;
                    container = document.getElementById('applePayDiv');
                    if (container) {
                        clearInterval(poll);
                        self.initializeApplePay(initData);
                    } else if (attempts >= maxAttempts) {
                        clearInterval(poll);
                        console.error('Apple Pay container #applePayDiv not found in DOM after waiting.');
                    }
                }, 250);
            },

            /**
             * Initialize the Apple Pay class from the loaded script.
             *
             * The applepaydirect.js from Cardlink exposes an initApplePay() function:
             *   initApplePay(version, extId, total, currency, displayItemsLabel, totalLabel, mid, url)
             */
            initializeApplePay: function (initData) {
                var self = this;
                var config = window.checkoutConfig.payment.cardlink_checkout_applepay;

                if (typeof initApplePay === 'undefined') {
                    console.error('initApplePay function is not available after script load.');
                    return;
                }

                try {
                    var orderTotal = parseFloat(quote.totals()['grand_total']).toFixed(2);
                    var currencyCode = quote.totals()['quote_currency_code'] || 'EUR';
                    var xmlVersion = (initData.vposVersion || '2') + '.1';
                    var orderId = self.generateOrderId();

                    // Provide a stub paymentResponse to prevent ReferenceError
                    // in environments where applepaydirect.js references it globally.
                    if (typeof window.paymentResponse === 'undefined') {
                        window.paymentResponse = {
                            complete: function (status) {
                                console.log('Apple Pay paymentResponse.complete(' + status + ')');
                                if (status === 'success') {
                                    self.handleApplePaySuccess();
                                } else {
                                    self.handleApplePayFailure();
                                }
                            }
                        };
                    }

                    // Expose threeDSHandler globally so applepaydirect.js can invoke it
                    // when the VPOS returns "processing" status (3DS required).
                    window.threeDSHandler = function (resp) {
                        self.threeDSHandler(resp);
                    };

                    // Expose payment success/failure callbacks globally in case
                    // applepaydirect.js calls them by name.
                    window.applePayOnSuccess = function () { self.handleApplePaySuccess(); };
                    window.applePayOnFailure = function () { self.handleApplePayFailure(); };
                    window.applePayOnCancel = function () { self.handleApplePayCancelled(); };
                    window.applePayOnError = function () { self.handleApplePayFailure(); };

                    // Install an XHR/fetch interceptor to detect the /sale
                    // response directly — applepaydirect.js closes the Apple Pay
                    // sheet but does not call our callbacks on success/failure.
                    self._saleResponseHandled = false;
                    self.installSaleResponseInterceptor();

                    // Call the Cardlink Apple Pay Direct initialization function.
                    // Parameters: version, extId (orderId), total, currency,
                    //             displayItemsLabel, totalLabel, mid, url (wallet sale endpoint)
                    initApplePay(
                        xmlVersion,
                        orderId,
                        orderTotal,
                        currencyCode,
                        'Order Total',
                        'Total',
                        initData.mid,
                        config.walletUrl.replace(/\/+$/, '')
                    );

                    console.log('Apple Pay initialized successfully.');
                    self.applePayReady(true);
                } catch (error) {
                    console.error('Error initializing Apple Pay:', error);
                }
            },

            /**
             * 3DS handler callback from Apple Pay Direct.
             * Called when VPOS returns status "processing" with 3DS parameters.
             *
             * Flow:
             * 1. Fetch XID from /applepaycreatexid
             * 2. Sign MPI form data via /applepaysigndata
             * 3. Submit MPI form to the MPI URL for 3D-Secure authentication
             */
            threeDSHandler: function (resp) {
                var self = this;
                var config = window.checkoutConfig.payment.cardlink_checkout_applepay;

                console.log('Apple Pay 3DS handler called:', JSON.stringify(resp));

                var orderAmount = resp.orderAmount;
                var orderId = resp.orderId;
                var txId = resp.txId;
                var cardEncData = resp.cardEncData;
                var trExtId = resp.trExtId;
                var trMpiCounts = resp.trMpiCounts;

                if (!cardEncData) {
                    console.warn('3DS handler called without cardEncData');
                    self.handleApplePayFailure();
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
                            self.handleApplePayFailure();
                            return;
                        }

                        console.log('XID received:', xid);

                        var mid = config.applePayGatewayMerchantId;
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
                                    self.handleApplePayFailure();
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
                                    form.target = 'cardlink_checkout_applepay_3ds_iframe';
                                } else {
                                    // Full-page redirect to 3DS
                                    fullScreenLoader.startLoader();
                                }
                                form.submit();
                            },
                            error: function (xhr, status, error) {
                                console.error('Failed to sign MPI data:', error);
                                fullScreenLoader.stopLoader();
                                self.handleApplePayFailure();
                            }
                        });
                    },
                    error: function (xhr, status, error) {
                        console.error('Failed to get XID:', error);
                        fullScreenLoader.stopLoader();
                        self.handleApplePayFailure();
                    }
                });
            },

            /**
             * Show the 3DS modal and display the iframe.
             */
            openThreeDSInIFrame: function () {
                var modal = document.getElementById('cardlink_checkout_applepay--modal');
                if (modal) {
                    modal.style.display = 'block';
                }
                fullScreenLoader.stopLoader();
            },

            /**
             * Hide the 3DS modal and clear the iframe.
             */
            closeThreeDSIFrame: function () {
                var modal = document.getElementById('cardlink_checkout_applepay--modal');
                if (modal) {
                    modal.style.display = 'none';
                }
                var iframe = document.getElementById('cardlink_checkout_applepay--modal-iframe');
                if (iframe) {
                    iframe.src = '';
                }
            },

            /**
             * Intercept XMLHttpRequest and fetch calls to the wallet /sale
             * endpoint so we can detect the VPOS response directly.
             *
             * applepaydirect.js makes the AJAX call internally and only closes
             * the Apple Pay sheet on success — it does NOT invoke
             * paymentResponse.complete() or any global callback.  This
             * interceptor bridges that gap.
             */
            installSaleResponseInterceptor: function () {
                var self = this;

                if (self._xhrInterceptorInstalled) {
                    return;
                }
                self._xhrInterceptorInstalled = true;

                var config = window.checkoutConfig.payment.cardlink_checkout_applepay;
                var walletUrl = (config.walletUrl || '').replace(/\/+$/, '');

                function isSaleUrl(url) {
                    return typeof url === 'string'
                        && url.indexOf(walletUrl) !== -1
                        && url.indexOf('sale') !== -1;
                }

                // ── XMLHttpRequest interceptor ──────────────────────────
                var origOpen = XMLHttpRequest.prototype.open;
                var origSend = XMLHttpRequest.prototype.send;

                XMLHttpRequest.prototype.open = function (method, url) {
                    if (method && method.toUpperCase() === 'POST' && isSaleUrl(url)) {
                        this._isApplePaySale = true;
                    }
                    return origOpen.apply(this, arguments);
                };

                XMLHttpRequest.prototype.send = function () {
                    if (this._isApplePaySale) {
                        var xhr = this;
                        xhr.addEventListener('load', function () {
                            self.onSaleResponse(xhr.responseText);
                        });
                    }
                    return origSend.apply(this, arguments);
                };

                // ── fetch() interceptor ─────────────────────────────────
                if (typeof window.fetch === 'function') {
                    var origFetch = window.fetch;
                    window.fetch = function (input, init) {
                        var fetchUrl = typeof input === 'string'
                            ? input
                            : (input && input.url ? input.url : '');
                        var fetchMethod = (init && init.method)
                            ? init.method.toUpperCase()
                            : 'GET';
                        var isApplePaySale = fetchMethod === 'POST' && isSaleUrl(fetchUrl);

                        var promise = origFetch.apply(this, arguments);

                        if (isApplePaySale) {
                            promise.then(function (response) {
                                // Clone so the original consumer can still read the body
                                response.clone().text().then(function (body) {
                                    self.onSaleResponse(body);
                                });
                            });
                        }

                        return promise;
                    };
                }
            },

            /**
             * Called when the wallet /sale XHR/fetch response is received.
             * Dispatches to the appropriate handler based on status.
             */
            onSaleResponse: function (responseText) {
                if (this._saleResponseHandled) {
                    return;
                }

                try {
                    var resp = JSON.parse(responseText);
                } catch (e) {
                    console.error('ApplePay: Failed to parse /sale response:', e);
                    return;
                }

                if (resp.status === 'success') {
                    this._saleResponseHandled = true;
                    this.handleApplePaySuccess();
                } else if (resp.status === 'fail') {
                    this._saleResponseHandled = true;
                    this.handleApplePayFailure(resp.error);
                }
                // 'processing' → 3DS flow, handled by threeDSHandler
            },

            /**
             * Handle successful Apple Pay payment.
             *
             * The VPOS payment and Magento order creation are already complete
             * at this point (handled by the /sale backend endpoint).
             * We just redirect to the checkout success page.
             */
            handleApplePaySuccess: function () {
                var self = this;

                self._saleResponseHandled = true;

                fullScreenLoader.startLoader();
                window.location.replace(url.build('checkout/onepage/success'));
            },

            /**
             * Handle failed Apple Pay payment
             *
             * @param {string} [errorMessage] Optional error message from the VPOS
             */
            handleApplePayFailure: function (errorMessage) {
                var self = this;

                self._saleResponseHandled = true;

                self.isPlaceOrderActionAllowed(true);
                fullScreenLoader.stopLoader();
                self.messageContainer.addErrorMessage({
                    message: errorMessage || 'Apple Pay payment failed. Please try again or choose a different payment method.'
                });
            },

            /**
             * Handle Apple Pay sheet cancellation/early close.
             */
            handleApplePayCancelled: function () {
                var self = this;

                self.isPlaceOrderActionAllowed(true);
                fullScreenLoader.stopLoader();

                self.messageContainer.addErrorMessage({
                    message: 'Apple Pay was cancelled or closed. This usually means no Apple account/card is configured in Wallet for the device.'
                });
            },

            /**
             * Generate an order ID for Apple Pay using the quote ID.
             *
             * Matches the standard Cardlink format: {quoteId}x{3-char hash}
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
                this.initApplePayScript();
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
