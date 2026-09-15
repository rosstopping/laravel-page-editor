<?php

return [
    'excluded_paths' => ['admin', 'admin/*', 'api/*', '_editor/*', 'nova-api/*', 'livewire/*'],
    'guard' => env('PAGE_EDITOR_GUARD'),
    'allowed_emails' => [],
    'images' => ['disk' => 'public', 'directory' => 'cms-images', 'max_kb' => 10240],
    'path' => storage_path('app/page-content'),
];
