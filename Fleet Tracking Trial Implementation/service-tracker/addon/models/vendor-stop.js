import Model, { attr } from '@ember-data/model';

export default class VendorStopModel extends Model {
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') legacy_ref;
    @attr('string') service_visit_uuid;
    @attr('string') vendor_uuid;
    @attr('string') vendor_name;
    @attr('string') arrived_at;
    @attr('string') approval_requested_at;
    @attr('string') approval_received_at;
    @attr('string') left_at;
    @attr('string') notes;
    @attr('date') created_at;
    @attr('date') updated_at;
}
