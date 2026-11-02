import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createScan, appendPage, SCAN_LIMITS } from '../resources/js/utils/dashboardScan.mjs'

function component(send, props = {}, view = 'FileExplorer') {
  const load = (name, base) => {
    const source = readFileSync(new URL(`../resources/js/pages/${name}.vue`, import.meta.url), 'utf8')
      .match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm, '').replace('export default', 'return')
    return new Function('Nova', 'FileExplorer', 'createScan', 'appendPage', 'SCAN_LIMITS', source)({ request: () => ({ get: send }) }, base, createScan, appendPage, SCAN_LIMITS)
  }
  const base = load('FileExplorer')
  const extra = view === 'Dashboard' ? load('Dashboard', base) : {}
  const options = { ...base, ...extra, methods: { ...base.methods, ...extra.methods }, computed: { ...base.computed, ...extra.computed } }
  const vm = { ...props, ...base.data.call(props), ...extra.data?.call(props), $nextTick: async () => {}, $refs: {} }
  Object.assign(vm, Object.fromEntries(Object.entries(options.methods).map(([key, value]) => [key, value.bind(vm)])))
  for (const [key, value] of Object.entries(options.computed)) Object.defineProperty(vm, key, { get: value.bind(vm) })
  options.beforeUnmount = function () { base.beforeUnmount?.call(this); extra.beforeUnmount?.call(this) }
  return { vm, options }
}

const page = (rows = [], next = 'next', snapshot = 'file') => ({ data: { data: rows, snapshot, next_cursor: next } })

test('empty partial pages can continue and previous restores cursors', async () => {
  const { vm } = component(async () => page())
  vm.fileId = 'file-id'
  await vm.refresh()
  await vm.next()
  assert.equal(vm.cursors.length, 2)
  await vm.previous()
  assert.equal(vm.cursors.length, 1)
})

test('pagination preserves submitted filters while search is being edited', async () => {
  const requests = []
  const { vm } = component(async (url, config) => { requests.push(config.params); return page() })
  vm.fileId = 'file-id'
  vm.search = 'original search'
  vm.severity = 'ERROR'
  await vm.refresh()
  vm.search = 'draft search'
  assert.equal(vm.hasPendingFilters, true)
  await vm.next()
  await vm.previous()
  assert.deepEqual(requests[1], { file_id: 'file-id', search: 'original search', level: 'ERROR', snapshot: 'file', cursor: 'next' })
  assert.equal(requests[2].search, 'original search')
  await vm.refresh()
  assert.deepEqual(requests[3], { file_id: 'file-id', search: 'draft search', level: 'ERROR', snapshot: null, cursor: null })
  assert.equal(vm.cursors.length, 1)
  assert.equal(vm.hasPendingFilters, false)
})

test('changing file or severity starts a new snapshot and resets pagination', async () => {
  const requests = []
  const { vm } = component(async (url, config) => { requests.push(config.params); return page() })
  vm.fileId = 'first'
  await vm.refresh()
  await vm.next()
  vm.fileId = 'second'
  vm.severity = 'WARNING'
  await vm.refresh()
  assert.deepEqual(requests.at(-1), { file_id: 'second', search: '', level: 'WARNING', snapshot: null, cursor: null })
  assert.deepEqual(vm.cursors, [null])
  await vm.clearFilters()
  assert.equal(requests.at(-1).level, '')
  assert.equal(vm.fileId, 'second')
})

test('stale list and detail responses cannot replace newer requests', async () => {
  const resolvers = []
  const { vm } = component(() => new Promise(resolve => resolvers.push(resolve)))
  vm.fileId = 'file-id'
  const old = vm.refresh()
  vm.search = 'new'
  const current = vm.refresh()
  resolvers[1](page([{ message: 'current' }]))
  await current
  resolvers[0](page([{ message: 'obsolete' }]))
  await old
  assert.equal(vm.rows[0].message, 'current')
  const detail = vm.show({ reference: 'ref', locator: 'loc' })
  vm.close()
  resolvers[2]({ data: { text: 'obsolete' } })
  await detail
  assert.equal(vm.selected, null)
  assert.equal(vm.detailLoading, false)
  assert.deepEqual(vm.detail, {})
})

