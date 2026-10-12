import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { saveRecord } from '../utils/record-save';

/**
 * Edit one damage record (fields + photos). Used inside the visit page, the Damage log and the Client Report page.
 * @record, @settings (dropdown lists), @userOptions, @onSaved(record), @onDelete(record), @onChanged() after photo changes.
 */
export default class DamageEditorComponent extends Component {
    @service notifications;
    @service intl;

    get conditions() {
        return ['Pre-existing', 'New'];
    }

    get yesNo() {
        return ['Yes', 'No'];
    }

    get subject() {
        return { type: 'damage_record', uuid: this.args.record.uuid, collection: 'photos' };
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

    @action async setRecordedBy(uuid) {
        const opt = (this.args.userOptions ?? []).find((o) => o.value === uuid);
        this.args.record.recorded_by_uuid = uuid;
        this.args.record.recorded_by_name = opt ? opt.label : '';
        return this.update('recorded_by_uuid', uuid);
    }

    @action async photosChanged() {
        try {
            await this.args.record.reload();
        } catch (e) {
            this.notifications.error(e.message);
        }
        this.args.onChanged?.(this.args.record);
    }
}
