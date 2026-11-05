// CMI payment method for WooCommerce's Checkout Blocks. Wrapped in a function so its names
// can't clash with other scripts on the page.
(function () {
    const settings = window.wc.wcSettings.getSetting('cmi_data', {});
    const label = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('Cmi', 'cmi');

    const Content = () => {
        return window.wp.htmlEntities.decodeEntities(settings.description || '');
    };

    const Icon = () => {
        return settings.icon
            ? window.wp.element.createElement('img', { src: settings.icon, style: { float: 'right', marginRight: '10px', marginLeft: '10px', height: '40px', maxHeight: '40px' } })
            : '';
    };

    const Label = () => {
        return window.wp.element.createElement('span', { style: { width: '100%', lineHeight: '2.5' } }, label, window.wp.element.createElement(Icon));
    };

    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'cmi',
        label: window.wp.element.createElement(Label),
        content: window.wp.element.createElement(Content, null),
        edit: window.wp.element.createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings.supports,
        },
    });
})();
