require([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    var sections = ['cart'];
    customerData.invalidate(sections);
    customerData.reload(sections, true);
});