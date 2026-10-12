#!/usr/bin/env node
/* REAL-DATA ORACLE.
 * Extracts the REAL functions from src/Index.html (same approach as tests/oracle/gen.js), loads the production
 * Drive db (tests/realdata/fleet-data.json) the way the tracker does (collections object -> array, tombstoned
 * records dropped with the Code.gs / mergeRemote rule  tomb.t >= record._u), and writes the expected results to
 * tests/realdata/expected.realdata.json:
 *   - per visit   : calcVisit (stages, total, target, lane, met, big, check, overdue, per vendor, per stop, per job)
 *   - per job     : svcInfo + nextDueOf
 *   - per vehicle : vehicleIndex() + the "next services due" table of vehicleDetail()
 *   - petty cash  : totals per car / visit (linked and visitExpenses()) / category / payer
 *   - dashboard   : every KPI table of dashboardView()  (the DOM code is ported 1:1, calc functions are the real ones)
 * Usage: node tests/realdata/oracle.js [fleet-data.json] [Index.html] [out.json]
 * Time zone pinned to Asia/Riyadh, Date.now() pinned to NOW below. */
process.env.TZ = 'Asia/Riyadh';
const fs = require('fs'), path = require('path');
const dataPath = process.argv[2] || path.resolve(__dirname, 'fleet-data.json');
const htmlPath = process.argv[3] || path.resolve(__dirname, '../../../../src/Index.html');
const outPath = process.argv[4] || path.resolve(__dirname, 'expected.realdata.json');
const src = fs.readFileSync(htmlPath, 'utf8');
const NOW_LOCAL = '2026-10-12T14:00';           // Riyadh wall clock "now" used for open-visit hours

function grabLine(re) { const m = src.match(re); if (!m) throw new Error('not found: ' + re); return m[0]; }
function grabBlock(startRe) {
  const m = startRe.exec(src); if (!m) throw new Error('not found: ' + startRe);
  let i = src.indexOf('{', m.index), d = 0, j = i;
  for (; j < src.length; j++) { if (src[j] === '{') d++; else if (src[j] === '}') { d--; if (!d) break; } }
  return src.slice(m.index, j + 1);
}
const helpers = ['ms', 'diffH', 'num', 'norm', 'avg', 'sum', 'pkey'].map(n => grabLine(new RegExp('^const ' + n + ' = .*$', 'm'))).join('\n');
const a = src.indexOf('const visitById = '), b = src.indexOf('/* ================= device, ids');
const calcBlock = src.slice(a, b);
const photosOf = grabLine(/^const photosOf = .*$/m), listOf = grabLine(/^const listOf = .*$/m);
const vehicleIndexSrc = grabBlock(/function vehicleIndex\(VS, EX\)/);
const visitExpensesSrc = grabBlock(/function visitExpenses\(v\)/);
const migrateSrc = grabBlock(/function migrate\(\)/);
const seedText = src.slice(src.indexOf('const SEED_CATALOG = ['), src.indexOf('];', src.indexOf('const SEED_CATALOG = [')) + 2);
const defaultSettingsSrc = grabBlock(/function defaultSettings\(\)/);
const freshStateSrc = grabBlock(/function freshState\(\)/);

const api = new Function('NOW', `
  'use strict';
  let S = null;
  ${helpers}
  ${photosOf}
  ${listOf}
  ${seedText}
  ${defaultSettingsSrc}
  ${freshStateSrc}
  ${calcBlock}
  ${vehicleIndexSrc}
  ${visitExpensesSrc}
  ${migrateSrc}
  return { setState(s) { S = s; }, getState() { return S; }, migrate, calcVisit, svcInfo, nextDueOf, stopInfo, vehicleIndex, visitExpenses, listOf, photosOf,
           helpers: { ms, diffH, num, norm, avg, sum, pkey }, STAGE_NAMES, STAGE_SHORT, STAGE_OWNER, setNow(n) { Date.now = () => n; } };
`);
const J = api(0);
const { avg, sum, pkey, num } = J.helpers;

