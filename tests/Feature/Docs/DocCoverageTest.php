<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Enums\AgentName;
use App\Enums\AutoPlanMode;
use App\Enums\AutoShipMode;
use App\Enums\BackingService;
use App\Filament\Workspaces\Resources\WorkspaceResource\RelationManagers\ApiTokensRelationManager;
use App\Services\Docs\DocManifest;
use Tests\TestCase;

/**
 * Keeps the docs honest for the ENUMERABLE facts: when a new enum case / API
 * ability / endpoint is added in code, the relevant English doc must mention
 * it, or the test fails. This is the reliable layer of the docs-freshness
 * strategy; prose-level accuracy is covered by the periodic drift audit.
 *
 * Enums with deliberately undocumented internal cases (WorkerSource's
 * devcontainer) are covered by the explicit user-facing subset rather than
 * iterating ::cases().
 */
class DocCoverageTest extends TestCase
{
    private function doc(string $file): string
    {
        return (string) file_get_contents(app(DocManifest::class)->absolutePath($file));
    }

    private function assertDocMentions(string $file, string $needle, string $what): void
    {
        $this->assertTrue(
            stripos($this->doc($file), $needle) !== false,
            "{$what} '{$needle}' is not documented in docs/{$file}. Add it (English source), "
            .'then re-translate + `php artisan argos:docs:stamp-translations`.',
        );
    }

    public function test_rest_api_documents_every_ability(): void
    {
        foreach (array_keys(ApiTokensRelationManager::ABILITIES) as $ability) {
            $this->assertDocMentions('REST-API.md', (string) $ability, 'API ability');
        }
    }

    public function test_rest_api_documents_the_versioned_endpoints(): void
    {
        $this->assertDocMentions('REST-API.md', '/api/v1/workspaces', 'API endpoint');
        $this->assertDocMentions('REST-API.md', '/api/v1/tasks', 'API endpoint');
    }

    public function test_projects_doc_documents_every_backing_service(): void
    {
        foreach (BackingService::cases() as $service) {
            $this->assertDocMentions('WORKSPACE-SETTINGS.md', $service->value, 'Backing service');
        }
    }

    public function test_auto_task_modes_are_documented(): void
    {
        foreach ([...AutoPlanMode::cases(), ...AutoShipMode::cases()] as $mode) {
            $this->assertDocMentions('CONFIGURATION.md', '`'.$mode->value.'`', 'Auto-task mode');
            $this->assertDocMentions('REST-API.md', '`'.$mode->value.'`', 'Auto-task mode');
        }
    }

    public function test_agents_doc_documents_every_agent(): void
    {
        foreach (AgentName::cases() as $agent) {
            $this->assertDocMentions('AGENTS.md', $agent->value, 'Agent');
        }
    }

    public function test_byoi_is_documented(): void
    {
        $this->assertDocMentions('BYOI.md', 'byoi', 'Worker source');
    }
}
