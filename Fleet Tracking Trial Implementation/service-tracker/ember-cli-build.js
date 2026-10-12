'use strict';

const EmberAddon = require('ember-cli/lib/broccoli/ember-addon');

module.exports = function (defaults) {
    const app = new EmberAddon(defaults, {
        // Add options here
        'ember-cli-babel': {
            includePolyfill: false,
        },
        autoImport: {
            watchDependencies: ['@fleetbase/ember-core', '@fleetbase/ember-ui'],
        },
    });

    return app.toTree();
};
