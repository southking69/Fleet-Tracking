/**
 * Offline write queue for Service Tracker.
 *
 * Framework-agnostic: no React, no React Native, no MMKV import. The host injects
 *   - a KeyValueStorage (MMKV / AsyncStorage / in-memory),
 *   - a Transport (the Fleetbase adapter),
 *   - optional clock / scheduler / random (so tests are deterministic).
 *
 * Guarantees
 *   - Persisted write-through on every mutation; survives app restart (items that were
 *     "in flight" when the app died are simply re-sent; the server de-dupes on client_uuid).
 *   - Record writes are strict FIFO. A transient failure at the head halts the record lane
 *     (order matters: a visit must exist before its stops) and retries with exponential backoff.
 *   - Photo uploads are a SEPARATE lane. They never block record writes and a failed photo
 *     never rolls back a record. A photo waits until its parent record has been delivered.
 *   - De-dupe by client_uuid: enqueueing the same client_uuid twice is a no-op, also after the
 *     original was delivered (delivered ids are kept in a bounded ledger).
 *   - Offline-created parents: a later task may reference `{{ref:<client_uuid>}}` in its path or
 *     body; it is resolved to the server id returned for that client_uuid once delivered.
 *   - 4xx (except 401/408/409/425/429) = permanent: item is parked as "dead" (with its
 *     dependants) for the user to retry/discard; it does not block the rest of the queue.
 *   - 409 is treated as "already delivered" (the server's idempotent duplicate answer).
 *   - 401 pauses the queue without burning attempts (host should re-authenticate then flush()).
 */

export type MaybePromise<T> = T | Promise<T>;

export interface KeyValueStorage {
    getItem(key: string): MaybePromise<string | null | undefined>;
    setItem(key: string, value: string): MaybePromise<void>;
    removeItem(key: string): MaybePromise<void>;
}

export type HttpMethod = 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RecordTaskInput {
    kind: 'record';
    /** Idempotency key. Generated if omitted. */
    clientUuid?: string;
    method: HttpMethod;
    /** May contain `{{ref:<client_uuid>}}` tokens. */
    path: string;
    /** May contain `{{ref:<client_uuid>}}` inside string values. client_uuid is added automatically. */
    body?: Record<string, any>;
    /** Extra explicit dependencies (client_uuids that must be delivered first). */
    dependsOn?: string[];
    label?: string;
}

export interface PhotoTaskInput {
    kind: 'photo';
    clientUuid?: string;
    /** client_uuid of a queued record this photo belongs to (waits for it). */
    parentClientUuid?: string;
    /** OR the server id of a record that already exists remotely (no waiting). */
    parentServerId?: string;
    path: string;
    fileUri: string;
    fileName?: string;
    mime?: string;
    /** Extra multipart text fields (subject_type, kind, angle ...). */
    fields?: Record<string, string>;
    label?: string;
}

export type TaskInput = RecordTaskInput | PhotoTaskInput;

export type ItemStatus = 'pending' | 'dead';

export interface QueueItem {
    clientUuid: string;
    seq: number;
    kind: 'record' | 'photo';
    status: ItemStatus;
    createdAt: number;
    attempts: number;
    serverFailures: number;
    nextAttemptAt: number;
    lastError?: string;
    label?: string;
    // record
    method?: HttpMethod;
    path: string;
    body?: Record<string, any>;
    dependsOn?: string[];
    // photo
    parentClientUuid?: string;
    parentServerId?: string;
    fileUri?: string;
    fileName?: string;
    mime?: string;
    fields?: Record<string, string>;
}

export interface QueueState {
    version: 1;
    seq: number;
    items: QueueItem[];
    /** client_uuid -> server id, for every delivered record (bounded). */
    refs: Record<string, string>;
    refOrder: string[];
}

export interface TransportRequest {
    kind: 'record' | 'photo';
    method: HttpMethod;
    path: string;
    clientUuid: string;
    body?: Record<string, any>;
    photo?: { uri: string; name: string; type: string; fields: Record<string, string> };
}

export interface TransportResponse {
    status: number;
    data?: any;
}

/** Throw this from a Transport for failures without an HTTP response (offline, timeout, DNS). */
export class NetworkError extends Error {
    constructor(message = 'Network request failed') {
        super(message);
        this.name = 'NetworkError';
    }
}

/** Throw this for failures that can never succeed on retry (e.g. photo file vanished). */
export class PermanentError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'PermanentError';
    }
}

