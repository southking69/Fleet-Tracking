/**
 * Registers the Service Tracker entry in the Fleetbase console navigation.
 * Called by the console's extension loader for every installed `fleetbase-extension`.
 */
export default {
    setupExtension(app, universe) {
        const menuService = universe.getService('menu');

        menuService.registerHeaderMenuItem('Service Tracker', 'console.service-tracker', {
            icon: 'screwdriver-wrench',
            priority: 6,
            id: 'service-tracker',
        });
    },
};
