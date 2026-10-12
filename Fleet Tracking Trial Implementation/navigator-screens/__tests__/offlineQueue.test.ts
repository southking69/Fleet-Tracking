import { OfflineQueue, ref, NetworkError, PermanentError, TransportRequest } from '../src/services/offlineQueue';
import { FakeServer, MemoryStorage, ManualScheduler } from './helpers';

function make(opts: { storage?: MemoryStorage; server?: FakeServer; clock?: { t: number }; online?: boolean } = {}) {
    const storage = opts.storage ?? new MemoryStorage();
    const server = opts.server ?? new FakeServer();
    const clock = opts.clock ?? { t: 1_000_000 };
    const scheduler = new ManualScheduler(clock);
    const q = new OfflineQueue({ storage, transport: server.transport(), now: () => clock.t, scheduler, random: () => 0, baseDelayMs: 1000, maxDelayMs: 60_000 });
    return { q, storage, server, clock, scheduler };
}

/** A complete visit as the driver app would record it, entirely offline. */
async function recordFullVisit(q: OfflineQueue) {
    const V = 'visit-1', S1 = 'stop-1', S2 = 'stop-2', J = 'job-1', D = 'dmg-1', P = 'pc-1';
    await q.enqueue({ kind: 'record', clientUuid: V, method: 'POST', path: 'service-tracker/visits', body: { plate: 'ABC 1234', odometer: 120000, arrived_at_lsa: '2026-10-06T08:00:00Z' } });
    await q.enqueue({ kind: 'record', clientUuid: 'pickup', method: 'PATCH', path: `service-tracker/visits/${ref(V)}`, body: { picked_up_at: '2026-10-06T08:30:00Z', pickup_inspected: true } });
    for (let a = 0; a < 8; a++) await q.enqueue({ kind: 'photo', clientUuid: `insp-pickup-${a}`, parentClientUuid: V, path: 'service-tracker/uploads', fileUri: `file:///p${a}.jpg`, fields: { subject_type: 'visit', stage: 'Pickup inspection', angle: String(a) } });
    for (const [s, name] of [[S1, 'Petromin'], [S2, 'Local Mechanic']]) {
        await q.enqueue({ kind: 'record', clientUuid: s, method: 'POST', path: `service-tracker/visits/${ref(V)}/stops`, body: { vendor_name: name } });
        for (const act of ['check-in', 'approval-requested', 'approval-received', 'left']) {
            await q.enqueue({ kind: 'record', clientUuid: `${s}-${act}`, method: 'POST', path: `service-tracker/visits/${ref(V)}/stops/${ref(s)}/${act}`, body: { at: '2026-10-06T09:00:00Z' } });
        }
    }
    await q.enqueue({ kind: 'record', clientUuid: J, method: 'POST', path: `service-tracker/visits/${ref(V)}/jobs`, body: { service_name: 'Oil change', stop_client_uuid: ref(S1), status: 'Done' } });
    await q.enqueue({ kind: 'record', clientUuid: D, method: 'POST', path: 'service-tracker/damage-records', body: { visit_id: ref(V), location: 'Rear bumper', severity: 'Minor' } });
    for (let i = 0; i < 3; i++) await q.enqueue({ kind: 'photo', clientUuid: `dmg-photo-${i}`, parentClientUuid: D, path: 'service-tracker/uploads', fileUri: `file:///d${i}.jpg`, fields: { subject_type: 'damage' } });
    await q.enqueue({ kind: 'record', clientUuid: P, method: 'POST', path: 'service-tracker/petty-cash', body: { amount: 4500, currency: 'SAR', category: 'Fuel' } });
    await q.enqueue({ kind: 'photo', clientUuid: 'receipt-1', parentClientUuid: P, path: 'service-tracker/uploads', fileUri: 'file:///r.jpg', fields: { subject_type: 'petty_cash' } });
    await q.enqueue({ kind: 'record', clientUuid: 'return', method: 'PATCH', path: `service-tracker/visits/${ref(V)}`, body: { returned_at: '2026-10-06T16:00:00Z', return_inspected: true } });
}
const TOTAL_RECORDS = 1 + 1 + 2 * 5 + 1 + 1 + 1 + 1; // 15
const TOTAL_PHOTOS = 8 + 3 + 1; // 12

