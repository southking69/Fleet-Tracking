#!/usr/bin/env node
/* Oracle generator: extracts the REAL functions from src/Index.html and runs them to produce
 * tests/fixtures/expected.json. Usage: node tests/oracle/gen.js [path/to/Index.html]
 * Time zone is pinned to Asia/Riyadh (no DST) and Date.now() is stubbed per fixture. */
process.env.TZ = 'Asia/Riyadh';
const fs = require('fs'), path = require('path');
const htmlPath = process.argv[2] || path.resolve(__dirname, '../../../../src/Index.html');
const src = fs.readFileSync(htmlPath, 'utf8');

function grabLine(re) { const m = src.match(re); if (!m) throw new Error('not found: ' + re); return m[0]; }
function grabBlock(startRe) { // from `function name(...) {` to its matching brace
  const m = startRe.exec(src); if (!m) throw new Error('not found: ' + startRe);
  let i = src.indexOf('{', m.index), d = 0, j = i;
  for (; j < src.length; j++) { if (src[j] === '{') d++; else if (src[j] === '}') { d--; if (!d) break; } }
  return src.slice(m.index, j + 1);
}
const helpers = ['ms', 'diffH', 'num', 'norm', 'sum'].map(n => grabLine(new RegExp('^const ' + n + ' = .*$', 'm'))).join('\n');
const a = src.indexOf('const visitById = '), b = src.indexOf('/* ================= device, ids');
if (a < 0 || b < 0) throw new Error('calc block not found');
const calcBlock = src.slice(a, b);                        // visitById .. calcVisit (+ STAGE_*)
const seedText = src.slice(src.indexOf('const SEED_CATALOG = ['), src.indexOf('];', src.indexOf('const SEED_CATALOG = [')) + 2);
const defaultSettingsSrc = grabBlock(/function defaultSettings\(\)/);
const loadSampleSrc = grabBlock(/function loadSample\(\)/);

const api = new Function('NOW', `
  'use strict';
  let S = null; const __Date = Date; const realNow = Date.now;
  ${helpers}
  ${seedText}
  ${defaultSettingsSrc}
  ${calcBlock}
  ${loadSampleSrc}
  const counters = { visits: 0 };
  const nextId = (p, k) => 'V-JA' + String(++counters.visits).padStart(4, '0');
  const ensureDevice = () => true, save = () => {};
  let uidN = 0; const uid = () => 'x' + (++uidN);
  return {
    setState(s) { S = s; }, getState() { return S; }, SEED_CATALOG, defaultSettings, loadSample, calcVisit, svcInfo, nextDueOf, stopInfo,
    reset() { counters.visits = 0; uidN = 0; S = { settings: defaultSettings(), catalog: SEED_CATALOG.map(c => ({ ...c })), visits: [], stops: [], services: [], damages: [], expenses: [] }; },
    helpers: { ms, diffH, num, norm, sum },
    setNow(n) { Date.now = () => n; }
  };
`);
const J = api(0);

function run(name, state, now) {
  J.setNow(now);
  J.setState(state);
  const S = state;
  const ctx = { catalog: S.catalog, lanes: S.settings.lanes, vendors: S.settings.vendors, allVisits: S.visits, allJobs: S.services, now };
  const cases = S.visits.map(v => {
    const r = J.calcVisit(v);
    const { stops, svcs, ...expected } = r;
    return { visit: v, stops: S.stops.filter(x => x.visitId === v.id), jobs: S.services.filter(x => x.visitId === v.id), expected };
  });
  const svc = S.services.map(sv => ({ job: sv, expected: J.svcInfo(sv), nextDue: J.nextDueOf(sv) }));
  return { name, ctx, cases, svc };
}
const clone = o => JSON.parse(JSON.stringify(o));
const fixtures = [];
const NOW = new Date('2026-10-12T12:00').getTime();

// 1. the real sample data, produced by the real loadSample()
J.reset(); J.loadSample();
fixtures.push(run('sample', J.getState(), NOW));

