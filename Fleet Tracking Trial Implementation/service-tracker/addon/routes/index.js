import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class IndexRoute extends Route {
    @service hostRouter;

    redirect() {
        return this.hostRouter.transitionTo('console.service-tracker.visits');
    }
}
