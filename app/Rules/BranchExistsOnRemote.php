<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\ConnectedAccount;
use App\Services\Git\RemoteBranchValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BranchExistsOnRemote implements ValidationRule
{
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $platform,
        private readonly ?string $token,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || $this->url === null || $this->url === '') {
            return;
        }

        $token = $this->resolveToken();
        $result = app(RemoteBranchValidator::class)->validate($this->url, $value, $token);

        if (! $result['ok']) {
            $fail($result['error'] ?? 'Branch konnte nicht verifiziert werden.');
        }
    }

    private function resolveToken(): ?string
    {
        if ($this->token !== null && $this->token !== '') {
            return $this->token;
        }

        if ($this->platform === 'github') {
            return ConnectedAccount::where('provider', 'github')->first()?->token;
        }

        return null;
    }
}
