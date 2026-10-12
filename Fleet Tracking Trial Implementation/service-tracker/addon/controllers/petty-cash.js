import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { debounce } from '@ember/runloop';
import { inject as service } from '@ember/service';
import { saveRecord } from '../utils/record-save';
import { resolvePeriod } from '../utils/periods';
import { todayIso, plateKey } from '../utils/format';

export default class PettyCashController extends Controller {
    @service store;
    @service notifications;
    @service intl;
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    queryParams = ['from', 'to', 'q', 'paid_by'];

    @tracked from = resolvePeriod('thisMonth')[0];
    @tracked to = resolvePeriod('thisMonth')[1];
    @tracked q = '';
    @tracked paid_by = '';
    @tracked selectedId = null;

    get settings() {
        return this.api.settings ?? {};
    }

    get rows() {
        return this.model?.toArray?.() ?? [];
    }

    get selected() {
        return this.rows.find((r) => r.id === this.selectedId) ?? null;
    }

    get paidByOptions() {
        return [...new Set(this.rows.map((r) => r.paid_by).filter(Boolean))];
    }

    /**
     * Totals of the rows in the current view. When the server sends `meta.summary`
     * ({ total, count, cars, byCategory: { name: minor } }) that is preferred.
     */
    get summary() {
        const s = this.model?.meta?.summary;
        if (s) {
            return { total: s.total, count: s.count, cars: s.cars, categories: Object.entries(s.byCategory ?? s.by_category ?? {}).sort((a, b) => b[1] - a[1]) };
        }
        const byCat = {};
        let total = 0;
        this.rows.forEach((r) => {
            const k = r.category || this.intl.t('service-tracker.petty.no-category');
            byCat[k] = (byCat[k] ?? 0) + (r.amount ?? 0);
            total += r.amount ?? 0;
        });
        return {
            total,
            count: this.rows.length,
            cars: new Set(this.rows.map((r) => plateKey(r.plate)).filter(Boolean)).size,
            categories: Object.entries(byCat).sort((a, b) => b[1] - a[1]),
        };
    }

    get topCategories() {
        return this.summary.categories.slice(0, 4).map(([name, total]) => ({ name, total }));
    }

    @action setPeriod({ from, to }) {
        this.from = from;
        this.to = to;
    }

    applyQuery(value) {
        this.q = value;
    }

    @action searchPlate(event) {
        debounce(this, this.applyQuery, event.target.value, 300);
    }

    @action setPaidBy(value) {
        this.paid_by = value ?? '';
    }

    @action showAll() {
        this.from = '';
        this.to = '';
        this.q = '';
        this.paid_by = '';
    }

    @action select(record) {
        this.selectedId = record.id;
    }

    @action async add() {
        const record = this.store.createRecord('petty-cash-entry', { entry_date: todayIso(), currency: this.settings.currency ?? 'SAR' });
        if (await saveRecord(record, this.notifications)) {
            this.selectedId = record.id;
            if (this.to && record.entry_date > this.to) {
                this.to = record.entry_date;
            }
        } else {
            record.unloadRecord();
        }
    }

    @action async remove(record) {
        if (!window.confirm(this.intl.t('service-tracker.petty.confirm-delete', { id: record.public_id }))) {
            return;
        }
        await record.destroyRecord();
        this.selectedId = null;
    }

    @action async download() {
        try {
            const blob = await this.api.exportPeriod({ from: this.from, to: this.to, only: 'petty', petty: true });
            this.api.saveBlob(blob, `Petty_Cash_${this.from || 'start'}_to_${this.to || todayIso()}.xlsx`);
        } catch (e) {
            this.notifications.error(e.message);
        }
    }
}
