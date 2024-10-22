var config = {
    paths: {
        'require_multiple_card_fields': 'Cardlink_Checkout/js/require_multiple_card_fields',
        'require_multiple_iris_fields': 'Cardlink_Checkout/js/require_multiple_iris_fields',
    },
    shim: {
        'require_multiple_card_fields': {
            deps: ['jquery', 'mage/adminhtml/form']
        },
        'require_multiple_iris_fields': {
            deps: ['jquery', 'mage/adminhtml/form']
        }
    }
}