test('failed pagination keeps the previous page, rows and snapshot', async () => {
  let fail = false
  const { vm } = component(async () => {
    if (fail) throw { response: { data: { message: 'Rotated' } } }
    return page([{ message: 'preserved' }])
  })
  vm.fileId = 'file-id'
  await vm.refresh()
  fail = true
  await vm.next()
  assert.equal(vm.loading, false)
  assert.equal(vm.error, 'Rotated')
  assert.equal(vm.rows[0].message, 'preserved')
  assert.equal(vm.snapshot, 'file')
  assert.deepEqual(vm.cursors, [null])
  await vm.refresh()
  assert.deepEqual(vm.rows, [])
  assert.equal(vm.hasRead, false)
  assert.equal(vm.snapshot, null)
})

test('pagination ignores repeated clicks and unavailable directions', async () => {
  let resolve
  let calls = 0
  const { vm } = component(() => { calls++; return new Promise(r => { resolve = r }) })
  await vm.previous()
  await vm.next()
  assert.equal(calls, 0)
  vm.result = { next_cursor: 'next' }
  const pending = vm.next()
  await vm.next()
  assert.equal(calls, 1)
  resolve(page())
  await pending
  assert.equal(vm.cursors.length, 2)
})

test('refresh during a pagination response cannot append an old cursor', async () => {
  let resolve
  const { vm } = component(() => new Promise(r => { resolve = r }))
  vm.fileId = 'file-id'
  vm.result = { next_cursor: 'old-cursor' }
  const pending = vm.next()
  const oldResolve = resolve
  const refresh = vm.refresh()
  resolve(page())
  await refresh
  oldResolve(page())
  await pending
  assert.deepEqual(vm.cursors, [null])
})

test('details cannot cancel list requests or leave loading stuck', async () => {
  const resolvers = []
  const signals = []
  const { vm } = component((url, config) => {
    signals.push(config.signal)
    return new Promise(resolve => resolvers.push(resolve))
  })
  const pending = vm.load(null)
  const detail = vm.show({ reference: 'ref', locator: 'loc' })
  assert.equal(signals[0].aborted, false)
  vm.close()
  assert.equal(signals[1].aborted, true)
  resolvers[0](page())
  resolvers[1]({ data: { text: 'obsolete' } })
  await Promise.all([pending, detail])
  assert.equal(vm.loading, false)
  assert.deepEqual(vm.detail, {})
})

test('switching details clears old contents and failed reads release detail loading', async () => {
  const { vm } = component(async () => { throw new Error('offline') })
  vm.selected = { reference: 'first' }
  vm.detail = { text: 'first contents' }
  await vm.show({ reference: 'second', locator: 'loc' })
  assert.deepEqual(vm.detail, {})
  assert.equal(vm.detailLoading, false)
  assert.match(vm.detailError, /Registro indisponível/)
})

test('dated legacy URLs apply both their file and level on first request', async () => {
  const requests = []
  const { vm, options } = component(async (url, config) => {
    requests.push({ url, params: config.params })
    return url.endsWith('/files')
      ? { data: { data: [{ id: 'latest', name: 'laravel-today.log' }, { id: 'dated', name: 'laravel-2026-10-14.log' }], partial: false } }
      : page()
  }, { date: '2026-10-14', level: 'ERROR' })
  await options.mounted.call(vm)
  assert.equal(requests[1].params.file_id, 'dated')
  assert.equal(requests[1].params.level, 'ERROR')
  assert.equal(vm.initializing, false)
})

test('empty file discovery does not query entries and discovery failure is visible', async () => {
  const requests = []
  const { vm, options } = component(async url => { requests.push(url); return { data: { data: [], partial: false } } })
  await options.mounted.call(vm)
  assert.equal(requests.length, 1)
  assert.equal(vm.initializing, false)
  assert.equal(vm.hasRead, false)
  const failed = component(async () => { throw new Error('offline') })
  await failed.options.mounted.call(failed.vm)
  assert.match(failed.vm.error, /Não foi possível listar/)
  assert.equal(failed.vm.initializing, false)
})

