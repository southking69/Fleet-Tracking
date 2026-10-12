import { calcStages, stopInfo, toMinorUnits, fromMinorUnits, dueStatusFromLeft, fitWithin, applyOverlay, pruneOverlay, upsertOverlay, slug, INSPECTION_ANGLES, Visit, Overlay, VendorStop, fmtHours } from '../src/services/visitModel';
import parity from './fixtures/calc-parity.json';

describe('turnaround maths: parity with Index.html calcVisit()', () => {
    // fixture expected values were produced by executing the ORIGINAL calcVisit/stopInfo source from Index.html
    test.each(parity.map((p: any) => [p.name, p]))('%s', (_n, p: any) => {
        const v: Visit = { id: 'x', plate: 'P', ...p.visit, stops: p.stops.map((s: any, i: number) => ({ id: String(i), ...s })) };
        const c = calcStages(v, Date.parse('2026-10-06T20:00:00Z'));
        expect(c.stages.length).toBe(6);
        c.stages.forEach((s, i) => (p.expected.stages[i] == null ? expect(s).toBeNull() : expect(s).toBeCloseTo(p.expected.stages[i], 9)));
        if (p.expected.total == null) expect(c.total).toBeNull();
        else expect(c.total).toBeCloseTo(p.expected.total, 9);
        expect(c.status).toBe(p.expected.status);
        expect(c.big).toBe(p.expected.big);
        expect(c.bigName).toBe(p.expected.bigName);
        expect(c.owner).toBe(p.expected.owner);
        if (p.expected.THV == null) expect(c.THV).toBeNull();
        else expect(c.THV).toBeCloseTo(p.expected.THV, 9);
        c.si.forEach((s, i) => {
            for (const k of ['atVendor', 'appr', 'net'] as const) {
                const e = p.expected.si[i][k];
                e == null ? expect(s[k]).toBeNull() : expect(s[k]).toBeCloseTo(e, 9);
            }
        });
    });

    test('stopInfo net = at-vendor minus approval wait', () => {
        const i = stopInfo({ arrived_at: '2026-10-06T09:00:00Z', left_at: '2026-10-06T11:00:00Z', approval_requested_at: '2026-10-06T09:30:00Z', approval_received_at: '2026-10-06T10:00:00Z' });
        expect(i).toEqual({ atVendor: 2, appr: 0.5, net: 1.5 });
    });

    test('open visit reports hours open; target met flag', () => {
        const v: Visit = { id: 'a', plate: 'P', arrived_at_lsa: '2026-10-06T08:00:00Z', target_hours: 12 };
        expect(calcStages(v, Date.parse('2026-10-06T14:00:00Z')).open).toBe(6);
        const closed: Visit = { ...v, returned_at: '2026-10-06T18:00:00Z' };
        expect(calcStages(closed).met).toBe(true);
        expect(calcStages({ ...closed, returned_at: '2026-10-07T09:00:00Z' }).met).toBe(false);
    });

    test('fmtHours', () => {
        expect(fmtHours(null)).toBe('–');
        expect(fmtHours(0.5)).toBe('30m');
        expect(fmtHours(3.8333333)).toBe('3h 50m');
    });
});

describe('money and due helpers', () => {
    test('toMinorUnits: SAR input to halalas, incl. Arabic-Indic digits', () => {
        expect(toMinorUnits('45')).toBe(4500);
        expect(toMinorUnits('45.5')).toBe(4550);
        expect(toMinorUnits('1,234.56')).toBe(123456);
        expect(toMinorUnits('٤٥٫٥')).toBe(4550); // ٤٥٫٥
        expect(toMinorUnits('0.1')).toBe(10);
        expect(toMinorUnits('19.99')).toBe(1999); // float-safe rounding
        expect(toMinorUnits('abc')).toBeNull();
        expect(toMinorUnits('')).toBeNull();
        expect(fromMinorUnits(123456)).toBe('1234.56');
    });
    test('dueStatusFromLeft thresholds match the desktop tracker (<0 overdue, <=1000 soon)', () => {
        expect(dueStatusFromLeft(-1)).toBe('OVERDUE');
        expect(dueStatusFromLeft(0)).toBe('Due soon');
        expect(dueStatusFromLeft(1000)).toBe('Due soon');
        expect(dueStatusFromLeft(1001)).toBe('On time');
        expect(dueStatusFromLeft(null)).toBe('No previous due');
    });
    test('photo target size: longest side 1000, no upscale', () => {
        expect(fitWithin(4000, 3000)).toEqual({ width: 1000, height: 750 });
        expect(fitWithin(3000, 4000)).toEqual({ width: 750, height: 1000 });
        expect(fitWithin(800, 600)).toEqual({ width: 800, height: 600 });
    });
    test('the 8 suggested inspection angles', () => {
        expect([...INSPECTION_ANGLES]).toEqual(['front', 'rear', 'left', 'right', 'front-left', 'front-right', 'rear-left', 'rear-right']);
    });
    test('slug', () => {
        expect(slug('In progress')).toBe('in_progress');
        expect(slug('Pre-existing')).toBe('pre_existing');
        expect(slug('Wheel / Rim')).toBe('wheel_rim');
    });
});

describe('optimistic overlay', () => {
    const base: Overlay = { entries: [] };
    test('created entity appears immediately, patches apply to server copy, no duplicate once server returns it', () => {
        let o = upsertOverlay(base, { type: 'stop', id: 'local-1', patch: { vendor_name: 'Petromin' }, created: true, parentId: 'v1', taskIds: ['local-1'] });
        o = upsertOverlay(o, { type: 'stop', id: 'local-1', patch: { arrived_at: 'T' }, created: false, parentId: 'v1', taskIds: ['t2'] });
        let list = applyOverlay<VendorStop>('stop', [], o, 'v1');
        expect(list).toHaveLength(1);
        expect(list[0]).toMatchObject({ id: 'local-1', vendor_name: 'Petromin', arrived_at: 'T', _pending: true });
        // server now returns it under its own id but with our client_uuid
        list = applyOverlay<VendorStop>('stop', [{ id: 'stop_abc', client_uuid: 'local-1', vendor_name: 'Petromin' }], o, 'v1');
        expect(list).toHaveLength(1);
        expect(list[0]).toMatchObject({ id: 'stop_abc', arrived_at: 'T' });
        // other parents are untouched
        expect(applyOverlay<VendorStop>('stop', [], o, 'v2')).toHaveLength(0);
    });
    test('prune: only after all tasks delivered AND a later server read', () => {
        const o = upsertOverlay(base, { type: 'visit', id: 'a', patch: { notes: 'x' }, created: true, taskIds: ['a', 'b'] });
        const delivered = new Set<string>(['a']);
        expect(pruneOverlay(o, (id) => delivered.has(id), 1e15).entries).toHaveLength(1); // b not delivered yet
        delivered.add('b');
        const marked = pruneOverlay(o, (id) => delivered.has(id), null);
        expect(marked.entries).toHaveLength(1);
        expect(marked.entries[0].deliveredAt).toBeDefined();
        expect(pruneOverlay(marked, (id) => delivered.has(id), marked.entries[0].deliveredAt! - 1).entries).toHaveLength(1); // fetched before delivery: keep
        expect(pruneOverlay(marked, (id) => delivered.has(id), marked.entries[0].deliveredAt! + 1).entries).toHaveLength(0);
    });
});
