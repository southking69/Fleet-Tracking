'use strict';

const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');

module.exports = buildEngine({
    name,

    lazyLoading: {
        enabled: true,
    },

    isDevelopingAddon() {
        return false;
    },

    // The engine ships its own translations/ directory (en-us, ar-ae); ember-intl in the console host merges them.
    treeForPublic() {
        return undefined;
    },
});