test('legacy lowercase levels and all map to supported API filters', async () => {
  for (const [level, expected] of [['error', 'ERROR'], ['all', ''], ['unknown', '']]) {
    let params
    const { vm } = component(async (url, config) => { params = config.params; return page() }, { level })
    vm.fileId = 'dated'
    await vm.refresh()
    assert.equal(params.level, expected)
  }
})

test('unmount cancels discovery and ignores late responses', async () => {
  let resolve
  let signal
  const { vm, options } = component((url, config) => {
    signal = config.signal
    return new Promise(r => { resolve = r })
  })
  const pending = options.mounted.call(vm)
  options.beforeUnmount.call(vm)
  assert.equal(signal.aborted, true)
  resolve({ data: { data: [{ id: 'late' }] } })
  await pending
  assert.deepEqual(vm.files, [])
})


test('Nova registers a distinct dashboard and keeps the list and dated views as explorers', () => {
  const registered = new Map()
  const source = readFileSync(new URL('../resources/js/tool.js', import.meta.url), 'utf8').replace(/^import .*$/gm, '')
  const list = {}, dashboard = {}
  new Function('Nova', 'FileExplorer', 'Dashboard', source)({ booting: fn => fn(), inertia: (name, component) => registered.set(name, component) }, list, dashboard)
  assert.equal(registered.get('NovaLogs'), dashboard)
  assert.equal(registered.get('LogsTool'), list)
  assert.equal(registered.get('ShowLogs'), list)
  assert.notEqual(registered.get('NovaLogs'), registered.get('LogsTool'))
})

const batch = (rows, offset, ceiling = 10, next = offset < ceiling ? `c${offset}` : null) => ({ data: { data: rows, scanned_to: offset, ceiling, snapshot: 'captured', next_cursor: next } })
const failure = (id, level = 'ERROR', message = `Failure ${id}`) => ({ reference: String(id), locator: `locator-${id}`, level, message, occurred_at: '2026-10-30 12:00:00' })
function dashboard(send) {
  const result = component(send, {}, 'Dashboard')
  result.vm.fileId = 'file-id'
  result.vm.initializing = false
  result.vm.waitBetweenBatches = async () => {}
  return result
}

test('dashboard counters aggregate only failures and preserve the latest valid timestamp', () => {
  const { vm } = dashboard(async () => batch([], 1))
  assert.equal(vm.metricsReady, false)
  vm.acceptPage(batch([
    failure(1, 'ERROR', 'Repeated'), failure(2, 'ERROR', 'Repeated'),
    { ...failure(3, 'CRITICAL', 'Critical'), occurred_at: null },
    { ...failure(4, 'WARNING'), occurred_at: '2026-10-30 15:00:00' }, failure(5, 'INFO'),
  ], 1).data)
  assert.equal(vm.metricsReady, true)
  assert.deepEqual(vm.summary, { total: 3, critical: 1, latest: '2026-10-30 12:00:00' })
  assert.equal(vm.messageGroups.length, 2)
  assert.equal(vm.messageGroups[0].count, 2)
  assert.equal(vm.distribution.find(item => item.level === 'ERROR').percent, 67)
  assert.equal(vm.distribution.reduce((sum, item) => sum + item.count, 0), 3)
  assert.equal(vm.occurrences[0].reference, '2')
  vm.loading = true
  vm.error = 'network unavailable'
  assert.equal(vm.metricsReady, true)
  assert.equal(vm.summary.total, 3)
})

test('dashboard follows empty batches to EOF and accumulates without keeping cursor history', async () => {
  const requests = []
  const { vm } = dashboard(async (url, config) => {
    requests.push(config.params)
    const offset = config.params.cursor ? Number(config.params.cursor.slice(1)) + 1 : 1
    return batch(offset === 2 ? [] : [failure(offset)], offset, 3)
  })
  await vm.refresh()
  vm.search = 'unsubmitted draft'
  await vm.startScan()
  assert.equal(vm.scan.batches, 3)
  assert.equal(vm.summary.total, 2)
  assert.equal(vm.progressPercent, 100)
  assert.equal(vm.result.next_cursor, null)
  assert.equal(vm.scanning, false)
  assert.deepEqual(vm.cursors, [null])
  assert.deepEqual(requests[1], { file_id: 'file-id', search: '', level: '', failures: 1, snapshot: 'captured', cursor: 'c1' })
})

