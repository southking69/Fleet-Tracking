import Model, { attr } from '@ember-data/model';

export default class PettyCashEntryModel extends Model {
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') legacy_ref;
    @attr('string') entry_date;
    @attr('string') vehicle_uuid;
    @attr('string') plate;
    @attr('string') service_visit_uuid;
    @attr('string') service_visit_public_id;
    @attr('string') category;
    /** Integer minor units (cents). */
    @attr('number') amount;
    @attr('string', { defaultValue: 'SAR' }) currency;
    @attr('string') paid_by;
    @attr('string') description;
    @attr('string') journal_uuid;

    /** Core File rows: [{ uuid, url, original_filename }] */
    @attr() receipts;
    @attr('date') created_at;
    @attr('date') updated_at;
}
