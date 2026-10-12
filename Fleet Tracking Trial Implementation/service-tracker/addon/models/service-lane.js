import Model, { attr } from '@ember-data/model';

export default class ServiceLaneModel extends Model {
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') legacy_ref;
    @attr('string') name;
    @attr('number') target_hours;
    @attr('number', { defaultValue: 0 }) sort;
    @attr('date') created_at;
    @attr('date') updated_at;
}
