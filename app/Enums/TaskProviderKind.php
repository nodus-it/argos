<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\ProvidesOptions;
enum TaskProviderKind: string
{
    use ProvidesOptions;

    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Linear = 'linear';
    /** An own system: tasks come in over the REST API, status goes out via a signed webhook. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Linear => 'Linear',
            self::Custom => __('task_providers.custom.label'),
        };
    }

    /** Status reaches this system through Argos' webhook, not through a tracker API. */
    public function isCustom(): bool
    {
        return $this === self::Custom;
    }

    /** Returns the ConnectedAccount provider key for OAuth lookup. */
    public function providerKey(): ?string
    {
        return match ($this) {
            self::GitHub => 'github',
            self::GitLab => 'gitlab',
            self::Linear => 'linear',
            self::Custom => null,
        };
    }
}
