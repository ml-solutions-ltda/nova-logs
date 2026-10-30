<template>
  <div class="nova-logs nova-logs-explorer" :aria-busy="initializing || loading">
    <header class="logs-header">
      <h1>Logs do Laravel</h1>
      <p>Consulte os registros por arquivo, severidade ou texto da mensagem.</p>
    </header>
    <form class="logs-filters" @submit.prevent="refresh">
      <label class="logs-field logs-file">
        <span>Arquivo</span>
        <select v-model="fileId" :disabled="initializing || !files.length" @change="refresh">
          <option v-if="!files.length" value="">Nenhum arquivo disponível</option>
          <option v-for="file in files" :key="file.id" :value="file.id">{{ file.name }}</option>
        </select>
      </label>
      <label class="logs-field">
        <span>Severidade</span>
        <select v-model="severity" :disabled="initializing || !fileId" @change="refresh">
          <option value="">Todas</option>
          <option v-for="item in levels" :key="item" :value="item">{{ item }}</option>
        </select>
      </label>
      <label class="logs-field logs-search">
        <span>Pesquisa</span>
        <input v-model="search" type="search" maxlength="256" placeholder="Buscar texto nos registros…" :disabled="initializing || !fileId" />
      </label>
      <div class="logs-actions">
        <button type="submit" class="logs-button logs-button-primary" :disabled="initializing || loading || !fileId">{{ loading ? 'Consultando…' : 'Aplicar filtros' }}</button>
        <button type="button" class="logs-button" :disabled="initializing || !fileId || (!search && !severity)" @click="clearFilters">Limpar</button>
        <a v-if="fileId" class="logs-button" :href="`${base}/files/${fileId}/download`">Baixar original</a>
      </div>
    </form>
    <p v-if="hasPendingFilters" class="logs-notice" role="status">Pesquisa alterada. Use Aplicar filtros ou pressione Enter para atualizar os resultados.</p>
    <p v-if="error" role="alert" class="logs-error">{{ error }}</p>
    <p v-if="discoveryPartial" role="status" class="logs-notice">A lista de arquivos é parcial. Consulte a configuração operacional para acessar outros arquivos.</p>
    <div class="logs-results" aria-live="polite" aria-atomic="true">
      <p v-if="initializing || loading" role="status">Consultando registros…</p>
      <template v-else-if="hasRead">
        <p><strong>{{ rows.length }} {{ rows.length === 1 ? 'registro' : 'registros' }} nesta janela</strong> · {{ result.bytes_read || 0 }} bytes examinados</p>
        <p v-if="result.next_cursor">Leitura parcial. Use {{ rows.length ? 'Próxima' : 'Continuar pesquisa' }} para examinar o próximo trecho do arquivo.</p>
        <p v-else>Fim do trecho capturado. Aplique os filtros novamente para incluir novos registros.</p>
      </template>
      <p class="logs-hint">Leitura em ordem física crescente, por janelas limitadas. Os resultados não representam todo o histórico do arquivo.</p>
    </div>
    <div class="logs-table-scroll" role="region" aria-label="Registros de log" tabindex="0">
      <table class="logs-table">
        <colgroup><col class="logs-date-column" /><col class="logs-level-column" /><col /><col class="logs-action-column" /></colgroup>
        <thead><tr><th scope="col">Data (UTC)</th><th scope="col">Nível</th><th scope="col">Mensagem</th><th scope="col"><span class="logs-sr-only">Ações</span></th></tr></thead>
        <tbody>
          <tr v-for="entry in rows" :key="entry.reference">
            <td class="logs-date">{{ entry.occurred_at || 'Sem data' }}</td>
            <td><span class="logs-level" :data-level="entry.level">{{ entry.level }}</span></td>
            <td><div class="logs-message">{{ entry.message }}</div></td>
            <td><button type="button" class="logs-button" :disabled="loading" @click="show(entry)">Detalhes</button></td>
          </tr>
          <tr v-if="!rows.length"><td colspan="4" class="logs-empty">
            <template v-if="initializing || loading">Carregando registros…</template>
            <template v-else-if="error">Não foi possível concluir a consulta. Aplique os filtros para tentar novamente.</template>
            <template v-else-if="!files.length">Nenhum arquivo de log disponível.</template>
            <template v-else-if="result.next_cursor">Nenhum registro encontrado nesta janela. Continue a pesquisa no próximo trecho.</template>
            <template v-else>Nenhum registro encontrado para os filtros aplicados.</template>
          </td></tr>
        </tbody>
      </table>
    </div>
    <nav class="logs-pagination" aria-label="Paginação dos logs">
      <span>Página {{ cursors.length }}</span>
      <div class="logs-actions">
        <button type="button" class="logs-button" :disabled="loading || cursors.length <= 1" @click="previous">Anterior</button>
        <button type="button" class="logs-button" :disabled="loading || !result.next_cursor" @click="next">{{ !rows.length && result.next_cursor ? 'Continuar pesquisa' : 'Próxima' }}</button>
      </div>
    </nav>
    <section v-if="selected" class="logs-detail" aria-label="Detalhes do registro" :aria-busy="detailLoading">
      <div class="logs-detail-header"><h2>Detalhes do registro</h2><button type="button" class="logs-button" @click="close">Fechar detalhes</button></div>
      <p class="logs-hint">Prévia limitada; o último registro do arquivo ainda pode receber novas linhas.</p>
      <p v-if="detailLoading" role="status">Carregando trecho…</p>
      <p v-if="detailError" class="logs-error" role="alert">{{ detailError }}</p>
      <pre v-if="detail.text">{{ detail.text }}</pre>
      <button v-if="detail.next_offset != null" type="button" class="logs-button" :disabled="detailLoading" @click="show(selected, detail.next_offset)">Próximo trecho</button>
    </section>
  </div>
