#!/usr/bin/env node
/**
 * Static cross-layer contract check. No dependencies, no network, no PHP/Ember runtime.
 *
 *   node tests/contract/check-contract.mjs          (run from service-tracker/, or anywhere)
 *   NAVIGATOR_DIR=/path/to/navigator-screens node tests/contract/check-contract.mjs
 *
 * It extracts
 *   - the routes registered in server/src/routes.php (driver API `v1/service-tracker/*`, console `int/v1/service-tracker/*`,
 *     including what `fleetbaseRoutes()` expands to) and checks that the controller method behind each one exists;
 *   - every endpoint the Ember engine calls (addon/services/service-tracker-api.js + the Ember Data models in addon/utils/resources.js);
 *   - every endpoint the Navigator screens call (src/services/taskFactory.ts, src/contexts/ServiceVisitContext.tsx);
 * and FAILS (exit 1) when a client call has no server route, a route has no controller method, or one of the
 * field-level invariants below is broken (Ember JSON root keys vs the controllers, upload `subject` values and the
 * Navigator multipart field names vs UploadRequest, `service_visit_uuid` / `catalog_item_uuid` bodies vs the request rules).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = process.env.SERVICE_TRACKER_DIR ? path.resolve(process.env.SERVICE_TRACKER_DIR) : path.resolve(HERE, '..', '..'); // service-tracker/
const NAV = process.env.NAVIGATOR_DIR || path.resolve(ROOT, '..', 'navigator-screens');
const SRC = path.join(ROOT, 'server', 'src');
const read = (p) => fs.readFileSync(p, 'utf8');
const failures = [];
const fail = (msg) => failures.push(msg);

/* ------------------------------------------------------------------ helpers */

const norm = (p) => p.replace(/\?.*$/, '').replace(/^\/+|\/+$/g, '').replace(/\/{2,}/g, '/');
/** `{id}` / `{stop}` / `${anything}` -> `{}` so routes and client paths compare structurally. */
const shape = (p) => norm(p).replace(/\$\{[^}]*\}/g, '{}').replace(/\{[^}]*\}/g, '{}');
const key = (m, p) => `${m.toUpperCase()} ${shape(p)}`;

/* ------------------------------------------------------------------ server routes */

