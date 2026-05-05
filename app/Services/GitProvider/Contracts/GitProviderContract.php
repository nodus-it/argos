<?php

declare(strict_types=1);

namespace App\Services\GitProvider\Contracts;

interface GitProviderContract extends GitServiceContract
{
    public function getProviderKey(): string;

    public function label(): string;
}
