/**
 * Fleet Service Tracker – Google Apps Script back end.
 * Data is kept in your Google Drive, in a folder called "FleetTracker Data":
 *   fleet-data.json   – all visits, jobs, vendor stops, damage, petty cash, settings
 *   photos/           – one JPG per photo
 *   backups/          – an automatic copy of fleet-data.json every day
 *
 * SETUP: change ACCESS_CODE below to your own code, then Deploy > New deployment > Web app
 *   Execute as: Me      Who has access: Anyone with Google account
 */
const ACCESS_CODE = 'CHANGE-ME-1234';        // <-- choose your own code and share it only with the supervisor
const DATA_FOLDER_NAME = 'FleetTracker Data';

const COLL = ['visits', 'stops', 'services', 'damages', 'expenses'];

function doGet() {
  return HtmlService.createHtmlOutputFromFile('Index')
    .setTitle('Fleet Service Tracker')
    .addMetaTag('viewport', 'width=device-width, initial-scale=1, viewport-fit=cover')
    .setFaviconUrl('https://ssl.gstatic.com/docs/script/images/favicon.ico');
}

/* ---------------- storage helpers ---------------- */
function check_(code) { if (String(code || '') !== String(ACCESS_CODE)) throw new Error('ACCESS_DENIED'); }
function folder_() {
  const props = PropertiesService.getScriptProperties();
  const id = props.getProperty('FOLDER_ID');
  if (id) { try { const f = DriveApp.getFolderById(id); if (!f.isTrashed()) return f; } catch (e) { } }
  const f = DriveApp.createFolder(DATA_FOLDER_NAME);
  props.setProperty('FOLDER_ID', f.getId());
  return f;
}
function sub_(name) { const p = folder_(), it = p.getFoldersByName(name); return it.hasNext() ? it.next() : p.createFolder(name); }
function emptyDb_() { const db = { rev: 0, settings: null, _su: 0, settingsRev: 0, catalog: null, _cu: 0, catalogRev: 0, tomb: {} }; COLL.forEach(function (c) { db[c] = {}; }); return db; }
function dataFile_() {
  const f = folder_(), it = f.getFilesByName('fleet-data.json');
  return it.hasNext() ? it.next() : f.createFile('fleet-data.json', JSON.stringify(emptyDb_()), 'application/json');
}
function load_() {
  const db = JSON.parse(dataFile_().getBlob().getDataAsString() || '{}');
  const base = emptyDb_(); Object.keys(base).forEach(function (k) { if (db[k] == null) db[k] = base[k]; });
  return db;
}
function save_(db) {
  const file = dataFile_();
  file.setContent(JSON.stringify(db));
  const props = PropertiesService.getScriptProperties(), today = Utilities.formatDate(new Date(), Session.getScriptTimeZone(), 'yyyy-MM-dd');
  if (props.getProperty('LAST_BACKUP') !== today) {
    try { file.makeCopy('fleet-data-' + today + '.json', sub_('backups')); props.setProperty('LAST_BACKUP', today); } catch (e) { }
  }
}

/* ---------------- sync logic (pure – also unit tested) ---------------- */
function applyChanges_(db, ch, rev) {
  let n = 0;
  Object.keys(ch.tomb || {}).forEach(function (k) {
    const t = ch.tomb[k], cur = db.tomb[k];
    if (!cur || t > cur.t) {
      db.tomb[k] = { t: t, r: rev }; n++;
      const i = k.indexOf(':'), c = k.slice(0, i), id = k.slice(i + 1);
      if (db[c] && db[c][id] && (db[c][id]._u || 0) <= t) delete db[c][id];
    }
  });
  COLL.forEach(function (c) {
    (ch[c] || []).forEach(function (r) {
      const k = c + ':' + r.id, tb = db.tomb[k];
      if (tb && tb.t >= (r._u || 0)) return;
      const ex = db[c][r.id];
      if (!ex || (r._u || 0) > (ex._u || 0)) { r._r = rev; db[c][r.id] = r; n++; }
    });
  });
  if (ch.settings && (ch._su || 0) > (db._su || 0)) { db.settings = ch.settings; db._su = ch._su; db.settingsRev = rev; n++; }
  if (ch.catalog && (ch._cu || 0) > (db._cu || 0)) { db.catalog = ch.catalog; db._cu = ch._cu; db.catalogRev = rev; n++; }
  return n;
}
function delta_(db, since) {
  const out = { rev: db.rev, tomb: {} };
  COLL.forEach(function (c) { out[c] = Object.keys(db[c]).map(function (id) { return db[c][id]; }).filter(function (r) { return (r._r || 0) > since; }); });
  Object.keys(db.tomb).forEach(function (k) { if (db.tomb[k].r > since) out.tomb[k] = db.tomb[k].t; });
  if (db.settings && db.settingsRev > since) { out.settings = db.settings; out._su = db._su; }
  if (db.catalog && db.catalogRev > since) { out.catalog = db.catalog; out._cu = db._cu; }
  return out;
}

/* ---------------- API called from the app ---------------- */
function api_hello(code) { check_(code); return { ok: true, folder: folder_().getName(), folderUrl: folder_().getUrl() }; }
function api_pull(code, since) { check_(code); return delta_(load_(), Number(since) || 0); }
function api_push(code, ch, since) {
  check_(code);
  const lock = LockService.getScriptLock(); lock.waitLock(25000);
  try {
    const db = load_(), rev = db.rev + 1;
    if (applyChanges_(db, ch || {}, rev)) { db.rev = rev; save_(db); }
    return delta_(db, Number(since) || 0);
  } finally { lock.releaseLock(); }
}
function api_putPhoto(code, id, dataUrl) {
  check_(code);
  if (!/^[A-Za-z0-9_-]+$/.test(id)) throw new Error('bad id');
  const dir = sub_('photos'), name = id + '.jpg', it = dir.getFilesByName(name);
  if (it.hasNext()) return true;
  const m = /^data:([^;]+);base64,(.*)$/.exec(dataUrl || ''); if (!m) throw new Error('bad image');
  dir.createFile(Utilities.newBlob(Utilities.base64Decode(m[2]), m[1], name));
  return true;
}
function api_getPhotos(code, ids) {
  check_(code);
  const dir = sub_('photos'), out = {};
  (ids || []).slice(0, 40).forEach(function (id) {
    const it = dir.getFilesByName(id + '.jpg');
    if (it.hasNext()) { const b = it.next().getBlob(); out[id] = 'data:' + (b.getContentType() || 'image/jpeg') + ';base64,' + Utilities.base64Encode(b.getBytes()); }
    else out[id] = null;
  });
  return out;
}
function api_delPhoto(code, id) {
  check_(code);
  const it = sub_('photos').getFilesByName(id + '.jpg');
  while (it.hasNext()) it.next().setTrashed(true);
  return true;
}
function api_saveExport(code, name, base64, mime) {
  check_(code);
  const f = sub_('exports').createFile(Utilities.newBlob(Utilities.base64Decode(base64), mime, String(name).replace(/[\\/:*?"<>|]/g, '_')));
  return f.getUrl();
}
