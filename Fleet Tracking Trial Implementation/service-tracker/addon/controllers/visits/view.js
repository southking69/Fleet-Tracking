import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { saveRecord } from '../../utils/record-save';
import { todayIso } from '../../utils/format';

/**
 * Match a server calc entry to its record. `calc.si[i]` / `calc.sinfo[i]` are aligned with `calc.stops[i]` / `calc.jobs[i]`
 * (the engine inputs, whose `id` is the record uuid, ordered created_at ascending), so the record is found by uuid in
 * `calc.stops` / `calc.jobs` and the info taken at that position. Falls back to the record's own index.
 */
function infoFor(infoList, sourceList, record, index) {
    if (!Array.isArray(infoList)) {
        return {};
    }
    const pos = Array.isArray(sourceList) ? sourceList.findIndex((x) => x && x.id === record.uuid) : -1;
    return infoList[pos >= 0 ? pos : index] ?? {};
}

export default class VisitsViewController extends Controller {
    @service store;
    @service hostRouter;
    @service notifications;
    @service intl;
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    @tracked visit;
    @tracked stops = [];
    @tracked jobs = [];
    @tracked damages = [];
    @tracked petty = [];

    setup({ visit, stops, jobs, damages, petty }) {
        this.visit = visit;
        this.stops = stops;
        this.jobs = jobs;
        this.damages = damages;
        this.petty = petty;
    }

    get settings() {
        return this.api.settings ?? {};
    }

    get calc() {
        return this.visit?.calc ?? {};
    }

    get title() {
        return [this.intl.t('service-tracker.visit.title', { id: this.visit.public_id }), this.visit.plate, this.visit.make_model].filter(Boolean).join(' · ');
    }

    get stopRows() {
        return this.stops.map((stop, index) => ({ stop, index, info: infoFor(this.calc.si, this.calc.stops, stop, index) }));
    }

    get jobRows() {
        return this.jobs.map((job, index) => ({ job, index, info: infoFor(this.calc.sinfo, this.calc.jobs, job, index) }));
    }

    get newDamageCount() {
        return this.damages.filter((d) => d.condition === 'New').length;
    }

    get visitOptions() {
        return [{ value: this.visit.uuid, label: this.visit.public_id }];
    }

    get technicianOptions() {
        return this.lookups.userOptions;
    }

    /** Re-fetch the visit so the server `calc` reflects the latest stop/job/damage edits. */
    @action async refreshCalc() {
        try {
            await this.visit.reload();
        } catch (e) {
            this.notifications.error(e.message);
        }
    }

    /* ---------- visit fields ---------- */

    @action async setField(field, value) {
        this.visit[field] = value;
        await saveRecord(this.visit, this.notifications);
    }

    @action setText(field, event) {
        return this.setField(field, event.target.value);
    }

    @action setNumber(field, event) {
        const raw = event.target.value;
        return this.setField(field, raw === '' ? null : Number(raw));
    }

    @action setCheck(field, event) {
        return this.setField(field, event.target.checked);
    }

    @action setPlate(event) {
        const plate = event.target.value.toUpperCase().trim();
        this.visit.plate = plate;
        const match = this.lookups.vehicles.find((v) => String(v.plate_number ?? '').toUpperCase() === plate);
        if (match) {
            this.visit.vehicle_uuid = match.uuid ?? match.id;
            if (!this.visit.make_model) {
                this.visit.make_model = [match.make, match.model].filter(Boolean).join(' ');
            }
        }
        return saveRecord(this.visit, this.notifications);
    }

    @action setTechnician(uuid) {
        const opt = this.technicianOptions.find((o) => o.value === uuid);
        this.visit.technician_uuid = uuid;
        this.visit.technician_name = opt ? opt.label : '';
        return saveRecord(this.visit, this.notifications);
    }

    @action async conditionPhotosChanged() {
        await this.refreshCalc();
    }

    get pickupSubject() {
        return { type: 'service_visit', uuid: this.visit.uuid, collection: 'pickup_photos' };
    }