export interface Transport {
    send(req: TransportRequest): Promise<TransportResponse>;
}

export interface Scheduler {
    set(fn: () => void, ms: number): unknown;
    clear(handle: unknown): void;
}

export interface QueueStats {
    online: boolean;
    flushing: boolean;
    pendingRecords: number;
    pendingPhotos: number;
    dead: number;
    total: number;
    nextAttemptAt: number | null;
    lastError?: string;
}

export interface QueueOptions {
    storage: KeyValueStorage;
    transport: Transport;
    storageKey?: string;
    now?: () => number;
    random?: () => number;
    scheduler?: Scheduler;
    baseDelayMs?: number;
    maxDelayMs?: number;
    jitter?: number;
    maxServerFailures?: number;
    maxLedger?: number;
    extractId?: (data: any, clientUuid: string) => string | undefined;
    onDelivered?: (item: QueueItem, response: TransportResponse) => void;
}

const REF_RE = /\{\{ref:([A-Za-z0-9_-]+)\}\}/g;

export const ref = (clientUuid: string) => `{{ref:${clientUuid}}}`;

export function uuidv4(random: () => number = Math.random): string {
    const g: any = typeof globalThis !== 'undefined' ? globalThis : {};
    if (g.crypto && typeof g.crypto.randomUUID === 'function') return g.crypto.randomUUID();
    const hex = (n: number) => Math.floor(random() * n).toString(16);
    let s = '';
    for (let i = 0; i < 36; i++) {
        if (i === 8 || i === 13 || i === 18 || i === 23) s += '-';
        else if (i === 14) s += '4';
        else if (i === 19) s += (8 + Math.floor(random() * 4)).toString(16);
        else s += hex(16);
    }
    return s;
}

function findRefs(value: any, out: Set<string>) {
    if (typeof value === 'string') {
        let m: RegExpExecArray | null;
        const re = new RegExp(REF_RE.source, 'g');
        while ((m = re.exec(value))) out.add(m[1]);
    } else if (Array.isArray(value)) {
        value.forEach((v) => findRefs(v, out));
    } else if (value && typeof value === 'object') {
        Object.values(value).forEach((v) => findRefs(v, out));
    }
}

function resolveRefs<T>(value: T, refs: Record<string, string>): T {
    if (typeof value === 'string') {
        return value.replace(new RegExp(REF_RE.source, 'g'), (_m, id) => refs[id] ?? _m) as any;
    }
    if (Array.isArray(value)) return value.map((v) => resolveRefs(v, refs)) as any;
    if (value && typeof value === 'object') {
        const o: any = {};
        for (const [k, v] of Object.entries(value as any)) o[k] = resolveRefs(v, refs);
        return o;
    }
    return value;
}

const defaultExtractId = (data: any, clientUuid: string): string | undefined => {
    const d = data?.data ?? data;
    const id = d?.public_id ?? d?.id ?? d?.uuid;
    return id != null ? String(id) : undefined;
};

const defaultScheduler: Scheduler = {
    set: (fn, ms) => setTimeout(fn, ms),
    clear: (h) => clearTimeout(h as any),
};

type Outcome = 'delivered' | 'transient' | 'network' | 'auth' | 'dead';

export class OfflineQueue {
    private opts: Required<Omit<QueueOptions, 'onDelivered'>> & Pick<QueueOptions, 'onDelivered'>;
    private state: QueueState = { version: 1, seq: 0, items: [], refs: {}, refOrder: [] };
    private loaded = false;
    private online = true;
    private authPaused = false;
    private flushing: Promise<void> | null = null;
    private rerun: { ignoreBackoff: boolean } | null = null;
    private timer: unknown = null;
    private writeChain: Promise<void> = Promise.resolve();
    private listeners = new Set<(s: QueueStats) => void>();
    private lastError: string | undefined;

    constructor(options: QueueOptions) {
        this.opts = {
            storageKey: 'service_tracker.offline_queue.v1',
            now: () => Date.now(),
            random: Math.random,
            scheduler: defaultScheduler,
            baseDelayMs: 2000,
            maxDelayMs: 5 * 60 * 1000,
            jitter: 0.2,
            maxServerFailures: 10,
            maxLedger: 5000,
            extractId: defaultExtractId,
            ...options,
        } as any;
    }

