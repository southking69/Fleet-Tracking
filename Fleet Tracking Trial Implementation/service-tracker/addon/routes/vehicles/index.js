import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class VehiclesIndexRoute extends Route {
    @service('service-tracker-api') api;

    queryParams = { q: { refreshModel: true } };

    async model({ q }) {
        const res = await this.api.vehiclesDue({ q: q || undefined });
        return res.vehicleDue ?? [];
    }
}