// helpers for invented edge fixtures
function base() { J.reset(); const S = J.getState(); S.settings.vendors = ['Petromin', '', 'Tyre Vendor', 'Local Mechanic']; return S; }
let sid = 0;
const V = (S, id, o) => { const v = Object.assign({ id, plate: 'AAA 1', make: 'X', odo: 1000, arrived: '', picked: '', returned: '' }, o); S.visits.push(v); return v; };
const ST = (S, vid, o) => S.stops.push(Object.assign({ id: 's' + (++sid), visitId: vid, vendor: 'Petromin', arrived: '', apprReq: '', apprRec: '', left: '' }, o));
const SV = (S, vid, service, o) => S.services.push(Object.assign({ id: 'j' + (++sid), visitId: vid, service, vendor: 'Petromin', nextOverride: '', nextDate: '' }, o));

{ // open visit (in progress, now injected)
  const S = base(); V(S, 'V1', { odo: 5000, arrived: '2026-10-12T06:30', picked: '2026-10-12T07:15' });
  ST(S, 'V1', { arrived: '2026-10-12T08:00', left: '' }); SV(S, 'V1', 'Oil change - engine oil & filter');
  V(S, 'V2', { odo: 7000 }); // not started
  fixtures.push(run('open-visit-and-not-started', S, NOW));
}
{ // missing timestamps on a closed visit
  const S = base(); V(S, 'V1', { odo: 5000, arrived: '2026-10-05T08:00', picked: '', returned: '2026-10-05T18:00' });
  ST(S, 'V1', { arrived: '2026-10-05T10:00', apprReq: '2026-10-05T10:10', apprRec: '', left: '2026-10-05T14:00' }); SV(S, 'V1', 'Wheel alignment');
  V(S, 'V2', { odo: 5100, arrived: '2026-10-06T08:00', picked: '2026-10-06T09:00', returned: '2026-10-06T18:00' });
  ST(S, 'V2', { arrived: '2026-10-06T10:00', left: '' }); ST(S, 'V2', { arrived: '2026-10-06T12:00', left: '2026-10-06T13:00' }); SV(S, 'V2', 'Wheel balancing');
  fixtures.push(run('missing-timestamps', S, NOW));
}
{ // no services / no stops
  const S = base(); V(S, 'V1', { odo: 5000, arrived: '2026-10-05T08:00', picked: '2026-10-05T09:00', returned: '2026-10-05T18:00' });
  ST(S, 'V1', { arrived: '2026-10-05T10:00', left: '2026-10-05T14:00' });
  V(S, 'V2', { odo: 5000, arrived: '2026-10-05T08:00', picked: '2026-10-05T09:00', returned: '2026-10-05T18:00' }); SV(S, 'V2', 'Wheel balancing');
  V(S, 'V3', { odo: 5000, arrived: '2026-10-05T08:00', returned: '2026-10-05T18:00' });
  fixtures.push(run('no-services-no-stops', S, NOW));
}
{ // missing odometer + odometer oddities
  const S = base();
  V(S, 'V1', { odo: '', arrived: '2026-10-05T08:00', picked: '2026-10-05T09:00', returned: '2026-10-05T17:00' }); ST(S, 'V1', { arrived: '2026-10-05T09:30', left: '2026-10-05T15:00' }); SV(S, 'V1', 'Oil change - engine oil & filter');
  V(S, 'V2', { odo: '50000', arrived: '2026-10-06T08:00', picked: '2026-10-06T09:00', returned: '2026-10-06T17:00' }); ST(S, 'V2', { arrived: '2026-10-06T09:30', left: '2026-10-06T15:00' }); SV(S, 'V2', 'Oil change - engine oil & filter');
  V(S, 'V3', { odo: 'abc', plate: 'ZZ 9' }); SV(S, 'V3', 'Oil change - engine oil & filter');
  V(S, 'V4', { odo: null, plate: 'ZZ 9' }); SV(S, 'V4', 'Not in catalog');
  V(S, 'V5', { odo: 0, plate: '' }); SV(S, 'V5', 'Oil change - engine oil & filter');
  fixtures.push(run('missing-odometer', S, NOW));
}
{ // same plate repeat service overdue (+ plate normalisation, picks MAX prev nextDue, ignores later odometer)
  const S = base();
  V(S, 'V1', { plate: 'abc-1234', odo: 10000, arrived: '2026-09-01T08:00' }); SV(S, 'V1', 'Oil change - engine oil & filter'); // nextDue 15000
  V(S, 'V2', { plate: 'ABC 1234', odo: 12000, arrived: '2026-09-10T08:00' }); SV(S, 'V2', 'Oil change - engine oil & filter'); // nextDue 17000
  V(S, 'V3', { plate: 'A.B_C1234', odo: 18000, arrived: '2026-10-01T08:00', picked: '2026-10-01T09:00', returned: '2026-10-01T19:00' });
  ST(S, 'V3', { arrived: '2026-10-01T09:30', left: '2026-10-01T17:00' }); SV(S, 'V3', 'Oil change - engine oil & filter'); SV(S, 'V3', 'Timing belt / chain replacement'); SV(S, 'V3', 'Wheel alignment');
  V(S, 'V4', { plate: 'ABC1234', odo: 17000, arrived: '2026-10-02T08:00' }); SV(S, 'V4', 'Oil change - engine oil & filter'); // exactly on prev due 17000 => On time
  V(S, 'V5', { plate: 'ABC1234', odo: 30000, arrived: '2026-10-03T08:00' }); SV(S, 'V5', 'Oil change - engine oil & filter'); SV(S, 'V5', 'Wheel alignment');
  V(S, 'V6', { plate: 'ABC1234', odo: 18000, arrived: '2026-10-04T08:00' }); SV(S, 'V6', 'Oil change - engine oil & filter'); // same odo as V3: not "earlier"
  fixtures.push(run('same-plate-repeat-overdue', S, NOW));
}
{ // override next-due (override wins, 0 override, string override, previous-due via override)
  const S = base();
  V(S, 'V1', { plate: 'OVR 1', odo: 20000, arrived: '2026-09-01T08:00' }); SV(S, 'V1', 'Oil change - engine oil & filter', { nextOverride: 22000 }); SV(S, 'V1', 'Battery replacement', { nextOverride: '25000' }); SV(S, 'V1', 'Wheel alignment', { nextOverride: 0 });
  V(S, 'V2', { plate: 'OVR 1', odo: 23000, arrived: '2026-10-01T08:00' }); SV(S, 'V2', 'Oil change - engine oil & filter', { nextOverride: 99999 }); SV(S, 'V2', 'Battery replacement'); SV(S, 'V2', 'Wheel alignment');
  fixtures.push(run('override-next-due', S, NOW));
}
{ // multi-vendor, lanes, between-vendors, clamp, ties, empty/zero lane target, vendor not in settings
  const S = base(); S.settings.lanes = [{ name: 'A', target: 24 }, { name: 'B', target: 24 }, { name: 'Zero', target: 0 }, { name: 'S', target: '12' }];
  S.catalog.push({ service: 'Zero svc', category: 'C', lane: 'Zero', vendor: 'Petromin', interval: 0 }, { service: 'S svc', category: 'C', lane: 'S', vendor: '', interval: 1000 });
  V(S, 'V1', { plate: 'MV 1', odo: 100, arrived: '2026-10-05T08:00', picked: '2026-10-05T09:00', returned: '2026-10-05T20:00' });
  ST(S, 'V1', { vendor: 'Petromin', arrived: '2026-10-05T09:30', apprReq: '2026-10-05T09:40', apprRec: '2026-10-05T10:40', left: '2026-10-05T12:00' });
  ST(S, 'V1', { vendor: 'Tyre Vendor', arrived: '2026-10-05T13:00', left: '2026-10-05T15:00' });
  ST(S, 'V1', { vendor: 'Unlisted', arrived: '2026-10-05T15:10', left: '2026-10-05T16:00' });
  SV(S, 'V1', 'Zero svc'); SV(S, 'V1', 'S svc'); SV(S, 'V1', 'Wheel alignment');
  V(S, 'V2', { plate: 'MV 2', odo: 100, arrived: '2026-10-05T08:00', picked: '2026-10-05T09:00', returned: '2026-10-05T10:00' }); // overlapping stops => negative between, clamp
  ST(S, 'V2', { arrived: '2026-10-05T09:10', left: '2026-10-05T09:50' }); ST(S, 'V2', { arrived: '2026-10-05T09:10', left: '2026-10-05T09:50' }); SV(S, 'V2', 'Wheel balancing');
  V(S, 'V3', { plate: 'MV 3', odo: 100, arrived: '2026-10-05T08:00', picked: '2026-10-05T08:00', returned: '2026-10-05T08:00' }); // all-zero stages tie
  ST(S, 'V3', { arrived: '2026-10-05T08:00', left: '2026-10-05T08:00' }); SV(S, 'V3', 'Oil change - engine oil & filter');
  V(S, 'V4', { plate: 'MV 4', odo: 100, arrived: '2026-10-05T08:00', picked: '2026-10-05T09:00', returned: '2026-10-05T10:00' }); // total 2 vs target 12 => met; and returned before left => negative ret
  ST(S, 'V4', { arrived: '2026-10-05T09:10', left: '2026-10-05T11:00' }); SV(S, 'V4', 'Oil change - engine oil & filter');
  V(S, 'V5', { plate: 'MV 5', odo: 100, arrived: '2026-10-05T08:00', picked: '2026-10-05T08:20', returned: '2026-10-05T08:50:30' }); // seconds, non-round
  ST(S, 'V5', { arrived: '2026-10-05T08:31:10', left: '2026-10-05T08:40:20' }); SV(S, 'V5', 'Oil change - engine oil & filter');
  fixtures.push(run('multi-vendor-lanes-clamp', S, NOW));
}
{ // date-only / garbage / bogus open-visit quirks
  const S = base();
  V(S, 'V1', { arrived: '2026-10-05', picked: '2026-10-05T09:00', returned: '2026-10-06' }); ST(S, 'V1', { arrived: '2026-10-05T10:00', left: '2026-10-05T11:00' }); SV(S, 'V1', 'Wheel alignment');
  V(S, 'V2', { arrived: 'garbage', picked: '', returned: '' }); SV(S, 'V2', 'Wheel alignment'); // open with unparseable arrived => (now - null)/36e5
  V(S, 'V3', { arrived: '2026-10-05T08:00', picked: '2026-10-05T09:00', returned: 'garbage' }); ST(S, 'V3', { arrived: '2026-10-05T09:30', left: 'junk' }); SV(S, 'V3', 'Wheel alignment');
  fixtures.push(run('date-quirks', S, NOW));
}

