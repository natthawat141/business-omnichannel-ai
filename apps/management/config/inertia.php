<?php

return [
    // Linux is case-sensitive; this repository uses resources/js/pages, not Pages.
    'page_paths' => [resource_path('js/pages')],
    'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/pages')],
        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],
];
