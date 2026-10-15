# Nova Logs 2.0 — bounded direct Laravel logs

`mlsolutions/nova-logs` supplies the Laravel Nova 4/5 log explorer and `Support\BoundedLogReader`. Logs remain in files; no entry database, ingestion worker or full-acervo parsing is required.

## Upgrade from 1.x

This is an intentional major API change. Install 2.0 and its shipped assets together; rebuild/clear the application's route/config caches through its normal release procedure. `/nova-logs/dashboard`, `/nova-logs/list` and old dated Inertia URLs now use the same direct explorer. Old `get_chart_data`, `get_list_logs`, and `get/{date}/{level}` APIs return HTTP 410 with migration instructions; they must not silently resume full parsing. Date-specific original downloads remain supported. File deletion is retired (410); use an independently authorized retention procedure. No data, file or old index is deleted by upgrading.

API prefix: `/nova-vendor/mlsolutions/nova-log-viewer`:

- `GET /files`: bounded discovery, `data`, `partial`.
- `GET /entries`: optional `file_id`, `level`, literal `search`, `snapshot`, `cursor`. Returns `data`, `next_cursor`, `partial`, `bytes_read`, `scanned_to`, `ceiling`, `snapshot`. Continue even after an empty page. Reuse filters and snapshot; refresh to change file/filter or include appends.
- `GET /entries/{reference}?locator=...&offset=...`: bounded details and `next_offset`.
- `GET /files/{id}/download`: authorized original streamed from a verified open handle, in 64 KiB chunks; never parses the file first.

Every route requires the registered Tool's authorization and an authenticated platform administrator or existing `viewAlarmDashboard` permission. Logs may contain multiple companies: this is global operational access, not a tenant-filtered viewer. Do not grant that permission to tenant users. Every response is private/no-store; outputs escape HTML. Downloads contain original sensitive data.

## Configuration and budgets

`nova-logs.reader`: server-owned `roots` (flat directories), `node` (unique to independent volumes), `include_single` (true), `discovery_entries` (1,000), `max_files` (100), `read_bytes` (1 MiB), `scan_records` (500), `read_seconds` (0.25), `entry_preview_bytes` (16 KiB). Bounds are clamped; pages return at most 100 records. Allowed filenames: `laravel-*.log` with simple ASCII filename characters and optionally `laravel.log`. `laravel.log` is included for Laravel's single channel. No recursion, symlinks, arbitrary path parameters, gzip or other component logs.

Discovery caches metadata only within a reader instance/request. Once its budget expires, `partial=true`; files beyond the bounded discovery are unavailable until roots are narrowed. Do not reuse a reader singleton between requests.

A page reads forward in physical offset order within one selected file, initially the most recently modified file among the bounded discovery results. It does not merge or sort the entire log history by date. The captured size is a high water boundary; append is excluded until refresh. It is not an immutable snapshot: device/inode, size and hashes at the original head/tail reject common rotation, truncation, removal and rewriting (409). Arbitrary rewrites between anchors, or truncate/regrow that restores anchors, cannot be reliably detected without a full checksum or cooperative writer; operators must treat the original files as append-only. Expiration is 30 minutes from each issued token. Cursors/locators are encrypted/authenticated with Laravel's application key and bound to node/operator/filter/version.

A very large multiline record crosses multiple requests: cursors retain bounded metadata, a byte counter, literal-match state and at most 2 KiB of binary-safe search overlap. No record is returned until its end or captured EOF is reached. The EOF record is explicitly incomplete because writers can append. Headers in formats not recognized by the installed parser are reported as unknown; JSON lines above the bounded header buffer can require original inspection. The direct API has no global counts/instant historical search. Literal queries are at most 256 characters. UTF-8 display replacement can occur at fragment boundaries; offsets always count raw bytes.

The cooperative 250 ms budget bounds parsing between reads, not blocking filesystem I/O. Scanner reads are at most 1 MiB, including header lookahead; fixed-size identity checks add a few KiB. Returning metadata, encrypted locators and details is bounded by page/preview limits. Each concurrent request has its own budget; use existing HTTP throttle and PHP worker capacity.

## Verification

PHPUnit covers budgets, oversized multiline continuation, literal search, allowlist/symlinks, rotation, expired tokens and operator isolation. `npm test` covers pagination including empty pages, stale response protection, cancellation and errors. Build with Node 22 and Laravel Mix; Webpack is pinned to the compatible version. Tests can use an isolated `tests/composer.json`; licensed Nova/application authorization and authenticated browser checks remain separate integration gates.

Rollback requires application and package/asset/route revisions to match. Do not restart the former indexing loop automatically. Preserve existing entry/search/status tables and original files. Review release effects and active queue-worker versions separately.
