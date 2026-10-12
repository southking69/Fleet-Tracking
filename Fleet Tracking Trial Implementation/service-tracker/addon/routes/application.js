import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/** Loads settings + FleetOps reference data once for the whole engine. */
export default class ApplicationRoute extends Route {
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    async model() {
        await Promise.all([this.api.getSettings(), this.lookups.load()]);
        return null;
    }
}
