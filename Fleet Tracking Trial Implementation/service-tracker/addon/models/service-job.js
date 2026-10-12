import Model, { attr } from '@ember-data/model';

export default class ServiceJobModel extends Model {
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') legacy_ref;
    @attr('string') service_visit_uuid;
    @attr('string') catalog_item_uuid;
    @attr('string') service_name;
    @attr('string') vendor_uuid;
    @attr('string') vendor_name;
    @attr('boolean') approval_needed;
    @attr('string', { defaultValue: 'Pending' }) status;
    @attr('string') parts_replaced;
    @attr('number') next_due_override_km;
    @attr('string') next_due_date;
    @attr('string') notes;
    @attr('date') created_at;
    @attr('date') updated_at;
}
