import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';
import { inject as service } from '@ember/service';
import { saveRecord } from '../../utils/record-save';

export default class VisitsIndexController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;

    queryParams = ['q', 'status', 'page'];

    @tracked q = '';
    @tracked status = '';
    @tracked page = 1;

    statuses = ['Not started', 'In progress', 'Closed'];

    get meta() {
        return this.model?.meta ?? {};
    }

    get hasPrev() {
        return Number(this.page) > 1;
    }

    get hasNext() {
        const m = this.meta;
        return m.last_page ? Number(this.page) < Number(m.last_page) : false;
    }

    @action search(event) {
        const value = event.target.value;
        debounce(this, this.applySearch, value, 300);
    }

    applySearch(value) {
        this.page = 1;
        this.q = value;
    }

    @action setStatus(value) {
        this.page = 1;
        this.status = value ?? '';
    }

    @action prev() {
        this.page = Math.max(1, Number(this.page) - 1);
    }

    @action next() {
        this.page = Number(this.page) + 1;
    }

    @action open(visit) {
        return this.hostRouter.transitionTo('console.service-tracker.visits.view', visit.public_id);
    }

    @action async newVisit() {
        const visit = this.store.createRecord('service-visit', { pickup_inspected: false, return_inspected: false });
        if (await saveRecord(visit, this.notifications)) {
            this.hostRouter.transitionTo('console.service-tracker.visits.view', visit.public_id);
        } else {
            visit.unloadRecord();
        }
    }
}