    /** Load persisted state. Must be awaited once before use (e.g. at app start). */
    async init(): Promise<void> {
        if (this.loaded) return;
        try {
            const raw = await this.opts.storage.getItem(this.opts.storageKey);
            if (raw) {
                const parsed = JSON.parse(raw) as QueueState;
                if (parsed && parsed.version === 1 && Array.isArray(parsed.items)) {
                    this.state = { version: 1, seq: parsed.seq || 0, items: parsed.items, refs: parsed.refs || {}, refOrder: parsed.refOrder || Object.keys(parsed.refs || {}) };
                }
            }
        } catch (e) {
            // Corrupt blob: keep it aside rather than silently lose it, start clean.
            try {
                const raw = await this.opts.storage.getItem(this.opts.storageKey);
                if (raw) await this.opts.storage.setItem(this.opts.storageKey + '.corrupt', raw);
            } catch {}
        }
        this.loaded = true;
        this.emit();
    }

    // ---------- public API ----------

    subscribe(fn: (s: QueueStats) => void): () => void {
        this.listeners.add(fn);
        return () => this.listeners.delete(fn);
    }

    getStats(): QueueStats {
        const items = this.state.items;
        const pend = items.filter((i) => i.status === 'pending');
        const next = pend.length ? Math.min(...pend.map((i) => i.nextAttemptAt)) : null;
        return {
            online: this.online,
            flushing: !!this.flushing,
            pendingRecords: pend.filter((i) => i.kind === 'record').length,
            pendingPhotos: pend.filter((i) => i.kind === 'photo').length,
            dead: items.filter((i) => i.status === 'dead').length,
            total: items.length,
            nextAttemptAt: next,
            lastError: this.lastError,
        };
    }

    getItems(): QueueItem[] {
        return this.state.items.map((i) => ({ ...i }));
    }

    getServerId(clientUuid: string): string | undefined {
        return this.state.refs[clientUuid];
    }

    isDelivered(clientUuid: string): boolean {
        return clientUuid in this.state.refs;
    }

    /** Returns the client_uuid. A repeated client_uuid is ignored (deduped). */
    async enqueue(input: TaskInput): Promise<{ clientUuid: string; deduped: boolean }> {
        this.assertLoaded();
        const clientUuid = input.clientUuid || uuidv4(this.opts.random);
        if (this.state.items.some((i) => i.clientUuid === clientUuid) || clientUuid in this.state.refs) {
            return { clientUuid, deduped: true };
        }
        const base = {
            clientUuid,
            seq: ++this.state.seq,
            status: 'pending' as ItemStatus,
            createdAt: this.opts.now(),
            attempts: 0,
            serverFailures: 0,
            nextAttemptAt: 0,
            label: input.label,
        };
        let item: QueueItem;
        if (input.kind === 'record') {
            item = { ...base, kind: 'record', method: input.method, path: input.path, body: input.body ? JSON.parse(JSON.stringify(input.body)) : {}, dependsOn: input.dependsOn };
        } else {
            item = {
                ...base,
                kind: 'photo',
                path: input.path,
                parentClientUuid: input.parentClientUuid,
                parentServerId: input.parentServerId,
                fileUri: input.fileUri,
                fileName: input.fileName || `${clientUuid}.jpg`,
                mime: input.mime || 'image/jpeg',
                fields: { ...(input.fields || {}) },
            };
        }
        this.state.items.push(item);
        await this.persist();
        this.emit();
        return { clientUuid, deduped: false };
    }

    setOnline(online: boolean): void {
        const was = this.online;
        this.online = online;
        this.emit();
        if (online && !was) {
            // Connectivity regained: try right away, ignoring any backoff delay.
            void this.flush({ ignoreBackoff: true });
        }
    }

    /** Call after the user re-authenticates. */
    resumeAuth(): void {
        this.authPaused = false;
        void this.flush({ ignoreBackoff: true });
    }

    async retryDead(clientUuid?: string): Promise<void> {
        let changed = false;
        for (const it of this.state.items) {
            if (it.status === 'dead' && (!clientUuid || it.clientUuid === clientUuid)) {
                it.status = 'pending';
                it.attempts = 0;
                it.serverFailures = 0;
                it.nextAttemptAt = 0;
                changed = true;
            }
        }
        if (changed) {
            await this.persist();
            this.emit();
            void this.flush({ ignoreBackoff: true });
        }
    }

    async discard(clientUuid: string): Promise<void> {
        this.state.items = this.state.items.filter((i) => i.clientUuid !== clientUuid);
        await this.persist();
        this.emit();
    }

