<template>
  <div class="nova-logs card p-6">
    <h1 class="text-2xl mb-4">Logs do Laravel</h1>
    <p class="mb-4">Leitura direta por arquivo, em ordem física crescente. A pesquisa continua em janelas limitadas; resultados e contagens não representam todo o acervo.</p>
    <p v-if="error" role="alert" class="text-red-600 mb-4">{{ error }}</p>
    <form @submit.prevent="refresh" class="flex flex-wrap gap-3 mb-4">
      <label>Arquivo <select v-model="fileId" class="form-control form-select" @change="refresh"><option v-for="file in files" :key="file.id" :value="file.id">{{ file.name }}</option></select></label>
      <label>Severidade <select v-model="level" class="form-control form-select"><option value="">Todas</option><option v-for="item in levels" :key="item">{{ item }}</option></select></label>
      <label>Pesquisa <input v-model="search" maxlength="256" class="form-control" /></label>
      <button class="btn btn-default" :disabled="loading">Atualizar / filtrar</button>
      <a v-if="fileId" class="btn btn-default" :href="`${base}/files/${fileId}/download`">Baixar original</a>
    </form>
    <p role="status" class="mb-4">{{ rows.length }} registros nesta janela · {{ result.bytes_read || 0 }} bytes examinados. {{ result.next_cursor ? 'Pesquisa parcial: Próxima continua inclusive quando a página estiver vazia.' : 'Fim do teto capturado. Atualize para incluir novos registros.' }}</p>
    <p v-if="discoveryPartial" role="status">Descoberta parcial de arquivos. Refine as raízes de logs na configuração operacional.</p>
    <table class="w-full"><thead><tr><th>Data (UTC)</th><th>Nível</th><th>Mensagem</th><th></th></tr></thead><tbody><tr v-for="entry in rows" :key="entry.reference"><td>{{ entry.occurred_at || 'Sem data' }}</td><td>{{ entry.level }}</td><td>{{ entry.message }}</td><td><button class="btn" @click="show(entry)">Detalhes</button></td></tr></tbody></table>
    <div class="flex gap-4 mt-4"><button class="btn btn-default" :disabled="loading || cursors.length <= 1" @click="previous">Anterior</button><span>Página {{ cursors.length }}</span><button class="btn btn-default" :disabled="loading || !result.next_cursor" @click="next">Próxima</button></div>
    <section v-if="selected" class="mt-6" aria-label="Detalhes do registro"><button class="btn" @click="close">Voltar à lista</button><p>Prévia limitada; o registro no teto pode receber novas linhas.</p><pre class="whitespace-pre-wrap">{{ detail.text }}</pre><button v-if="detail.next_offset != null" class="btn" @click="show(selected, detail.next_offset)">Próximo trecho</button></section>
  </div>
</template>
<script>
export default {
  props: ['date'],
  data() { return { base: '/nova-vendor/mlsolutions/nova-log-viewer', files: [], fileId: '', search: '', level: '', levels: ['ERROR','CRITICAL','ALERT','EMERGENCY','WARNING','INFO','DEBUG','NOTICE'], rows: [], result: {}, cursors: [null], snapshot: null, loading: false, error: '', selected: null, detail: {}, discoveryPartial: false, version: 0, controller: null } },
  async mounted() {
    const version = ++this.version; this.controller = new AbortController();
    try { const { data } = await Nova.request().get(`${this.base}/files`, { timeout: 20000, signal: this.controller.signal }); if (version !== this.version) return; this.files = data.data; this.discoveryPartial = data.partial; this.fileId = this.files.find(file => file.name === `laravel-${this.date}.log`)?.id || this.files[0]?.id || ''; await this.refresh() }
    catch (error) { this.error = error.response?.data?.message || 'Não foi possível listar arquivos.' }
  },
  beforeUnmount() { this.version++; this.controller?.abort() },
  methods: {
    async refresh() { this.snapshot = null; this.cursors = [null]; this.selected = null; await this.load(null) },
    async load(cursor) {
      const version = ++this.version; this.controller?.abort(); this.controller = new AbortController(); this.loading = true; this.error = '';
      try {
        const { data } = await Nova.request().get(`${this.base}/entries`, { params: { file_id: this.fileId || undefined, search: this.search, level: this.level, snapshot: this.snapshot, cursor }, signal: this.controller.signal, timeout: 20000 });
        if (version !== this.version) return false;
        this.rows = data.data; this.result = data; this.snapshot = data.snapshot; return true;
      } catch (error) { if (version === this.version && error.code !== 'ERR_CANCELED') this.error = error.response?.data?.message || 'Falha na leitura. Atualize a consulta.'; return false }
      finally { if (version === this.version) this.loading = false }
    },
    async next() { const cursor = this.result.next_cursor; if (await this.load(cursor)) this.cursors.push(cursor) },
    async previous() { if (await this.load(this.cursors.at(-2))) this.cursors.pop() },
    close() { this.version++; this.controller?.abort(); this.loading = false; this.selected = null },
    async show(entry, offset = 0) {
      const version = ++this.version; this.controller?.abort(); this.controller = new AbortController(); this.selected = entry; this.error = '';
      try { const { data } = await Nova.request().get(`${this.base}/entries/${entry.reference}`, { params: { locator: entry.locator, offset }, signal: this.controller.signal, timeout: 20000 }); if (version === this.version) this.detail = data }
      catch (error) { if (version === this.version && error.code !== 'ERR_CANCELED') this.error = error.response?.data?.message || 'Registro indisponível.' }
    }
  }
}
</script>
