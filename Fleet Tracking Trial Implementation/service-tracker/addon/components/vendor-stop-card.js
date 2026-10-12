import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { saveRecord } from '../utils/record-save';

/**
 * One vendor stop of a visit. Times are edited with Now buttons; the derived hours (@info = server calc.si[i])
 * are displayed, never recomputed.
 */
export default class VendorStopCardComponent extends Component {
    @service notifications;

    @action async update(field, value) {
        this.args.stop[field] = value;
        if (await saveRecord(this.args.stop, this.notifications)) {
            this.args.onSaved?.(this.args.stop);
        }
    }

    @action async setVendor(uuid) {
        const opt = (this.args.vendorOptions ?? []).find((o) => o.value === uuid);
        this.args.stop.vendor_uuid = uuid;
        this.args.stop.vendor_name = opt ? opt.label : '';
        if (await saveRecord(this.args.stop, this.notifications)) {
            this.args.onSaved?.(this.args.stop);
        }
    }

    @action setText(field, event) {
        return this.update(field, event.target.value);
    }
}
