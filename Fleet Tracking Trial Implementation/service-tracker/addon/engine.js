import Engine from '@ember/engine';
import loadInitializers from 'ember-load-initializers';
import Resolver from 'ember-resolver';
import config from './config/environment';
import { services, externalRoutes } from '@fleetbase/ember-core/exports';

const { modulePrefix } = config;

/**
 * Same wiring as the FleetOps engine: the host services and external routes shared with every Fleetbase engine come from
 * `@fleetbase/ember-core/exports` (router, store, session, fetch, notifications, intl, universe, hostRouter, ...).
 */
export default class ServiceTrackerEngine extends Engine {
    modulePrefix = modulePrefix;
    Resolver = Resolver;
    dependencies = {
        services,
        externalRoutes,
    };
}

loadInitializers(ServiceTrackerEngine, modulePrefix);
