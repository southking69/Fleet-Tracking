/**
 * Pure (framework-free) domain model for the Navigator Service Tracker screens:
 * wire types, constants, the turnaround stage maths ported from src/Index.html
 * (stopInfo L441, calcVisit L448) and the optimistic-overlay merge.
 */

// ---------------------------------------------------------------- wire types (driver REST, snake_case)

export type StageName = 'Pickup inspection' | 'Return inspection' | 'Vendor reported';
export type Severity = 'Minor' | 'Moderate' | 'Major';
export type Condition = 'Pre-existing' | 'New';
export type JobStatus = 'Pending' | 'In progress' | 'Done';
export type StopAction = 'check-in' | 'approval-requested' | 'approval-received' | 'left';

export interface VendorStop {
    id: string;
    client_uuid?: string;
    vendor_name: string;
    vendor_uuid?: string | null;
    arrived_at?: string | null;
    approval_requested_at?: string | null;
    approval_received_at?: string | null;
    left_at?: string | null;
    notes?: string;
    _pending?: boolean;
}

export interface ServiceJob {
    id: string;
    client_uuid?: string;
    catalog_item_id?: string | null;
    service_name: string;
    vendor_name?: string;
    approval_needed: boolean;
    status: JobStatus;
    parts_replaced?: string;
    next_due_override_km?: number | null;
    next_due_date?: string | null;
    notes?: string;
    next_due_km?: number | null; // server-computed
    _pending?: boolean;
}

export interface PhotoRef {
    id?: string;
    uri: string; // remote url or local file uri
    local?: boolean;
    angle?: string;
}

export interface DamageRecord {
    id: string;
    client_uuid?: string;
    stage: StageName;
    location: string;
    damage_type: string;
    severity: Severity;
    condition: Condition;
    occurred_at: string;
    client_notified: 'Yes' | 'No';
    notes?: string;
    photos?: PhotoRef[];
    _pending?: boolean;
}

export interface PettyCashEntry {
    id: string;
    client_uuid?: string;
    entry_date: string; // yyyy-mm-dd
    plate?: string;
    service_visit_id?: string | null;
    category: string;
    amount: number; // integer minor units (halalas)
    currency: string;
    paid_by?: string;
    description?: string;
    photos?: PhotoRef[];
    _pending?: boolean;
}

export interface Visit {
    id: string;
    client_uuid?: string;
    plate: string;
    make_model?: string;
    odometer?: number | null;
    arrived_at_lsa?: string | null;
    picked_up_at?: string | null;
    returned_at?: string | null;
    pickup_inspected?: boolean;
    return_inspected?: boolean;
    lane?: string;
    target_hours?: number | null;
    notes?: string;
    status?: 'not_started' | 'in_progress' | 'closed';
    stops?: VendorStop[];
    jobs?: ServiceJob[];
    damage_records?: DamageRecord[];
    inspection_photos?: { pickup?: PhotoRef[]; return?: PhotoRef[] };
    calc?: Partial<VisitCalc>;
    _pending?: boolean;
}

export interface CatalogItem {
    id: string;
    /** server uuid (jobs reference their catalog item by uuid) */
    uuid?: string;
    name: string;
    category?: string;
    lane?: string;
    suggested_vendor_name?: string;
    interval_km?: number;
}

export type DueStatus = 'OVERDUE' | 'Due soon' | 'On time' | 'Odometer missing' | 'No previous due';

export interface VehicleDue {
    plate: string;
    make_model?: string;
    last_odometer?: number | null;
    items: { service_name: string; next_due_km: number | null; km_left: number | null; status?: DueStatus }[];
}

// ---------------------------------------------------------------- constants (defaults mirror defaultSettings() in Index.html)

export const INSPECTION_ANGLES = ['front', 'rear', 'left', 'right', 'front-left', 'front-right', 'rear-left', 'rear-right'] as const;
export type InspectionAngle = (typeof INSPECTION_ANGLES)[number];

export const DEFAULT_LISTS = {
    damageLocations: ['Front bumper', 'Rear bumper', 'Hood', 'Roof', 'Trunk', 'Windshield', 'Rear window', 'Left front door', 'Left rear door', 'Right front door', 'Right rear door', 'Left mirror', 'Right mirror', 'Wheel / Rim', 'Tyre', 'Interior', 'Other'],
    damageTypes: ['Scratch', 'Dent', 'Crack', 'Broken part', 'Missing item', 'Stain / interior damage', 'Other'],
    severities: ['Minor', 'Moderate', 'Major'] as Severity[],
    expenseCats: ['Fuel', 'Spare parts', 'Oil / fluids top-up', 'Towing / transport', 'Car wash', 'Parking / tolls', 'Tools / consumables', 'Other'],
    svcStatuses: ['Pending', 'In progress', 'Done'] as JobStatus[],
    currency: 'SAR',
};