/* ---- load the db like the tracker would (server delta -> client state) ---- */
const raw = JSON.parse(fs.readFileSync(dataPath, 'utf8'));
const db = raw.state || raw;
const COLL = ['visits', 'stops', 'services', 'damages', 'expenses'];
const toList = c => Array.isArray(db[c]) ? db[c].map(r => ({ ...r })) : Object.keys(db[c] || {}).map(id => ({ id, ...db[c][id] }));
const tombT = k => { const t = (db.tomb || {})[k]; return t == null ? null : (typeof t === 'object' ? t.t : t); };
const S = { settings: JSON.parse(JSON.stringify(db.settings)), catalog: JSON.parse(JSON.stringify(db.catalog || [])), tomb: {}, _su: db._su || 0, _cu: db._cu || 0, counters: { visit: 1, stop: 1, service: 1, damage: 1 } };
const tombSkipped = {};
COLL.forEach(c => {
  tombSkipped[c] = [];
  S[c] = toList(c).filter(r => { const t = tombT(c + ':' + r.id); if (t != null && t >= (r._u || 0)) { tombSkipped[c].push(r.id); return false; } return true; });
});
J.setState(S); J.migrate();
J.setNow(new Date(NOW_LOCAL).getTime());
const NOW = new Date(NOW_LOCAL).getTime();

/* ---- per visit / per job ---- */
const visits = S.visits.map(v => {
  const r = J.calcVisit(v); const { stops, svcs, ...expected } = r;
  return { id: v.id, stopIds: stops.map(s => s.id), jobIds: svcs.map(s => s.id), expected };
});
const jobs = S.services.map(sv => ({ id: sv.id, visitId: sv.visitId, expected: J.svcInfo(sv), nextDue: J.nextDueOf(sv) }));

/* ---- vehicles (vehicleIndex is the real function; the due table is vehicleDetail() ported) ---- */
const vehicles = J.vehicleIndex().map(x => {
  const ids = new Set(x.visits.map(v => v.id));
  const latest = new Map();
  S.services.filter(s => ids.has(s.visitId) && s.service).forEach(s => { const i = J.svcInfo(s); if (i.nextDue == null) return; const p = latest.get(s.service); if (!p || (i.odo || 0) >= (p.i.odo || 0)) latest.set(s.service, { s, i }); });
  const due = [...latest.values()].map(({ s, i }) => ({ name: s.service, done: i.odo, next: i.nextDue, left: x.lastOdo != null ? i.nextDue - x.lastOdo : null }))
    .sort((p, q) => (p.left ?? 1e12) - (q.left ?? 1e12))
    .map(d => ({ ...d, status: d.left == null ? '' : d.left < 0 ? 'OVERDUE' : d.left <= 1000 ? 'Due soon' : 'OK' }));
  return { key: x.key, plate: x.plate, make: x.make, visitIds: x.visits.map(v => v.id), visits: x.visits.length, closed: x.closed, open: x.open, jobs: x.jobs, lastVisit: x.lastVisit,
    lastOdo: x.lastOdo, damages: x.damages, overdue: x.overdue, cash: x.cash, expenses: x.expenses.length, due };
});

/* ---- petty cash ---- */
const r2 = n => Math.round(n * 100) / 100;
const groupSum = (rows, keyf) => { const o = {}; rows.forEach(e => { const k = keyf(e); o[k] = (o[k] || 0) + (num(e.amount) || 0); }); Object.keys(o).forEach(k => o[k] = r2(o[k])); return o; };
const petty = {
  total: r2(sum(S.expenses.map(e => num(e.amount)))), entries: S.expenses.length,
  byCategory: groupSum(S.expenses, e => e.category || '(no category)'),
  byCar: groupSum(S.expenses, e => pkey(e.plate) || '(none)'),
  byPaidBy: groupSum(S.expenses, e => e.paidBy || '(none)'),
  byVisitLinked: groupSum(S.expenses.filter(e => e.visitId), e => e.visitId),
  byVisitReport: Object.fromEntries(S.visits.map(v => [v.id, r2(sum(J.visitExpenses(v).map(e => num(e.amount))))])),
  cars: new Set(S.expenses.map(e => pkey(e.plate)).filter(Boolean)).size,
};

