/**
 * Ember Data model name -> REST path segment + JSON root keys under /int/v1/service-tracker/.
 * Roots are the Fleetbase convention (snake_case of the server model name) and match the `$plural` / `$singular`
 * of the PHP controllers: lists come back as `{service_visits: [...], meta}`, single records as `{service_visit: {...}}`,
 * and writes are posted as `{service_visit: {...}}` (the server also accepts the flat form).
 * Record ids are the server `uuid`; `public_id` (visit_xxx ...) is a plain attribute and is accepted in paths too.
 */
export const RESOURCES = {
    'service-visit': { path: 'visits', plural: 'service_visits', singular: 'service_visit' },
    'vendor-stop': { path: 'vendor-stops', plural: 'vendor_stops', singular: 'vendor_stop' },
    'service-job': { path: 'service-jobs', plural: 'service_jobs', singular: 'service_job' },
    'damage-record': { path: 'damage-records', plural: 'damage_records', singular: 'damage_record' },
    'petty-cash-entry': { path: 'petty-cash', plural: 'petty_cash_entries', singular: 'petty_cash_entry' },
    'service-catalog-item': { path: 'catalog', plural: 'service_catalog_items', singular: 'service_catalog_item' },
    'service-lane': { path: 'lanes', plural: 'service_lanes', singular: 'service_lane' },
};

export const NAMESPACE = 'service-tracker';
