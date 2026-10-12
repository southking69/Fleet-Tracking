import Model, { attr } from '@ember-data/model';

export default class ServiceCatalogItemModel extends Model {
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') legacy_ref;
    @attr('string') name;
    @attr('string') category;
    @attr('string') lane_uuid;
    @attr('string') lane_name;
    @attr('string') suggested_vendor_uuid;
    @attr('string') suggested_vendor_name;
    @attr('number', { defaultValue: 0 }) interval_km;
    /** Sent with a PUT when `name` changed so the server cascades the rename to jobs. */
    @attr('boolean') rename_cascade;
    @attr('date') created_at;
    @attr('date') updated_at;
}
