<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerSource: string
{
    case Standard = 'standard';
    case Byoi = 'byoi';
    case Devcontainer = 'devcontainer';

    public function label(): string
    {
        return match ($this) {
            self::Standard => __('enums.worker_source.standard'),
            self::Byoi => __('enums.worker_source.byoi'),
            self::Devcontainer => __('enums.worker_source.devcontainer'),
        };
    }
}
