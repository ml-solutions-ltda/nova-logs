# Changelog

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
