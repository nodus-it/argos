<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Actions\Action;

/**
 * A reusable Filament action that links to a page in the in-app documentation
 * viewer (feature D.3). Works as a page/resource header action and as a
 * form-field `->hintAction()`. The reference is `slug` or `slug#anchor`:
 *
 *   DocsLinkAction::make('oauth')
 *   DocsLinkAction::make('setup#reverse-proxy')
 */
final class DocsLinkAction
{
    public static function make(string $ref, ?string $name = null): Action
    {
        [$slug, $anchor] = array_pad(explode('#', $ref, 2), 2, null);

        return Action::make($name ?? 'docs_'.str_replace(['-', '/'], '_', $slug))
            ->label(__('navigation.pages.documentation'))
            ->icon('heroicon-o-book-open')
            ->color('gray')
            ->link()
            ->url(DocLink::url($slug, $anchor));
    }
}