const helperCases = {
  num: ['', null, '  ', '12', ' 12 ', '1e3', '0x10', 'abc', true, false, 0, '5.', '.5', '-3', '1,000', '+7', 12.5, '1_0', '--1'],
  norm: ['abc 1234', 'ABC-1234', 'a.b_c 1 2', '  x y  ', '', null, 0, 'ا ب-1', 'ab\u00a0c', 'ß-x', 'tab\tnl\nz'],
  ms: ['', null, '2026-10-05T08:00', '2026-10-05T08:00:30', '2026-10-05T08:00:30.123', '2026-10-05', '2026-10-05T08:00Z', '2026-10-05T08:00+05:30', '2026-10-05T08:00:00-0200', '2026-10-05 08:00', 'garbage', '2026-13-01T00:00', '2026-02-30', '2026-10-05T25:00', '2026-10-05T24:00'],
};
const helperOut = {};
for (const k of Object.keys(helperCases)) helperOut[k] = helperCases[k].map(x => ({ input: x, expected: J.helpers[k](x) }));
helperOut.diffH = [['2026-10-05T08:00', '2026-10-05T09:30'], ['2026-10-05T08:00', ''], ['', '2026-10-05T08:00'], ['2026-10-05', '2026-10-05T08:00'], ['2026-10-05T10:00', '2026-10-05T08:00']].map(x => ({ input: x, expected: J.helpers.diffH(...x) }));
const out = path.resolve(__dirname, '../fixtures/expected.json');
fs.writeFileSync(out, JSON.stringify({ generator: 'tests/oracle/gen.js', tz: 'Asia/Riyadh', helpers: helperOut, fixtures }, null, 1));
console.log('wrote', out, fixtures.length, 'fixtures,', fixtures.reduce((n, f) => n + f.cases.length, 0), 'visits,', fixtures.reduce((n, f) => n + f.svc.length, 0), 'services');