export const PHOTO_MAX_PX = 1000;

/** Target size that fits within `max` px (longest side), preserving aspect ratio, never upscaling. */
export function fitWithin(width: number, height: number, max: number = PHOTO_MAX_PX): { width: number; height: number } {
    const ratio = Math.min(max / width, max / height, 1);
    return { width: Math.max(1, Math.floor(width * ratio)), height: Math.max(1, Math.floor(height * ratio)) };
}
export const PHOTO_JPEG_QUALITY = 72; // = 0.72

export const STAGE_SHORT = ['Pickup wait', 'Transit to 1st vendor', 'Approval wait', 'Vendor work', 'Between vendors', 'Return transit'];
export const STAGE_OWNER = ['Our team', 'Our team', 'Splendor', 'Vendor', 'Our team', 'Our team'];

/** 'In progress' -> 'in_progress', 'Pre-existing' -> 'pre_existing': stable translation keys for stored English values. */
export const slug = (s: string) => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

// ---------------------------------------------------------------- time helpers

export const ms = (iso?: string | null): number | null => {
    if (!iso) return null;
    const t = Date.parse(iso);
    return Number.isNaN(t) ? null : t;
};
export const diffH = (a?: string | null, b?: string | null): number | null => {
    const x = ms(a), y = ms(b);
    return x == null || y == null ? null : (y - x) / 36e5;
};
const sum = (xs: (number | null)[]) => xs.reduce<number>((a, x) => a + (x || 0), 0);

export interface StopInfo {
    atVendor: number | null;
    appr: number | null;
    net: number | null;
}
/** Port of stopInfo() (Index.html L441). */
export function stopInfo(st: Pick<VendorStop, 'arrived_at' | 'left_at' | 'approval_requested_at' | 'approval_received_at'>): StopInfo {
    const atVendor = diffH(st.arrived_at, st.left_at);
    const appr = diffH(st.approval_requested_at, st.approval_received_at);
    return { atVendor, appr, net: atVendor == null ? null : atVendor - (appr || 0) };
}

export interface VisitCalc {
    stages: (number | null)[];
    total: number | null;
    target: number | null;
    status: 'Not started' | 'In progress' | 'Closed';
    big: number;
    bigName: string;
    owner: string;
    met: boolean | null;
    open: number | null;
    THV: number | null;
    si: StopInfo[];
}

/** Port of the stage part of calcVisit() (Index.html L448). Due/overdue is server-side. */
export function calcStages(v: Visit, now: number = Date.now()): VisitCalc {
    const stops = v.stops || [];
    const si = stops.map(stopInfo);
    const target = v.target_hours != null && v.target_hours > 0 ? v.target_hours : null;
    const status = v.returned_at ? 'Closed' : v.arrived_at_lsa ? 'In progress' : 'Not started';
    const THV = stops.length ? sum(si.map((x) => x.atVendor)) : null;
    const wPick = diffH(v.arrived_at_lsa, v.picked_up_at);
    const arrs = stops.map((s) => ms(s.arrived_at)).filter((x): x is number => x != null);
    const lefts = stops.map((s) => ms(s.left_at)).filter((x): x is number => x != null);
    const wTrans = ms(v.picked_up_at) != null && arrs.length ? (Math.min(...arrs) - (ms(v.picked_up_at) as number)) / 36e5 : null;
    const wAppr = THV == null ? null : sum(si.map((x) => x.appr));
    const wWork = THV == null || wAppr == null ? null : THV - wAppr;
    const wRet = ms(v.returned_at) != null && lefts.length ? ((ms(v.returned_at) as number) - Math.max(...lefts)) / 36e5 : null;
    const total = diffH(v.arrived_at_lsa, v.returned_at);
    const wBetw = [total, wPick, wTrans, THV, wRet].every((x) => x != null) ? Math.max(0, Math.round((total! - wPick! - wTrans! - THV! - wRet!) * 100) / 100) : null;
    const stages = [wPick, wTrans, wAppr, wWork, wBetw, wRet];
    const have = stages.filter((x): x is number => x != null);
    let big = -1;
    if (status === 'Closed' && have.length) {
        const mx = Math.max(...have);
        big = stages.findIndex((x) => x != null && x === mx);
    }
    const a = ms(v.arrived_at_lsa);
    return {
        stages,
        total,
        target,
        status,
        big,
        bigName: big >= 0 ? STAGE_SHORT[big] : '',
        owner: big >= 0 ? STAGE_OWNER[big] : '',
        met: total != null && target != null ? total <= target : null,
        open: a != null && !v.returned_at ? (now - a) / 36e5 : null,
        THV,
        si,
    };
}

