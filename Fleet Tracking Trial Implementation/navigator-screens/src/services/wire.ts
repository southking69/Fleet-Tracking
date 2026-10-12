/**
 * Wire adapter: server (driver REST, `/v1/service-tracker/*`) JSON -> the shapes the screens use.
 * Pure functions, no React / SDK imports, unit tested in __tests__/wire.test.ts.
 *
 * Server facts this file encodes (see service-tracker/server/src/Http):
 *  - `id` of every entity is its PUBLIC id (visit_xxx, vstop_xxx, sjob_xxx, dmg_xxx, pcash_xxx); `uuid` is also present.
 *  - child rows reference their visit by `service_visit_uuid` (uuid) and `service_visit_public_id`.
 *  - visit rows embed (when `with=` asks) `stops`, `jobs`, `damage_records`, `pickup_photos`, `return_photos`;
 *    petty rows carry `receipts`; photo objects are `{uuid, url, caption (= angle), ...}`.
 *  - money is `amount` in integer minor units; datetimes are UTC ISO strings ending in `Z`.
 */
import { CatalogItem, DamageRecord, DueStatus, PettyCashEntry, PhotoRef, ServiceJob, VehicleDue, Visit } from './visitModel';

const arr = (x: any): any[] => (Array.isArray(x) ? x : []);

export const photoRefs = (list: any): PhotoRef[] =>
    arr(list)
        .map((p) => ({ id: p?.public_id ?? p?.uuid ?? p?.id, uri: p?.url ?? p?.uri, angle: p?.angle ?? (p?.caption || undefined) }) as PhotoRef)
        .filter((p) => !!p.uri);

export function normalizeJob(j: any, info?: any): ServiceJob {
    return {
        ...j,
        catalog_item_id: j.catalog_item_id ?? j.catalog_item_uuid ?? null,
        approval_needed: !!j.approval_needed,
        next_due_km: info?.nextDue ?? j.next_due_km ?? null,
    };
}

export function normalizeDamage(d: any): DamageRecord {
    return { ...d, photos: photoRefs(d.photos) };
}

export function normalizePetty(p: any): PettyCashEntry {
    return { ...p, service_visit_id: p.service_visit_public_id ?? p.service_visit_id ?? null, photos: photoRefs(p.receipts ?? p.photos) };
}

export function normalizeVisit(v: any): Visit {
    const sinfo = arr(v?.calc?.sinfo);
    const out: any = { ...v };
    if (v.stops) out.stops = arr(v.stops);
    if (v.jobs) out.jobs = arr(v.jobs).map((j, i) => normalizeJob(j, sinfo[i]));
    const dmg = v.damage_records ?? v.damages;
    if (dmg) out.damage_records = arr(dmg).map(normalizeDamage);
    if (v.pickup_photos || v.return_photos) out.inspection_photos = { pickup: photoRefs(v.pickup_photos), return: photoRefs(v.return_photos) };
    delete out.damages;
    delete out.pickup_photos;
    delete out.return_photos;
    return out as Visit;
}

export function normalizeCatalogItem(c: any): CatalogItem {
    return { id: c.id ?? c.public_id ?? c.uuid, uuid: c.uuid, name: c.name, category: c.category, lane: c.lane ?? c.lane_name, suggested_vendor_name: c.suggested_vendor_name, interval_km: c.interval_km };
}

/** `GET lookups` -> catalog + dropdown lists. */
export function normalizeLookups(data: any): { catalog: CatalogItem[]; lists: Record<string, any> } {
    const d = data?.data ?? data ?? {};
    const lists: Record<string, any> = { ...(d.lists || {}) };
    if (d.currency) lists.currency = d.currency;
    return { catalog: arr(d.catalog).map(normalizeCatalogItem), lists };
}

const DUE_STATUS: Record<string, DueStatus> = { OVERDUE: 'OVERDUE', 'Due soon': 'Due soon', OK: 'On time', 'On time': 'On time' };

/** `GET vehicles/due` rows `{plate, make_model, last_odometer, due:[{service, next_due_km, km_remaining, status}]}`. */
export function normalizeVehicleDue(rows: any[]): VehicleDue[] {
    return arr(rows).map((r) => ({
        plate: r.plate,
        make_model: r.make_model,
        last_odometer: r.last_odometer ?? null,
        items: arr(r.due ?? r.items).map((d) => ({
            service_name: d.service ?? d.service_name,
            next_due_km: d.next_due_km ?? null,
            km_left: d.km_remaining ?? d.km_left ?? null,
            status: d.status ? DUE_STATUS[d.status] ?? undefined : undefined,
        })),
    }));
}
