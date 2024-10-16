require([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    var sections = ['cart'];
    customerData.initStorage();
    customerData.init();
    customerData.invalidate(sections);
    customerData.reload(sections, true);
});