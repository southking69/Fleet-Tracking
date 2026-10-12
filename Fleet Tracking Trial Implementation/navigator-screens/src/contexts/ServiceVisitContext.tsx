import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { AppState } from 'react-native';
import useFleetbase from '../hooks/use-fleetbase';
import { storage } from '../hooks/use-storage';
import { OfflineQueue, QueueItem, QueueStats, uuidv4 } from '../services/offlineQueue';
import { mmkvQueueStorage } from '../services/mmkvStorage';
import { createFleetbaseTransport } from '../services/fleetbaseTransport';
import { persistPhoto, removePhoto } from '../services/photoStore';
import * as T from '../services/taskFactory';
import { normalizeLookups, normalizePetty, normalizeVehicleDue, normalizeVisit } from '../services/wire';
import {
    CatalogItem,
    DamageRecord,
    DEFAULT_LISTS,
    EntityType,
    JobStatus,
    Overlay,
    PettyCashEntry,
    ServiceJob,
    StopAction,
    VehicleDue,
    VendorStop,
    Visit,
    applyOverlay,
    pruneOverlay,
    upsertOverlay,
} from '../services/visitModel';

const K = { visits: 'st.visits', petty: 'st.petty', catalog: 'st.catalog', lists: 'st.lists', overlay: 'st.overlay', fetchedAt: 'st.fetchedAt', due: 'st.due' };

function readJson<TT>(key: string, fallback: TT): TT {
    try {
        const raw = storage.getString(key);
        return raw ? (JSON.parse(raw) as TT) : fallback;
    } catch {
        return fallback;
    }
}
function writeJson(key: string, value: unknown) {
    try {
        storage.set(key, JSON.stringify(value));
    } catch (e) {
        console.warn('service-tracker: cache write failed', key, e);
    }
}
const asList = (r: any): any[] => (Array.isArray(r) ? r : Array.isArray(r?.data) ? r.data : []);
const nowIso = () => new Date().toISOString();
const todayStr = () => nowIso().slice(0, 10);

export interface Lists {
    damageLocations: string[];
    damageTypes: string[];
    severities: string[];
    expenseCats: string[];
    svcStatuses: string[];
    vendors: string[];
    currency: string;
}

export interface NewVisitInput {
    plate: string;
    make_model?: string;
    odometer?: number | null;
    notes?: string;
    arrived_at_lsa?: string | null;
}
export interface JobInput {
    catalog_item_id?: string | null;
    service_name: string;
    vendor_name?: string;
    approval_needed: boolean;
    status: JobStatus;
    parts_replaced?: string;
    next_due_override_km?: number | null;
    next_due_date?: string | null;
    notes?: string;
}
export interface DamageInput {
    stage: DamageRecord['stage'];
    location: string;
    damage_type: string;
    severity: DamageRecord['severity'];
    condition: DamageRecord['condition'];
    client_notified: 'Yes' | 'No';
    notes?: string;
}
export interface PettyInput {
    category: string;
    amount: number; // minor units
    currency: string;
    description?: string;
    paid_by?: string;
    plate?: string;
    entry_date?: string;
}

interface Ctx {
    ready: boolean;
    visits: Visit[];
    petty: PettyCashEntry[];
    catalog: CatalogItem[];
    lists: Lists;
    vehiclesDue: VehicleDue[];
    queue: QueueStats;
    failedItems: QueueItem[];
    isRefreshing: boolean;
    refresh: () => Promise<void>;
    loadVehiclesDue: () => Promise<void>;
    getVisit: (id: string) => Visit | undefined;
    createVisit: (input: NewVisitInput) => Promise<string>;
    stampVisit: (visitId: string, field: 'arrived_at_lsa' | 'picked_up_at' | 'returned_at') => Promise<string>;
    updateVisit: (visitId: string, patch: Record<string, any>) => Promise<void>;
    addVendorStop: (visitId: string, vendorName: string) => Promise<string>;
    stampStop: (visitId: string, stopId: string, action: StopAction) => Promise<string>;
    saveJob: (visitId: string, input: JobInput, existingJobId?: string) => Promise<string>;
    submitInspection: (visitId: string, stage: 'pickup' | 'return', photos: { uri: string; angle?: string }[]) => Promise<void>;
    addDamage: (visitId: string, input: DamageInput, photoUris: string[]) => Promise<string>;
    addPettyCash: (input: PettyInput, receiptUris: string[], visitId?: string | null) => Promise<string>;
    retryFailed: (clientUuid?: string) => Promise<void>;
    discardFailed: (clientUuid: string) => Promise<void>;
}

