import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class CatalogRoute extends Route {
    @service store;
    @service('service-tracker-lookups') lookups;

    async model() {
        await this.lookups.load();
        return this.store.query('service-catalog-item', { limit: 1000, sort: 'name' });
    }
}
