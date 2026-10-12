import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class VehiclesViewRoute extends Route {
    @service store;
    @service('service-tracker-api') api;

    async model({ plate }) {
        const [detail, visits, damages, petty] = await Promise.all([
            this.api.vehicleDetail(plate),
            this.store.query('service-visit', { plate, limit: 200, sort: '-created_at' }),
            this.store.query('damage-record', { plate, limit: 200, sort: '-created_at' }),
            this.store.query('petty-cash-entry', { plate, limit: 200, sort: '-entry_date' }),
        ]);
        const row = (detail.vehicleDue ?? [])[0] ?? {};
        return {
            plate,
            vehicle: row,
            due: row.due ?? [],
            history: row.history ?? [],
            visits: visits.toArray(),
            damages: damages.toArray(),
            petty: petty.toArray(),
        };
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.petty = model.petty;
    }
}
