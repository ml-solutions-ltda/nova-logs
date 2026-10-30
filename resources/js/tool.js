import FileExplorer from './pages/FileExplorer.vue';
import Dashboard from './pages/Dashboard.vue';

Nova.booting(() => {
    Nova.inertia('NovaLogs', Dashboard);
    Nova.inertia('LogsTool', FileExplorer);
    Nova.inertia('ShowLogs', FileExplorer);
});
