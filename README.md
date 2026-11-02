# Nova Logs 2.x — bounded direct Laravel logs

`mlsolutions/nova-logs` supplies the Laravel Nova 4/5 log explorer and `Support\BoundedLogReader`. Logs remain in files; no entry database, ingestion worker or full-acervo parsing is required.

## Upgrade from 1.x

This is an intentional major API change. Install 2.0 and its shipped assets together; rebuild/clear the application's route/config caches through its normal release procedure. `/nova-logs/dashboard` is an error dashboard; `/nova-logs/list` and old dated Inertia URLs use the direct explorer. Both consume the bounded reader. Old `get_chart_data`, `get_list_logs`, and `get/{date}/{level}` APIs return HTTP 410 with migration instructions; they must not silently resume full parsing. Date-specific original downloads remain supported. File deletion is retired (410); use an independently authorized retention procedure. No data, file or old index is deleted by upgrading.

API prefix: `/nova-vendor/mlsolutions/nova-log-viewer`:

- `GET /files`: bounded discovery, `data`, `partial`.
- `GET /entries`: optional `file_id`, `level`, literal `search`, `snapshot`, `cursor`, and boolean `failures` (send `1` in query strings). `failures=1` restricts results to ERROR/CRITICAL/ALERT/EMERGENCY and excludes deprecation-classified entries; it is bound to the cursor like other filters. Omitting it or sending `0` preserves the general list. Returns `data`, `next_cursor`, `partial`, `bytes_read`, `scanned_to`, `ceiling`, `snapshot`. Continue even after an empty page. Reuse filters and snapshot; refresh to change file/filter or include appends.
- `GET /entries/{reference}?locator=...&offset=...`: bounded details and `next_offset`.
- `GET /files/{id}/download`: authorized original streamed from a verified open handle, in 64 KiB chunks; never parses the file first.

Every route requires the registered Tool's authorization and an authenticated platform administrator or existing `viewAlarmDashboard` permission. Logs may contain multiple companies: this is global operational access, not a tenant-filtered viewer. Do not grant that permission to tenant users. Every response is private/no-store; outputs escape HTML. Downloads contain original sensitive data.

## Explorer controls (local UI correction, 2026-10-29)

The list explorer at `/nova-logs/list` supports every log level. Changing the file or severity applies the filters immediately and starts a fresh snapshot at page one. Submit text search with **Aplicar filtros** or Enter; **Limpar** clears text and severity while keeping the selected file. Reapplying filters also captures new appends. Dated legacy links apply their date and level on the initial query.

Pagination uses the submitted filters and captured snapshot. Editing search text shows a pending-change notice; it does not change an existing cursor. Empty partial pages offer **Continuar pesquisa** rather than implying that no match exists in the rest of the file. Failed pagination preserves the last successful page; failed fresh queries show an error without displaying results from old filters. Listing and detail requests have separate cancellation states, and late responses are ignored.

Fields and actions have consistent sizing and wrap on small screens. Long messages are limited to three preview lines, with the full bounded record available in **Detalhes**; they cannot push actions out of the table. Only the table scrolls horizontally on narrow screens. Labels, keyboard focus, loading/error feedback and light/dark colors are included. No global historical totals or unbounded background search were added.

These interface changes were released in 2.1.0. Applications locked to 2.0.4 must adopt the new version through their normal dependency/release process before these changes reach their users. Version 2.1.1 also refreshes the asset URLs when their contents change.

## Asset cache invalidation

From 2.1.1, the script and stylesheet registrations each include a fingerprint of
their own content. A release with changed bytes gets a new Nova asset URL even
when file size and modification time are unchanged; unchanged contents keep the
same URL. This supports long cache lifetimes without retaining an older interface
after a page reload. The existing authentication and asset middleware still apply.

Missing asset files are not registered, so an incomplete package does not throw
while booting unrelated Nova tools. The log interface still requires both shipped
assets; verify their presence and the installed revision during release checks.

## Error dashboard and progressive batches

`/nova-logs/dashboard` registers a separate Vue component. It requests `failures=1` and displays accumulated failure/critical counters, distinct message previews, latest valid timestamp, clickable severity distribution, recurring previews and up to six recent occurrences for investigation. CRITICAL/ALERT/EMERGENCY contribute to the critical metric. Unknown dates remain inspectable without inventing a timestamp.

The first bounded query loads one batch. **Carregar em lotes** continues sequentially from its cursor, with one active batch request and a 750 ms gap. **Pausar carregamento** cancels the active request/delay; **Retomar carregamento** resumes at the last accepted cursor without double counting. Each automatic round pauses after at most 60 requests or a cooperative 60-second budget checked between requests; an in-flight request retains its 20-second timeout. Partial pages without matches continue normally. No automatic retries, background polling, index, worker or full-file PHP read.

