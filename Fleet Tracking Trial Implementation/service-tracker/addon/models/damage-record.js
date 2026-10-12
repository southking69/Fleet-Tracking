import Model, { attr } from '@ember-data/model';

export default class DamageRecordModel extends Model {
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') legacy_ref;
    @attr('string') service_visit_uuid;
    @attr('string') service_visit_public_id;
    @attr('string') vehicle_uuid;
    @attr('string') plate;
    @attr('string') stage;
    @attr('string') location;
    @attr('string') damage_type;
    @attr('string') severity;
    @attr('string') condition;
    @attr('string') occurred_at;
    @attr('string') recorded_by_uuid;
    @attr('string') recorded_by_name;
    @attr('string') client_notified;
    @attr('string') issue_uuid;
    @attr('string') notes;

    /** Core File rows: [{ uuid, url, original_filename }] */
    @attr() photos;
    @attr('date') created_at;
    @attr('date') updated_at;
}
