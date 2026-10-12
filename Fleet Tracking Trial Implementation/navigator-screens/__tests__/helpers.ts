import { KeyValueStorage, Scheduler, Transport, TransportRequest, TransportResponse, NetworkError } from '../src/services/offlineQueue';

export class MemoryStorage implements KeyValueStorage {
    data = new Map<string, string>();
    getItem(k: string) {
        return this.data.get(k) ?? null;
    }
    setItem(k: string, v: string) {
        this.data.set(k, v);
    }
    removeItem(k: string) {
        this.data.delete(k);
    }
    clone() {
        const s = new MemoryStorage();
        this.data.forEach((v, k) => s.data.set(k, v));
        return s;
    }
}

/** Server with client_uuid idempotency exactly as SPEC.md requires. */
export class FakeServer {
    records = new Map<string, { id: string; req: TransportRequest }>(); // by client_uuid
    photos = new Map<string, { id: string; req: TransportRequest }>();
    hits: TransportRequest[] = [];
    log: string[] = []; // order of FIRST application
    private n = 0;
    online = true;
    /** if set, the response is lost after the server applied the write */
    loseResponseFor: (req: TransportRequest) => boolean = () => false;
    failWith: (req: TransportRequest) => number | null = () => null;
    knownIds = new Set<string>();

    transport(): Transport {
        return { send: (req) => this.handle(req) };
    }

    async handle(req: TransportRequest): Promise<TransportResponse> {
        if (!this.online) throw new NetworkError();
        this.hits.push(req);
        const f = this.failWith(req);
        if (f) return { status: f, data: { error: 'x' } };
        const store = req.kind === 'photo' ? this.photos : this.records;
        // refs must be resolved before reaching the wire
        if (/\{\{ref:/.test(req.path) || /\{\{ref:/.test(JSON.stringify(req.body || req.photo?.fields || {}))) {
            return { status: 422, data: { error: 'unresolved ref' } };
        }
        // a path that mentions a server id must mention one we issued
        const m = /^service-tracker\/visits\/([^/]+)/.exec(req.path);
        if (m && !this.knownIds.has(m[1])) return { status: 404, data: { error: 'no such visit ' + m[1] } };
        let existing = store.get(req.clientUuid);
        if (!existing) {
            existing = { id: `srv-${++this.n}`, req };
            store.set(req.clientUuid, existing);
            this.knownIds.add(existing.id);
            this.log.push(`${req.kind}:${req.method} ${req.path}`);
        }
        if (this.loseResponseFor(req)) throw new NetworkError('response lost');
        return { status: 200, data: { data: { id: existing.id } } };
    }

    hitsFor(clientUuid: string) {
        return this.hits.filter((h) => h.clientUuid === clientUuid).length;
    }
}

export class ManualScheduler implements Scheduler {
    tasks: { id: number; fn: () => void; at: number }[] = [];
    private n = 0;
    constructor(private clock: { t: number }) {}
    set(fn: () => void, ms: number) {
        const id = ++this.n;
        this.tasks.push({ id, fn, at: this.clock.t + ms });
        return id;
    }
    clear(h: unknown) {
        this.tasks = this.tasks.filter((t) => t.id !== h);
    }
    /** advance time and run due tasks */
    async advance(ms: number) {
        this.clock.t += ms;
        const due = this.tasks.filter((t) => t.at <= this.clock.t);
        this.tasks = this.tasks.filter((t) => t.at > this.clock.t);
        for (const d of due) {
            d.fn();
            await new Promise((r) => setImmediate(r));
        }
    }
}