The progress bar measures the accepted physical offset (`scanned_to/ceiling`), not cumulative read bytes, since lookahead may reread bytes. Counters refer to the accumulated accepted portion of one selected file and fixed captured ceiling. At EOF they cover that captured file/filter, not other files, the whole day/environment, or later appends. Restart to include appends or change filters. Loading/errors preserve valid partial counters; initial failures show unavailable values. A 409/invalid snapshot requires restarting. Transient failures, 429 and non-advancing batches pause for manual retry, preserving the previous checkpoint. Expired tokens still require restarting.

Browser memory is bounded independently of file size: four severity counters, latest valid timestamp, six recent occurrence samples, the last bounded response, and at most 250 distinct message previews with one sample each. All accepted failures contribute to exact counters. Beyond 250 messages, the distinct metric shows `250+` and recurrence panels explicitly describe a sample of tracked messages; they cannot claim a global top-five ranking. Counts for tracked messages remain exact. Numeric displays use compact notation for large values, with exact counters retained internally and in metric titles/accessibility labels. Full log bodies and cursor history are not accumulated. The checkpoint lives only in the open view; leaving/reloading it starts a new query.

File/severity changes apply immediately and reset the aggregate. Text search uses Enter or **Atualizar dashboard**; an unsubmitted search draft does not change an active scan's filters. **Limpar filtros** preserves failure-only scope and selected file while starting a fresh aggregate. Clicking a severity bar restarts with that filter. Recurring messages/occurrences open bounded details and focus the panel. **Abrir lista de logs** leads to the general explorer, whose manual pagination remains unchanged. Install the frontend and matching PHP controller together.

## Configuration and budgets

`nova-logs.reader`: server-owned `roots` (flat directories), `node` (unique to independent volumes), `include_single` (true), `discovery_entries` (1,000), `max_files` (100), `read_bytes` (1 MiB), `scan_records` (500), `read_seconds` (0.25), `entry_preview_bytes` (16 KiB). Bounds are clamped; pages return at most 100 records. Allowed filenames: `laravel-*.log` with simple ASCII filename characters and optionally `laravel.log`. `laravel.log` is included for Laravel's single channel. No recursion, symlinks, arbitrary path parameters, gzip or other component logs.

Discovery caches metadata only within a reader instance/request. Once its budget expires, `partial=true`; files beyond the bounded discovery are unavailable until roots are narrowed. Do not reuse a reader singleton between requests.

A page reads forward in physical offset order within one selected file, initially the most recently modified file among the bounded discovery results. It does not merge or sort the entire log history by date. The captured size is a high water boundary; append is excluded until refresh. It is not an immutable snapshot: device/inode, size and hashes at the original head/tail reject common rotation, truncation, removal and rewriting (409). Arbitrary rewrites between anchors, or truncate/regrow that restores anchors, cannot be reliably detected without a full checksum or cooperative writer; operators must treat the original files as append-only. Expiration is 30 minutes from each issued token. Cursors/locators are encrypted/authenticated with Laravel's application key and bound to node/operator/filter/version.

A very large multiline record crosses multiple requests: cursors retain bounded metadata, a byte counter, literal-match state and at most 2 KiB of binary-safe search overlap. No record is returned until its end or captured EOF is reached. The EOF record is explicitly incomplete because writers can append. Headers in formats not recognized by the installed parser are reported as unknown; JSON lines above the bounded header buffer can require original inspection. The direct API has no global counts/instant historical search. Literal queries are at most 256 characters. UTF-8 display replacement can occur at fragment boundaries; offsets always count raw bytes.

The cooperative 250 ms budget bounds parsing between reads, not blocking filesystem I/O. Scanner reads are at most 1 MiB, including header lookahead; fixed-size identity checks add a few KiB. Returning metadata, encrypted locators and details is bounded by page/preview limits. Each concurrent request has its own budget; use existing HTTP throttle and PHP worker capacity.

## Verification

PHPUnit covers budgets, oversized multiline continuation, literal search, allowlist/symlinks, rotation, expired tokens and operator isolation. `npm test` covers list pagination plus accumulated batches, empty windows, bounded rounds, pause/resume, stale response protection, request/timer cancellation, non-advancing/error recovery and memory caps. A sparse 512 MiB fixture is read in bounded PHP batches under a 64 MiB PHP memory limit; it is not a complete scan or a production load benchmark. Build with Node 22 and Laravel Mix; Webpack is pinned to the compatible version. Tests can use an isolated `tests/composer.json`; licensed Nova/application authorization and authenticated browser checks remain separate integration gates.

Rollback requires application and package/asset/route revisions to match. Do not restart the former indexing loop automatically. Preserve existing entry/search/status tables and original files. Review release effects and active queue-worker versions separately.