    get returnSubject() {
        return { type: 'service_visit', uuid: this.visit.uuid, collection: 'return_photos' };
    }

    /* ---------- vendor stops ---------- */

    @action async addStop() {
        const stop = this.store.createRecord('vendor-stop', { service_visit_uuid: this.visit.uuid });
        if (await saveRecord(stop, this.notifications)) {
            this.stops = [...this.stops, stop];
            await this.refreshCalc();
        } else {
            stop.unloadRecord();
        }
    }

    @action async deleteStop(stop) {
        if (!window.confirm(this.intl.t('service-tracker.visit.confirm-delete-stop'))) {
            return;
        }
        await stop.destroyRecord();
        this.stops = this.stops.filter((s) => s !== stop);
        await this.refreshCalc();
    }

    /* ---------- jobs ---------- */

    @action async addJob() {
        const job = this.store.createRecord('service-job', { service_visit_uuid: this.visit.uuid, status: 'Pending' });
        if (await saveRecord(job, this.notifications)) {
            this.jobs = [...this.jobs, job];
            await this.refreshCalc();
        } else {
            job.unloadRecord();
        }
    }

    @action async deleteJob(job) {
        if (!window.confirm(this.intl.t('service-tracker.visit.confirm-delete-job'))) {
            return;
        }
        await job.destroyRecord();
        this.jobs = this.jobs.filter((j) => j !== job);
        await this.refreshCalc();
    }

    /** Create a catalog item from the type-ahead ("+ Add as new service"); resolves to the saved record. */
    @action async createCatalogItem(name) {
        const standard = this.lookups.lanes.find((l) => /standard/i.test(l.name)) ?? this.lookups.lanes[0];
        const item = this.store.createRecord('service-catalog-item', { name, category: 'Other', lane_uuid: standard?.uuid, interval_km: 0 });
        if (await saveRecord(item, this.notifications)) {
            await this.lookups.reloadCatalog();
            return item;
        }
        item.unloadRecord();
        return null;
    }

    /* ---------- damage ---------- */

    @action async addDamage() {
        const record = this.store.createRecord('damage-record', {
            service_visit_uuid: this.visit.uuid,
            vehicle_uuid: this.visit.vehicle_uuid,
            plate: this.visit.plate,
            stage: this.settings.stages?.[0],
            occurred_at: new Date().toISOString(),
            recorded_by_uuid: this.visit.technician_uuid,
            recorded_by_name: this.visit.technician_name,
        });
        if (await saveRecord(record, this.notifications)) {
            this.damages = [...this.damages, record];
            await this.refreshCalc();
        } else {
            record.unloadRecord();
        }
    }

    @action async deleteDamage(record) {
        if (!window.confirm(this.intl.t('service-tracker.visit.confirm-delete-damage'))) {
            return;
        }
        await record.destroyRecord();
        this.damages = this.damages.filter((d) => d !== record);
        await this.refreshCalc();
    }

    /* ---------- petty cash ---------- */

    @action async addPetty() {
        if (!this.visit.plate) {
            this.notifications.warning(this.intl.t('service-tracker.visit.plate-first'));
            return;
        }
        const record = this.store.createRecord('petty-cash-entry', {
            entry_date: todayIso(),
            plate: this.visit.plate,
            vehicle_uuid: this.visit.vehicle_uuid,
            service_visit_uuid: this.visit.uuid,
            currency: this.settings.currency ?? 'SAR',
        });
        if (await saveRecord(record, this.notifications)) {
            this.petty = [...this.petty, record];
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

    /* ---------- navigation ---------- */

    @action async deleteVisit() {
        if (!window.confirm(this.intl.t('service-tracker.visit.confirm-delete', { id: this.visit.public_id }))) {
            return;
        }
        await this.visit.destroyRecord();
        this.hostRouter.transitionTo('console.service-tracker.visits');
    }

    @action carHistory() {
        this.hostRouter.transitionTo('console.service-tracker.vehicles.view', this.visit.plate);
    }

    @action clientReport() {
        this.hostRouter.transitionTo('console.service-tracker.report', { queryParams: { visit: this.visit.public_id } });
    }
}
