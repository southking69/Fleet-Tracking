import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';
import { inject as service } from '@ember/service';
import { fmtDate } from '../utils/format';

const TOGGLES = ['turnaround', 'vendorTimes', 'overdue', 'petty', 'photos', 'condPhotos'];

export default class ReportController extends Controller {
    @service store;
    @service notifications;
    @service intl;
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    queryParams = ['visit'];

    @tracked visit = null; // public_id
    @tracked pdfUrl = null;
    @tracked loading = false;
    @tracked error = null;
    @tracked damages = [];

    pdfBlob = null;

    get settings() {
        return this.api.settings ?? {};
    }

    get opts() {
        return this.settings.reportOpts ?? {};
    }

    get toggles() {
        return TOGGLES.map((key) => ({ key, checked: !!this.opts[key], label: `service-tracker.report.toggle.${key}` }));
    }

    get visitOptions() {
        return (this.model?.toArray?.() ?? []).map((v) => ({ value: v.public_id, label: `${v.public_id} · ${v.plate || this.intl.t('service-tracker.report.no-plate')} · ${fmtDate(v.arrived_at_lsa)}` }));
    }

    get selectedVisit() {
        return (this.model?.toArray?.() ?? []).find((v) => v.public_id === this.visit) ?? null;
    }

    setup() {
        if (!this.selectedVisit) {
            this.visit = this.model?.firstObject?.public_id ?? null;
        }
        this.refresh();
    }

    teardown() {
        this.revoke();
    }

    revoke() {
        if (this.pdfUrl) {
            URL.revokeObjectURL(this.pdfUrl);
        }
        this.pdfUrl = null;
        this.pdfBlob = null;
    }

    async refresh() {
        const visit = this.selectedVisit;
        if (!visit) {
            this.damages = [];
            this.revoke();
            return;
        }
        this.loading = true;
        this.error = null;
        try {
            const [damages, blob] = await Promise.all([this.store.query('damage-record', { service_visit_uuid: visit.uuid, limit: 100 }), this.api.visitPdf(visit.public_id, this.opts)]);
            this.damages = damages.toArray();
            this.revoke();
            this.pdfBlob = blob;
            this.pdfUrl = URL.createObjectURL(blob);
        } catch (e) {
            this.error = e.message;
        } finally {
            this.loading = false;
        }
    }

    @action selectVisit(publicId) {
        this.visit = publicId;
        this.refresh();
    }

    @action async toggle(key, event) {
        const reportOpts = { ...this.opts, [key]: event.target.checked };
        try {
            await this.api.saveSettings({ ...this.settings, reportOpts });
        } catch (e) {
            this.notifications.error(e.message);
        }
        debounce(this, this.refresh, 400);
    }

    @action photosChanged() {
        this.refresh();
    }

    @action download() {
        if (this.pdfBlob) {
            this.api.saveBlob(this.pdfBlob, `${this.visit}_report.pdf`);
        }
    }

    @action print() {
        const frame = document.getElementById('st-report-frame');
        frame?.contentWindow?.print();
    }
}
