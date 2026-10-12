import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class VisitsViewRoute extends Route {
    @service store;
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    async model({ public_id }) {
        // the URL carries the public id while record ids are uuids: look it up with queryRecord (GET visits?public_id=..&single=1)
        const visit = await this.store.queryRecord('service-visit', { public_id, single: true });
        const scope = { service_visit_uuid: visit.uuid, limit: 200, sort: 'created_at' };
        const [stops, jobs, damages, petty] = await Promise.all([
            this.store.query('vendor-stop', scope),
            this.store.query('service-job', scope),
            this.store.query('damage-record', scope),
            this.store.query('petty-cash-entry', scope),
        ]);
        return { visit, stops: stops.toArray(), jobs: jobs.toArray(), damages: damages.toArray(), petty: petty.toArray() };
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.setup(model);
    }
}