function parseRoutes() {
    const text = read(path.join(SRC, 'routes.php'));
    const lines = text.split('\n');
    const routes = []; // {method, path, scope, action:'Controller@method'|null, controller, std}
    let sawInternal = false;
    let scope = null;
    let fbName = null;
    let fbExtras = [];
    let fbOpen = false;

    const standard = (name, controller, sc, names) => {
        const base = (m, p, a) => routes.push({ method: m, path: p, scope: sc, controller, action: a });
        base('GET', name, names.query);
        base('POST', name, names.create);
        base('DELETE', `${name}/bulk-delete`, 'bulkDelete');
        base('GET', `${name}/{id}`, names.find);
        base('PUT', `${name}/{id}`, names.update);
        base('PATCH', `${name}/{id}`, names.update);
        base('DELETE', `${name}/{id}`, names.delete);
    };
    // `$rest($router, 'name', 'Controller', closure?)` (our explicit registrar) vs core-api's fleetbaseRoutes() (*Record actions)
    const REST = { query: 'query', create: 'create', find: 'find', update: 'update', delete: 'delete' };
    const FLEETBASE = { query: 'queryRecord', create: 'createRecord', find: 'findRecord', update: 'updateRecord', delete: 'deleteRecord' };
    let style = REST;
    let fbController = null;

    for (const raw of lines) {
        const line = raw.trim();
        if (line.startsWith('//') || line.startsWith('*') || line.startsWith('/*')) continue;
        if (/internal_prefix/.test(line)) sawInternal = true;
        if (/prefix\('v1\/service-tracker'\)/.test(line)) scope = sawInternal ? 'int' : 'api';

        let m;
        if ((m = line.match(/\$rest\(\$router,\s*'([^']+)',\s*'([A-Za-z]+Controller)'/)) || (m = line.match(/fleetbaseRoutes\('([^']+)'/))) {
            fbName = m[1];
            fbController = m[2] ?? null;
            style = m[2] ? REST : FLEETBASE;
            fbExtras = [];
            fbOpen = /function\s*\(/.test(line);
            if (!fbOpen) {
                const c = fbController ?? line.match(/,\s*'([A-Za-z]+Controller)'\s*\)/)?.[1] ?? null;
                standard(fbName, c, scope, style);
                fbName = null;
            }
            continue;
        }
        if (fbOpen) {
            if ((m = line.match(/\$router->(get|post|put|patch|delete)\('([^']+)',\s*\$controller\('([^']+)'\)/))) {
                fbExtras.push({ method: m[1].toUpperCase(), path: `${fbName}/${m[2]}`, action: m[3] });
                continue;
            }
            const close = line.match(/^\}\s*,\s*\[\]\s*,\s*'([A-Za-z]+Controller)'\s*\)/) || (fbController && /^\}\)/.test(line) ? [null, fbController] : null);
            if (close) {
                for (const e of fbExtras) routes.push({ ...e, scope, controller: close[1] });
                standard(fbName, close[1], scope, style);
                fbOpen = false;
                fbName = null;
            }
            continue;
        }
        if ((m = line.match(/\$router->match\(\[([^\]]+)\],\s*'([^']+)',\s*'([A-Za-z]+Controller)@([A-Za-z]+)'/))) {
            for (const verb of m[1].match(/'(\w+)'/g).map((v) => v.replace(/'/g, ''))) {
                routes.push({ method: verb.toUpperCase(), path: m[2], scope, controller: m[3], action: m[4] });
            }
            continue;
        }
        if ((m = line.match(/\$router->(get|post|put|patch|delete)\('([^']+)',\s*'([A-Za-z]+Controller)@([A-Za-z]+)'/))) {
            routes.push({ method: m[1].toUpperCase(), path: m[2], scope, controller: m[3], action: m[4] });
        }
    }
    const prefix = { api: 'v1/service-tracker', int: 'int/v1/service-tracker' };
    return routes.map((r) => ({ ...r, full: `${prefix[r.scope]}/${r.path}` }));
}

/** Methods available on a controller class, following `extends` through `use ... as Alias` imports. */
function controllerMethods(scope, controller, seen = new Set()) {
    const dir = scope === 'api' ? 'Api/v1' : 'Internal/v1';
    let file = path.join(SRC, 'Http', 'Controllers', dir, `${controller}.php`);
    if (!fs.existsSync(file)) file = path.join(SRC, 'Http', 'Controllers', `${controller}.php`);
    if (!fs.existsSync(file) || seen.has(file)) return new Set();
    seen.add(file);
    const src = read(file);
    const methods = new Set([...src.matchAll(/function\s+([A-Za-z_]\w*)\s*\(/g)].map((m) => m[1]));
    const ext = src.match(/class\s+\w+\s+extends\s+(\w+)/);
    if (ext) {
        const alias = [...src.matchAll(/use\s+([\w\\]+)\s+as\s+(\w+);/g)].find((u) => u[2] === ext[1]);
        const target = alias ? alias[1] : [...src.matchAll(/use\s+([\w\\]+\\)?(\w+);/g)].map((u) => u[2]).includes(ext[1]) ? ext[1] : ext[1];
        const full = alias ? alias[1] : null;
        const parentName = (full ? full.split('\\').pop() : target);
        const parentScope = full && /Internal\\v1/.test(full) ? 'int' : full && /Api\\v1/.test(full) ? 'api' : scope === 'api' && !full ? 'api' : 'int';
        for (const m of controllerMethods(parentScope, parentName, seen)) methods.add(m);
    }
    return methods;
}

/* ------------------------------------------------------------------ Ember client */

async function emberCalls() {
    const calls = []; // {method, path, from}
    const base = 'int/v1/service-tracker';
    const svcFile = path.join(ROOT, 'addon', 'services', 'service-tracker-api.js');
    const svc = read(svcFile);
    const lit = String.raw`[\x60'"]([^\x60'"]+)[\x60'"]`;
    for (const m of svc.matchAll(new RegExp(String.raw`this\.(getJson|postJson|putJson|deleteJson|blob)\(\s*${lit}`, 'g'))) {
        const verb = { getJson: 'GET', postJson: 'POST', putJson: 'PUT', deleteJson: 'DELETE', blob: 'GET' }[m[1]];
        calls.push({ method: verb, path: `${base}/${m[2]}`, from: `service-tracker-api.js ${m[1]}` });
    }
    for (const m of svc.matchAll(/window\.fetch\(`\$\{this\.baseUrl\}([^`]+)`,\s*\{\s*method:\s*'(\w+)'/g)) {
        calls.push({ method: m[2].toUpperCase(), path: `${base}/${m[1]}`, from: 'service-tracker-api.js window.fetch' });
    }
    // convenience wrappers call this.blob('...') with a literal path
    for (const m of svc.matchAll(/this\.blob\(`?'?([a-z/]+[^'`,)]*)'?`?[,)]/g)) {
        if (!calls.some((c) => c.path === `${base}/${m[1].replace(/\$\{[^}]*\}/g, '{}')}`)) { /* covered above */ }
    }

    // plain ES module without imports: load from a data: URL so no package.json `type` is needed
    const resSrc = read(path.join(ROOT, 'addon', 'utils', 'resources.js'));
    const mod = await import(`data:text/javascript;base64,${Buffer.from(resSrc).toString('base64')}`);
    const resources = mod.RESOURCES;
    for (const [model, r] of Object.entries(resources)) {
        const p = `${base}/${r.path}`;
        for (const [method, suffix] of [['GET', ''], ['POST', ''], ['GET', '/{id}'], ['PUT', '/{id}'], ['DELETE', '/{id}']]) {
            calls.push({ method, path: p + suffix, from: `ember-data ${model}` });
        }
    }

    // models used by the engine must be registered in RESOURCES (or be a FleetOps/core model)
    const foreign = new Set(['vendor', 'vehicle', 'user']);
    const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]));
    for (const f of walk(path.join(ROOT, 'addon')).filter((x) => x.endsWith('.js'))) {
        const src = read(f);
        for (const m of src.matchAll(/(?:store\.|this\.safeQuery\()\s*(?:query|queryRecord|findRecord|createRecord|findAll|peekAll)?\(?\s*'([a-z-]+)'/g)) {
            if (!resources[m[1]] && !foreign.has(m[1])) fail(`Ember: ${path.relative(ROOT, f)} uses model '${m[1]}' which is not in addon/utils/resources.js`);
        }
        if (!f.endsWith('service-tracker-api.js') && /\bwindow\.fetch\(|this\.fetch\.(get|post|put|delete)\(/.test(src)) {
            fail(`Ember: ${path.relative(ROOT, f)} calls the API directly; route it through service-tracker-api.js so the contract check sees it`);
        }
    }
    return { calls, resources };
}

/* ------------------------------------------------------------------ Navigator client */

function navigatorCalls() {
    const calls = [];
    const subst = (p) => p.replace(/\$\{\s*(?:T\.)?NS\s*\}/g, 'service-tracker').replace(/\$\{\s*UPLOADS\s*\}/g, 'service-tracker/uploads');
    const tf = read(path.join(NAV, 'src', 'services', 'taskFactory.ts'));
    const vm = read(path.join(NAV, 'src', 'services', 'visitModel.ts'));
    const actions = (vm.match(/export type StopAction = ([^;]+);/) || [, ''])[1].match(/'([^']+)'/g)?.map((s) => s.replace(/'/g, '')) || [];
    if (!actions.length) fail('Navigator: could not read StopAction from visitModel.ts');

    for (const m of tf.matchAll(/method:\s*'(POST|PUT|PATCH|DELETE)',\s*path:\s*`([^`]+)`/g)) {
        const p = subst(m[2]);
        if (p.includes('${action}')) {
            for (const a of actions) calls.push({ method: m[1], path: `v1/${p.replace('${action}', a)}`, from: 'taskFactory.ts' });
        } else {
            calls.push({ method: m[1], path: `v1/${p}`, from: 'taskFactory.ts' });
        }
    }
    // photo tasks go through `path: UPLOADS`
    if (/path:\s*UPLOADS/.test(tf)) calls.push({ method: 'POST', path: 'v1/service-tracker/uploads', from: 'taskFactory.ts photoTask' });

    const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => (e.isDirectory() ? walk(path.join(d, e.name)) : [path.join(d, e.name)]));
    for (const f of walk(path.join(NAV, 'src')).filter((x) => /\.tsx?$/.test(x))) {
        const src = read(f);
        for (const m of src.matchAll(/\ba\.(get|post|put|patch|delete)\(\s*`([^`]+)`/g)) {
            calls.push({ method: m[1].toUpperCase(), path: `v1/${subst(m[2])}`, from: path.relative(NAV, f) });
        }
    }
    return calls;
}

