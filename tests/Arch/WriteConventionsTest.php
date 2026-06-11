<?php

declare(strict_types=1);

use App\Events\DomainEvent;
use App\Filament\Admin\Support\Pages\CreateRecord;
use App\Filament\Admin\Support\Pages\EditRecord;
use App\Services\EntityService;

// --- Base-class anchors -----------------------------------------------------
// The base classes turn conventions into structural rules: a domain event that
// forgets to extend the base, an entity service that bypasses it, or a resource
// page that does not route through a service, fails here. (Spike scope: the
// existing app/Events/Task/* events are migrated to DomainEvent later, then the
// event anchor widens to all of App\Events.)

arch('credential domain events extend the domain event base')
    ->expect('App\Events\Credentials')
    ->toExtend(DomainEvent::class);

arch('migrated entity services extend the base entity service')
    ->expect([
        'App\Services\OAuth\ProviderOAuthConfigService',
        'App\Services\Credentials\ProviderCredentialService',
    ])
    ->toExtend(EntityService::class);

arch('migrated oauth-config create page routes writes through the base page')
    ->expect('App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages\CreateProviderOAuthConfig')
    ->toExtend(CreateRecord::class);

arch('migrated oauth-config edit page routes writes through the base page')
    ->expect('App\Filament\Admin\Resources\ProviderOAuthConfigResource\Pages\EditProviderOAuthConfig')
    ->toExtend(EditRecord::class);

// --- R3' (scoped) : no direct model mutations in the presentation layer -----
// Native arch() can't see method calls like ->save() on a model, so the
// "writes only in services" rule is a source scan. Scoped here to the spike
// surface; later this widens to all of app/ outside app/Services.

it('keeps write calls out of the migrated presentation files', function (string $relativePath): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

    expect($source)
        ->not->toMatch('/->\s*(save|update|delete|forceFill|forceDelete)\s*\(/')
        ->and($source)->not->toMatch('/::\s*(create|updateOrCreate|firstOrCreate)\s*\(/');
})->with([
    'app/Filament/Admin/Resources/ProviderOAuthConfigResource.php',
    'app/Filament/Admin/Resources/ProviderCredentialResource.php',
    'app/Filament/Admin/Resources/ProviderOAuthConfigResource/Pages/CreateProviderOAuthConfig.php',
    'app/Filament/Admin/Resources/ProviderOAuthConfigResource/Pages/EditProviderOAuthConfig.php',
    'app/Filament/Admin/Resources/ApiClientResource.php',
    'app/Filament/Admin/Resources/ApiClientResource/Pages/CreateApiClient.php',
    'app/Filament/Admin/Resources/ApiClientResource/Pages/EditApiClient.php',
    'app/Filament/Admin/Resources/AgentCredentialResource.php',
    'app/Filament/Admin/Resources/AgentCredentialResource/Pages/CreateAgentCredential.php',
    'app/Filament/Admin/Resources/AgentCredentialResource/Pages/EditAgentCredential.php',
    'app/Filament/Admin/Resources/ProviderCredentialResource/Pages/CreateProviderCredential.php',
    'app/Filament/Admin/Resources/ProviderCredentialResource/Pages/EditProviderCredential.php',
    'app/Filament/Admin/Resources/RepoProfileResource/Pages/CreateRepoProfile.php',
    'app/Filament/Admin/Resources/RepoProfileResource/Pages/EditRepoProfile.php',
    'app/Filament/Admin/Resources/WorkerStackResource.php',
    'app/Filament/Admin/Resources/WorkerStackResource/Pages/CreateWorkerStack.php',
    'app/Filament/Admin/Resources/WorkerStackResource/Pages/EditWorkerStack.php',
    'app/Filament/Admin/Resources/RepoProfileResource/RelationManagers/TaskProviderBindingsRelationManager.php',
]);
