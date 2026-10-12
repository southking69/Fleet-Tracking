'use strict';
const { name, fleetbase } = require('../package');

module.exports = function (environment) {
    const ENV = {
        modulePrefix: name,
        environment,
        mountedEngineRoutePrefix: getMountedEngineRoutePrefix(),
    };

    return ENV;
};

/** `console.<fleetbase.route>`: the route the console mounts this engine on (package.json `fleetbase.route`). */
function getMountedEngineRoutePrefix() {
    let mountedEngineRoutePrefix = 'service-tracker';
    if (fleetbase && typeof fleetbase.route === 'string') {
        mountedEngineRoutePrefix = fleetbase.route;
    }

    return `console.${mountedEngineRoutePrefix}`;
}
