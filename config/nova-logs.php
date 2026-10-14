<?php

return [
    'reader' => ['roots' => [storage_path('logs')], 'node' => env('LARAVEL_LOG_DASHBOARD_NODE', 'local'), 'include_single' => true, 'read_bytes' => 1048576, 'read_seconds' => 0.25, 'scan_records' => 500, 'discovery_entries' => 1000, 'max_files' => 100],
    'log-viewer' => [
        'force_disable_routing' => true, // see https://github.com/PHPJunior/nova-logs/issues/36
    ],
];
