'use strict';

module.exports = {
    extends: 'recommended',
    rules: {
        'no-curly-component-invocation': false,
        'no-implicit-this': { allow: ['t', 'page-title'] },
        'require-input-label': false,
        'no-inline-styles': false,
        'no-duplicate-landmark-elements': false,
        'no-invalid-interactive': false,
        'no-action': false,
        'no-positive-tabindex': false,
        'no-redundant-role': false,
        'require-button-type': false,
        'no-passed-in-event-handlers': false,
        'table-groups': false,
        'no-bare-strings': false,
        'no-autofocus-attribute': false,
        'link-href-attributes': false,
        'no-nested-interactive': false,
    },
};