/* ---- dashboard (dashboardView body, no date filter) ---- */
const vs = S.visits, ids = new Set(vs.map(v => v.id)), cs = vs.map(v => ({ v, c: J.calcVisit(v) }));
const stops = S.stops.filter(s => ids.has(s.visitId)), svcs = S.services.filter(s => ids.has(s.visitId)), dmg = S.damages.filter(d => ids.has(d.visitId));
const closed = cs.filter(x => x.c.status === 'Closed'), judged = cs.filter(x => x.c.met != null);
const avgs = J.STAGE_NAMES.map((_, i) => avg(cs.map(x => x.c.stages[i]))), tot = sum(avgs);
const sinfo = svcs.map(J.svcInfo);
const od = sinfo.filter(x => x.status === 'OVERDUE'), cmp = od.length + sinfo.filter(x => x.status === 'On time').length;
const pct = (n, d) => d ? Math.round(n / d * 100) : null;
const vendorsList = S.settings.vendors.filter(Boolean);
const dashboard = {
  kpis: { visits: vs.length, closed: closed.length, in_progress: cs.filter(x => x.c.status === 'In progress').length, jobs: svcs.length, stops: stops.length,
    petty_cash_total: petty.total, avg_turnaround_hours: avg(cs.map(x => x.c.total)), pct_within_target: pct(judged.filter(x => x.c.met).length, judged.length), open_over_24h: cs.filter(x => x.c.open > 24).length },
  stageAvg: avgs, stageShare: avgs.map(x => (tot && x) ? x / tot * 100 : 0),
  byOwner: Object.fromEntries(['Our team', 'Splendor', 'Vendor'].map(o => [o, closed.filter(x => x.c.owner === o).length])),
  byLane: S.settings.lanes.map(l => { const m = cs.filter(x => x.c.lane === l.name), cl = m.filter(x => x.c.status === 'Closed'), j = m.filter(x => x.c.met != null);
    return { lane: l.name, target: num(l.target), closed: cl.length, avg_turnaround: avg(m.map(x => x.c.total)), pct_within_target: pct(j.filter(x => x.c.met).length, j.length) }; }),
  byVendor: vendorsList.map(n => { const ss = stops.filter(s => s.vendor === n), inf = ss.map(J.stopInfo);
    return { vendor: n, stops: ss.length, jobs_done: svcs.filter(s => s.vendor === n).length, avg_per_stop: avg(inf.map(x => x.atVendor)), avg_approval: avg(inf.map(x => x.appr)), avg_net: avg(inf.map(x => x.net)) }; }),
  categories: Object.fromEntries(S.settings.categories.map(cn => [cn, sinfo.filter(x => x.category === cn).length])),
  damage: { records: dmg.length, new_records: dmg.filter(d => d.condition === 'New').length,
    closed_no_pickup_inspection: closed.filter(x => !x.v.pins).length, closed_no_return_inspection: closed.filter(x => !x.v.rins).length,
    closed_no_pickup_photos: closed.filter(x => !J.listOf(x.v, 'pickupPhotos').length).length, closed_no_return_photos: closed.filter(x => !J.listOf(x.v, 'returnPhotos').length).length,
    closed_with_data_problems: closed.filter(x => x.c.check && x.c.check !== 'OK').length },
  compliance: { comparable: cmp, overdue: od.length, pct_overdue: pct(od.length, cmp), avg_km_over: od.length ? Math.round(avg(od.map(x => x.kmOver))) : null,
    worst_km_over: od.length ? Math.max(...od.map(x => x.kmOver)) : null, visits_with_overdue: cs.filter(x => x.c.overdueN > 0).length, missing_odometer: sinfo.filter(x => x.status === 'Odometer missing').length },
};

const vids = new Set(S.visits.map(v => v.id));
const orphans = { stops: S.stops.filter(x => !vids.has(x.visitId)).map(x => x.id), services: S.services.filter(x => !vids.has(x.visitId)).map(x => x.id), damages: S.damages.filter(x => !vids.has(x.visitId)).map(x => x.id) };
const out = {
  orphans, generator: 'tests/realdata/oracle.js', tz: 'Asia/Riyadh', nowLocal: NOW_LOCAL, nowMs: NOW, source: path.basename(dataPath), rev: db.rev,
  counts: Object.fromEntries(COLL.map(c => [c, S[c].length])), tombSkipped,
  lanes: S.settings.lanes, vendors: S.settings.vendors,
  visits, jobs, vehicles, petty, dashboard,
};
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
console.log('wrote', outPath, '-', vs.length, 'visits,', S.services.length, 'jobs,', vehicles.length, 'vehicles,', S.expenses.length, 'expenses; tomb skipped:', JSON.stringify(tombSkipped));
