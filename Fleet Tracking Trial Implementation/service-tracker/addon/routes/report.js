import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class ReportRoute extends Route {
    @service store;

    queryParams = { visit: { refreshModel: false } };

    model() {
        return this.store.query('service-visit', { limit: 200, sort: '-created_at' });
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.setup();
    }

    resetController(controller, isExiting) {
        if (isExiting) {
            controller.teardown();
        }
    }
}
