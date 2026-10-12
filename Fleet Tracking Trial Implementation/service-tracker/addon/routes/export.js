import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ExportRoute extends Route {
    @service('service-tracker-api') api;

    model() {
        return this.api.getSettings();
    }
}
