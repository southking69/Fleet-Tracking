#!/usr/bin/env node
/* Deterministic stress variant of the REAL production file: same records, plus edge cases the real data happens not to hit
 * (tombstones of every shape, overdue services on a dash-variant plate, open / not-started visits, free-text services,
 * awkward amounts and odometers, a vendor that is not in settings). The oracle (real Index.html functions) and the
 * PHP pipeline are both run on it, so every branch is compared against the original JavaScript.
 * Usage: node tests/realdata/make-stress.js  -> fleet-data.stress.json */
const fs = require('fs'), path = require('path');
const d = JSON.parse(fs.readFileSync(path.join(__dirname, 'fleet-data.json'), 'utf8'));
const U = 1791800000000;
const V = (id, o) => { d.visits[id] = Object.assign({ id, plate: 'ZZZ 1', make: 'Test', odo: '', arrived: '', picked: '', returned: '', pins: false, rins: false, tech: 'Technician 1', notes: '', created: U + Object.keys(d.visits).length, pickupPhotos: [], returnPhotos: [], _u: U, _r: 200 }, o); };
let n = 0;
const ST = (visitId, o) => { const id = 'zs' + (++n); d.stops[id] = Object.assign({ id, visitId, vendor: 'Petromin', arrived: '', apprReq: '', apprRec: '', left: '', notes: '', _u: U, _r: 200 }, o); };
const SV = (visitId, service, o) => { const id = 'zj' + (++n); d.services[id] = Object.assign({ id, visitId, service, vendor: 'Petromin', apprNeeded: '', status: 'Done', parts: '', nextOverride: '', nextDate: '', notes: '', _u: U, _r: 200 }, o); };
const EX = (visitId, o) => { const id = 'E-ZS' + (++n); d.expenses[id] = Object.assign({ id, visitId, plate: '', date: '2026-10-12', category: 'Fuel', amount: 1, paidBy: 'Supervisor', receipts: [], description: 'x', notes: '', created: U + n, _u: U, _r: 200 }, o); };

// 1. overdue on a dash / case / trailing space variant of an existing plate (3083 NER: oil change diesel at 145,559 km, interval 5,000 => due 150,559)
V('V-ST0001', { plate: '3083-ner ', odo: 151000, arrived: '2026-10-12T08:00', picked: '2026-10-12T08:30', returned: '2026-10-12T20:00', make: 'Staria' });
ST('V-ST0001', { vendor: 'Petromin', arrived: '2026-10-12T09:00', apprReq: '2026-10-12T09:10', apprRec: '2026-10-12T10:40', left: '2026-10-12T14:00' });
ST('V-ST0001', { vendor: 'Unlisted Vendor', arrived: '2026-10-12T14:20', left: '2026-10-12T16:00' });
SV('V-ST0001', 'Oil change - engine oil & filter Diesel', { nextOverride: '' });                       // overdue by 441 km
SV('V-ST0001', 'Wheel alignment', {});                                                                 // interval 10,000, previous visit has none -> No previous due
SV('V-ST0001', 'Custom flush (free text)', { vendor: 'Tyre Vendor' });                                 // not in catalog
SV('V-ST0001', 'Battery replacement', { nextOverride: 0 });                                            // override 0 wins
// 2. same plate, later, with numeric-string odometer and an on-time repeat
V('V-ST0002', { plate: '3083 NER', odo: '151500', arrived: '2026-10-13T08:00', picked: '2026-10-13T08:00', returned: '2026-10-13T09:00' });
ST('V-ST0002', { vendor: 'Petromin', arrived: '2026-10-13T08:10', left: '2026-10-13T08:50' });
SV('V-ST0002', 'Oil change - engine oil & filter Diesel', { nextOverride: '160000' });
// 3. open visit (in progress), not started visit, visit with no plate, odometer garbage
V('V-ST0003', { plate: '9999 OPN', odo: 100, arrived: '2026-10-11T09:00', picked: '2026-10-11T10:00' });
ST('V-ST0003', { vendor: 'Local Mechanic', arrived: '2026-10-11T11:00' });
SV('V-ST0003', 'Brake pads - front', { status: 'In progress' });
V('V-ST0004', { plate: '', odo: 'abc' });
SV('V-ST0004', 'Brake pads - front', {});
V('V-ST0005', { plate: '8888 NST', odo: 50 });
// 4. timestamp disorder (returned before last left -> negative return stage; overlapping stops -> clamp)
V('V-ST0006', { plate: '7777 NEG', odo: 1000, arrived: '2026-10-10T08:00', picked: '2026-10-10T09:00', returned: '2026-10-10T10:00' });
ST('V-ST0006', { vendor: 'Local Mechanic', arrived: '2026-10-10T09:10', left: '2026-10-10T11:00' });
ST('V-ST0006', { vendor: 'Local Mechanic', arrived: '2026-10-10T09:10', left: '2026-10-10T09:50' });
SV('V-ST0006', 'Oil change - engine oil & filter Petrol', {});
// 4b. boundaries: same odometer as an earlier visit of the car (not "earlier"), and exactly on the previous due km (kmOver 0 => On time)
V('V-ST0007', { plate: '3083 NER', odo: 145559, arrived: '2026-10-12T07:00', picked: '2026-10-12T07:10', returned: '2026-10-12T07:50' }); SV('V-ST0007', 'Oil change - engine oil & filter Diesel', {});
V('V-ST0008', { plate: '3083 NER', odo: 150559, arrived: '2026-10-12T07:00', picked: '2026-10-12T07:10', returned: '2026-10-12T07:50' }); SV('V-ST0008', 'Oil change - engine oil & filter Diesel', {});
// 5. awkward money
EX('V-ST0001', { amount: 19.995, plate: '3083-ner ' }); EX('V-ST0001', { amount: '1.005' }); EX('V-ST0001', { amount: 0.1 }); EX('V-ST0001', { amount: 0.2 });
EX('V-ST0001', { amount: '12,50', category: '' }); EX('V-ST0001', { amount: '', category: 'Other' }); EX('V-ST0001', { amount: 1234567.891, plate: '3083 NER' });
EX('', { amount: 40, plate: '9999-opn', date: '2026-10-11' });     // unlinked: belongs on V-ST0003's report by plate + date
// 6. tombstones: object {t,r}, bare number, string number; equal to _u (skipped), older than _u (kept)
const tomb = d.tomb;
tomb['expenses:E-ABD0004'] = { t: d.expenses['E-ABD0004']._u + 5, r: 108 };
tomb['expenses:E-ABD0003'] = d.expenses['E-ABD0003']._u;                       // bare number, equal => skipped
tomb['expenses:E-ABD0002'] = String(d.expenses['E-ABD0002']._u + 1);           // numeric string
tomb['stops:xmv2j4nhqy2qrc'] = { t: d.stops['xmv2j4nhqy2qrc']._u, r: 108 };    // equal => skipped
tomb['services:xmv2jpf3wrp0gz'] = { t: d.services['xmv2jpf3wrp0gz']._u - 1, r: 108 };   // older than the record => kept
tomb['damages:D-AB0005'] = { t: d.damages['D-AB0005']._u + 1, r: 108 };
tomb['visits:V-ABD0004'] = { t: d.visits['V-ABD0004']._u + 1, r: 108 };         // visit deleted: its children become orphans (kept in the db until pruned)
fs.writeFileSync(path.join(__dirname, 'fleet-data.stress.json'), JSON.stringify(d, null, 1));
console.log('wrote fleet-data.stress.json');
