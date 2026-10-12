import Model, { attr } from '@ember-data/model';

export default class ServiceVisitModel extends Model {
    /** @ids */
    @attr('string') uuid;
    @attr('string') public_id;
    @attr('string') legacy_ref;
    @attr('string') work_order_uuid;
    @attr('string') vehicle_uuid;
    @attr('string') technician_uuid;
    @attr('string') technician_name;

    /** @attributes */
    @attr('string') plate;
    @attr('string') make_model;
    @attr('number') odometer;
    @attr('string') arrived_at_lsa;
    @attr('string') picked_up_at;
    @attr('string') returned_at;
    @attr('boolean') pickup_inspected;
    @attr('boolean') return_inspected;
    @attr('string') lane;
    @attr('number') target_hours;
    @attr('string') notes;
    @attr('string') status;
    @attr('string') status_label;
    @attr('string') plate_key;
    @attr('number') stops_count;
    @attr('number') jobs_count;

    /** Condition photos: [{ uuid, url, original_filename }] */
    @attr() pickup_photos;
    @attr() return_photos;

    /** Server-computed turnaround document (stages, total, target, lane, status, big, bigName, owner, met, open, check, overdueN, worstOver, THV, perVendor, si[], sinfo[]). Read-only. */
    @attr() calc;

    /** @dates */
    @attr('date') created_at;
    @attr('date') updated_at;

    get displayName() {
        return [this.public_id, this.plate, this.make_model].filter(Boolean).join(' · ');
    }
}