/* ------------------------------------------------------------------ run */

const routes = parseRoutes();
if (process.env.DEBUG_ROUTES) console.log(routes.map((r) => `${r.scope} ${r.method} ${r.full} ${r.controller}@${r.action}`).join("\n"));
/** A route matches a client path when its `{param}` segments (which may sit inside a segment, e.g. `{type}.xlsx`) accept the client's. */
const matchers = routes.map((r) => ({ r, re: new RegExp(`^${norm(r.full).replace(/[.*+?^$()|\\]/g, '\\$&').replace(/\{[^}]*\}/g, '[^/]+')}$`) }));
const findRoute = (method, p) => {
    const concrete = norm(p).replace(/\$\{[^}]*\}|\{[^}]*\}/g, 'X');
    // prefer an exact structural match, then a pattern match
    return matchers.find((x) => x.r.method === method.toUpperCase() && shape(x.r.full) === shape(p))?.r ?? matchers.find((x) => x.r.method === method.toUpperCase() && x.re.test(concrete))?.r;
};

// 1. controller method behind every route
let routeChecks = 0;
for (const r of routes) {
    routeChecks++;
    if (!r.controller) { fail(`route ${r.method} ${r.full}: controller could not be determined`); continue; }
    const methods = controllerMethods(r.scope, r.controller);
    if (!methods.has(r.action)) fail(`route ${r.method} ${r.full}: ${r.controller}@${r.action} is not defined (scope ${r.scope})`);
}

