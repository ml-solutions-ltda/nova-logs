# Changelog

## 2.1.0 — 2026-11-02

- Add progressive cursor batches with accumulated dashboard counters, byte progress, pause/resume and bounded automatic rounds.
- Bound retained message/occurrence samples independently of file size and label sampled recurrence data explicitly.
- Verify sparse logs larger than PHP memory, request cancellation/checkpoints, transient failures and non-advancing responses.

- Restore a distinct error dashboard with window metrics, severity distribution, recurring previews and investigation details.
- Expose the reader's existing failure-only scope as an optional validated `failures` API filter, preserving general listing and cursor binding.

- Apply file/severity changes immediately and keep pagination bound to submitted filters while text search is edited.
- Reset snapshots on filter changes, honor levels on dated links, and provide clear partial/empty/error states.
- Isolate detail cancellation from list loading and ignore stale pagination/detail responses.
- Align fields and actions, constrain long messages, and add responsive table scrolling, keyboard feedback and dark colors.
- Expand frontend regression coverage and rebuild the shipped Nova assets.

## 2.0.4

- Rewind verified handles after fingerprint checks so original downloads stream from byte zero.
- Cover original stream contents in the reader regression suite; the application also verifies the captured byte ceiling after append.

## 2.0.3

- Preserve binary UTF-8 search overlap across cursor requests.
- Retry a header fragment at the next window instead of losing a record at the byte-budget boundary.

## 2.0.2

- Keep detail-header parsing within the captured entry boundary when an incomplete header receives an append.

## 2.0.1

- Accept the bounded 64 KiB token envelope for integrations with extended header metadata; keep UTF-8 tokens compact.

## 2.0.0

- Replace whole-acervo Arcanedev parsing with a shared bounded direct file reader and cursor-based explorer.
- Enforce Laravel-only flat discovery, verified handles, node/operator cursor scope, read/record/time budgets and explicit partial search.
- Preserve authorized original download; migrate APIs and retire file deletion without deleting historical data.
- Add PHP and frontend tests, reproducible dependency lock and build CI.
