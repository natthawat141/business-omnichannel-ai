<?php

namespace App\Support;

final class AgentSetupExamples
{
    public static function antigravity(string $baseUrl): string
    {
        return json_encode(['mcpServers' => ['document-intake' => [
            'command' => '/ABSOLUTE/PATH/TO/node',
            'args' => ['/ABSOLUTE/PATH/TO/management-agent/node_modules/@local/document-intake-agent/bin/document-intake-mcp.js'],
            'env' => [
                'MANAGEMENT_API_BASE_URL' => $baseUrl,
                'MANAGEMENT_API_TOKEN' => 'PASTE_KEY_LOCALLY',
            ],
        ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