// 2. client calls need a server route
const { calls: ember, resources } = await emberCalls();
const nav = navigatorCalls();
const rows = [];
for (const [layer, list] of [['ember', ember], ['navigator', nav]]) {
    const seen = new Set();
    for (const c of list) {
        const k = key(c.method, c.path);
        if (seen.has(k)) continue;
        seen.add(k);
        const hit = findRoute(c.method, c.path);
        rows.push({ layer, method: c.method, path: shape(c.path), from: c.from, route: hit ? `${hit.controller}@${hit.action}` : null });
        if (!hit) fail(`${layer}: ${c.method} ${norm(c.path)} (${c.from}) has no server route`);
    }
}

// 3. field-level invariants
const ctrlDir = path.join(SRC, 'Http', 'Controllers', 'Internal', 'v1');
const ctrlSrc = {
    'service-visit': 'VisitController', 'vendor-stop': 'VendorStopController', 'service-job': 'ServiceJobController', 'damage-record': 'DamageController',
    'petty-cash-entry': 'PettyCashController', 'service-catalog-item': 'CatalogController', 'service-lane': 'LaneController',
};
for (const [model, r] of Object.entries(resources)) {
    const src = read(path.join(ctrlDir, `${ctrlSrc[model]}.php`));
    const plural = src.match(/\$plural\s*=\s*'([^']+)'/)?.[1];
    const singular = src.match(/\$singular\s*=\s*'([^']+)'/)?.[1];
    if (plural !== r.plural || singular !== r.singular) fail(`Ember root keys for ${model} (${r.plural}/${r.singular}) differ from ${ctrlSrc[model]} (${plural}/${singular})`);
    const routePath = routes.find((x) => x.controller === ctrlSrc[model] && x.scope === 'int' && /^(query|queryRecord)$/.test(x.action) && x.method === 'GET' && !x.path.includes('{'))?.path;
    if (routePath !== r.path) fail(`Ember path for ${model} ('${r.path}') differs from the console route of ${ctrlSrc[model]} ('${routePath}')`);
}

