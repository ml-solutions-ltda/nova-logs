# Changelog

## 2.0.0

- Replace whole-acervo Arcanedev parsing with a shared bounded direct file reader and cursor-based explorer.
- Enforce Laravel-only flat discovery, verified handles, node/operator cursor scope, read/record/time budgets and explicit partial search.
- Preserve authorized original download; migrate APIs and retire file deletion without deleting historical data.
- Add PHP and frontend tests, reproducible dependency lock and build CI.
