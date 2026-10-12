import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { getOwner } from '@ember/application';
import { camelizeKeys } from '../utils/case';
import { NAMESPACE } from '../utils/resources';

const INT = 'int/v1';

/** Ember subject (model + collection) -> the server's upload `subject` value. */
function uploadSubject({ type, collection }) {
    switch (type) {
        case 'damage_record':
            return 'damage';
        case 'petty_cash_entry':
            return 'petty_cash';
        case 'vendor_stop':
            return 'stop';
        case 'service_visit':
            return collection === 'return_photos' ? 'visit_return' : 'visit_pickup';
        default:
            return type;
    }
}

function clientUuid() {
    return window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/**
 * Thin client for the non-model Service Tracker endpoints (dashboard, vehicles due list, settings,
 * PDF/XLSX downloads, photo uploads). JSON calls go through the console `fetch` service;
 * binary calls use the browser fetch with the session token.
 */
export default class ServiceTrackerApiService extends Service {
    @service fetch;
    @service session;
    @service notifications;
    @service intl;

    /** Cached settings document (see getSettings). */
    @tracked settings = null;

    /* ---------- JSON ---------- */

    getJson(path, query = {}, { raw = false } = {}) {
        return this.fetch.get(`${NAMESPACE}/${path}`, query, { namespace: INT }).then((r) => (raw ? r : camelizeKeys(r)));
    }

    postJson(path, body = {}, { raw = false } = {}) {
        return this.fetch.post(`${NAMESPACE}/${path}`, body, { namespace: INT }).then((r) => (raw ? r : camelizeKeys(r)));
    }

    putJson(path, body = {}, { raw = false } = {}) {
        return this.fetch.put(`${NAMESPACE}/${path}`, body, { namespace: INT }).then((r) => (raw ? r : camelizeKeys(r)));
    }

    deleteJson(path, query = {}) {
        return this.fetch.delete(`${NAMESPACE}/${path}`, query, { namespace: INT });
    }

    /* ---------- settings ---------- */

    /**
     * Settings document (`service-tracker.settings`): company, client, currency, reportOpts{...}, dropdown lists.
     * Keys are used as stored (camelCase), never transformed.
     */
    async getSettings({ force = false } = {}) {
        if (this.settings && !force) {
            return this.settings;
        }
        const res = await this.getJson('settings', {}, { raw: true });
        this.settings = this.withDefaults(res.settings ?? res);
        return this.settings;
    }

    async saveSettings(settings) {
        const res = await this.putJson('settings', { settings }, { raw: true });
        this.settings = this.withDefaults(res.settings ?? settings);
        return this.settings;
    }

    withDefaults(s = {}) {
        return {
            company: '',
            client: '',
            currency: 'SAR',
            categories: [],
            damageLocations: [],
            damageTypes: [],
            severities: ['Minor', 'Moderate', 'Major'],
            stages: ['Pickup inspection', 'Return inspection', 'Vendor reported'],
            svcStatuses: ['Pending', 'In progress', 'Done'],
            expenseCats: [],
            excelTiming: true,
            excelPetty: false,
            excelVehicles: true,
            ...s,
            reportOpts: { turnaround: true, vendorTimes: true, photos: true, overdue: true, condPhotos: false, petty: true, ...(s.reportOpts || {}) },
        };
    }

    /* ---------- aggregates ---------- */

    dashboard({ from, to } = {}) {
        return this.getJson('dashboard', { from, to });
    }

    vehiclesDue(query = {}) {
        return this.getJson('vehicles/due', query);
    }

    /** Same list endpoint filtered to one plate: the single row also carries `history`. */
    vehicleDetail(plate) {
        return this.getJson('vehicles/due', { plate });
    }

    /* ---------- binary ---------- */

    get baseUrl() {
        const env = getOwner(this).resolveRegistration('config:environment') || {};
        const host = this.fetch.host ?? this.fetch.apiHost ?? env.API?.host ?? '';
        return `${String(host).replace(/\/$/, '')}/${INT}/${NAMESPACE}/`;
    }

    get authHeaders() {
        const token = this.session?.data?.authenticated?.token;
        const headers = { Accept: '*/*' };
        if (token) {
            headers.Authorization = `Bearer ${token}`;
        }
        return headers;
    }

    async blob(path, query = {}) {
        const qs = new URLSearchParams();
        Object.entries(query).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') {
                qs.set(k, typeof v === 'boolean' ? (v ? '1' : '0') : v);
            }
        });
        const url = `${this.baseUrl}${path}${qs.toString() ? `?${qs}` : ''}`;
        const res = await window.fetch(url, { headers: this.authHeaders, credentials: 'include' });
        if (!res.ok) {
            throw new Error(`${res.status} ${res.statusText}`);
        }
        return res.blob();
    }

    /** Visit client report as a PDF Blob. `opts` = the six reportOpts toggles. */
    visitPdf(visitId, opts = {}) {
        // query keys are the server's reportOpts names (camelCase): turnaround, vendorTimes, photos, overdue, condPhotos, petty
        return this.blob(`reports/visit/${encodeURIComponent(visitId)}.pdf`, {
            turnaround: opts.turnaround,
            vendorTimes: opts.vendorTimes,
            photos: opts.photos,
            overdue: opts.overdue,
            condPhotos: opts.condPhotos,
            petty: opts.petty,
        });
    }

    exportFull() {
        return this.blob('exports/full.xlsx');
    }

    exportPeriod({ from, to, basis = 'arrived', timing = true, petty = false, vehicles = true, only } = {}) {
        return this.blob('exports/period.xlsx', { from, to, basis, timing, petty, vehicles, only });
    }

    /** Trigger a browser download for a Blob. */
    saveBlob(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    }

    /* ---------- photos ---------- */

    /**
     * Upload already-resized JPEG files for a record. `subject` = { type: 'damage_record'|'petty_cash_entry'|'service_visit', uuid, collection }.
     * Posted with the server's canonical multipart fields: `subject` (visit_pickup|visit_return|damage|petty_cash|stop),
     * `subject_uuid`, `photos[]`, plus a `client_uuid` so a retried upload is not stored twice.
     * Resolves to the stored file list [{ uuid, url, original_filename }].
     */
    async uploadPhotos(subject, files) {
        const body = new FormData();
        body.append('subject', uploadSubject(subject));
        body.append('subject_uuid', subject.uuid);
        body.append('client_uuid', clientUuid());
        files.forEach((f) => body.append('photos[]', f, f.name));
        const res = await window.fetch(`${this.baseUrl}uploads`, { method: 'POST', body, headers: this.authHeaders, credentials: 'include' });
        if (!res.ok) {
            throw new Error(`${res.status} ${res.statusText}`);
        }
        const json = camelizeKeys(await res.json());
        return json.files ?? json.photos ?? [];
    }

    deletePhoto(fileUuid) {
        return this.deleteJson(`uploads/${fileUuid}`);
    }
}
