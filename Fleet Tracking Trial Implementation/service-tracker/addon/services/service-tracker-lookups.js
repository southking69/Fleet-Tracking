import Service, { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

/**
 * FleetOps / core reference data used by the forms: vendors, vehicles, technicians (users),
 * plus the Service Tracker lanes. Loaded lazily and cached for the session.
 */
export default class ServiceTrackerLookupsService extends Service {
    @service store;

    @tracked vendors = [];
    @tracked vehicles = [];
    @tracked users = [];
    @tracked lanes = [];
    @tracked catalog = [];
    loaded = false;

    async safeQuery(model, query) {
        try {
            return (await this.store.query(model, query)).toArray();
        } catch (e) {
            return [];
        }
    }

    async load({ force = false } = {}) {
        if (this.loaded && !force) {
            return this;
        }
        const [vendors, vehicles, users, lanes, catalog] = await Promise.all([
            this.safeQuery('vendor', { limit: 500, sort: 'name' }),
            this.safeQuery('vehicle', { limit: 500 }),
            this.safeQuery('user', { limit: 500 }),
            this.store.query('service-lane', { sort: 'sort', limit: 100 }).then((r) => r.toArray()),
            this.store.query('service-catalog-item', { limit: 1000, sort: 'name' }).then((r) => r.toArray()),
        ]);
        this.vendors = vendors;
        this.vehicles = vehicles;
        this.users = users;
        this.lanes = lanes;
        this.catalog = catalog;
        this.loaded = true;
        return this;
    }

    async reloadCatalog() {
        this.catalog = (await this.store.query('service-catalog-item', { limit: 1000, sort: 'name' })).toArray();
        return this.catalog;
    }

    async reloadLanes() {
        this.lanes = (await this.store.query('service-lane', { sort: 'sort', limit: 100 })).toArray();
        return this.lanes;
    }

    /** Vendor options for a <select>: [{ value: uuid, label: name }] */
    get vendorOptions() {
        return this.vendors.map((v) => ({ value: v.uuid ?? v.id, uuid: v.uuid ?? v.id, label: v.name ?? v.display_name ?? v.id }));
    }

    get userOptions() {
        return this.users.map((u) => ({ value: u.uuid ?? u.id, label: u.name ?? u.email }));
    }

    get plates() {
        return this.vehicles.map((v) => ({ uuid: v.uuid ?? v.id, plate: v.plate_number ?? v.plate, label: v.display_name ?? v.plate_number })).filter((v) => v.plate);
    }
}