const defaultLists: Lists = { ...DEFAULT_LISTS, vendors: ['Local Mechanic', 'Petromin', 'Tyre Vendor', 'Other Specialist'] };
const emptyStats: QueueStats = { online: true, flushing: false, pendingRecords: 0, pendingPhotos: 0, dead: 0, total: 0, nextAttemptAt: null };

const ServiceVisitContext = createContext<Ctx | null>(null);

export const ServiceVisitProvider = ({ children }: { children: React.ReactNode }) => {
    const { adapter } = useFleetbase();
    const adapterRef = useRef(adapter);
    adapterRef.current = adapter;

    const [ready, setReady] = useState(false);
    const [serverVisits, setServerVisits] = useState<Visit[]>(() => readJson<Visit[]>(K.visits, []));
    const [serverPetty, setServerPetty] = useState<PettyCashEntry[]>(() => readJson<PettyCashEntry[]>(K.petty, []));
    const [catalog, setCatalog] = useState<CatalogItem[]>(() => readJson<CatalogItem[]>(K.catalog, []));
    const [lists, setLists] = useState<Lists>(() => ({ ...defaultLists, ...readJson<Partial<Lists>>(K.lists, {}) }));
    const [vehiclesDue, setVehiclesDue] = useState<VehicleDue[]>(() => readJson<VehicleDue[]>(K.due, []));
    const [overlay, setOverlay] = useState<Overlay>(() => readJson<Overlay>(K.overlay, { entries: [] }));
    const [queueStats, setQueueStats] = useState<QueueStats>(emptyStats);
    const [failedItems, setFailedItems] = useState<QueueItem[]>([]);
    const [isRefreshing, setIsRefreshing] = useState(false);

    const overlayRef = useRef(overlay);
    overlayRef.current = overlay;
    const fetchedAtRef = useRef<number | null>(readJson<number | null>(K.fetchedAt, null));
    const refreshingRef = useRef(false);
    const refreshTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // ---- the queue (one per app process) ----
    const queueRef = useRef<OfflineQueue | null>(null);
    if (!queueRef.current) {
        queueRef.current = new OfflineQueue({
            storage: mmkvQueueStorage,
            transport: createFleetbaseTransport(() => adapterRef.current as any),
            onDelivered: (item) => {
                if (item.kind === 'photo' && item.fileUri) void removePhoto(item.fileUri);
            },
        });
    }
    const queue = queueRef.current;

    const persistOverlay = useCallback((next: Overlay) => {
        overlayRef.current = next;
        setOverlay(next);
        writeJson(K.overlay, next);
    }, []);

    const reconcileOverlay = useCallback(() => {
        const next = pruneOverlay(overlayRef.current, (id) => queue.isDelivered(id), fetchedAtRef.current);
        if (next.entries.length !== overlayRef.current.entries.length || next.entries.some((e, i) => e.deliveredAt !== overlayRef.current.entries[i]?.deliveredAt)) persistOverlay(next);
    }, [persistOverlay, queue]);

    const refresh = useCallback(async () => {
        const a: any = adapterRef.current;
        if (!a || refreshingRef.current) return;
        refreshingRef.current = true;
        setIsRefreshing(true);
        const startedAt = Date.now();
        try {
            const [visits, petty, lookups] = await Promise.all([
                a.get(`${T.NS}/visits`, { sort: '-created_at', limit: 100, with: 'stops,jobs,damage_records,photos' }),
                a.get(`${T.NS}/petty-cash`, { sort: '-entry_date', limit: 200 }),
                // catalog, lanes, vendors and the dropdown lists come from one call (GET lookups); null when offline/unavailable
                a.get(`${T.NS}/lookups`).catch(() => null),
            ]);
            const v = asList(visits).map(normalizeVisit) as Visit[];
            const p = asList(petty).map(normalizePetty) as PettyCashEntry[];
            setServerVisits(v);
            setServerPetty(p);
            writeJson(K.visits, v);
            writeJson(K.petty, p);
            if (lookups && typeof lookups === 'object') {
                const { catalog: c, lists: s } = normalizeLookups(lookups);
                setCatalog(c);
                writeJson(K.catalog, c);
                const merged: Lists = {
                    ...defaultLists,
                    damageLocations: s.damageLocations ?? defaultLists.damageLocations,
                    damageTypes: s.damageTypes ?? defaultLists.damageTypes,
                    severities: s.severities ?? defaultLists.severities,
                    expenseCats: s.expenseCats ?? defaultLists.expenseCats,
                    svcStatuses: s.svcStatuses ?? defaultLists.svcStatuses,
                    vendors: s.vendors ?? defaultLists.vendors,
                    currency: s.currency ?? defaultLists.currency,
                };
                setLists(merged);
                writeJson(K.lists, merged);
            }
            // Only trust a read that began after the last delivery it should reflect.
            fetchedAtRef.current = startedAt;
            writeJson(K.fetchedAt, startedAt);
            reconcileOverlay();
        } catch (e) {
            console.warn('service-tracker: refresh failed (offline?)', e);
        } finally {
            refreshingRef.current = false;
            setIsRefreshing(false);
        }
    }, [reconcileOverlay]);

    const refreshSoon = useCallback(() => {
        if (refreshTimer.current) clearTimeout(refreshTimer.current);
        refreshTimer.current = setTimeout(() => void refresh(), 1500);
    }, [refresh]);

    // ---- boot: load queue, wire connectivity ----
    useEffect(() => {
        let alive = true;
        let prevPending = 0;
        const unsub = queue.subscribe((s) => {
            if (!alive) return;
            setQueueStats(s);
            setFailedItems(queue.getItems().filter((i) => i.status === 'dead'));
            const pending = s.pendingRecords + s.pendingPhotos;
            if (prevPending > 0 && pending === 0) {
                reconcileOverlay();
                refreshSoon();
            } else if (pending !== prevPending) {
                reconcileOverlay();
            }
            prevPending = pending;
        });
        (async () => {
            await queue.init();
            if (!alive) return;
            setReady(true);
            reconcileOverlay();
            void queue.flush({ ignoreBackoff: true });
            void refresh();
        })();

        // Connectivity: NetInfo is the proper signal; the app does not list it as a direct dependency
        // (only legacy code uses it), so it is optional here. Without it we still flush on foreground + every 30 s.
        let netUnsub: (() => void) | undefined;
        try {
            // eslint-disable-next-line @typescript-eslint/no-var-requires
            const NetInfo = require('@react-native-community/netinfo').default;
            netUnsub = NetInfo.addEventListener((s: any) => queue.setOnline(s.isConnected !== false && s.isInternetReachable !== false));
        } catch {
            /* NetInfo not installed */
        }
        const appSub = AppState.addEventListener('change', (st) => {
            if (st === 'active') {
                void queue.flush({ ignoreBackoff: true });
                refreshSoon();
            }
        });
        const interval = setInterval(() => void queue.flush(), 30_000);
        return () => {
            alive = false;
            unsub();
            netUnsub?.();
            appSub.remove();
            clearInterval(interval);
            if (refreshTimer.current) clearTimeout(refreshTimer.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // adapter becomes available after login -> try to send anything queued
    useEffect(() => {
        if (ready && adapter) {
            void queue.flush({ ignoreBackoff: true });
            void refresh();
        }
    }, [adapter, ready, queue, refresh]);

    // ---- effective (server + overlay) data ----
    const visits = useMemo(() => {
        const base = applyOverlay<Visit>('visit', serverVisits, overlay).map((v) => ({
            ...v,
            stops: applyOverlay<VendorStop>('stop', v.stops || [], overlay, v.id),
            jobs: applyOverlay<ServiceJob>('job', v.jobs || [], overlay, v.id),
            damage_records: applyOverlay<DamageRecord>('damage', v.damage_records || [], overlay, v.id),
        }));
        return base.sort((a, b) => (b.arrived_at_lsa || '').localeCompare(a.arrived_at_lsa || '') || b.id.localeCompare(a.id));
    }, [serverVisits, overlay]);

    const petty = useMemo(() => applyOverlay<PettyCashEntry>('petty', serverPetty, overlay).sort((a, b) => (b.entry_date || '').localeCompare(a.entry_date || '')), [serverPetty, overlay]);

    const getVisit = useCallback((id: string) => visits.find((v) => v.id === id || v.client_uuid === id), [visits]);

    // ---- write helpers ----
    /** Resolve how a queued write must refer to an entity that may exist only on this device. */
    const target = useCallback(
        (id: string): T.Target => {
            const sid = queue.getServerId(id);
            if (sid) return { id: sid, local: false };
            const o = overlayRef.current.entries.find((e) => e.id === id && e.created);
            return { id, local: !!o };
        },
        [queue]
    );

    const track = useCallback(
        (type: EntityType, id: string, patch: Record<string, any>, taskIds: string[], extra: { created?: boolean; parentId?: string } = {}) => {
            persistOverlay(upsertOverlay(overlayRef.current, { type, id, patch, created: !!extra.created, parentId: extra.parentId, taskIds }));
        },
        [persistOverlay]
    );

    const createVisit = useCallback<Ctx['createVisit']>(
        async (input) => {
            const id = uuidv4();
            const body = { plate: input.plate.trim().toUpperCase(), make_model: input.make_model || '', odometer: input.odometer ?? null, notes: input.notes || '', arrived_at_lsa: input.arrived_at_lsa ?? nowIso() };
            await queue.enqueue(T.createVisitTask(id, body));
            track('visit', id, { ...body, stops: [], jobs: [], damage_records: [], pickup_inspected: false, return_inspected: false, status: 'in_progress' }, [id], { created: true });
            void queue.flush();
            return id;
        },
        [queue, track]
    );

    const updateVisit = useCallback<Ctx['updateVisit']>(
        async (visitId, patch) => {
            const taskId = uuidv4();
            await queue.enqueue(T.updateVisitTask(target(visitId), patch, taskId));
            track('visit', visitId, patch, [taskId]);
            void queue.flush();
        },
        [queue, target, track]
    );

    const stampVisit = useCallback<Ctx['stampVisit']>(
        async (visitId, field) => {
            const at = nowIso();
            await updateVisit(visitId, { [field]: at });
            return at;
        },
        [updateVisit]
    );

    const addVendorStop = useCallback<Ctx['addVendorStop']>(
        async (visitId, vendorName) => {
            const id = uuidv4();
            const body = { vendor_name: vendorName };
            await queue.enqueue(T.createStopTask(target(visitId), id, body));
            track('stop', id, { ...body, arrived_at: null, approval_requested_at: null, approval_received_at: null, left_at: null }, [id], { created: true, parentId: visitId });
            void queue.flush();
            return id;
        },
        [queue, target, track]
    );

    const STAMP_FIELD: Record<StopAction, keyof VendorStop> = {
        'check-in': 'arrived_at',
        'approval-requested': 'approval_requested_at',
        'approval-received': 'approval_received_at',
        left: 'left_at',
    };

    const stampStop = useCallback<Ctx['stampStop']>(
        async (visitId, stopId, action) => {
            const at = nowIso(); // the moment of the tap, NOT the moment of delivery
            const taskId = uuidv4();
            await queue.enqueue(T.stopStampTask(target(visitId), target(stopId), action, at, taskId));
            track('stop', stopId, { [STAMP_FIELD[action]]: at }, [taskId], { parentId: visitId });
            void queue.flush();
            return at;
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [queue, target, track]
    );

    const saveJob = useCallback<Ctx['saveJob']>(
        async (visitId, input, existingJobId) => {
            const id = existingJobId || uuidv4();
            // wire name: `catalog_item_uuid` (the server also accepts the catalog public id); screens keep reading `catalog_item_id`
            const { catalog_item_id, ...rest } = input;
            const body = { ...rest, catalog_item_uuid: catalog_item_id ?? null };
            const patch = { ...body, catalog_item_id: catalog_item_id ?? null };
            if (existingJobId) {
                const taskId = uuidv4();
                await queue.enqueue(T.updateJobTask(target(visitId), target(existingJobId), body, taskId));
                track('job', id, patch, [taskId], { parentId: visitId });
            } else {
                await queue.enqueue(T.createJobTask(target(visitId), id, body));
                track('job', id, patch, [id], { created: true, parentId: visitId });
            }
            void queue.flush();
            return id;
        },
        [queue, target, track]
    );

    const submitInspection = useCallback<Ctx['submitInspection']>(
        async (visitId, stage, photos) => {
            const tg = target(visitId);
            const flag = stage === 'pickup' ? 'pickup_inspected' : 'return_inspected';
            const recId = uuidv4();
            const patch: Record<string, any> = { [flag]: true };
            // 1. photos first copied durably (resize + copy), 2. record write, 3. photo uploads as separate queue items
            const files: { uri: string; angle?: string }[] = [];
            for (const p of photos) files.push({ uri: await persistPhoto(p.uri), angle: p.angle });
            await queue.enqueue(T.updateVisitTask(tg, patch, recId));
            for (const f of files) await queue.enqueue(T.photoTask({ parent: tg, subjectType: 'visit', stage, angle: f.angle }, f.uri));
            const prev = (visits.find((v) => v.id === visitId)?.inspection_photos ?? {}) as any;
            const key = stage === 'pickup' ? 'pickup' : 'return';
            track('visit', visitId, { ...patch, inspection_photos: { ...prev, [key]: [...(prev[key] || []), ...files.map((f) => ({ uri: f.uri, local: true, angle: f.angle }))] } }, [recId]);
            void queue.flush();
        },
        [queue, target, track, visits]
    );

    const addDamage = useCallback<Ctx['addDamage']>(
        async (visitId, input, photoUris) => {
            const id = uuidv4();
            const tg = target(visitId);
            const files: string[] = [];
            for (const u of photoUris) files.push(await persistPhoto(u));
            const occurred_at = nowIso();
            const body = { ...input, occurred_at };
            await queue.enqueue(T.createDamageTask(id, tg, body));
            for (const f of files) await queue.enqueue(T.photoTask({ parent: { id, local: true }, subjectType: 'damage' }, f));
            track('damage', id, { ...body, photos: files.map((uri) => ({ uri, local: true })) }, [id], { created: true, parentId: visitId });
            void queue.flush();
            return id;
        },
        [queue, target, track]
    );

    const addPettyCash = useCallback<Ctx['addPettyCash']>(
        async (input, receiptUris, visitId) => {
            const id = uuidv4();
            const files: string[] = [];
            for (const u of receiptUris) files.push(await persistPhoto(u));
            const body = { entry_date: todayStr(), ...input };
            await queue.enqueue(T.createPettyCashTask(id, visitId ? target(visitId) : null, body));
            for (const f of files) await queue.enqueue(T.photoTask({ parent: { id, local: true }, subjectType: 'petty_cash' }, f));
            track('petty', id, { ...body, service_visit_id: visitId ?? null, photos: files.map((uri) => ({ uri, local: true })) }, [id], { created: true });
            void queue.flush();
            return id;
        },
        [queue, target, track]
    );

    const loadVehiclesDue = useCallback(async () => {
        const a: any = adapterRef.current;
        if (!a) return;
        try {
            const r = await a.get(`${T.NS}/vehicles/due`);
            const list = normalizeVehicleDue(asList(r)) as VehicleDue[];
            setVehiclesDue(list);
            writeJson(K.due, list);
        } catch (e) {
            console.warn('service-tracker: vehicles/due failed (offline? showing cache)', e);
        }
    }, []);

    const value = useMemo<Ctx>(
        () => ({
            ready,
            visits,
            petty,
            catalog,
            lists,
            vehiclesDue,
            queue: queueStats,
            failedItems,
            isRefreshing,
            refresh,
            loadVehiclesDue,
            getVisit,
            createVisit,
            stampVisit,
            updateVisit,
            addVendorStop,
            stampStop,
            saveJob,
            submitInspection,
            addDamage,
            addPettyCash,
            retryFailed: (id) => queue.retryDead(id),
            discardFailed: (id) => queue.discard(id),
        }),
        [ready, visits, petty, catalog, lists, vehiclesDue, queueStats, failedItems, isRefreshing, refresh, loadVehiclesDue, getVisit, createVisit, stampVisit, updateVisit, addVendorStop, stampStop, saveJob, submitInspection, addDamage, addPettyCash, queue]
    );

    return <ServiceVisitContext.Provider value={value}>{children}</ServiceVisitContext.Provider>;
};

export const useServiceVisits = (): Ctx => {
    const ctx = useContext(ServiceVisitContext);
    if (!ctx) throw new Error('useServiceVisits must be used inside <ServiceVisitProvider>');
    return ctx;
};

export default ServiceVisitContext;
