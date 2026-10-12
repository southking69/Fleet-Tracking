#!/usr/bin/env python3
"""Throw-away MOCK of the Service Tracker HTTP surface, used ONLY to test ops/smoke/smoke.sh itself (its control flow,
jq comparisons, retry/idempotency logic) in CI without a Fleetbase install.

It is NOT Fleetbase and NOT the PHP extension: it answers what ops/smoke/smoke.sh ASSUMES the server answers, with the
expected numbers read from the fixture. A green run against this mock proves nothing about the real server.

  MOCK_PORT=18765 python3 ops/smoke/mock_server.py service-tracker/server/tests/fixtures/expected.json [wrong]

Pass the word "wrong" as the 2nd argument to make /calc return a total that is 1 h too high (the smoke run must then FAIL).
Credentials accepted: API keys SMOKEKEY_A / SMOKEKEY_B, console logins whose identity contains smoke-a / smoke-b.
"""
import json, os, re, sys, io, zipfile, uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
PORT = int(os.environ.get('MOCK_PORT', '18765'))
FIX = json.load(open(sys.argv[1]))
CASES = [f for f in FIX['fixtures'] if f['name']=='sample'][0]['cases']
KEYS = {'A':'A','B':'B'}; TOK = {'A':'A','B':'B'}
DB = {t: dict(visits={}, dmg={}, petty={}, stops={}, photos={}, cu={}) for t in 'AB'}
BREAK = set(sys.argv[2:])
def case_of(v):
    m = re.search(r'smoke (V-\w+)', v.get('notes') or '')
    for c in CASES:
        if m and c['visit']['id'] == m.group(1): return c
