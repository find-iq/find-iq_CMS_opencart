# Changelog

All notable changes to the Find-iQ OpenCart module are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versions follow [Semantic Versioning](https://semver.org/).

---

## [0.6.2] — 2026-06-09

### Added
- **Full re-upload mode** (`reset=1` webhook parameter): resets all sync state,
  removes ghost products from the sync table, then re-syncs everything from
  scratch including fresh image resizes.
- **"Full re-upload" quick link** in the admin Documentation tab (all four
  languages: UK, EN, RU, PL) — ready-to-copy URL with `&reset=1` pre-filled.

### Fixed
- `reset=1` was silently ignored when a sync process was already running.
  The webhook now stops the running worker (writes stop flag, waits up to 2 s)
  before resetting state and launching a new process.
- Infinite sync loop caused by ghost products — rows in `oc_find_iq_sync_products`
  for products that no longer exist or have `status = 0` in `oc_product`.
  `prepareTempTable` now accepts a `$full_clean` flag: when `true` it deletes all
  ghost rows before inserting new active products, preventing the worker from
  fetching an empty batch forever.

---

## [0.6.1] — 2026-04-16

### Fixed
- HTML entity decode applied to all text fields (product names, descriptions,
  attributes, category names) to prevent double-encoded entities in the Find-iQ
  index.

---

## [0.6] — 2026-04-16

### Added
- Initial public release.
- Fire-and-forget webhook endpoint (`catalog/controller/find_iq/webhook.php`):
  responds immediately, runs the sync worker as a background PHP CLI process.
- Self-respawning cron worker: relaunches itself if products remain after the
  server time limit is reached.
- Built-in GD image resizer (`FindIQImage`) — no Imagick dependency required.
- Three-phase sync: new products → changed products (price / stock / special) →
  full reindex.
- Lock file mechanism (`find_iq_sync.lock`) prevents duplicate workers.
- Stop flag (`find_iq_sync.stop`) allows graceful halt via `action=stop`.
- Admin panel: ready-to-copy webhook URLs with one-click copy, cron log viewer,
  image resize size selector, fast/full reindex interval settings, image
  processor choice (GD or OpenCart).
- Four language packs: UK, EN, RU, PL.
