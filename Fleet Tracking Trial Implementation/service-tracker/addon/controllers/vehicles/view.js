import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { saveRecord } from '../../utils/record-save';
import { todayIso } from '../../utils/format';

export default class VehiclesViewController extends Controller {
    @service hostRouter;
    @service store;
    @service notifications;
    @service intl;
    @service('service-tracker-api') api;

    @tracked petty = [];

    get settings() {
        return this.api.settings ?? {};
    }

    get title() {
        const v = this.model.vehicle;
        return [v.plate ?? this.model.plate, v.makeModel].filter(Boolean).join(' · ');
    }

    get visitOptions() {
        return this.model.visits.map((v) => ({ value: v.uuid, label: `${v.public_id}` }));
    }

    @action openVisit(visit) {
        this.hostRouter.transitionTo('console.service-tracker.visits.view', visit.public_id ?? visit.visitId ?? visit);
    }

    @action openVisitId(id) {
        this.hostRouter.transitionTo('console.service-tracker.visits.view', id);
    }

    @action async addPetty() {
        const record = this.store.createRecord('petty-cash-entry', { entry_date: todayIso(), plate: this.model.vehicle.plate ?? this.model.plate, currency: this.settings.currency ?? 'SAR' });
        if (await saveRecord(record, this.notifications)) {
            this.petty = [record, ...this.petty];
        } else {
            record.unloadRecord();
        }
    }

    @action async deletePetty(record) {
        if (!window.confirm(this.intl.t('service-tracker.petty.confirm-delete', { id: record.public_id }))) {
            return;
        }
        await record.destroyRecord();
        this.petty = this.petty.filter((p) => p !== record);
    }
}
