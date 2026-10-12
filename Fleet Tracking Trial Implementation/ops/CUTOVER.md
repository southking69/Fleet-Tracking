# CUTOVER: from the Apps Script tracker to Fleetbase

Scope: moving the live data and the daily work from the Google Apps Script tracker (`src/Index.html` + `src/Code.gs`, data in a Drive JSON file with photos as `<id>.jpg`) to Fleetbase + Service Tracker. Also: backup/restore, monitoring, the data-quality findings in the **real** data set, and the licence and data-protection points that need a person to decide.

Nothing here has been executed against a running Fleetbase. The importer was exercised only through its mapping layer (`AppsScriptMapper`) and the engine, on the real file, with 744 parity checks, 0 differences (CI job `realdata-parity`). The database half of the importer (name matching, `withoutEvents`, dry-run rollback, file storage) is unexecuted: **the first `--dry-run` on the trial server is the first time it runs**. Read RISKS.md R-02.

---

## 1. Parallel-run procedure

Rule that avoids most pain: **every visit has exactly one home.** Never edit the same visit in both systems.

| Phase | When | What happens | Exit criterion |
|---|---|---|---|
| 0. Install and UAT | T-14 to T-7 | INSTALL.md, then `UAT-checklist.md` sections 1 to 9 on a scratch company | all parity items P; defects triaged |
| 1. Dry-run import | T-7 | section 2 below with `--dry-run` on a copy of the real file; read the reconciliation (section 3) | exit code 0 or every difference explained |
| 2. Real import into the trial company | T-5 | non-dry run after a backup; second run proves idempotency (all `updated`, none `created`) | counts match, 0 differences |
| 3. Pilot, shadow mode | T0 to T+7 | Navigator on 2 technicians' phones, a defined **pilot set of cars** handled **only** in Fleetbase; all other cars stay in the tracker. Supervisor compares the Fleetbase Excel (Data & Export, "Last 7 days") against the tracker's Excel for the same period for the cars both have seen (shadow-key a few visits in both on purpose, labelled "SHADOW" in notes, excluded from totals) | daily: shadow visits equal within 0.01 h; no missing photo; no data loss reported by the technicians |
| 4. Widen | T+7 to T+14 | all cars in Fleetbase **for new visits**; the tracker stays open for reading and for visits that started there | 5 working days without a blocking defect |
| 5. Freeze and final import | cutover day, outside working hours | see below | reconciliation 0 differences |
| 6. Hypercare | 2 weeks after | tracker stays read-only; daily backup check; supervisor reviews dashboard "data problems" | no rollback trigger hit (section 4) |

**Cutover day script**

1. Announce the freeze. In the tracker: stop entry (set the Drive data file to view-only for everybody but you, or switch the web app to "only me").
2. Export the final `fleet-data.json` from Drive (and the photos, section 2). Keep a dated copy outside the server: it is your rollback artefact.
3. Backup Fleetbase (section 5): `backup.sh`.
4. Run the importer **dry**, read the reconciliation, then run it for real with `--report`.
5. Open ten visits at random in the console and compare numbers with the tracker's own screens (the engine parity makes this a formality; photos are the thing to eyeball).
6. Re-enable Navigator for all technicians; hand out the APK link (INSTALL.md 15.3).
7. Record go in the log with the backup name and the SHA of the Fleetbase checkout.

### Why a nightly re-import is safe during phases 3 and 4

The importer is idempotent: every imported record keeps the old id in `legacy_ref`, a re-run **updates in place**, records created natively in Fleetbase (no `legacy_ref`) are never touched, and records you soft-deleted afterwards are not resurrected. So during the pilot you may re-import the tracker's file to refresh the cars that still live there. Do not edit an imported visit in Fleetbase and re-import it: the next import overwrites your edit with the tracker's version.

---

## 2. Importer commands

Prerequisites on the server (otherwise the matching degrades to plain text plus warnings):

* the company exists and was seeded (INSTALL.md 9); get its uuid or public id:
  `docker compose exec -T application php artisan tinker --execute='echo \Fleetbase\Models\Company::pluck("name","public_id");'`
