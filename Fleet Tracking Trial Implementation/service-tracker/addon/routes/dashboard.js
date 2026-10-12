import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class DashboardRoute extends Route {
    @service('service-tracker-api') api;

    queryParams = {
        from: { refreshModel: true },
        to: { refreshModel: true },
    };

    model({ from, to }) {
        return this.api.dashboard({ from: from || undefined, to: to || undefined });
    }
}
