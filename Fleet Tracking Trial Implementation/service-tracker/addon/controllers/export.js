import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { resolvePeriod } from '../utils/periods';
import { todayIso } from '../utils/format';

export default class ExportController extends Controller {
    @service notifications;
    @service intl;
    @service('service-tracker-api') api;

    @tracked from = resolvePeriod('thisMonth')[0];
    @tracked to = resolvePeriod('thisMonth')[1];
    @tracked basis = 'arrived';
    @tracked busy = false;

    get settings() {
        return this.api.settings ?? this.model ?? {};
    }

    get basisOptions() {
        return [
            { value: 'arrived', label: this.intl.t('service-tracker.export.basis-arrived') },
            { value: 'returned', label: this.intl.t('service-tracker.export.basis-returned') },
        ];
    }

    @action setPeriod({ from, to }) {
        this.from = from;
        this.to = to;
    }

    @action setBasis(value) {
        this.basis = value ?? 'arrived';
    }

    @action async setFlag(key, event) {
        try {
            await this.api.saveSettings({ ...this.settings, [key]: event.target.checked });
        } catch (e) {
            this.notifications.error(e.message);
        }
    }

    async run(fn, filename) {
        this.busy = true;
        try {
            this.api.saveBlob(await fn(), filename);
        } catch (e) {
            this.notifications.error(e.message);
        } finally {
            this.busy = false;
        }
    }

    @action downloadFull() {
        return this.run(() => this.api.exportFull(), `Fleet_Service_Tracker_ALL_${todayIso()}.xlsx`);
    }

    @action downloadPeriod() {
        const s = this.settings;
        return this.run(
            () => this.api.exportPeriod({ from: this.from, to: this.to, basis: this.basis, timing: s.excelTiming, petty: s.excelPetty, vehicles: s.excelVehicles }),
            `Service_Report_${this.from || 'start'}_to_${this.to || todayIso()}.xlsx`
        );
    }
}