test('a bounded run pauses and resumes from its last accepted cursor without double counting', async () => {
  const { vm } = dashboard(async (url, config) => {
    const offset = config.params.cursor ? Number(config.params.cursor.slice(1)) + 1 : 1
    return batch([failure(offset)], offset, 10)
  })
  vm.batchLimits.requests = 2
  await vm.refresh()
  await vm.startScan()
  assert.equal(vm.scan.batches, 3)
  assert.equal(vm.summary.total, 3)
  assert.match(vm.pauseReason, /Pausa automática/)
  await vm.startScan()
  assert.equal(vm.scan.batches, 5)
  assert.equal(vm.summary.total, 5)
  vm.batchLimits.durationMs = 0
  await vm.startScan()
  assert.equal(vm.scan.batches, 5)
  assert.match(vm.pauseReason, /Pausa automática/)
})

test('pause cancels an in-flight batch; resume uses the unconsumed cursor', async () => {
  let resolve, signal, enter
  const entered = new Promise(r => { enter = r })
  let delayed = true
  const cursors = []
  const { vm } = dashboard(async (url, config) => {
    cursors.push(config.params.cursor)
    const offset = config.params.cursor ? Number(config.params.cursor.slice(1)) + 1 : 1
    if (config.params.cursor && delayed) {
      signal = config.signal
      enter()
      return new Promise(r => { resolve = () => r(batch([failure(offset)], offset, 3)) })
    }
    return batch([failure(offset)], offset, 3)
  })
  await vm.refresh()
  const pending = vm.startScan()
  await entered
  vm.pauseScan('Paused')
  assert.equal(signal.aborted, true)
  resolve()
  await pending
  assert.equal(vm.summary.total, 1)
  assert.equal(vm.result.next_cursor, 'c1')
  delayed = false
  await vm.startScan()
  assert.deepEqual(cursors, [null, 'c1', 'c1', 'c2'])
  assert.equal(vm.summary.total, 3)
})

test('changing filters while a batch is pending discards the previous aggregate and late response', async () => {
  let resolve, enter
  const entered = new Promise(r => { enter = r })
  const { vm } = dashboard(async (url, config) => {
    if (config.params.cursor) {
      enter()
      return new Promise(r => { resolve = () => r(batch([failure('stale')], 2)) })
    }
    return batch([failure(config.params.search || 'original')], 1)
  })
  await vm.refresh()
  const pending = vm.startScan()
  await entered
  vm.search = 'new filter'
  await vm.refresh()
  resolve()
  await pending
  assert.equal(vm.summary.total, 1)
  assert.equal(vm.messageGroups[0].entry.reference, 'new filter')
  assert.equal(vm.result.next_cursor, 'c1')
  await vm.clearFilters()
  assert.equal(vm.scan.batches, 1)
  assert.equal(vm.appliedFilters.failures, 1)
})

test('failed reads pause without retry; 409 blocks resume and 429 allows manual retry', async () => {
  for (const status of [409, 429]) {
    let fail = false, calls = 0
    const { vm } = dashboard(async (url, config) => {
      calls++
      if (fail) throw { response: { status, data: { message: 'Temporary failure' } } }
      const offset = config.params.cursor ? Number(config.params.cursor.slice(1)) + 1 : 1
      return batch([failure(offset)], offset, 2)
    })
    await vm.refresh()
    fail = true
    await vm.startScan()
    assert.equal(calls, 2)
    assert.equal(vm.summary.total, 1)
    assert.equal(vm.scanning, false)
    assert.equal(vm.scanBlocked, status === 409)
    fail = false
    await vm.startScan()
    assert.equal(calls, status === 409 ? 2 : 3)
    assert.equal(vm.summary.total, status === 409 ? 1 : 2)
    await vm.refresh()
    assert.equal(vm.scanBlocked, false)
    assert.equal(vm.summary.total, 1)
  }
})

