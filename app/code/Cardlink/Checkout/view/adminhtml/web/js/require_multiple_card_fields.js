define('Cardlink_Checkout/js/require_multiple_card_fields', [
    'jquery',
    'mage/adminhtml/form'
], function ($) {
    'use strict';

    return function (config) {
        var paymentMethodActive = $('#payment_other_cardlink_checkout_active');

        // Array of field IDs to validate conditionally
        var fieldsToValidate = [
            '#payment_other_cardlink_checkout_merchant_id',
            '#payment_other_cardlink_checkout_shared_secret'
        ];

        // Function to toggle required validation based on active status
        function toggleRequired() {
            var isActive = paymentMethodActive.val() == 1;

            fieldsToValidate.forEach(function (fieldSelector) {
                var field = $(fieldSelector);
                if (isActive) {
                    field.addClass('required-entry'); // Add required validation
                    field.closest('tr').find('label').append('<span class="required">*</span>');
                } else {
                    field.removeClass('required-entry'); // Remove required validation
                    field.closest('tr').find('.required .required').remove(); // Remove the asterisk
                }
            });
        }

        // Initial check when page loads
        toggleRequired();

        // Recheck when the payment method active status changes
        paymentMethodActive.change(function () {
            toggleRequired();
        });
    };
});
