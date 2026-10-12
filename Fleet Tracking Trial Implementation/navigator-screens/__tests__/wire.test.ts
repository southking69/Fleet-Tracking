import { normalizeLookups, normalizePetty, normalizeVehicleDue, normalizeVisit, photoRefs } from '../src/services/wire';
import * as T from '../src/services/taskFactory';

describe('wire adapter (server JSON -> screen shapes)', () => {
    test('visit: damages -> damage_records, photo urls -> uri, jobs get catalog_item_id and server next due', () => {
        const v = normalizeVisit({
            id: 'visit_1',
            plate: 'ABC 1234',
            calc: { sinfo: [{ nextDue: 25000 }] },
            stops: [{ id: 'vstop_1', vendor_name: 'Petromin' }],
            jobs: [{ id: 'sjob_1', service_name: 'Oil change', catalog_item_uuid: 'uuid-c', approval_needed: 1 }],
            damages: [{ id: 'dmg_1', photos: [{ uuid: 'f1', url: 'https://x/a.jpg', caption: 'front' }] }],
            pickup_photos: [{ uuid: 'f2', url: 'https://x/p.jpg', caption: 'rear' }],
            return_photos: [],
        });
        expect(v.damage_records?.[0].photos).toEqual([{ id: 'f1', uri: 'https://x/a.jpg', angle: 'front' }]);
        expect(v.jobs?.[0]).toMatchObject({ catalog_item_id: 'uuid-c', approval_needed: true, next_due_km: 25000 });
        expect(v.inspection_photos).toEqual({ pickup: [{ id: 'f2', uri: 'https://x/p.jpg', angle: 'rear' }], return: [] });
        expect((v as any).damages).toBeUndefined();
    });

    test('petty: receipts -> photos and service_visit_public_id -> service_visit_id; amount stays in minor units', () => {
        const p = normalizePetty({ id: 'pcash_1', amount: 1250, service_visit_uuid: 'u', service_visit_public_id: 'visit_1', receipts: [{ uuid: 'r', url: 'https://x/r.jpg' }] });
        expect(p).toMatchObject({ service_visit_id: 'visit_1', amount: 1250 });
        expect(p.photos).toEqual([{ id: 'r', uri: 'https://x/r.jpg', angle: undefined }]);
    });

    test('lookups: catalog (id, uuid, lane name) and lists incl. vendors/currency', () => {
        const { catalog, lists } = normalizeLookups({ data: { catalog: [{ id: 'svc_1', uuid: 'u1', name: 'Oil', category: 'Routine', lane: 'Express', interval_km: 10000 }], lists: { damageTypes: ['Dent'], vendors: ['Petromin'] }, currency: 'SAR' } });
        expect(catalog[0]).toMatchObject({ id: 'svc_1', uuid: 'u1', lane: 'Express', interval_km: 10000 });
        expect(lists).toMatchObject({ damageTypes: ['Dent'], vendors: ['Petromin'], currency: 'SAR' });
    });

    test('vehicles/due: server `due` rows -> screen items; OK -> On time', () => {
        const rows = normalizeVehicleDue([{ plate: 'A 1', make_model: 'Hilux', last_odometer: 1000, due: [{ service: 'Oil', next_due_km: 900, km_remaining: -100, status: 'OVERDUE' }, { service: 'Tyres', next_due_km: 9000, km_remaining: 8000, status: 'OK' }] }]);
        expect(rows[0].items).toEqual([
            { service_name: 'Oil', next_due_km: 900, km_left: -100, status: 'OVERDUE' },
            { service_name: 'Tyres', next_due_km: 9000, km_left: 8000, status: 'On time' },
        ]);
    });

    test('photoRefs drops entries without a url', () => {
        expect(photoRefs([{ uuid: 'a' }, { uuid: 'b', url: 'u' }])).toHaveLength(1);
    });
});

describe('write bodies use the server field names', () => {
    test('child writes reference the visit by service_visit_uuid; photos by subject_type/subject_id fields', () => {
        const d = T.createDamageTask('c1', { id: 'visit_1', local: false }, { stage: 'Pickup inspection' });
        expect(d.body).toMatchObject({ service_visit_uuid: 'visit_1' });
        expect(d.body).not.toHaveProperty('service_visit_id');
        const p = T.createPettyCashTask('c2', null, { amount: 100 });
        expect(p.body).toMatchObject({ service_visit_uuid: null, amount: 100 });
        const j = T.updateJobTask({ id: 'v', local: false }, { id: 'sjob_1', local: false }, { service_name: 'x' }, 'c3');
        expect(j.path).toBe('service-tracker/service-jobs/sjob_1');
        expect(j.body).toMatchObject({ service_visit_uuid: 'v' });
    });
});