/** Prefer the server's calc when present (it is authoritative); fill the rest locally so offline edits show. */
export function visitCalc(v: Visit, now?: number): VisitCalc {
    const local = calcStages(v, now);
    return v.calc && Array.isArray(v.calc.stages) && !v._pending ? ({ ...local, ...v.calc } as VisitCalc) : local;
}

export function fmtHours(h: number | null | undefined): string {
    if (h == null || Number.isNaN(h)) return '–';
    const mins = Math.round(h * 60);
    const hh = Math.floor(mins / 60), mm = mins % 60;
    return hh > 0 ? `${hh}h ${String(mm).padStart(2, '0')}m` : `${mm}m`;
}

/** Due badge semantics from Index.html L1416 (+ dueBadge L829). */
export function dueStatusFromLeft(kmLeft: number | null | undefined): DueStatus {
    if (kmLeft == null) return 'No previous due';
    if (kmLeft < 0) return 'OVERDUE';
    if (kmLeft <= 1000) return 'Due soon';
    return 'On time';
}

/** SAR (or any 2-decimal currency) user input -> integer minor units. Accepts Arabic-Indic digits and commas. */
export function toMinorUnits(input: string | number | null | undefined): number | null {
    if (input == null || input === '') return null;
    if (typeof input === 'number') return Number.isFinite(input) ? Math.round(input * 100) : null;
    const ascii = input
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x660))
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x6f0))
        .replace(/٫/g, '.')
        .replace(/[,٬\s]/g, '');
    if (!/^-?\d*\.?\d+$/.test(ascii)) return null;
    return Math.round(parseFloat(ascii) * 100);
}
export const fromMinorUnits = (minor: number | null | undefined): string => (minor == null ? '' : (minor / 100).toFixed(2));

// ---------------------------------------------------------------- optimistic overlay

export type EntityType = 'visit' | 'stop' | 'job' | 'damage' | 'petty';

export interface OverlayEntry {
    type: EntityType;
    /** server id, or the client_uuid for entities created offline */
    id: string;
    /** field patch applied on top of the server copy (or the whole entity if created) */
    patch: Record<string, any>;
    created: boolean;
    parentId?: string; // visit id for stop/job/damage
    /** queue client_uuids that must all be delivered before this entry is dropped */
    taskIds: string[];
    deliveredAt?: number;
}

export interface Overlay {
    entries: OverlayEntry[];
}

const sameEntity = (e: any, o: OverlayEntry) => e.id === o.id || (e.client_uuid && e.client_uuid === o.id);

/** Apply the overlay to a list of server entities of one type. Created entities that the server already returns are not duplicated. */
export function applyOverlay<T extends { id: string; client_uuid?: string }>(type: EntityType, server: T[], overlay: Overlay, parentId?: string): T[] {
    const entries = overlay.entries.filter((o) => o.type === type && (parentId == null || o.parentId === parentId));
    const out = server.map((e) => {
        const o = entries.find((x) => sameEntity(e, x));
        return o ? ({ ...e, ...o.patch, _pending: !o.deliveredAt } as T) : e;
    });
    for (const o of entries) {
        if (!o.created) continue;
        if (server.some((e) => sameEntity(e, o))) continue;
        out.push({ ...(o.patch as any), id: o.id, client_uuid: o.id, _pending: true });
    }
    return out;
}

/** Drop overlay entries whose every task is delivered AND that a fresh server read has since confirmed. */
export function pruneOverlay(overlay: Overlay, isDelivered: (taskId: string) => boolean, serverFetchedAfter: number | null): Overlay {
    return {
        entries: overlay.entries
            .map((o) => (o.deliveredAt || !o.taskIds.every(isDelivered) ? o : { ...o, deliveredAt: Date.now() }))
            .filter((o) => !(o.deliveredAt && serverFetchedAfter != null && serverFetchedAfter >= o.deliveredAt)),
    };
}

export function upsertOverlay(overlay: Overlay, entry: OverlayEntry): Overlay {
    const i = overlay.entries.findIndex((e) => e.type === entry.type && e.id === entry.id);
    if (i < 0) return { entries: [...overlay.entries, entry] };
    const cur = overlay.entries[i];
    const merged: OverlayEntry = { ...cur, patch: { ...cur.patch, ...entry.patch }, taskIds: [...new Set([...cur.taskIds, ...entry.taskIds])], deliveredAt: undefined };
    const entries = overlay.entries.slice();
    entries[i] = merged;
    return { entries };
}
