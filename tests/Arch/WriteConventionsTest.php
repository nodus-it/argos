<?php

declare(strict_types=1);

use App\Events\DomainEvent;
use App\Filament\Admin\Support\Pages\CreateRecord;
use App\Filament\Admin\Support\Pages\EditRecord;
use App\Services\EntityService;

/** The three presentation namespaces the purity rules below apply to. */
const PRESENTATION_NAMESPACES = ['App\Filament', 'App\Http', 'App\Livewire'];

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

// --- R3' : no direct model mutations in the presentation layer --------------
// Native arch() can't see method calls like ->save() on a model, so the
// "writes only in services" rule is a source scan. It now covers the whole
// presentation layer (Filament, Http, Livewire); the only exemption is the
// base Create/Edit pages, which legitimately delegate to $this->service().
// Persistence belongs in app/Services — add a service method, not a write here.

/**
 * Every PHP file in the presentation layer, relative to the repo root, minus
 * the base pages that route writes through a service by design. Enumerated
 * from disk (not the booted app) so the scan also runs in isolated arch runs.
 *
 * @return array<string, array{string}>
 */
function presentationSourceFiles(): array
{
    $root = dirname(__DIR__, 2);
    $exempt = [
        'app/Filament/Admin/Support/Pages/CreateRecord.php',
        'app/Filament/Admin/Support/Pages/EditRecord.php',
    ];

    $files = [];
    foreach (['app/Filament', 'app/Http', 'app/Livewire'] as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            if (in_array($relative, $exempt, true)) {
                continue;
            }

            $files[$relative] = [$relative];
        }
    }

    ksort($files);

    return $files;
}

it('keeps write calls out of the presentation layer', function (string $relativePath): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

    expect($source)
        ->not->toMatch('/->\s*(save|update|delete|forceFill|forceDelete)\s*\(/')
        ->and($source)->not->toMatch('/::\s*(create|updateOrCreate|firstOrCreate)\s*\(/');
})->with(presentationSourceFiles());

// --- R2 : no process / shell execution in the presentation layer ------------
// Spawning processes and running shell commands is worker/job territory. The
// presentation layer asks a service; it never reaches the OS itself.
arch('no process or shell execution in the presentation layer')
    ->expect(PRESENTATION_NAMESPACES)
    ->not->toUse([
        'Illuminate\Support\Facades\Process',
        'Symfony\Component\Process\Process',
        'exec',
        'shell_exec',
        'proc_open',
        'popen',
        'passthru',
        'system',
    ]);

// --- R4 : no filesystem IO in the presentation layer ------------------------
// Reading or writing domain artifacts (worker state, logs, stored files) goes
// through a service, so the presentation layer stays free of raw filesystem IO.
arch('no filesystem io in the presentation layer')
    ->expect(PRESENTATION_NAMESPACES)
    ->not->toUse([
        'Illuminate\Support\Facades\Storage',
        'Illuminate\Support\Facades\File',
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'fwrite',
        'unlink',
        'mkdir',
    ]);