</template>
<script>
export default {
  props: ['date', 'level', 'baseUrl'],
  data() {
    const levels = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY', 'WARNING', 'INFO', 'DEBUG', 'NOTICE']
    const initialLevel = typeof this.level === 'string' ? this.level.toUpperCase() : ''
    return {
      base: '/nova-vendor/mlsolutions/nova-log-viewer',
      files: [], fileId: '', search: '', severity: levels.includes(initialLevel) ? initialLevel : '', levels,
      rows: [], result: {}, cursors: [null], snapshot: null, appliedFilters: null,
      initializing: true, loading: false, hasRead: false, error: '', errorStatus: null, discoveryPartial: false,
      selected: null, detail: {}, detailLoading: false, detailError: '',
      version: 0, controller: null, detailVersion: 0, detailController: null,
    }
  },
  computed: {
    hasPendingFilters() {
      return this.appliedFilters && this.search !== this.appliedFilters.search
    },
  },
  async mounted() {
    const version = ++this.version
    this.controller = new AbortController()
    try {
      const { data } = await Nova.request().get(`${this.base}/files`, { timeout: 20000, signal: this.controller.signal })
      if (version !== this.version) return
      this.files = data.data
      this.discoveryPartial = data.partial
      this.fileId = this.files.find(file => file.name === `laravel-${this.date}.log`)?.id || this.files[0]?.id || ''
      this.initializing = false
      if (this.fileId) await this.refresh()
    } catch (error) {
      if (version === this.version && error.code !== 'ERR_CANCELED') this.error = error.response?.data?.message || 'Não foi possível listar arquivos. Recarregue a página para tentar novamente.'
    } finally {
      if (version === this.version) this.initializing = false
    }
  },
  beforeUnmount() {
    this.version++
    this.controller?.abort()
    this.close()
  },
  methods: {
    acceptPage() { return true },
    queryFilters() {
      return { file_id: this.fileId || undefined, search: this.search, level: this.severity }
    },
    async refresh() {
      this.close()
      this.snapshot = null
      this.cursors = [null]
      this.rows = []
      this.result = {}
      this.hasRead = false
      this.appliedFilters = this.queryFilters()
      if (!this.fileId) return false
      return this.load(null)
    },
    async clearFilters() {
      this.search = ''
      this.severity = ''
      return this.refresh()
    },
    async load(cursor) {
      const version = ++this.version
      this.controller?.abort()
      this.controller = new AbortController()
      this.loading = true
      this.error = ''
      this.errorStatus = null
      const filters = this.appliedFilters || this.queryFilters()
      try {
        // Cursors are bound to the submitted filters, never to an unsubmitted draft.
        const { data } = await Nova.request().get(`${this.base}/entries`, {
          params: { ...filters, snapshot: this.snapshot, cursor },
          signal: this.controller.signal, timeout: 20000,
        })
        if (version !== this.version) return false
        if (this.acceptPage(data) === false) return false
        this.rows = data.data
        this.result = data
        this.snapshot = data.snapshot
        this.hasRead = true
        return true
      } catch (error) {
        if (version === this.version && error.code !== 'ERR_CANCELED') {
          this.error = error.response?.data?.message || 'Falha na leitura. Atualize a consulta para tentar novamente.'
          this.errorStatus = error.response?.status || null
        }
        return false
      } finally {
        if (version === this.version) this.loading = false
      }
    },
    async next() {
      if (this.loading || !this.result.next_cursor) return
      this.close()
      const version = this.version + 1
      const cursor = this.result.next_cursor
      if (await this.load(cursor) && version === this.version) this.cursors.push(cursor)
    },
    async previous() {
      if (this.loading || this.cursors.length <= 1) return
      this.close()
      const version = this.version + 1
      if (await this.load(this.cursors.at(-2)) && version === this.version) this.cursors.pop()
    },
    close() {
      this.detailVersion++
      this.detailController?.abort()
      this.detailLoading = false
      this.selected = null
      this.detail = {}
      this.detailError = ''
    },
    async show(entry, offset = 0) {
      const version = ++this.detailVersion
      this.detailController?.abort()
      this.detailController = new AbortController()
      if (this.selected?.reference !== entry.reference || offset === 0) this.detail = {}
      this.selected = entry
      this.detailLoading = true
      this.detailError = ''
      try {
        const { data } = await Nova.request().get(`${this.base}/entries/${entry.reference}`, {
          params: { locator: entry.locator, offset }, signal: this.detailController.signal, timeout: 20000,
        })
        if (version === this.detailVersion) this.detail = data
      } catch (error) {
        if (version === this.detailVersion && error.code !== 'ERR_CANCELED') this.detailError = error.response?.data?.message || 'Registro indisponível. Feche os detalhes e tente novamente.'
      } finally {
        if (version === this.detailVersion) this.detailLoading = false
      }
    },
  },
}
</script>
