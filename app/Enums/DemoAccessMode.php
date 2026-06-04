<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-task access protection for its live demo, written into the Traefik
 * file-provider route as middleware. `Inherit` resolves to the global default
 * (config `argos.preview.auth`); the other cases are the resolved, effective
 * modes the deployer actually renders.
 */
enum DemoAccessMode: string
{
    /** Use the stack-wide default (config argos.preview.auth). */
    case Inherit = 'inherit';

    /** Require an Argos login (Traefik forwardAuth → /_argos/demo-gate). */
    case Session = 'session';

    /** Shared HTTP Basic credentials. */
    case Basic = 'basic';

    /** No protection — anyone with the URL. */
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Inherit => __('enums.demo_access_mode.inherit'),
            self::Session => __('enums.demo_access_mode.session'),
            self::Basic => __('enums.demo_access_mode.basic'),
            self::Public => __('enums.demo_access_mode.public'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Inherit => 'gray',
            self::Session => 'success',
            self::Basic => 'warning',
            self::Public => 'danger',
        };
    }

    /**
     * Resolve `Inherit` against the stack-wide default into an effective mode
     * (never returns `Inherit`). The global default `none` maps to `Public`.
     */
    public function resolve(): self
    {
        if ($this !== self::Inherit) {
            return $this;
        }

        return match ((string) config('argos.preview.auth', 'none')) {
            'session' => self::Session,
            'basic' => self::Basic,
            default => self::Public,
        };
    }
}