class H(BaseHTTPRequestHandler):
    def log_message(self,*a): pass
    def send(self, code, obj=None, raw=None, ctype='application/json'):
        b = raw if raw is not None else json.dumps(obj if obj is not None else {}).encode()
        self.send_response(code); self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def tenant(self):
        a = self.headers.get('Authorization','').replace('Bearer ','')
        return KEYS.get(a.replace('SMOKEKEY_','')) or TOK.get(a.replace('SMOKETOK_',''))
    def body(self):
        n = int(self.headers.get('Content-Length') or 0); raw = self.rfile.read(n)
        try: return json.loads(raw or b'{}'), raw
        except Exception: return {}, raw
    def do_GET(self): self.route('GET')
    def do_POST(self): self.route('POST')
    def do_PUT(self): self.route('PUT')
    def route(self, m):
        p = self.path.split('?')[0]; q = self.path[len(p)+1:]
        data, raw = self.body()
        if p == '/health': return self.send(200, {'status':'ok'})
        if p == '/int/v1/auth/login':
            return self.send(200, {'token': 'SMOKETOK_' + ('A' if 'smoke-a' in data.get('identity','') else 'B')})
        t = self.tenant()
        if p.startswith('/storage/'): return self.send(200, raw=b'\xff\xd8\xff', ctype='image/jpeg')
        if not t: return self.send(401, {'error':'unauth'})
        d = DB[t]
        if p == '/int/v1/service-tracker/settings': return self.send(200, {'settings': {'timezone':'Asia/Riyadh'}})
        if p == '/int/v1/service-tracker/lanes':
            return self.send(200, {'lanes':[{'name':'Express','target_hours':'12.00'},{'name':'Standard','target_hours':'24.00'},{'name':'Heavy','target_hours':'48.00'}]})
        if p == '/int/v1/service-tracker/catalog': return self.send(200, {'catalog':[], 'meta':{'total':145}})
        if p in ('/v1/vendors','/v1/vehicles'): return self.send(201, {'id': 'x_'+uuid.uuid4().hex[:6]})
        base = '/v1/service-tracker/'
        if p.startswith(base) or p.startswith('/int/v1/service-tracker/'):
            internal = p.startswith('/int')
            r = p.split('service-tracker/',1)[1]
            if r == 'visits' and m == 'GET': return self.send(200, {'data': list(d['visits'].values()), 'meta': {'total': len(d['visits'])}})
            if r == 'visits' and m == 'POST':
                if data.get('client_uuid') in d['cu']: return self.send(200, d['visits'][d['cu'][data['client_uuid']]])
                vid = 'visit_' + uuid.uuid4().hex[:8]; v = dict(data, id=vid, status='in_progress', stops=[], jobs=[], pickup_photos=[])
                d['visits'][vid] = v; d['cu'][data.get('client_uuid')] = vid; return self.send(201, v)
            mm = re.match(r'visits/([^/]+)(/.*)?$', r)
            if mm:
                vid, rest = mm.group(1), mm.group(2) or ''
                v = d['visits'].get(vid)
                if not v: return self.send(404, {'error':'not found'})
                if rest == '' and m == 'GET': return self.send(200, v)
                if rest == '' and m == 'PUT':
                    v.update(data)
                    if data.get('returned_at'): v['status'] = 'closed'
                    return self.send(200, v)
                if rest == '/calc':
                    c = case_of(v)
                    if c:
                        e = json.loads(json.dumps(c['expected']))
                        if 'wrong' in BREAK: e['total'] += 1
                        return self.send(200, e)
                    return self.send(200, {})
                if rest == '/stops' and m == 'POST':
                    sid = 'vstop_' + uuid.uuid4().hex[:6]; s = dict(data, id=sid); d['stops'][sid] = s; return self.send(201, s)
                if rest == '/jobs' and m == 'POST': return self.send(201, dict(data, id='sjob_' + uuid.uuid4().hex[:6]))
                ev = re.match(r'/stops/([^/]+)/(check-in|approval-requested|approval-received|left)$', rest)
                if ev:
                    s = d['stops'][ev.group(1)]; col = {'check-in':'arrived_at','approval-requested':'approval_requested_at','approval-received':'approval_received_at','left':'left_at'}[ev.group(2)]
                    at = data.get('at')
                    if col == 'left_at' and s.get('arrived_at') and at < s['arrived_at']: return self.send(422, {'error':'earlier'})
                    if not s.get(col): s[col] = at
                    return self.send(200, s)
            if r.startswith('damage-records'):
                if m == 'GET': return self.send(200, {'data': list(d['dmg'].values()), 'meta': {'total': len(d['dmg'])}})
                if data.get('service_visit_uuid') not in d['visits']: return self.send(404, {'error':'visit'})
                cu = data['client_uuid']
                if cu in d['dmg']: return self.send(200, d['dmg'][cu])
                d['dmg'][cu] = dict(data, id='dmg_' + uuid.uuid4().hex[:6]); return self.send(201, d['dmg'][cu])
            if r.startswith('petty-cash'):
                if m == 'GET': return self.send(200, {'data': list(d['petty'].values()), 'meta': {'total': len(d['petty'])}})
                cu = data['client_uuid']
                if cu in d['petty']: return self.send(200, d['petty'][cu])
                d['petty'][cu] = dict(data, id='pcash_' + uuid.uuid4().hex[:6]); return self.send(201, d['petty'][cu])
            if r == 'uploads':
                m2 = re.search(rb'name="subject_uuid"\r\n\r\n([^\r]+)', raw); m3 = re.search(rb'name="client_uuid"\r\n\r\n([^\r]+)', raw)
                vid = m2.group(1).decode(); cu = m3.group(1).decode(); d['photos'].setdefault(cu, 1)
                d['visits'][vid]['pickup_photos'] = [{'url': 'http://127.0.0.1:%d/storage/a.jpg' % PORT, 'uuid': cu}]
                return self.send(201, {'files': []})
            if r == 'dashboard':
                return self.send(200, {'kpis': {'visits':4,'closed':4,'jobs':8,'stops':5,'avg_turnaround_hours':17.75,'pct_within_target':50},
                    'stages':[{'avg_hours':x} for x in (2.75,0.5,0.625,7.375,0.0833,6.4167)],
                    'due_compliance':{'jobs_overdue':1,'worst_km_overdue':1600}, 'damage_inspections':{'damage_records':2,'damage_new':1}})
            if r.startswith('vehicles/due'):
                return self.send(200, {'vehicles':[{'plate':'a'},{'plate':'b'},{'plate':'c'}], 'due':[{'service':'Oil','status':'OVERDUE'}]})
            if r.startswith('reports/visit/'):
                if r.split('/')[2].replace('.pdf','') not in d['visits']: return self.send(404, {})
                return self.send(200, raw=b'%PDF-1.4 mock' + b'x'*2000, ctype='application/pdf')
            if r.startswith('exports/'):
                buf = io.BytesIO(); z = zipfile.ZipFile(buf, 'w')
                full = r.startswith('exports/full')
                names = ['Vehicles','Visits','Vendor Stops','Services','Damage Log','Petty Cash','Service Catalog'] if full else ['Summary','Visits']
                if not full and 'petty=1' in q: names.append('Petty Cash')
                if not full and 'vehicles=1' in q: names.insert(1,'Vehicles')
                z.writestr('xl/workbook.xml', '<workbook><sheets>' + ''.join('<sheet name="%s" sheetId="%d"/>' % (n, i) for i, n in enumerate(names)) + '</sheets></workbook>')
                z.writestr('xl/sharedStrings.xml', '<sst>' + ('<si><t>Average total turnaround (hrs)</t></si>' if 'timing=0' not in q else '') + '</sst>')
                z.close(); return self.send(200, raw=buf.getvalue(), ctype='application/octet-stream')
        self.send(404, {'error':'no route '+p})
ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
