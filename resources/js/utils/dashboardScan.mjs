export const SCAN_LIMITS = Object.freeze({ groups: 250, recent: 6, requests: 60, durationMs: 60000, delayMs: 750 })
export const ERROR_LEVELS = Object.freeze(['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'])

export function createScan() {
  return { counts: Object.fromEntries(ERROR_LEVELS.map(level => [level, 0])), total: 0, latest: null, groups: new Map(), groupsLimited: false, recent: [], batches: 0, offset: 0, ceiling: null }
}

// Called synchronously before a page/cursor is committed. No array grows with the file.
export function appendPage(scan, page) {
  const offset = page.scanned_to
  const ceiling = page.ceiling ?? 0
  if (!Array.isArray(page.data) || page.data.length > 100 || !Number.isSafeInteger(offset) || !Number.isSafeInteger(ceiling) || offset < 0 || offset > ceiling || (scan.ceiling !== null && ceiling !== scan.ceiling)) {
    throw new Error('O lote retornou um progresso inválido. Reinicie a consulta.')
  }
  if ((page.next_cursor && offset <= scan.offset) || (scan.batches && ceiling > 0 && offset <= scan.offset)) {
    const error = new Error('A leitura não avançou. Aguarde e retome o carregamento.')
    error.code = 'LOG_SCAN_STALLED'
    throw error
  }
  for (const source of page.data) {
    if (!source || !ERROR_LEVELS.includes(source.level)) continue
    const entry = { reference: source.reference, locator: source.locator, level: source.level, message: typeof source.message === 'string' ? source.message : '', occurred_at: typeof source.occurred_at === 'string' ? source.occurred_at : null, sequence: ++scan.total }
    scan.counts[entry.level]++
    if (entry.occurred_at && (!scan.latest || entry.occurred_at > scan.latest)) scan.latest = entry.occurred_at
    const message = entry.message.trim()
    if (scan.groups.has(message)) scan.groups.get(message).count++
    else if (scan.groups.size < SCAN_LIMITS.groups) scan.groups.set(message, { message, count: 1, entry })
    else scan.groupsLimited = true
    scan.recent.push(entry)
    scan.recent.sort((a, b) => (b.occurred_at || '').localeCompare(a.occurred_at || '') || b.sequence - a.sequence)
    if (scan.recent.length > SCAN_LIMITS.recent) scan.recent.length = SCAN_LIMITS.recent
  }
  scan.batches++
  scan.offset = offset
  scan.ceiling = ceiling
}
