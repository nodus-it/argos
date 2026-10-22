<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Pages;

use App\Services\Docs\DocManifest;
use App\Services\Docs\DocsRenderer;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Illuminate\Support\Facades\Route;

/**
 * In-app documentation viewer (feature D.1). Renders the curated operator docs
 * (config/docs.php) to HTML via DocsRenderer at /docs/{slug?}. Reachable
 * by every authenticated user and — via the RedirectToOnboarding whitelist —
 * before onboarding, so setup docs are available from the first boot.
 */
class Documentation extends Page
{
    protected string $view = 'filament.workspaces.documentation';

    protected static ?string $slug = 'docs';

    public string $docSlug = '';

    public string $docTitle = '';

    public string $html = '';

    /** @var list<array{level: int, text: string, slug: string}> */
    public array $toc = [];

    public static function getRoutePath(Panel $panel): string
    {
        return '/docs/{slug?}';
    }

    /** `/docs/api` (and its JSON) is the generated REST reference, not a page here. */
    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        Route::where(['slug' => '(?!api(?:\.json)?$)[A-Za-z0-9._-]+'])->group(fn () => parent::routes($panel, $configuration));
    }

    public function mount(?string $slug = null): void
    {
        $manifest = app(DocManifest::class);
        $slug ??= $manifest->defaultSlug();

        $entry = $slug !== null ? $manifest->find($slug) : null;
        $renamed = $entry === null && $slug !== null ? config('docs.aliases.'.$slug) : null;
        if (is_string($renamed)) {
            $this->redirect(static::getUrl(['slug' => $renamed]));

            return;
        }
        abort_if($entry === null, 404);

        $rendered = app(DocsRenderer::class)->render($entry['file']);

        $this->docSlug = $entry['slug'];
        // The doc's own H1 is the page heading; the manifest title is the fallback.
        $this->docTitle = $rendered->title !== '' ? $rendered->title : $entry['title'];
        $this->html = $rendered->html;
        $this->toc = $rendered->toc;
    }

    /**
     * The manifest sections for the sidebar.
     *
     * @return list<array{title: string, pages: list<array{slug: string, title: string, file: string}>}>
     */
    public function sections(): array
    {
        return app(DocManifest::class)->sections();
    }

    public function getTitle(): string
    {
        return $this->docTitle !== '' ? $this->docTitle : __('navigation.pages.documentation');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-book-open';
    }


    public static function getNavigationLabel(): string
    {
        return __('navigation.pages.documentation');
    }

    public static function getNavigationSort(): ?int
    {
        // Above the external API-docs link (sort 99) within the Help group.
        return 50;
    }
}
