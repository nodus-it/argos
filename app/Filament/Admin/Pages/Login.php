<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;

/**
 * Argos split-screen login. Keeps Filament's authenticate() flow (rate
 * limiting, validation, multi-factor) and only swaps the rendered markup for
 * the dark "control-room" redesign — see ARGOS_LOGIN.md. The view binds custom
 * inputs to the same `data.*` form state, so no auth logic is reimplemented.
 */
final class Login extends BaseLogin
{
    protected string $view = 'filament.auth.login';

    /**
     * "Stay signed in" defaults to on, matching the redesign spec (§5.2).
     */
    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->default(true);
    }
}
