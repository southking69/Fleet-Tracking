import Controller from '@ember/controller';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { saveRecord } from '../utils/record-save';

const LISTS = [
    ['categories', 'service-tracker.settings.list.categories'],
    ['damageLocations', 'service-tracker.settings.list.damage-locations'],
    ['damageTypes', 'service-tracker.settings.list.damage-types'],
    ['severities', 'service-tracker.settings.list.severities'],
    ['stages', 'service-tracker.settings.list.stages'],
    ['svcStatuses', 'service-tracker.settings.list.job-statuses'],
    ['expenseCats', 'service-tracker.settings.list.expense-cats'],
];

export default class SettingsController extends Controller {
    @service store;
    @service notifications;
    @service intl;
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    get settings() {
        return this.api.settings ?? this.model ?? {};
    }

    get lists() {
        return LISTS.map(([key, label]) => ({ key, label, text: (this.settings[key] ?? []).join('\n') }));
    }

    async persist(patch) {
        try {
            await this.api.saveSettings({ ...this.settings, ...patch });
            this.notifications.success(this.intl.t('service-tracker.settings.saved'));
        } catch (e) {
            this.notifications.error(e.message);
        }
    }

    @action setText(field, event) {
        return this.persist({ [field]: event.target.value.trim() });
    }

    @action setCurrency(event) {
        return this.persist({ currency: event.target.value.trim().toUpperCase() || 'SAR' });
    }

    @action setList(key, event) {
        const items = event.target.value
            .split('\n')
            .map((x) => x.trim())
            .filter(Boolean);
        return this.persist({ [key]: items });
    }

    /* ---------- lanes (Ember Data) ---------- */

    @action async setLaneField(lane, field, event) {
        lane[field] = field === 'target_hours' || field === 'sort' ? Number(event.target.value) : event.target.value.trim();
        await saveRecord(lane, this.notifications);
        this.lookups.reloadLanes();
    }

    @action async addLane() {
        const lane = this.store.createRecord('service-lane', { name: this.intl.t('service-tracker.settings.new-lane'), target_hours: 24, sort: this.lookups.lanes.length + 1 });
        if (await saveRecord(lane, this.notifications)) {
            this.lookups.reloadLanes();
        } else {
            lane.unloadRecord();
        }
    }

    @action async deleteLane(lane) {
        if (!window.confirm(this.intl.t('service-tracker.settings.confirm-delete-lane', { name: lane.name }))) {
            return;
        }
        try {
            await lane.destroyRecord();
        } catch (e) {
            lane.rollbackAttributes();
            this.notifications.error(e.message);
        }
        this.lookups.reloadLanes();
    }
}
