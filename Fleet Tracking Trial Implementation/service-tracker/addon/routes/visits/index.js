import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class VisitsIndexRoute extends Route {
    @service store;

    queryParams = {
        q: { refreshModel: true },
        status: { refreshModel: true },
        page: { refreshModel: true },
    };

    model({ q, status, page }) {
        return this.store.query('service-visit', { q: q || undefined, status: status || undefined, page, limit: 30, sort: '-created_at' });
    }
}
