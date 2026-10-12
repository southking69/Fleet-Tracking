import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

export default class SettingsRoute extends Route {
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    async model() {
        const [settings] = await Promise.all([this.api.getSettings({ force: true }), this.lookups.reloadLanes()]);
        return settings;
    }
}
