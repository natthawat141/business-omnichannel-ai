<?php

namespace Tests\Feature;

use Tests\TestCase;

class AiSkillDocumentationTest extends TestCase
{
    public function test_public_skill_describes_only_current_capabilities(): void
    {
        $skill = file_get_contents(public_path('skills/document-intake/SKILL.md'));
        $this->assertStringStartsWith("---\nname: document-intake\n", $skill);
        foreach (['document_list', 'document_get', 'documents:read', 'no remote MCP endpoint', 'Do not ask for secrets in chat', 'cannot read/download PDF contents'] as $contract) {
            $this->assertStringContainsString($contract, $skill);
        }
        $this->assertDoesNotMatchRegularExpression('/lk_[A-Za-z0-9]{8}\.[A-Za-z0-9]{48}/', $skill);
        $this->assertStringContainsString('/skills/document-intake/SKILL.md', file_get_contents(public_path('llms.txt')));
    }

    public function test_public_proposal_skill_requires_preview_and_human_apply_gate(): void
    {
        $skill = file_get_contents(public_path('skills/management-proposals/SKILL.md'));
        foreach (['agent_schema', 'agent_changes_preview', 'agent_changes_submit', 'human administrator', 'cannot approve, apply, publish', 'Do not invent missing values'] as $contract) {
            $this->assertStringContainsString($contract, $skill);
        }
        $this->assertDoesNotMatchRegularExpression('/lk_[A-Za-z0-9]{8}\.[A-Za-z0-9]{48}/', $skill);
        $this->assertStringContainsString('/skills/management-proposals/SKILL.md', file_get_contents(public_path('llms.txt')));
    }

    public function test_share_prompt_contains_public_url_not_issued_key(): void
    {
        $page = file_get_contents(resource_path('js/pages/AiSetup.tsx'));
        $this->assertSame(1, preg_match('/const skillPrompt = `([^`]+)`;/u', $page, $matches));
        $this->assertStringContainsString('${baseUrl}${skillPath}', $matches[1]);
        $this->assertStringNotContainsString('issued', $matches[1]);
        $this->assertStringNotContainsString('.key', $matches[1]);
        foreach (['${installCommand}', 'environment}', '${command}', '/docs/agent-setup.md'] as $step) {
            $this->assertStringContainsString($step, $matches[1]);
        }
        $this->assertMatchesRegularExpression('/useState<"agent" \\| "manual">\\("agent"\\)/', $page);
        $this->assertStringContainsString('setupMode ===', $page);
        $guide = file_get_contents(public_path('docs/agent-setup.md'));
        $this->assertStringContainsString('/downloads/document-intake-agent-0.2.0.tgz', $guide);
        $this->assertStringContainsString('The operator, not the agent', $guide);
    }

    public function test_setup_uses_reusable_accessible_developer_docs_components(): void
    {
        $page = file_get_contents(resource_path('js/pages/AiSetup.tsx'));
        $terminal = file_get_contents(resource_path('js/components/TerminalBlock.tsx'));

        foreach (['@base-ui/react/tabs', 'TerminalBlock', 'Copy page', 'pageCopy', 'ai-on-this-page'] as $contract) {
            $this->assertStringContainsString($contract, $page);
        }

        foreach (['@base-ui/react/tooltip', 'Tooltip.Root', 'aria-label={`คัดลอก ${label}`}', 'คัดลอกโดยไม่รวม key'] as $contract) {
            $this->assertStringContainsString($contract, $terminal);
        }

        $this->assertStringContainsString('This page never includes an issued key.', $page);
        $this->assertStringNotContainsString('visibleKey', $terminal);
    }
}
