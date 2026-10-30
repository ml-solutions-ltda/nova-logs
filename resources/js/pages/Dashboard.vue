<template>
  <div class="nova-logs nova-logs-explorer logs-dashboard" :aria-busy="initializing || loading">
    <header class="logs-header logs-dashboard-header">
      <div><h1>Dashboard de erros</h1><p>Falhas da aplicação, severidades e mensagens que precisam de investigação.</p></div>
      <a class="logs-button" :href="`${baseUrl || '/nova-logs'}/list`">Abrir lista de logs</a>
    </header>
    <form class="logs-filters" @submit.prevent="refresh">
      <label class="logs-field">
        <span>Arquivo</span>
        <select v-model="fileId" :disabled="initializing || !files.length" @change="refresh">
          <option v-if="!files.length" value="">Nenhum arquivo disponível</option>
          <option v-for="file in files" :key="file.id" :value="file.id">{{ file.name }}</option>
        </select>
      </label>
      <label class="logs-field">
        <span>Severidade</span>
        <select v-model="severity" :disabled="initializing || !fileId" @change="refresh">
          <option value="">Todas as falhas</option>
          <option v-for="item in levels" :key="item" :value="item">{{ item }}</option>
        </select>
      </label>
      <label class="logs-field logs-search">
        <span>Pesquisa</span>
        <input v-model="search" type="search" maxlength="256" placeholder="Buscar mensagem de erro…" :disabled="initializing || !fileId" />
      </label>
      <div class="logs-actions">
        <button type="submit" class="logs-button logs-button-primary" :disabled="initializing || loading || scanning || !fileId">{{ loading ? 'Consultando…' : 'Atualizar dashboard' }}</button>
        <button type="button" class="logs-button" :disabled="initializing || !fileId || (!search && !severity)" @click="clearFilters">Limpar filtros</button>
      </div>
    </form>
    <p v-if="hasPendingFilters" class="logs-notice" role="status">Pesquisa alterada. Atualize o dashboard ou pressione Enter para aplicar.</p>
    <p v-if="error" role="alert" class="logs-error">{{ error }}</p>
    <p v-if="discoveryPartial" role="status" class="logs-notice">A descoberta de arquivos é parcial. Consulte a configuração operacional para acessar outros arquivos.</p>
    <div class="logs-dashboard-scope">
      <strong>{{ scan.batches }} {{ scan.batches === 1 ? 'lote carregado' : 'lotes carregados' }} · {{ progressPercent }}% · {{ formatBytes(scan.offset) }} de {{ scan.ceiling === null ? '—' : formatBytes(scan.ceiling) }}</strong>
      <progress v-if="scan.batches" :value="progressPercent" max="100" aria-label="Progresso da leitura do arquivo"></progress>
      <p v-if="scanning" role="status">Carregando em lotes. Você pode pausar a qualquer momento.</p>
      <p v-else-if="initializing || loading" role="status">Consultando falhas…</p>
      <p v-else-if="error" role="status">Carregamento interrompido. Os indicadores preservam somente os lotes já incorporados.</p>
      <p v-else-if="pauseReason" role="status">{{ pauseReason }}</p>
      <p v-else-if="hasRead && result.next_cursor">Resultado parcial. Carregue mais lotes para ampliar os indicadores.</p>
      <p v-else-if="hasRead">Leitura do teto capturado concluída. Atualize para consultar novos registros.</p>
      <p v-if="scanBlocked" class="logs-hint">Reinicie a consulta para capturar um novo teto antes de continuar.</p>
      <p class="logs-hint">Indicadores acumulados do trecho carregado, em UTC. Novos registros gravados após o início entram somente ao reiniciar a consulta.</p>
      <div class="logs-actions">
        <button v-if="scanning" type="button" class="logs-button" @click="pauseScan('Leitura pausada. Você pode retomar do último lote carregado.')">Pausar carregamento</button>
        <button v-else-if="result.next_cursor" type="button" class="logs-button logs-button-primary" :disabled="loading || scanBlocked" @click="startScan">{{ pauseReason || error ? 'Retomar carregamento' : 'Carregar em lotes' }}</button>
      </div>
    </div>
    <p v-if="scan.groupsLimited" class="logs-notice" role="status">Mais de 250 mensagens distintas encontradas. As recorrências mostram uma amostra das mensagens acompanhadas; os totais de falhas continuam incluindo todos os lotes carregados.</p>
    <div class="logs-metrics" role="group" aria-label="Indicadores do trecho carregado">
      <article class="logs-metric"><span>Falhas carregadas</span><strong :title="metricsReady ? String(summary.total) : undefined" :aria-label="metricsReady ? String(summary.total) : 'Indisponível'">{{ metricsReady ? formatCount(summary.total) : '—' }}</strong><small>ERROR, CRITICAL, ALERT e EMERGENCY</small></article>
      <article class="logs-metric logs-metric-critical"><span>Casos críticos</span><strong :title="metricsReady ? String(summary.critical) : undefined" :aria-label="metricsReady ? String(summary.critical) : 'Indisponível'">{{ metricsReady ? formatCount(summary.critical) : '—' }}</strong><small>CRITICAL, ALERT e EMERGENCY</small></article>
      <article class="logs-metric"><span>Mensagens distintas</span><strong>{{ metricsReady ? (scan.groupsLimited ? messageGroups.length + '+' : messageGroups.length) : '—' }}</strong><small>Agrupadas pela mesma prévia de mensagem</small></article>
      <article class="logs-metric"><span>Última ocorrência carregada</span><strong class="logs-metric-time">{{ metricsReady ? summary.latest?.slice(11) || 'Sem data' : '—' }}</strong><small>{{ metricsReady && summary.latest ? summary.latest.slice(0, 10) + ' · UTC' : 'Somente registros com data válida' }}</small></article>
    </div>
    <div class="logs-dashboard-panels">
      <section class="logs-dashboard-panel" aria-labelledby="logs-severity-title">
        <h2 id="logs-severity-title">Distribuição por severidade</h2>
        <p class="logs-hint">Falhas acumuladas nos lotes carregados. Clique em um nível para reiniciar com esse filtro.</p>
        <div class="logs-severity-chart">
          <button v-for="item in distribution" :key="item.level" type="button" class="logs-severity-row" :aria-label="`${item.level}: ${metricsReady ? item.count : 'indisponível'} ocorrências`" :aria-pressed="severity === item.level" :disabled="initializing || loading || !fileId" @click="filterSeverity(item.level)">
            <span class="logs-severity-label"><span class="logs-level" :data-level="item.level">{{ item.level }}</span><strong>{{ metricsReady ? formatCount(item.count) : '—' }} <small v-if="metricsReady">· {{ item.percent }}%</small></strong></span>
            <span class="logs-severity-track" aria-hidden="true"><span :data-level="item.level" :style="{ width: (metricsReady ? item.percent : 0) + '%' }"></span></span>
          </button>
        </div>
      </section>
      <section class="logs-dashboard-panel" aria-labelledby="logs-recurring-title">
        <h2 id="logs-recurring-title">Mensagens recorrentes</h2>
        <p class="logs-hint">Até cinco prévias mais frequentes entre as mensagens acompanhadas.</p>
        <p v-if="!metricsReady" class="logs-panel-empty">{{ loading || initializing ? 'Consultando mensagens…' : 'Aguardando uma consulta válida.' }}</p>
        <p v-else-if="!messageGroups.length" class="logs-panel-empty">{{ result.next_cursor ? 'Nenhuma falha no trecho carregado. Continue em lotes.' : 'Nenhuma falha encontrada para os filtros aplicados.' }}</p>
        <ol v-else class="logs-recurring-list">
          <li v-for="group in messageGroups.slice(0, 5)" :key="group.message"><button type="button" class="logs-recurring-item" @click="show(group.entry)"><span class="logs-recurring-count" :title="String(group.count)">{{ formatCount(group.count) }}×</span><span class="logs-message">{{ group.message || 'Mensagem sem texto' }}</span><span class="logs-recurring-open" aria-hidden="true">↗</span></button></li>
        </ol>
      </section>
    </div>
    <section class="logs-dashboard-panel logs-occurrences" aria-labelledby="logs-occurrences-title">
      <div class="logs-detail-header"><h2 id="logs-occurrences-title">Ocorrências para investigar</h2><span class="logs-hint">Até seis falhas do trecho carregado, com as mais recentes primeiro</span></div>
      <p v-if="!metricsReady" class="logs-panel-empty">{{ loading || initializing ? 'Consultando ocorrências…' : error ? 'Consulta indisponível. Atualize o dashboard.' : 'Nenhum arquivo de log disponível.' }}</p>
      <p v-else-if="!errorRows.length" class="logs-panel-empty">{{ result.next_cursor ? 'Nenhuma falha neste trecho. Use Carregar em lotes para continuar.' : 'Nenhuma falha encontrada para os filtros aplicados.' }}</p>
      <div v-else class="logs-occurrence-list">
        <article v-for="entry in occurrences" :key="entry.reference" class="logs-occurrence">
          <div class="logs-occurrence-content"><div class="logs-occurrence-meta"><span class="logs-level" :data-level="entry.level">{{ entry.level }}</span><time>{{ entry.occurred_at || 'Sem data' }}</time></div><p class="logs-message">{{ entry.message }}</p></div>
          <button type="button" class="logs-button" @click="show(entry)">Detalhes</button>
        </article>
      </div>
    </section>
    <nav class="logs-pagination" aria-label="Consulta progressiva do dashboard">
      <span>{{ scan.batches }} {{ scan.batches === 1 ? 'lote incorporado' : 'lotes incorporados' }} · {{ progressPercent }}% do teto capturado</span>
      <button type="button" class="logs-button" :disabled="initializing || loading || scanning || !fileId" @click="refresh">Reiniciar consulta</button>
    </nav>
    <section v-if="selected" ref="dashboardDetail" class="logs-detail" tabindex="-1" aria-label="Detalhes da falha" :aria-busy="detailLoading">
      <div class="logs-detail-header"><h2>Detalhes da falha</h2><button type="button" class="logs-button" @click="close">Fechar detalhes</button></div>
      <p class="logs-hint">Prévia limitada do registro selecionado.</p>
      <p v-if="detailLoading" role="status">Carregando trecho…</p>
      <p v-if="detailError" class="logs-error" role="alert">{{ detailError }}</p>
      <pre v-if="detail.text">{{ detail.text }}</pre>
      <button v-if="detail.next_offset != null" type="button" class="logs-button" :disabled="detailLoading" @click="show(selected, detail.next_offset)">Próximo trecho</button>
    </section>
  </div>
