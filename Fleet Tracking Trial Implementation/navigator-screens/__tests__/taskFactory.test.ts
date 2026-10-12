import * as T from '../src/services/taskFactory';
import { OfflineQueue } from '../src/services/offlineQueue';
import { FakeServer, MemoryStorage } from './helpers';

describe('taskFactory', () => {
    test('stamp on a locally created visit/stop uses ref tokens; on server entities uses ids', () => {
        const local = T.stopStampTask({ id: 'uv', local: true }, { id: 'us', local: true }, 'check-in', '2026-10-06T09:00:00.000Z', 'c1');
        expect(local.path).toBe('service-tracker/visits/{{ref:uv}}/stops/{{ref:us}}/check-in');
        expect(local.body).toEqual({ at: '2026-10-06T09:00:00.000Z' });
        const remote = T.stopStampTask({ id: 'visit_1', local: false }, { id: 'vstop_1', local: false }, 'approval-requested', 'T', 'c2');
        expect(remote.path).toBe('service-tracker/visits/visit_1/stops/vstop_1/approval-requested');
    });
    test('photo task waits on a queued parent, but not on a server parent', () => {
        expect(T.photoTask({ parent: { id: 'dmg-x', local: true }, subjectType: 'damage' }, 'file:///a.jpg', 'p1')).toMatchObject({ kind: 'photo', parentClientUuid: 'dmg-x' });
        const p = T.photoTask({ parent: { id: 'visit_9', local: false }, subjectType: 'visit', stage: 'pickup', angle: 'front' }, 'file:///a.jpg', 'p2') as any;
        expect(p.parentServerId).toBe('visit_9');
        expect(p.parentClientUuid).toBeUndefined();
        expect(p.fields).toEqual({ subject_type: 'visit', stage: 'pickup', angle: 'front' });
    });

    test('driver flow through the factory: 4 vendor-stop stamps taken offline keep their tap times and arrive once', async () => {
        const server = new FakeServer();
        const q = new OfflineQueue({ storage: new MemoryStorage(), transport: server.transport(), now: () => 1, scheduler: { set: () => 0, clear: () => {} } });
        await q.init();
        q.setOnline(false);
        await q.enqueue(T.createVisitTask('v', { plate: 'ABC 1' }));
        await q.enqueue(T.createStopTask({ id: 'v', local: true }, 's', { vendor_name: 'Petromin' }));
        const times = ['2026-10-06T09:00:00.000Z', '2026-10-06T09:20:00.000Z', '2026-10-06T10:00:00.000Z', '2026-10-06T11:00:00.000Z'];
        const acts = ['check-in', 'approval-requested', 'approval-received', 'left'] as const;
        for (let i = 0; i < 4; i++) await q.enqueue(T.stopStampTask({ id: 'v', local: true }, { id: 's', local: true }, acts[i], times[i], `st${i}`));
        // user double-taps "Left": same client_uuid is not queued twice
        expect((await q.enqueue(T.stopStampTask({ id: 'v', local: true }, { id: 's', local: true }, 'left', times[3], 'st3'))).deduped).toBe(true);
        server.online = true;
        q.setOnline(true);
        await q.flush();
        expect(q.getStats().total).toBe(0);
        expect(server.records.size).toBe(6);
        const stamps = server.hits.filter((h) => /\/(check-in|approval-requested|approval-received|left)$/.test(h.path));
        expect(stamps.map((s) => s.body!.at)).toEqual(times); // device tap times, in tap order, not delivery time
        expect(stamps.map((s) => s.path.split('/').pop())).toEqual([...acts]);
    });
});