    /**
     * Process what can be processed now. Concurrent calls coalesce: a call during a running flush
     * schedules exactly one extra pass after it.
     */
    flush(opts: { ignoreBackoff?: boolean } = {}): Promise<void> {
        this.assertLoaded();
        if (this.flushing) {
            this.rerun = { ignoreBackoff: !!(opts.ignoreBackoff || this.rerun?.ignoreBackoff) };
            return this.flushing;
        }
        this.flushing = (async () => {
            try {
                let ignore = !!opts.ignoreBackoff;
                for (;;) {
                    await this.pass(ignore);
                    if (!this.rerun) break;
                    ignore = this.rerun.ignoreBackoff;
                    this.rerun = null;
                }
            } finally {
                this.flushing = null;
                this.scheduleWake();
                this.emit();
            }
        })();
        this.emit();
        return this.flushing;
    }

    dispose(): void {
        if (this.timer != null) this.opts.scheduler.clear(this.timer);
        this.timer = null;
        this.listeners.clear();
    }

    // ---------- internals ----------

    private assertLoaded() {
        if (!this.loaded) throw new Error('OfflineQueue.init() must be awaited before use');
    }

    private emit() {
        const s = this.getStats();
        this.listeners.forEach((l) => {
            try {
                l(s);
            } catch {}
        });
    }

    private persist(): Promise<void> {
        // trim ledger
        const max = this.opts.maxLedger;
        while (this.state.refOrder.length > max) {
            const old = this.state.refOrder.shift()!;
            delete this.state.refs[old];
        }
        const blob = JSON.stringify(this.state);
        this.writeChain = this.writeChain.then(() => Promise.resolve(this.opts.storage.setItem(this.opts.storageKey, blob)));
        return this.writeChain;
    }

    private backoff(attempts: number): number {
        const { baseDelayMs, maxDelayMs, jitter, random } = this.opts;
        const d = Math.min(maxDelayMs, baseDelayMs * Math.pow(2, Math.max(0, attempts - 1)));
        return Math.round(d * (1 + jitter * random()));
    }

    private scheduleWake() {
        if (this.timer != null) {
            this.opts.scheduler.clear(this.timer);
            this.timer = null;
        }
        if (!this.online || this.authPaused) return;
        const pend = this.state.items.filter((i) => i.status === 'pending');
        if (!pend.length) return;
        const next = Math.min(...pend.map((i) => i.nextAttemptAt));
        const delay = Math.max(0, next - this.opts.now());
        if (next === 0 && !pend.some((i) => i.attempts > 0)) return; // nothing failed; waiting on a dependency or a trigger
        this.timer = this.opts.scheduler.set(() => {
            this.timer = null;
            void this.flush();
        }, Math.max(50, delay));
    }

    private unresolvedDeps(it: QueueItem): string[] {
        const need = new Set<string>(it.dependsOn || []);
        if (it.kind === 'record') {
            findRefs(it.path, need);
            findRefs(it.body, need);
        } else if (it.parentClientUuid) {
            need.add(it.parentClientUuid);
            findRefs(it.path, need);
            findRefs(it.fields, need);
        }
        return [...need].filter((id) => !(id in this.state.refs));
    }

    private depState(missing: string[]): 'ready' | 'wait' | 'dead' {
        if (!missing.length) return 'ready';
        for (const id of missing) {
            const dep = this.state.items.find((i) => i.clientUuid === id);
            if (!dep || dep.status === 'dead') return 'dead';
        }
        return 'wait';
    }

    private async pass(ignoreBackoff: boolean) {
        if (!this.online || this.authPaused) return;
        // Lane 1: records, strict FIFO.
        for (;;) {
            const it = this.state.items.filter((i) => i.kind === 'record' && i.status === 'pending').sort((a, b) => a.seq - b.seq)[0];
            if (!it) break;
            if (!ignoreBackoff && it.nextAttemptAt > this.opts.now()) break;
            const missing = this.unresolvedDeps(it);
            const ds = this.depState(missing);
            if (ds === 'dead') {
                await this.markDead(it, `dependency not delivered: ${missing.join(',')}`);
                continue;
            }
            if (ds === 'wait') break; // earlier record still pending (backed off): keep FIFO
            const out = await this.attempt(it);
            if (out === 'delivered' || out === 'dead') continue;
            if (out === 'network' || out === 'auth') return; // nothing else can succeed right now
            break; // server error on the head record: keep FIFO, but let photos proceed
        }
        // Lane 2: photos, FIFO among ready items, independent of records' progress.
        const photos = this.state.items.filter((i) => i.kind === 'photo' && i.status === 'pending').sort((a, b) => a.seq - b.seq);
        for (const it of photos) {
            if (!this.online || this.authPaused) return;
            if (it.status !== 'pending') continue;
            if (!ignoreBackoff && it.nextAttemptAt > this.opts.now()) continue;
            const missing = this.unresolvedDeps(it);
            const ds = this.depState(missing);
            if (ds === 'dead') {
                await this.markDead(it, `parent not delivered: ${missing.join(',')}`);
                continue;
            }
            if (ds === 'wait') continue;
            const out = await this.attempt(it);
            if (out === 'network' || out === 'auth') return;
        }
    }