describe('OfflineQueue', () => {
    test('airplane mode: complete a whole visit offline, go online, exactly-once delivery in dependency order', async () => {
        const { q, server } = make();
        await q.init();
        q.setOnline(false);
        server.online = false;
        await recordFullVisit(q);
        await q.flush();
        expect(server.hits.length).toBe(0); // nothing leaves the device
        expect(q.getStats().pendingRecords).toBe(TOTAL_RECORDS);
        expect(q.getStats().pendingPhotos).toBe(TOTAL_PHOTOS);

        server.online = true;
        q.setOnline(true); // triggers flush
        await q.flush();

        const s = q.getStats();
        expect(s.total).toBe(0);
        expect(s.dead).toBe(0);
        expect(server.records.size).toBe(TOTAL_RECORDS);
        expect(server.photos.size).toBe(TOTAL_PHOTOS);
        // exactly once on the wire, no retries needed on a healthy link
        expect(server.hits.length).toBe(TOTAL_RECORDS + TOTAL_PHOTOS);
        for (const id of [...server.records.keys(), ...server.photos.keys()]) expect(server.hitsFor(id)).toBe(1);
        // ordering: visit first; each stop before its stamps; record writes strictly in FIFO order
        const recLog = server.hits.filter((h) => h.kind === 'record').map((h) => h.clientUuid);
        expect(recLog[0]).toBe('visit-1');
        expect(recLog.indexOf('stop-1')).toBeLessThan(recLog.indexOf('stop-1-check-in'));
        expect(recLog.indexOf('stop-1-check-in')).toBeLessThan(recLog.indexOf('stop-1-left'));
        expect(recLog[recLog.length - 1]).toBe('return');
        // refs were resolved into real server ids
        const stamp = server.hits.find((h) => h.clientUuid === 'stop-2-left')!;
        expect(stamp.path).toMatch(/^service-tracker\/visits\/srv-\d+\/stops\/srv-\d+\/left$/);
        // every write carried its client_uuid
        for (const h of server.hits.filter((x) => x.kind === 'record')) expect(h.body!.client_uuid).toBe(h.clientUuid);
        // photos only after parents
        const firstPhotoHit = server.hits.findIndex((h) => h.kind === 'photo');
        const lastRecordHit = server.hits.map((h) => h.kind).lastIndexOf('record');
        expect(firstPhotoHit).toBeGreaterThan(lastRecordHit); // lane order within a pass: records then photos
    });

    test('lost response (server applied the write, client saw a network error) does not duplicate', async () => {
        const { q, server, clock, scheduler } = make();
        await q.init();
        let lose = true;
        server.loseResponseFor = (r) => lose && r.clientUuid === 'visit-1';
        await recordFullVisit(q);
        await q.flush();
        expect(server.records.size).toBe(1); // server applied it
        expect(q.getStats().pendingRecords).toBe(TOTAL_RECORDS); // client believes nothing was delivered
        lose = false;
        await scheduler.advance(5000); // backoff elapses, wake timer fires retry
        await q.flush();
        expect(q.getStats().total).toBe(0);
        expect(server.records.size).toBe(TOTAL_RECORDS);
        expect(server.photos.size).toBe(TOTAL_PHOTOS);
        expect(server.hitsFor('visit-1')).toBe(2); // resent once, de-duped server side
        expect([...server.records.values()].filter((r) => r.req.clientUuid === 'visit-1').length).toBe(1);
        expect(server.log.filter((l) => l === 'record:POST service-tracker/visits').length).toBe(1);
    });

    test('partial failure mid-visit, app restart, resume: remaining items sent once, delivered ones never resent', async () => {
        const server = new FakeServer();
        const storage = new MemoryStorage();
        const a = make({ storage, server });
        await a.q.init();
        await recordFullVisit(a.q);
        // link dies after 6 record calls
        const realHandle = server.handle.bind(server);
        server.handle = async (req: TransportRequest) => {
            if (req.kind === 'record' && server.records.size >= 6 && !req.clientUuid.startsWith('x')) {
                if (server.hits.filter((h) => h.kind === 'record').length >= 6) throw new NetworkError('tunnel');
            }
            return realHandle(req);
        };
        await a.q.flush();
        const deliveredFirst = server.records.size;
        expect(deliveredFirst).toBe(6);
        expect(a.q.getStats().pendingRecords).toBe(TOTAL_RECORDS - 6);

        // --- app killed; new process, same persisted storage and same server ---
        server.handle = realHandle;
        const b = make({ storage: storage.clone(), server });
        await b.q.init();
        expect(b.q.getStats().pendingRecords).toBe(TOTAL_RECORDS - 6);
        expect(b.q.isDelivered('visit-1')).toBe(true);
        await b.q.flush({ ignoreBackoff: true });
        expect(b.q.getStats().total).toBe(0);
        expect(server.records.size).toBe(TOTAL_RECORDS);
        expect(server.photos.size).toBe(TOTAL_PHOTOS);
        for (const id of server.records.keys()) {
            // only the one record that failed (6 delivered + 1 failing attempt) may have been hit twice
            expect(server.hitsFor(id)).toBeLessThanOrEqual(2);
        }
        const delivered6 = [...server.records.keys()].slice(0, 6);
        for (const id of delivered6) expect(server.hitsFor(id)).toBe(1);
    });

    test('crash after server applied but before the queue persisted the delivery: replay is de-duped', async () => {
        const server = new FakeServer();
        const storage = new MemoryStorage();
        let snapshot: MemoryStorage | null = null;
        const a = make({ storage, server });
        await a.q.init();
        await a.q.enqueue({ kind: 'record', clientUuid: 'v', method: 'POST', path: 'service-tracker/visits', body: { plate: 'X' } });
        await a.q.enqueue({ kind: 'record', clientUuid: 'v-left', method: 'PATCH', path: `service-tracker/visits/${ref('v')}`, body: { returned_at: 'z' } });
        const real = server.handle.bind(server);
        server.handle = async (req) => {
            if (req.clientUuid === 'v' && !snapshot) snapshot = storage.clone(); // disk state at the moment of the call
            return real(req);
        };
        await a.q.flush();
        expect(server.records.size).toBe(2);
        // power loss: disk still shows the pre-delivery state
        server.handle = real;
        const b = make({ storage: snapshot!, server });
        await b.q.init();
        expect(b.q.getStats().pendingRecords).toBe(2);
        await b.q.flush();
        expect(b.q.getStats().total).toBe(0);
        expect(server.records.size).toBe(2); // still exactly two records on the server
    });

    test('enqueue is idempotent by client_uuid, also after delivery', async () => {
        const { q, server } = make();
        await q.init();
        const t = { kind: 'record', clientUuid: 'same', method: 'POST', path: 'service-tracker/petty-cash', body: { amount: 1 } } as const;
        expect((await q.enqueue(t)).deduped).toBe(false);
        expect((await q.enqueue(t)).deduped).toBe(true); // double tap
        await q.flush();
        expect((await q.enqueue(t)).deduped).toBe(true); // after delivery
        await q.flush();
        expect(server.hitsFor('same')).toBe(1);
        expect(server.records.size).toBe(1);
    });

    test('a stuck photo never blocks record writes; photo retries with backoff then completes', async () => {
        const { q, server, scheduler } = make();
        await q.init();
        let photoBroken = true;
        server.failWith = (r) => (r.kind === 'photo' && photoBroken ? 503 : null);
        await q.enqueue({ kind: 'record', clientUuid: 'dmg', method: 'POST', path: 'service-tracker/damage-records', body: { location: 'Hood' } });
        await q.enqueue({ kind: 'photo', clientUuid: 'ph', parentClientUuid: 'dmg', path: 'service-tracker/uploads', fileUri: 'file:///a.jpg' });
        await q.enqueue({ kind: 'record', clientUuid: 'pc', method: 'POST', path: 'service-tracker/petty-cash', body: { amount: 5 } });
        await q.flush();
        expect(server.records.size).toBe(2);
        expect(q.getStats()).toMatchObject({ pendingRecords: 0, pendingPhotos: 1 });
        photoBroken = false;
        await scheduler.advance(10_000);
        await q.flush();
        expect(q.getStats().total).toBe(0);
        expect(server.photos.size).toBe(1);
    });

    test('photo waits for its parent record; if parent is rejected permanently, photo is parked dead', async () => {
        const { q, server } = make();
        await q.init();
        server.failWith = (r) => (r.clientUuid === 'bad' ? 422 : null);
        await q.enqueue({ kind: 'record', clientUuid: 'bad', method: 'POST', path: 'service-tracker/damage-records', body: {} });
        await q.enqueue({ kind: 'photo', clientUuid: 'ph', parentClientUuid: 'bad', path: 'service-tracker/uploads', fileUri: 'file:///a.jpg' });
        await q.enqueue({ kind: 'record', clientUuid: 'ok', method: 'POST', path: 'service-tracker/petty-cash', body: {} });
        await q.flush();
        const items = q.getItems();
        expect(items.find((i) => i.clientUuid === 'bad')!.status).toBe('dead');
        expect(items.find((i) => i.clientUuid === 'ph')!.status).toBe('dead');
        expect(server.records.has('ok')).toBe(true); // queue was not blocked
        expect(server.hitsFor('ph')).toBe(0);
        // supervisor fixes the cause; user taps retry
        server.failWith = () => null;
        await q.retryDead();
        await q.flush({ ignoreBackoff: true });
        expect(q.getStats().total).toBe(0);
        expect(server.photos.size).toBe(1);
    });

    test('a record that depends on a dead record is parked dead, not sent with an unresolved id', async () => {
        const { q, server } = make();
        await q.init();
        server.failWith = (r) => (r.clientUuid === 'v' ? 400 : null);
        await q.enqueue({ kind: 'record', clientUuid: 'v', method: 'POST', path: 'service-tracker/visits', body: {} });
        await q.enqueue({ kind: 'record', clientUuid: 's', method: 'POST', path: `service-tracker/visits/${ref('v')}/stops`, body: {} });
        await q.flush();
        expect(q.getItems().map((i) => [i.clientUuid, i.status])).toEqual([['v', 'dead'], ['s', 'dead']]);
        expect(server.hitsFor('s')).toBe(0);
    });

    test('exponential backoff, capped, and head-of-line FIFO is preserved while backing off', async () => {
        const { q, server, clock, scheduler } = make();
        await q.init();
        server.failWith = (r) => (r.clientUuid === 'a' ? 500 : null);
        await q.enqueue({ kind: 'record', clientUuid: 'a', method: 'POST', path: 'service-tracker/petty-cash', body: {} });
        await q.enqueue({ kind: 'record', clientUuid: 'b', method: 'POST', path: 'service-tracker/petty-cash', body: {} });
        await q.flush();
        const delays: number[] = [];
        for (let i = 0; i < 4; i++) {
            const it = q.getItems().find((x) => x.clientUuid === 'a')!;
            delays.push(it.nextAttemptAt - clock.t);
            await scheduler.advance(it.nextAttemptAt - clock.t);
            await q.flush();
        }
        expect(delays).toEqual([1000, 2000, 4000, 8000]);
        expect(server.records.has('b')).toBe(false); // b never jumped ahead of a
        expect(server.hitsFor('b')).toBe(0);
        server.failWith = () => null;
        await q.flush({ ignoreBackoff: true });
        expect(server.log).toEqual(['record:POST service-tracker/petty-cash', 'record:POST service-tracker/petty-cash']);
        expect([...server.records.keys()]).toEqual(['a', 'b']);
    });

    test('server errors eventually park the item (maxServerFailures) but plain offline never does', async () => {
        const { q, server, scheduler } = make();
        await q.init();
        await q.enqueue({ kind: 'record', clientUuid: 'n', method: 'POST', path: 'service-tracker/petty-cash', body: {} });
        server.online = false;
        for (let i = 0; i < 40; i++) {
            await q.flush({ ignoreBackoff: true });
        }
        expect(q.getItems()[0].status).toBe('pending'); // 40 offline attempts, still alive
        server.online = true;
        server.failWith = () => 500;
        for (let i = 0; i < 12; i++) await q.flush({ ignoreBackoff: true });
        expect(q.getItems()[0].status).toBe('dead');
    });

    test('401 pauses the queue without burning attempts; resumeAuth continues', async () => {
        const { q, server } = make();
        await q.init();
        let expired = true;
        server.failWith = () => (expired ? 401 : null);
        await q.enqueue({ kind: 'record', clientUuid: 'a', method: 'POST', path: 'service-tracker/petty-cash', body: {} });
        await q.flush();
        await q.flush();
        expect(server.hits.length).toBe(1); // paused: second flush did not hit the wire
        expect(q.getItems()[0].attempts).toBe(0);
        expired = false;
        q.resumeAuth();
        await q.flush();
        expect(q.getStats().total).toBe(0);
    });

    test('409 duplicate answer counts as delivered', async () => {
        const { q, server } = make();
        await q.init();
        server.failWith = () => 409;
        await q.enqueue({ kind: 'record', clientUuid: 'a', method: 'POST', path: 'service-tracker/petty-cash', body: {} });
        await q.flush();
        expect(q.getStats().total).toBe(0);
    });

    test('overlapping flush() calls coalesce: no concurrent double-send', async () => {
        const { q, server } = make();
        await q.init();
        const real = server.handle.bind(server);
        let inflight = 0, maxInflight = 0;
        server.handle = async (req) => {
            inflight++;
            maxInflight = Math.max(maxInflight, inflight);
            await new Promise((r) => setTimeout(r, 5));
            inflight--;
            return real(req);
        };
        for (let i = 0; i < 5; i++) await q.enqueue({ kind: 'record', clientUuid: `r${i}`, method: 'POST', path: 'service-tracker/petty-cash', body: {} });
        await Promise.all([q.flush(), q.flush(), q.flush(), q.flush()]);
        expect(maxInflight).toBe(1);
        expect(server.hits.length).toBe(5);
        expect(server.records.size).toBe(5);
    });

    test('PermanentError (e.g. photo file deleted) parks only that item', async () => {
        const { q, server } = make();
        await q.init();
        const real = server.handle.bind(server);
        server.handle = async (req) => {
            if (req.clientUuid === 'ph1') throw new PermanentError('file missing');
            return real(req);
        };
        await q.enqueue({ kind: 'record', clientUuid: 'd', method: 'POST', path: 'service-tracker/damage-records', body: {} });
        await q.enqueue({ kind: 'photo', clientUuid: 'ph1', parentClientUuid: 'd', path: 'service-tracker/uploads', fileUri: 'file:///gone.jpg' });
        await q.enqueue({ kind: 'photo', clientUuid: 'ph2', parentClientUuid: 'd', path: 'service-tracker/uploads', fileUri: 'file:///ok.jpg' });
        await q.flush();
        expect(server.photos.has('ph2')).toBe(true);
        expect(q.getItems().map((i) => [i.clientUuid, i.status])).toEqual([['ph1', 'dead']]);
    });

    test('setOnline(true) flushes immediately, ignoring backoff', async () => {
        const { q, server } = make();
        await q.init();
        server.online = false;
        await q.enqueue({ kind: 'record', clientUuid: 'a', method: 'POST', path: 'service-tracker/petty-cash', body: {} });
        await q.flush();
        expect(q.getItems()[0].nextAttemptAt).toBeGreaterThan(0);
        server.online = true;
        q.setOnline(false);
        q.setOnline(true);
        await q.flush();
        expect(q.getStats().total).toBe(0);
    });

    test('corrupt persisted blob does not crash init; it is preserved aside', async () => {
        const storage = new MemoryStorage();
        storage.data.set('service_tracker.offline_queue.v1', '{not json');
        const { q } = make({ storage });
        await q.init();
        expect(q.getStats().total).toBe(0);
        expect(storage.data.get('service_tracker.offline_queue.v1.corrupt')).toBe('{not json');
    });

    test('after a restart, a NEW write can still reference a parent delivered in the previous session', async () => {
        const server = new FakeServer();
        const storage = new MemoryStorage();
        const a = make({ storage, server });
        await a.q.init();
        await a.q.enqueue({ kind: 'record', clientUuid: 'v', method: 'POST', path: 'service-tracker/visits', body: {} });
        await a.q.flush();
        const b = make({ storage: storage.clone(), server });
        await b.q.init();
        await b.q.enqueue({ kind: 'record', clientUuid: 's', method: 'POST', path: `service-tracker/visits/${ref('v')}/stops`, body: {} });
        await b.q.flush();
        expect(b.q.getStats().total).toBe(0);
        expect(server.hits[server.hits.length - 1].path).toBe(`service-tracker/visits/${a.q.getServerId('v')}/stops`);
    });

    test('photo for an already-synced record (server id) uploads immediately with subject_id', async () => {
        const { q, server } = make();
        await q.init();
        server.knownIds.add('visit_77');
        await q.enqueue({ kind: 'photo', clientUuid: 'ph', parentServerId: 'visit_77', path: 'service-tracker/uploads', fileUri: 'file:///a.jpg', fields: { subject_type: 'visit', angle: 'front' } });
        await q.flush();
        expect(q.getStats().total).toBe(0);
        expect(server.hits[0].photo!.fields).toMatchObject({ subject_id: 'visit_77', subject_type: 'visit', angle: 'front', client_uuid: 'ph' });
    });

    test('a photo that was sent twice (lost response) is stored once', async () => {
        const { q, server, scheduler } = make();
        await q.init();
        let lose = true;
        server.loseResponseFor = (r) => lose && r.kind === 'photo';
        await q.enqueue({ kind: 'record', clientUuid: 'd', method: 'POST', path: 'service-tracker/damage-records', body: {} });
        await q.enqueue({ kind: 'photo', clientUuid: 'ph', parentClientUuid: 'd', path: 'service-tracker/uploads', fileUri: 'file:///a.jpg' });
        await q.flush();
        lose = false;
        await scheduler.advance(10_000);
        await q.flush();
        expect(q.getStats().total).toBe(0);
        expect(server.photos.size).toBe(1);
        expect(server.hitsFor('ph')).toBe(2);
    });
});
