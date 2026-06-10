const ph_settings = window.wc.wcSettings.getSetting('cmi_data', {});

const ph_label = window.wp.htmlEntities.decodeEntities(ph_settings.title) || window.wp.i18n.__('Cmi', 'cmi');

const PH_Content = () => {
    return window.wp.htmlEntities.decodeEntities(ph_settings.description || '');
};

const Icon = () => {
    return ph_settings.icon
        ? wp.element.createElement('img', { src: ph_settings.icon, style: { float: 'right', marginRight: '10px',marginLeft: '10px',height: '40px', maxHeight:'40px' } }) 
        : '';
};
const Label = () => {
    return wp.element.createElement('span', { style: { width: '100%',lineHeight:'2.5'} }, ph_label, wp.element.createElement(Icon));
};


const PH_Block_Gateway = {
    name: 'cmi',
    label: wp.element.createElement(Label),
    content: Object(window.wp.element.createElement)(PH_Content, null),
    edit: Object(window.wp.element.createElement)(PH_Content, null),
    canMakePayment: () => true,
    ariaLabel: ph_label,
    supports: {
        features: ph_settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(PH_Block_Gateway);