    private async markDead(it: QueueItem, msg: string) {
        it.status = 'dead';
        it.lastError = msg;
        this.lastError = msg;
        await this.persist();
        this.emit();
        // cascade: anything that depended on this is dead on the next loop via depState
    }

    private buildRequest(it: QueueItem): TransportRequest {
        const refs = this.state.refs;
        if (it.kind === 'record') {
            const body = resolveRefs({ ...(it.body || {}), client_uuid: it.clientUuid }, refs);
            return { kind: 'record', method: it.method!, path: resolveRefs(it.path, refs), clientUuid: it.clientUuid, body };
        }
        return {
            kind: 'photo',
            method: 'POST',
            path: resolveRefs(it.path, refs),
            clientUuid: it.clientUuid,
            photo: {
                uri: it.fileUri!,
                name: it.fileName || `${it.clientUuid}.jpg`,
                type: it.mime || 'image/jpeg',
                fields: resolveRefs(
                    {
                        ...(it.fields || {}),
                        client_uuid: it.clientUuid,
                        ...(it.parentClientUuid ? { parent_client_uuid: it.parentClientUuid, subject_id: refs[it.parentClientUuid] ?? '' } : {}),
                        ...(it.parentServerId ? { subject_id: it.parentServerId } : {}),
                    },
                    refs
                ),
            },
        };
    }

    private async attempt(it: QueueItem): Promise<Outcome> {
        let status: number | undefined;
        let data: any;
        let errMsg = '';
        let outcome: Outcome;
        try {
            const res = await this.opts.transport.send(this.buildRequest(it));
            status = res.status;
            data = res.data;
        } catch (e: any) {
            if (e instanceof PermanentError) {
                await this.markDead(it, e.message);
                return 'dead';
            }
            const s = e?.status ?? e?.response?.status;
            if (typeof s === 'number') {
                status = s;
                data = e?.response?.data;
                errMsg = e?.message || `HTTP ${s}`;
            } else {
                errMsg = e?.message || 'Network request failed';
                status = undefined;
            }
        }

        if (status !== undefined && ((status >= 200 && status < 300) || status === 409)) {
            outcome = 'delivered';
        } else if (status === 401) {
            outcome = 'auth';
        } else if (status === undefined) {
            outcome = 'network';
        } else if (status >= 500 || status === 408 || status === 425 || status === 429) {
            outcome = 'transient';
        } else {
            outcome = 'dead';
        }

        if (outcome === 'delivered') {
            const id = this.opts.extractId(data, it.clientUuid) ?? it.clientUuid;
            this.state.items = this.state.items.filter((x) => x.clientUuid !== it.clientUuid);
            this.state.refs[it.clientUuid] = id;
            this.state.refOrder.push(it.clientUuid);
            this.lastError = undefined;
            await this.persist();
            try {
                this.opts.onDelivered?.(it, { status: status!, data });
            } catch {}
            this.emit();
            return 'delivered';
        }
        if (outcome === 'dead') {
            await this.markDead(it, errMsg || `HTTP ${status}`);
            return 'dead';
        }

        it.attempts += 1;
        it.lastError = errMsg || `HTTP ${status}`;
        this.lastError = it.lastError;
        if (outcome === 'auth') {
            it.attempts -= 1;
            this.authPaused = true;
        } else {
            if (outcome === 'transient') it.serverFailures += 1;
            if (it.serverFailures >= this.opts.maxServerFailures) {
                await this.markDead(it, `gave up after ${it.serverFailures} server errors: ${it.lastError}`);
                return 'dead';
            }
            it.nextAttemptAt = this.opts.now() + this.backoff(it.attempts);
        }
        await this.persist();
        this.emit();
        return outcome;
    }
}
