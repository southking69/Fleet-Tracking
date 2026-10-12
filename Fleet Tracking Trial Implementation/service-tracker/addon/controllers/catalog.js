import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { saveRecord } from '../utils/record-save';

export default class CatalogController extends Controller {
    @service store;
    @service notifications;
    @service intl;
    @service('service-tracker-api') api;
    @service('service-tracker-lookups') lookups;

    @tracked search = '';

    get settings() {
        return this.api.settings ?? {};
    }

    get laneOptions() {
        return this.lookups.lanes.map((l) => ({ value: l.uuid, label: l.name }));
    }

    get rows() {
        const words = this.search.toLowerCase().split(/\s+/).filter(Boolean);
        return (this.model?.toArray?.() ?? []).filter((c) => {
            if (c.isNew && !c.name) {
                return true;
            }
            const hay = `${c.name} ${c.category} ${c.suggested_vendor_name ?? ''}`.toLowerCase();
            return words.every((w) => hay.includes(w));
        });
    }

    @action setSearch(event) {
        this.search = event.target.value;
    }

    @action async setField(item, field, value) {
        item[field] = value;
        await saveRecord(item, this.notifications);
        this.lookups.reloadCatalog();
    }

    @action setNumber(item, event) {
        const raw = event.target.value;
        return this.setField(item, 'interval_km', raw === '' ? 0 : Number(raw));
    }

    /** Renaming cascades server-side to the `service_name` of every job that uses this catalog item. */
    @action async rename(item, event) {
        const name = event.target.value.trim();
        if (!name) {
            event.target.value = item.name;
            return;
        }
        if (name === item.name) {
            return;
        }
        item.name = name;
        item.rename_cascade = true;
        await saveRecord(item, this.notifications);
        item.rename_cascade = false;
        this.lookups.reloadCatalog();
    }

    @action setVendor(item, uuid) {
        const opt = this.lookups.vendorOptions.find((o) => o.value === uuid);
        item.suggested_vendor_name = opt ? opt.label : '';
        return this.setField(item, 'suggested_vendor_uuid', uuid);
    }

    @action async add() {
        const standard = this.lookups.lanes.find((l) => /standard/i.test(l.name)) ?? this.lookups.lanes[0];
        const item = this.store.createRecord('service-catalog-item', { name: this.intl.t('service-tracker.catalog.new-service'), category: 'Other', lane_uuid: standard?.uuid, interval_km: 0 });
        this.search = '';
        if (await saveRecord(item, this.notifications)) {
            this.lookups.reloadCatalog();
        } else {
            item.unloadRecord();
        }
    }

    @action async remove(item) {
        if (!window.confirm(this.intl.t('service-tracker.catalog.confirm-delete', { name: item.name }))) {
            return;
        }
        await item.destroyRecord();
        this.lookups.reloadCatalog();
    }
}