</template>
<script>
import FileExplorer from './FileExplorer.vue'
import { createScan, appendPage, SCAN_LIMITS } from '../utils/dashboardScan.mjs'

export default {
  extends: FileExplorer,
  data() {
    return {
      levels: ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], severity: '', scan: createScan(),
      scanning: false, scanRun: 0, scanBlocked: false, pauseReason: '', batchTimer: null, wakeBatch: null,
      batchLimits: { ...SCAN_LIMITS },
    }
  },
  beforeUnmount() { this.pauseScan() },
  computed: {
    metricsReady() { return this.scan.batches > 0 },
    errorRows() { return this.scan.recent },
    summary() { return { total: this.scan.total, critical: this.scan.counts.CRITICAL + this.scan.counts.ALERT + this.scan.counts.EMERGENCY, latest: this.scan.latest } },
    distribution() {
      return this.levels.map(level => ({ level, count: this.scan.counts[level], percent: this.scan.total ? Math.round(this.scan.counts[level] / this.scan.total * 100) : 0 }))
    },
    messageGroups() { return [...this.scan.groups.values()].sort((a, b) => b.count - a.count || a.message.localeCompare(b.message)) },
    occurrences() { return this.scan.recent },
    progressPercent() { return this.scan.ceiling ? Math.min(100, Math.floor(this.scan.offset / this.scan.ceiling * 100)) : this.scan.batches ? 100 : 0 },
  },
  methods: {
    queryFilters() { return { ...FileExplorer.methods.queryFilters.call(this), failures: 1 } },
    acceptPage(data) {
      try { appendPage(this.scan, data); return true }
      catch (error) { this.error = error.message; this.errorStatus = error.code === 'LOG_SCAN_STALLED' ? null : 409; this.scanBlocked = this.errorStatus === 409; return false }
    },
    async refresh() {
      this.pauseScan()
      this.scan = createScan()
      this.scanBlocked = false
      this.pauseReason = ''
      return FileExplorer.methods.refresh.call(this)
    },
    pauseScan(reason = '') {
      this.scanRun++
      this.scanning = false
      this.pauseReason = reason
      this.version++
      this.controller?.abort()
      this.loading = false
      clearTimeout(this.batchTimer)
      this.batchTimer = null
      const wake = this.wakeBatch
      this.wakeBatch = null
      wake?.()
    },
    waitBetweenBatches() {
      return new Promise(resolve => {
        this.wakeBatch = resolve
        this.batchTimer = setTimeout(() => { this.batchTimer = null; this.wakeBatch = null; resolve() }, this.batchLimits.delayMs)
      })
    },
    async startScan() {
      if (this.scanning || this.loading || this.initializing || this.scanBlocked || !this.result.next_cursor) return
      const run = ++this.scanRun
      const started = Date.now()
      let requests = 0
      this.scanning = true
      this.error = ''
      this.pauseReason = ''
      const active = () => this.scanning && run === this.scanRun
      try {
        while (active() && this.result.next_cursor) {
          if (requests >= this.batchLimits.requests || Date.now() - started >= this.batchLimits.durationMs) {
            this.pauseReason = 'Pausa automática no carregamento. Retome para consultar mais lotes.'
            break
          }
          await this.waitBetweenBatches()
          if (!active()) break
          if (Date.now() - started >= this.batchLimits.durationMs) {
            this.pauseReason = 'Pausa automática no carregamento. Retome para consultar mais lotes.'
            break
          }
          const ok = await this.load(this.result.next_cursor)
          requests++
          if (!active()) break
          if (!ok) {
            this.scanBlocked = this.scanBlocked || [401, 403, 409, 422].includes(this.errorStatus)
            this.pauseReason = this.errorStatus === 429 ? 'Limite temporário de requisições. Aguarde e retome o carregamento.' : 'Leitura interrompida. Retome ou reinicie a consulta.'
            break
          }
        }
      } finally {
        if (run === this.scanRun) this.scanning = false
      }
    },
    formatBytes(value) {
      if (!value) return '0 B'
      const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
      const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)))
      return `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(value / 1024 ** index)} ${units[index]}`
    },
    formatCount(value) { return new Intl.NumberFormat('pt-BR', { notation: value >= 100000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(value) },
    async filterSeverity(level) {
      this.severity = this.severity === level ? '' : level
      return this.refresh()
    },
    async show(entry, offset = 0) {
      const pending = FileExplorer.methods.show.call(this, entry, offset)
      await this.$nextTick()
      this.$refs.dashboardDetail?.focus()
      return pending
    },
  },
}
</script>
