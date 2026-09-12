<?php

return [
    'enabled' => (bool) env('REMOTE_MCP_ENABLED', false),
    'redirect_origins' => array_values(array_filter(array_map('trim', explode(',',
        env('REMOTE_MCP_REDIRECT_ORIGINS', 'http://localhost,http://127.0.0.1,https://chatgpt.com,https://claude.ai')
    )))),
];
