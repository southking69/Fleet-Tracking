import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { saveRecord } from '../utils/record-save';
import { toMinor, toMajor } from '../utils/format';

/**
 * Edit one petty cash entry (amount entered in major units, stored in cents) with receipt photos.
 * @record, @settings, @visitOptions ([{value,label}] visits of the car), @lockPlate, @onSaved, @onDelete, @onChanged.
 */
export default class PettyEditorComponent extends Component {
    @service notifications;

    get amountMajor() {
        return toMajor(this.args.record.amount);
    }

    get subject() {
        return { type: 'petty_cash_entry', uuid: this.args.record.uuid, collection: 'receipts' };
    }

    @action async update(field, value) {
        this.args.record[field] = value;
        if (await saveRecord(this.args.record, this.notifications)) {
            this.args.onSaved?.(this.args.record);
        }
    }

    @action setText(field, event) {
        return this.update(field, event.target.value);
    }

    @action setPlate(event) {
        return this.update('plate', event.target.value.toUpperCase().trim());
    }

    @action setAmount(event) {
        return this.update('amount', toMinor(event.target.value));
    }

    @action setDate(event) {
        return this.update('entry_date', event.target.value);
    }

    @action async receiptsChanged() {
        try {
            await this.args.record.reload();
        } catch (e) {
            this.notifications.error(e.message);
        }
        this.args.onChanged?.(this.args.record);
    }
}