test('stalled progress pauses before committing counters and permits manual recovery', async () => {
  let calls = 0
  const { vm } = dashboard(async () => {
    const offset = ++calls >= 3 ? 2 : 1
    return batch([failure(offset)], offset, 2)
  })
  await vm.refresh()
  await vm.startScan()
  assert.equal(vm.scan.batches, 1)
  assert.equal(vm.summary.total, 1)
  assert.equal(vm.result.next_cursor, 'c1')
  assert.equal(vm.scanBlocked, false)
  assert.match(vm.error, /não avançou/)
  await vm.startScan()
  assert.equal(vm.scan.batches, 2)
  assert.equal(vm.summary.total, 2)
  assert.equal(vm.result.next_cursor, null)
})

test('unmount clears a scheduled batch and never sends another request', async () => {
  let calls = 0
  const { vm, options } = dashboard(async () => { calls++; return batch([failure(1)], 1) })
  vm.waitBetweenBatches = options.methods.waitBetweenBatches.bind(vm)
  vm.batchLimits.delayMs = 60000
  await vm.refresh()
  const pending = vm.startScan()
  assert.notEqual(vm.batchTimer, null)
  options.beforeUnmount.call(vm)
  await pending
  assert.equal(vm.batchTimer, null)
  assert.equal(vm.wakeBatch, null)
  assert.equal(calls, 1)
})

test('scan memory remains bounded while exact totals cover many unique failures', () => {
  const scan = createScan()
  for (let offset = 50; offset <= 10000; offset += 50) {
    const rows = Array.from({ length: 50 }, (_, index) => failure(offset - index, offset % 100 ? 'ERROR' : 'CRITICAL'))
    appendPage(scan, batch(rows, offset, 10001).data)
  }
  assert.equal(scan.total, 10000)
  assert.equal(scan.counts.ERROR, 5000)
  assert.equal(scan.counts.CRITICAL, 5000)
  assert.equal(scan.groups.size, SCAN_LIMITS.groups)
  assert.equal(scan.recent.length, SCAN_LIMITS.recent)
  assert.equal(scan.groupsLimited, true)
  assert.equal(scan.batches, 200)
  const firstMessage = scan.groups.keys().next().value
  const count = scan.groups.get(firstMessage).count
  appendPage(scan, batch([failure('repeat', 'ERROR', firstMessage)], 10001, 10001).data)
  assert.equal(scan.groups.get(firstMessage).count, count + 1)
  assert.equal(scan.total, 10001)
  assert.equal(scan.groups.size, SCAN_LIMITS.groups)
})

test('empty files complete with zero errors; malformed progress cannot alter a scan', () => {
  const scan = createScan()
  appendPage(scan, batch([], 0, 0).data)
  assert.equal(scan.total, 0)
  assert.equal(scan.batches, 1)
  const invalid = createScan()
  assert.throws(() => appendPage(invalid, batch([failure(1)], 2, 1).data), /inválido/)
  assert.equal(invalid.total, 0)
  assert.equal(invalid.groups.size, 0)
})

test('dashboard details focus the panel and ignore late responses after closing', async () => {
  let resolve
  let focused = false
  const { vm } = dashboard(() => new Promise(r => { resolve = r }))
  vm.$refs.dashboardDetail = { focus: () => { focused = true } }
  const pending = vm.show({ reference: 'ref', locator: 'loc' })
  await Promise.resolve()
  assert.equal(focused, true)
  vm.close()
  resolve({ data: { text: 'obsolete' } })
  await pending
  assert.deepEqual(vm.detail, {})
})

test('large counters and file sizes have compact display without changing exact aggregates', () => {
  const { vm } = dashboard(async () => batch([], 1))
  assert.match(vm.formatCount(1000000), /mi/)
  assert.equal(vm.formatBytes(512 * 1024 * 1024), '512 MiB')
  assert.equal(vm.formatBytes(0), '0 B')
  assert.equal(vm.summary.total, 0)
})
