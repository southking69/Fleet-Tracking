import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class ApplicationController extends Controller {
    @service('service-tracker-lookups') lookups;

    tabs = [
        { route: 'visits', label: 'service-tracker.nav.visits' },
        { route: 'vehicles', label: 'service-tracker.nav.vehicles' },
        { route: 'petty-cash', label: 'service-tracker.nav.petty-cash' },
        { route: 'damage', label: 'service-tracker.nav.damage' },
        { route: 'dashboard', label: 'service-tracker.nav.dashboard' },
        { route: 'report', label: 'service-tracker.nav.report' },
        { route: 'catalog', label: 'service-tracker.nav.catalog' },
        { route: 'settings', label: 'service-tracker.nav.settings' },
        { route: 'export', label: 'service-tracker.nav.export' },
    ];
}
