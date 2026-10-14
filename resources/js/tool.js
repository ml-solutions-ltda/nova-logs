import FileExplorer from './pages/FileExplorer.vue';
Nova.booting(app => { for (const page of ['NovaLogs','LogsTool','ShowLogs']) Nova.inertia(page, FileExplorer); });