* FleetOps vendors with the exact names the tracker uses (`Local Mechanic`, `Petromin`, `Tyre Vendor`, `Other Specialist`, plus any custom ones in `settings.vendors`); missing vendors are **created** by the importer unless you pass `--no-create-vendors`;
* Fleetbase users whose display **names** equal the tracker's staff names (`Supervisor`, `Technician 1`, `Technician 2` in the real file), or you accept the names being kept as text with a warning and fix the technician on each visit later (names are matched case-insensitively);
* FleetOps vehicles for the plates (INSTALL.md 11, and section 5 below for the two plates that need a decision first). Not strictly required: unmatched plates import as text; they just do not link to FleetOps and the close hook has nothing to write.

Get the files into the container (the importer reads the container's filesystem):

```bash
cd "$FB"
CO=<company public id or uuid>
mkdir -p import/photos            # fleet-data.json from Drive; photos exported as <photoId>.jpg
docker compose exec -T application mkdir -p /tmp/import
docker compose cp import/fleet-data.json application:/tmp/import/fleet-data.json
docker compose cp import/photos application:/tmp/import/photos
```

The importer accepts either the Drive database JSON or an app backup (`{"format":..., "state": {...}, "photos": {...}}` with inline photos). With an app backup, `--photos` is optional.

**1. Dry run** (everything runs inside a transaction that is rolled back; no files written; the reconciliation is still printed; exit code 1 if anything differs):

```bash
docker compose exec -T application php artisan service-tracker:import \
  --company="$CO" --data=/tmp/import/fleet-data.json --photos=/tmp/import/photos \
  --tz=Asia/Riyadh --dry-run --report=/tmp/import/dry-run.json
docker compose cp application:/tmp/import/dry-run.json ./import/dry-run.json
```

**2. Real run** (after `backup.sh`):

```bash
docker compose exec -T application php artisan service-tracker:import \
  --company="$CO" --data=/tmp/import/fleet-data.json --photos=/tmp/import/photos \
  --tz=Asia/Riyadh --report=/tmp/import/import.json
```

**3. Idempotency check**: run step 2 again. The "Import actions" table must show `created 0` everywhere and no new rows in the console.

Options: `--skip-settings` (do not touch settings, lanes, catalog: use this for the nightly refresh during the pilot, so changes made in Fleetbase Settings are not overwritten), `--no-create-vendors`, `--tz` (the zone of the tracker's local times; default `Asia/Riyadh`), `--report` (JSON).

What the importer does and deliberately does not do: skips tombstoned records and orphans; trims and normalises plates; converts local times to UTC; keeps old ids in `legacy_ref`; creates catalog items for services used by jobs but absent from the catalog (empty lane/category/interval, as in the tracker); turns photos named `<id>.jpg` into file rows (idempotent per photo id). It does **not** trigger the FleetOps close hook for history: no `Maintenance`, `MaintenanceSchedule` or `Issue` rows are created for imported visits, so FleetOps will not know about last-service odometers from before the cutover. If you want the due schedules seeded, enter the latest service per car as a FleetOps schedule by hand, or open and re-save the latest visit per car (decide in the pilot; RISKS.md R-05).

## 3. Reading the reconciliation

The command prints three things, in this order.

**Import actions** (table): per collection, `created` / `updated`. First run: all `created`. Re-run: all `updated`, none `created`.

**Reconciliation: counts** (table): `source` vs `database` per collection, matched through `legacy_ref`. Expected for the production file of 11-12 Oct 2026: visits 9, stops 16, services 23, damages 7, expenses 6, tombstoned records skipped 0 (the file lists one tombstone, `services:xmv5i0lzcw3015`, which refers to a record not present; confirm in the dry run). `match` must be `yes` on every line. Catalog: the file has 151 entries; the extension seeds 145 shipped services; extra custom entries and auto-created ones for free-text services appear as additional rows. If `catalog` shows `NO`, check whether you used `--skip-settings` (then it is excluded from the check) and whether the seeder rows (no `legacy_ref`) collide by name with imported ones. This path is unexecuted: treat a catalog count mismatch as a question, not necessarily a defect.

**Reconciliation: N visits compared** and a differences table (first 40). For every visit the **old engine** (run on the source JSON) and the **new engine** (run on the rows now in MySQL) are compared on: total turnaround, the six stage hours, number of overdue jobs, status, and the petty-cash total of the visit. A difference row reads `visit | field | source | database`:

| Field in the table | What a difference means | First thing to check |
|---|---|---|
| `imported` (`present` / `missing`) | the visit never reached the database | warnings list: orphan, skipped tombstone; the visit id |
| `total` | turnaround differs by more than 0.011 h | time-zone (`--tz`), a timestamp that failed to parse, DST (Riyadh has none) |
| `stage:Pickup wait` ... `stage:Return transit` | the six stages individually | a stop that was dropped or reordered; stop timestamps |
| `overdue` | count of overdue jobs differs | odometer import (decimal cast), a job whose service name changed, a missing previous visit |
| `status` | Closed / In progress / Not started | `returned_at` or `arrived_at_lsa` lost |
| `petty_minor` | petty-cash total per visit in halalas | rounding of amounts with more than 2 decimals (accepted, up to 0.015 SAR per entry), an expense not linked to its visit |

Exit code 0 means every count matches and there is no difference; 1 means at least one. The `--report` JSON holds `ok`, `counts`, `actions`, `differences`, `per_visit` (source and database metrics side by side, with the new public id), `engine_now` (the clock used for open visits: an open visit's "open for X h" depends on it) and `data_quality`.

**Warnings** (up to 60 printed) come from the mapper and from name matching. For the real file the mapper reports these 6 (verified by running the mapping layer; they are not errors):

```
[missing_odometer] visits:V-AB0002   no odometer reading (closed visit)
[missing_odometer] visits:V-ABD0003  no odometer reading (closed visit)
[missing_odometer] visits:V-ABD0004  no odometer reading (closed visit)
[plate_whitespace] visits:V-ABD0007  plate "4473 RKR " has leading/trailing whitespace, trimmed
[missing_odometer] visits:V-ABD0007  no odometer reading (closed visit)
[expense_date_outside_visit] expenses:E-ABD0002  expense dated 2026-10-11 is outside visit V-ABD0002 (2026-10-03 .. 2026-10-03)
```

Plus warnings from name matching that only the database run can produce (an unmatched technician or vendor name): expect `Supervisor`, `Technician 1`, `Technician 2` to show up if you did not create users with those names.

**After the import, expect the dashboard to show data-quality findings** (engine, on the real file): V-AB0002, V-ABD0003, V-ABD0004, V-ABD0007 `Odometer missing`; V-ABD0001 `Vendor stop times missing`; V-ABD0005 and V-ABD0006 negative stages (section 5). That is the data, not an import defect.

---

## 4. Rollback plan

Decide the triggers in advance. Suggested **rollback triggers** during hypercare: data loss that cannot be recovered from the device queue or a backup; wrong turnaround numbers on 2 or more visits that are not explained by input; Navigator unusable on more than half of the phones for more than a working day; a security incident; the server down for more than 4 hours in working time with no fix in sight.

| Situation | Action |
|---|---|
| Before cutover day (phases 0 to 4) | nothing to roll back: the tracker is still the system of record. Delete the trial import if needed (below) |
| Cutover day, import fails or the reconciliation shows differences you cannot explain | do not flip. Restore the backup taken in step 3 (`restore.sh`), re-open the tracker for entry, fix, retry on another night |
| After cutover, trigger hit within the first 2 weeks | (1) announce; (2) make the Drive file editable again, (3) give the technicians the old web app link, (4) export what Fleetbase gathered since cutover: Data & Export full workbook + the PDFs of affected visits + `GET /int/v1/service-tracker/visits?with=...` if needed, (5) re-key those visits into the tracker (this is the real cost of a late rollback: it is manual), (6) keep Fleetbase running read-only for analysis, (7) write down the cause |
| After the hypercare window | the tracker is archived; rollback means the same procedure and re-keying, so be sure before you close the window |

**Removing an import** (soft delete; only when you need a clean slate and you have a backup): every imported row has a `legacy_ref`. In MySQL: `UPDATE service_visits SET deleted_at = NOW() WHERE company_uuid = '<uuid>' AND legacy_ref IS NOT NULL;` and the same for `vendor_stops`, `service_jobs`, `damage_records`, `petty_cash_entries`, `service_catalog_items`, `service_lanes`. Photos stay in storage (harmless, deduplicated on re-import). The importer will not resurrect soft-deleted rows, so for a **re-import** restore the backup instead of soft-deleting.

Navigator is a separate app id from the stock Navigator and from the old web app: rolling back needs no app change on the phones except telling people which one to use. Keep the debug-signed and the release APK of each version you hand out.

---

## 5. Data-quality items found in the real data (11-12 Oct 2026 file)

Source: `service-tracker/server/tests/realdata/fleet-data.json` (9 visits, 16 stops, 23 services, 7 damages, 6 expenses, 151 catalog entries) analysed by the mapping layer and by the original JavaScript engine. Each item needs a **decision from the supervisor before or right after the real import**. The importer keeps the data as it is and records the finding; it never "fixes" it.

| # | Finding | Evidence | Effect in Fleetbase | Decision / action |
|---|---|---|---|---|
| 1 | **Missing odometer on 4 of the 9 visits** | V-AB0002 (4998 TGB), V-ABD0003 (8698 HZR), V-ABD0004 (2589 LTB), V-ABD0007 (4473 RKR): empty `odo` | the service-due check cannot run; every job on these visits shows `Odometer missing`; the closed visits get the data check `Odometer missing`; no next-due is recorded for those cars | find the odometer (photo of the dashboard, FleetOps vehicle record, the driver) and enter it after import; the numbers recompute. Make the field mandatory in Navigator for new visits (not built; ask) |
| 2 | **Out-of-order timestamps, V-ABD0005 (3877-EER)** | arrived 07-Oct 08:21; first vendor arrival 10:55; **pickup 21:45** (about 11 h after the car reached the vendor); return 23:02 | stage "Transit to 1st vendor" computes **-10.83 h**; the data check still says OK (the check only tests for missing values) | almost certainly a mistyped pickup time (08:45?). Correct it in Fleetbase after import (the tracker's original stays untouched). Consider adding an "out of order" check (not built) |
| 3 | **Out-of-order timestamps, V-ABD0006 (3858 UKR)** | second stop (Petromin): arrived 08-Oct 16:36, **approval requested 23:13, approval received 18:41**, left 19:00; first stop (Local Mechanic) 20:36 to 22:55 on 08-Oct although pickup was 07-Oct 23:54; return 08-Oct 21:07 | approval wait **-4.53 h**, return transit **-1.80 h** | the two stops look swapped or mistyped; ask Technician 2. Correct after import |
| 4 | **Plates with stray characters** | `4473 RKR ` (trailing space, V-ABD0007), `3877-EER` (dash, V-ABD0005); others use `NNNN AAA` | trailing space is trimmed on import (reported as accepted). Matching to a vehicle and the "same car" history ignores spaces, dashes, dots, underscores and case, so `3877-EER` and `3877 EER` are one car | pick the **canonical display format** (suggest `NNNN AAA` as on the plate) and use it when creating FleetOps vehicles. The Arabic-letter plate form is not handled by the normaliser beyond lower/upper case: if the fleet uses Arabic plates, test one |
| 5 | **Plate 3875 EER vs 3877-EER: same car or two?** | V-ABD0002 `3875 EER` odometer **127,355** on 03-Oct; V-ABD0005 `3877-EER` odometer **142,705** on 07-Oct | different plate keys, so no shared history. If it is one car with a typo, the odometer rose **15,350 km in 4 days**, which is implausible; if it is two cars, both are fine. A wrong merge corrupts the "previous due" overdue logic for that car | **confirm with the technicians / registration cards which plate is right and whether both cars exist** before creating FleetOps vehicles. If it is a typo, correct the plate on the visit after import |
| 6 | **Near-duplicate custom catalog entries** | the catalog has 151 entries, 145 shipped plus customs: `Tire damage`, `Tire Puncher` (unused), `Tire Puncture` (used once), `Tyre puncture repair` (used once); `Oil change - Engine oil & filter Petrol` (10,000 km, used twice) and `Oil change - engine oil & filter Diesel` (5,000 km, used once) next to the shipped `Oil change - engine oil & filter` (5,000 km); `Tyre replacement - set of 2`; `Wrong Fueling` (lane Heavy, used once); also `Window tinting` and `Tyre replacement - single` used on jobs | the engine matches **service names exactly**, so each spelling is its own service: next-due and "previous due" never link `Oil change ... Petrol` to the shipped oil change, and the two puncture spellings split reporting | decide the clean list: merge `Tire Puncher`/`Tire Puncture`/`Tyre puncture repair`; decide whether Petrol/Diesel oil changes are separate services (they carry different intervals, so probably yes: then drop the generic one for those cars); rename with the catalog **rename cascade** (UAT 8.1) so existing jobs follow. Do the cleaning in Fleetbase **after** import, not in the tracker's file |
| 7 | V-ABD0001 has two vendor stops with **no times at all** | stops for Petromin and Local Mechanic with empty arrive/leave | data check `Vendor stop times missing`; vendor hours are zero for that visit | enter the times if known, else leave: it is a known gap |
| 8 | An expense dated **outside its visit** | E-ABD0002 dated 11-Oct, visit V-ABD0002 is 03-Oct | still linked to the visit, so the visit's petty-cash total includes it; the PDF (which links by visit **or** by plate and date) behaves the same | confirm it belongs to that visit; otherwise unlink it |
| 9 | Two visits with identical timestamps | V-ABD0003 and V-ABD0004: arrived 05-Oct 15:11, picked 16:02 | looks like a batch entry of two cars; times are probably approximate | accept, or correct |
| 10 | A job with status **Pending** on a closed visit | V-ABD0003 `Tyre replacement - single` | the close hook writes **no** FleetOps maintenance for a Pending job; the visit is closed anyway | decide whether the job was done; set to Done |
| 11 | Staff names are generic (`Supervisor`, `Technician 1`, `Technician 2`) and `settings.client` is empty, `company` is `US21` | `settings` block | technician filtering needs real users; the PDF header shows company and client | create real users and map; fill in client name in Settings |
| 12 | Photos | 26 photo references (damage photos, receipts); pickup/return photo lists are empty on all 9 visits | only photos present as `<id>.jpg` in the `--photos` folder are imported; the importer warns for the others. The dashboard counts closed visits without pickup/return photos (9 of 9 here) | export the photos from Drive before the final import; accept that old visits have no pickup/return photos |

---

## 6. Licence: AGPL-3.0 obligations for modified Fleetbase and Navigator

**This section is a working note for the people who must decide, not legal advice. Confirm with legal counsel before the trial goes beyond your own staff, before any distribution of the Navigator build, and before commercial use.**

Facts, read from the repositories (12 Oct 2026):

* `fleetbase/fleetbase`, `core-api` and `fleetops` (composer `license`) and `navigator-app` (package.json) are **AGPL-3.0-or-later**. Fleetbase's README says the project is **dual-licensed**: AGPL-3.0, or a paid **Fleetbase Commercial License (FCL)** that "removes the AGPL-3.0 obligations", lets you keep modifications proprietary, and adds support and IP indemnification (contact in the README, hello@fleetbase.io).
* The Service Tracker packages in this build declare **MIT** in their metadata. MIT code can be included in an AGPL work, but once it is linked into and distributed or served as part of a modified Fleetbase, the combination is governed by the AGPL (to the extent the combined work counts as "based on" Fleetbase, which a Laravel package that extends the Fleetbase application and an Ember engine loaded by the Fleetbase console very probably does). The MIT label on our files does not change what you owe for the combination.

What the AGPL asks of you, in practical terms:

| Obligation | When it bites | What to do |
|---|---|---|
| **Section 13, remote network interaction**: if you **modify** the program and users interact with it over a network, you must offer those users the **Corresponding Source** of your modified version | the moment someone other than you uses the server through the network, including your own staff and any client you give a login | publish the fork (Fleetbase checkout with your changes, `packages/service-tracker`, the Docker files) at a place users can reach, and show the link in the console or tell the users where it is. Internal-only use by your own employees is the common reading of "not conveying", but section 13 is written around **users**, so get a legal reading before relying on that |
| **Conveying** (distribution): if you hand a copy of the program or an image to someone | giving the Navigator APK to technicians who are contractors, sending the Docker image to a hosting provider, putting the APK on Google Play or TestFlight | the recipients must get the licence text and the source (or a written offer valid for 3 years). Keep the AGPL notices; do not strip the Fleetbase attribution (the console has a `DISABLE_FLEETBASE_ATTRIBUTION` build flag: do not use it before checking what it removes) |
| Same licence for the whole modified work | the Navigator fork, the patched console/API | your changes to Fleetbase and to Navigator are AGPL-3.0-or-later; the new extension code shipped inside should be offered under AGPL-compatible terms |
| Stores | Apple's App Store terms are widely considered incompatible with GPL-family licences; Google Play is less contentious but not risk-free | the plan in INSTALL.md is direct APK distribution to a known set of technicians for the trial; do not plan an App Store release before this is settled |

Things to **ask counsel / Fleetbase** (put the answers in the RISKS register):

1. Is internal use by your own staff plus a few contractors "interaction by users" under section 13 for a self-hosted, modified instance, and what is the minimum acceptable source offer (a private repo access for those users, or must it be public)?
2. Does giving the client (the party in the PDF reports) a login change anything? (Sending them a PDF does not make them a user of the software; a login does.)
3. Would an **FCL commercial licence** be cheaper than the compliance work, and does it cover Navigator as well (the README says it covers the Fleetbase project; confirm for `navigator-app`)?
4. Transistorsoft's `react-native-background-geolocation` is a **commercial, separately licensed** component inside Navigator: what does your licence key cover (RISKS.md R-12)? Map SDKs (Mapbox) and Google Maps have their own terms.
5. Is it acceptable that the Service Tracker packages are labelled MIT while being part of an AGPL combination, or should the metadata say AGPL-3.0-or-later?

## 7. Data protection note for photos (and the rest of the data)

What is stored: inspection photos of client vehicles at pickup and return (eight angles per visit), damage photos, receipt photos, odometer readings and plates, technician names, timestamps, and device times of taps. Photos can contain **people** (a technician, a passer-by, the driver's face in a mirror), **number plates**, **locations** (background), and **EXIF metadata**. Navigator downsizes images to 1000 px and re-encodes JPEG, which normally drops EXIF GPS: confirm on a real photo with `exiftool`. The console uploader resizes in the browser; server-side uploads store files exactly as received.

Decide and document, before real data goes on the server:

* **Hosting location and legal basis.** KSA's Personal Data Protection Law (PDPL) and its implementing rules may apply to personal data of technicians and any people in photos, and cross-border transfer rules apply if the server is outside the Kingdom. Which hosting region is chosen is an open decision (`BUILD_REPORT.md`). Counsel / the data-protection officer decides the legal basis and the notices.
* **Who sees the photos.** Roles: supervisors see everything; technicians see the photos of their own visits. Anyone with the PDF link or the file URL: the `public` storage disk serves files by URL **without authentication** if someone knows or guesses the path. Treat photo URLs as secrets, prefer an `s3` / `gcs` disk with private objects and signed URLs for real data, and **test** whether a photo URL opens in a private browser window (UAT section 6/7 side check). This is unverified (RISKS.md R-07).
* **Retention.** Define how long photos live (for example 12 months after the visit, or the contract period with the client) and who runs the deletion. There is no automatic purge in the extension. Soft-deleted records keep their files.
* **Subject requests and deletion.** Photos are stored as file rows linked to a visit / damage / receipt: deleting a record does not remove the file object from the disk (soft delete); a real erasure needs a purge of the file and its database row. No tool for it is shipped.
* **Backups** contain the same data: encrypt `config.tar.gz` (it holds the secrets) and keep the other artefacts in a protected location; apply the same retention.
* **Devices.** Navigator keeps unsent photos on the phone until delivered; lost-phone procedure: the APK has no remote wipe; disable the driver user, rotate the API key (INSTALL.md 15.2) and use the device's own lock/wipe.
* **People in photos.** Tell technicians not to photograph people; consider a short on-device notice. Blur/redaction is not built.
* **Third parties.** Fleetbase's console fetches `OSRM_HOST` (public routing server by default) and Google Maps if configured: addresses/coordinates leave the server. The registry host in `docker-compose.yml` (`REGISTRY_HOST=https://registry.fleetbase.io`) is contacted by Fleetbase for extension registry features; review whether you want that on a closed trial.

## 8. Backup and restore

Scripts (`ops/scripts/backup.sh`, `restore.sh`; syntax- and shellcheck-validated, **never run**). Run from the Fleetbase checkout root.

```bash
bash "$BUILD/ops/scripts/backup.sh" ./backups               # MySQL dump (fleetbase + fleetbase_sandbox), storage/app tar, config tar, SHA256SUMS
bash "$BUILD/ops/scripts/restore.sh" ./backups/<timestamp> --yes
```

* **What is backed up**: (1) `mysqldump --single-transaction --routines --triggers --hex-blob` of `fleetbase` (and `fleetbase_sandbox` if present), gzip; (2) `storage/app` (photos, receipts, generated files) as a tar from the application container; (3) `.env`, `api/.env`, the override, and the console runtime config: **secrets**, encrypt or store separately (`gpg -c` or `age`); (4) checksums.
* **What is not**: Redis (cache and the job queue; queued jobs in flight at backup time are lost, acceptable here); `storage/logs`; the Docker images (rebuildable); an `s3`/`gcs` bucket (use the provider's versioning/replication; the database dump references objects by path).
* **Schedule**: nightly at 21:00 UTC (00:00 Riyadh) and before every deploy or import:
  `0 21 * * * cd /srv/fleetbase && bash /srv/build/ops/scripts/backup.sh /srv/backups >> /var/log/fleet-backup.log 2>&1`
* **Retention and off-site**: keep 14 dailies and 8 weeklies; copy the day's backup off the host (`rclone copy`, `rsync` to another machine). A backup on the same disk as the data is not a backup.
* **Recovery point**: the stock compose starts MySQL with `--skip-log-bin`, so there is no point-in-time recovery; you can lose up to a day. If that is not acceptable, drop `--skip-log-bin` from the override's `database.command` and size the binlog expiry (the stock file turned it off because telematics ingestion grew it past 300 GB; this trial has no telematics ingestion).
* **Restore drill** (do it once before cutover, on a scratch VM): install per INSTALL.md steps 1 to 7 with a **different** `.env` secret set but the **same `APP_KEY`** as production (encrypted columns and signed URLs depend on it: without the original `APP_KEY` encrypted data is unreadable), restore, then check: log in, open a visit with photos, download a PDF, `php artisan migrate:status` shows all Ran, the dashboard numbers equal production.
* **Restore of a single table or record**: dump to a scratch database and copy rows; do not restore over production for a one-record mistake. Soft deletes make most "oops" recoverable with `UPDATE ... SET deleted_at = NULL`.
* Test that `gzip -t` passes on every artefact (the script does) and that the dump is not empty after the first run.

## 9. Monitoring and logging

Nothing below is installed; it is the minimum worth setting up for the trial.

| What | How | Alert when |
|---|---|---|
| API alive | external check (UptimeRobot, Uptime Kuma on another host) on `https://api.../health` expecting `"status"` | non-200 for 2 checks |
| Console alive and TLS | check `https://fleet.../` and certificate expiry | down, or less than 14 days to expiry |
| Containers | `docker compose ps` in a cron; `restart: unless-stopped` is set in the stock file | any service not `running`/`healthy` (queue has a healthcheck: `php artisan queue:status`) |
| Queue backlog and failures | `docker compose exec -T cache redis-cli llen queues:default` and `docker compose exec -T application php artisan queue:failed` | backlog growing for 10 minutes, or any failed job |
| Scheduler heartbeat | `docker compose exec -T scheduler php artisan schedule-monitor:list` (Fleetbase syncs the schedule monitor in `deploy.sh`) | a task late or failed, in particular `fleetops:process-maintenance-triggers` |
| Disk | `df -h` for the Docker data root; `docker system df` | storage volume above 80 % (photos), or any volume above 90 % |
| Application errors | Laravel daily logs `storage/logs/laravel-YYYY-MM-DD.log` in the `fleetbase_storage_logs` volume (`LOG_CHANNEL: daily` in the stock compose); `docker compose logs application` | `ERROR` lines; set up a daily `grep -c "\.ERROR:"` mail to yourself during the trial |
| Docker log growth | the stock compose sets no log rotation: add `{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"5"}}` to `/etc/docker/daemon.json` and restart Docker | n/a (prevention) |
| Import / data quality | dashboard "data problems" and "closed without photos" counters; the `--report` JSON of each import | a rising count after cutover |
| Navigator side | the offline-queue banner (queued, failed items with Retry/Discard) is the only signal; **no crash reporting or remote telemetry is built into the fork** | ask technicians to report a "failed" count above 0; consider adding Sentry later |

Log locations summary: API `docker compose logs application` and `storage/logs`; nginx (`httpd`) access/error logs in the container's `/var/log/nginx`; Caddy via `journalctl -u caddy`; MySQL `docker compose logs database`. Times in container logs are Riyadh time (`TZ`); Laravel's own log lines are UTC.

## 10. Hypercare checklist (first two weeks)

* Day 1: confirm backups ran and are restorable (list the artefacts, `gzip -t`).
* Daily: dashboard "data problems", failed queue items on phones, `queue:failed`, disk, last backup age.
* Weekly: Excel period report vs the supervisor's expectation; review the catalog for new near-duplicate spellings (section 5 item 6 will recur); review who has which role.
* Day 10: decide whether to close the hypercare window and archive the tracker.
