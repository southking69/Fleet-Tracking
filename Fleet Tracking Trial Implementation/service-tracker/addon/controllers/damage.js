import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';
import { inject as service } from '@ember/service';

export default class DamageController extends Controller {
    @service hostRouter;
    @service notifications;
    @service intl;
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    queryParams = ['q', 'severity', 'condition', 'stage'];

    @tracked q = '';
    @tracked severity = '';
    @tracked condition = '';
    @tracked stage = '';
    @tracked selectedId = null;

    conditions = ['Pre-existing', 'New'];

    get settings() {
        return this.api.settings ?? {};
    }

    get rows() {
        return this.model?.toArray?.() ?? [];
    }

    get selected() {
        return this.rows.find((r) => r.id === this.selectedId) ?? null;
    }

    applyQuery(value) {
        this.q = value;
    }

    @action searchPlate(event) {
        debounce(this, this.applyQuery, event.target.value, 300);
    }

    @action setFilter(field, value) {
        this[field] = value ?? '';
    }

    @action select(record) {
        this.selectedId = record.id;
    }

    @action openVisit(record) {
        this.hostRouter.transitionTo('console.service-tracker.visits.view', record.service_visit_public_id);
    }

    @action async remove(record) {
        if (!window.confirm(this.intl.t('service-tracker.visit.confirm-delete-damage'))) {
            return;
        }
        await record.destroyRecord();
        this.selectedId = null;
    }
}