const uploadReq = read(path.join(SRC, 'Http', 'Requests', 'UploadRequest.php'));
const subjects = (uploadReq.match(/const SUBJECTS = \[([^\]]+)\]/) || [, ''])[1].match(/'([^']+)'/g)?.map((s) => s.replace(/'/g, '')) || [];
const emberApi = read(path.join(ROOT, 'addon', 'services', 'service-tracker-api.js'));
for (const m of emberApi.matchAll(/return '([a-z_]+)';/g)) {
    if (!subjects.includes(m[1]) && ['damage', 'petty_cash', 'stop', 'visit_pickup', 'visit_return'].includes(m[1])) fail(`Ember upload subject ${m[1]} not accepted by UploadRequest`);
}
for (const f of ['subject', 'subject_uuid', 'photos[]', 'client_uuid']) {
    if (!emberApi.includes(`'${f}'`)) fail(`Ember uploadPhotos does not send the multipart field ${f}`);
}
const tf = read(path.join(NAV, 'src', 'services', 'taskFactory.ts'));
const q = read(path.join(NAV, 'src', 'services', 'offlineQueue.ts'));
const tr = read(path.join(NAV, 'src', 'services', 'fleetbaseTransport.ts'));
for (const f of ['subject_type', 'stage', 'angle']) if (!tf.includes(f)) fail(`Navigator photoTask does not send ${f}`);
for (const f of ['parent_client_uuid', 'subject_id', 'client_uuid']) if (!q.includes(f)) fail(`Navigator queue does not send ${f}`);
if (!tr.includes("'photos[0]'") && !tr.includes('"photos[0]"')) fail('Navigator transport must post the file as photos[0] (server reads photos[])');
for (const f of ['subject_type', 'subject_id', 'stage', 'angle', 'parent_client_uuid', 'collection', 'subject_uuid']) {
    if (!uploadReq.includes(`'${f}'`) && !uploadReq.includes(`input('${f}')`)) fail(`UploadRequest does not read ${f}`);
}

const rules = (name) => read(path.join(SRC, 'Http', 'Requests', `${name}.php`));
for (const [file, field] of [['DamageRecordRequest', 'service_visit_uuid'], ['ServiceJobRequest', 'service_visit_uuid'], ['ServiceJobRequest', 'catalog_item_uuid'], ['PettyCashRequest', 'service_visit_uuid'], ['PettyCashRequest', 'amount'], ['PettyCashRequest', 'currency'], ['ServiceVisitRequest', 'arrived_at_lsa'], ['ServiceCatalogItemRequest', 'rename_cascade'], ['VendorStopRequest', 'vendor_name']]) {
    if (!rules(file).includes(`'${field}'`)) fail(`${file} has no rule for ${field}`);
}
for (const f of ['service_visit_uuid', 'catalog_item_uuid']) {
    if (!/service_visit_uuid/.test(tf)) fail('Navigator writes must reference the visit by service_visit_uuid');
    if (/service_visit_id|catalog_item_id/.test(tf)) fail('Navigator taskFactory still sends *_id field names the server does not read');
    void f;
}

/* ------------------------------------------------------------------ report */

const w = (s, n) => String(s).padEnd(n);
console.log('\nCross-layer contract matrix (client call -> server route)\n');
for (const r of rows) console.log(`${w(r.layer, 10)}${w(r.method, 7)}${w(r.path, 60)} ${w(r.route ?? 'MISSING', 42)} ${r.from}`);
const matched = rows.filter((r) => r.route).length;
console.log(`\nserver routes: ${routes.length} (controller methods checked: ${routeChecks}); client calls: ${rows.length}, matched: ${matched}, missing: ${rows.length - matched}`);
if (failures.length) {
    console.error(`\nCONTRACT CHECK FAILED (${failures.length})`);
    for (const f of failures) console.error(`  - ${f}`);
    process.exit(1);
}
console.log('CONTRACT CHECK OK');
