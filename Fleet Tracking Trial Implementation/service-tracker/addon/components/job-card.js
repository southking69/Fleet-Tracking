import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { saveRecord } from '../utils/record-save';

/**
 * One job (service line) of a visit. @info is the server `calc.sinfo[i]` entry
 * ({ lane, target, nextDue, prev, kmOver, status, ... }); it is displayed as delivered.
 */
export default class JobCardComponent extends Component {
    @service notifications;
    @service intl;

    @action async update(field, value) {
        this.args.job[field] = value;
        if (await saveRecord(this.args.job, this.notifications)) {
            this.args.onSaved?.(this.args.job);
        }
    }

    @action setText(field, event) {
        return this.update(field, event.target.value);
    }

    @action setNumber(field, event) {
        const raw = event.target.value;
        return this.update(field, raw === '' ? null : Number(raw));
    }

    @action setDate(field, event) {
        return this.update(field, event.target.value || null);
    }

    @action setApproval(value) {
        return this.update('approval_needed', value === 'yes');
    }

    @action setVendor(uuid) {
        const opt = (this.args.vendorOptions ?? []).find((o) => o.value === uuid);
        this.args.job.vendor_uuid = uuid;
        this.args.job.vendor_name = opt ? opt.label : '';
        return this.update('vendor_uuid', uuid);
    }

    @action async pick(item) {
        const job = this.args.job;
        job.catalog_item_uuid = item.uuid;
        job.service_name = item.name;
        if (!job.vendor_uuid && item.suggested_vendor_uuid) {
            job.vendor_uuid = item.suggested_vendor_uuid;
            job.vendor_name = item.suggested_vendor_name;
        }
        if (await saveRecord(job, this.notifications)) {
            this.args.onSaved?.(job, { refreshCalc: true });
        }
    }

    @action async create(name) {
        const item = await this.args.onCreateCatalog?.(name);
        if (item) {
            await this.pick(item);
        }
    }

    get approvalValue() {
        const v = this.args.job.approval_needed;
        return v === true ? 'yes' : v === false ? 'no' : null;
    }

    get approvalOptions() {
        return [
            { value: 'yes', label: this.intl.t('service-tracker.common.yes') },
            { value: 'no', label: this.intl.t('service-tracker.common.no') },
        ];
    }
}
