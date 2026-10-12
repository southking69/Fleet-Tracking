import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';
import { inject as service } from '@ember/service';

export default class VehiclesIndexController extends Controller {
    @service hostRouter;
    @service('service-tracker-api') api;

    queryParams = ['q'];
    @tracked q = '';

    get currency() {
        return this.api.settings?.currency ?? 'SAR';
    }

    applyQuery(value) {
        this.q = value;
    }

    @action search(event) {
        debounce(this, this.applyQuery, event.target.value, 300);
    }

    @action open(vehicle) {
        this.hostRouter.transitionTo('console.service-tracker.vehicles.view', vehicle.plateKey ?? vehicle.plate);
    }
}
