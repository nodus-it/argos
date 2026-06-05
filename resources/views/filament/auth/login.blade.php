@php
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;

    $version = (string) config('argos.version');
    $sourceUrl = rtrim((string) config('argos.source_url'), '/');
    $hasPasswordReset = filament()->hasPasswordReset();
@endphp

{{-- Dark "control-room" split-screen login. Forces the dark variant via
     .login-scope regardless of the global theme. See ARGOS_LOGIN.md. --}}
<div class="login-scope">
    {{-- ============================ Brand side ============================ --}}
    <section class="login-brand" aria-hidden="true">
        <div class="lb-glow"></div>
        <div class="lb-grid"></div>
        <div class="lb-vignette"></div>

        <div class="lb-top">
            <x-argos-eye :size="30" />
            <span class="word">ARGOS</span>
        </div>

        <div class="lb-hero">
            <div class="lb-eye-stage">
                <span class="lb-ring r1"></span>
                <span class="lb-ring r2"></span>
                <span class="lb-ring r3"></span>
                <span class="lb-eye-glow"></span>
                <x-argos-eye :size="96" class="eye" />
            </div>
            <h1>
                {{ __('auth-login.headline_lead') }}
                <span class="hl">{{ __('auth-login.headline_accent') }}</span>
            </h1>
            <p>{{ __('auth-login.pitch') }}</p>

            <div class="lb-term">
                <div class="lb-term-head">
                    <i class="d1"></i>
                    <i class="d2"></i>
                    <i class="d3"></i>
                    <span class="tt">{{ __('auth-login.term_title') }}</span>
                    <span class="live"><span class="dot"></span>{{ __('auth-login.live') }}</span>
                </div>
                <div class="lb-term-body"
                     x-data="{
                        i: 0,
                        feed: [
                            { t: '12:30:02', c: 'ok',     x: '✓ basis-laravel · 2 tests passed' },
                            { t: '12:30:05', c: 'accent', x: 'agent> reviewing diff (3 files, +36 −1)' },
                            { t: '12:30:09', c: 'info',   x: '→ worker pulled image=node-22' },
                            { t: '12:30:14', c: 'ok',     x: '✓ PR #22 opened → main' },
                            { t: '12:30:18', c: 'accent', x: 'agent> drafting concept · api-ratelimit' },
                            { t: '12:30:21', c: 'warn',   x: '! auth-flow waiting for review' },
                        ],
                        rows() {
                            const out = [];
                            const n = this.feed.length;
                            for (let k = 4; k >= 0; k--) {
                                out.push(this.feed[((this.i - k) % n + n) % n]);
                            }
                            return out;
                        },
                        init() {
                            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                                return;
                            }
                            setInterval(() => { this.i = (this.i + 1) % this.feed.length; }, 2200);
                        },
                     }">
                    <template x-for="(row, idx) in rows()" :key="idx">
                        <div class="lb-term-line" :style="'opacity:' + (0.35 + idx * 0.1625)">
                            <span class="tt" x-text="row.t"></span>
                            <span :class="'t-' + row.c" x-text="row.x"></span>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <div class="lb-foot">
            <span class="st"><span class="dot"></span>{{ __('auth-login.foot_agents') }}</span>
            <span>{{ __('auth-login.foot_selfhosted') }}</span>
        </div>
    </section>

    {{-- ============================ Form side ============================= --}}
    <section class="login-form-side">
        <div class="login-card">
            <div class="lc-eye">
                <x-argos-eye :size="32" />
            </div>

            <h2>{{ __('auth-login.heading') }}</h2>
            <p class="lc-sub">{{ __('auth-login.subheading') }}</p>

            {{ FilamentView::renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE) }}

            <form wire:submit="authenticate" class="login-form">
                {{-- Email --}}
                <div>
                    <label class="lf-label" for="login-email">{{ __('auth-login.email_label') }} <span class="req">*</span></label>
                    <input
                        id="login-email"
                        type="email"
                        wire:model="data.email"
                        class="input"
                        autocomplete="email"
                        autofocus
                        required
                        placeholder="{{ __('auth-login.email_placeholder') }}"
                    />
                    @error('data.email')
                        <p class="lf-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Password --}}
                <div x-data="{ show: false }">
                    <div class="lf-pwhead">
                        <label class="lf-label" for="login-password">{{ __('auth-login.password_label') }} <span class="req">*</span></label>
                        @if ($hasPasswordReset)
                            <a href="{{ filament()->getRequestPasswordResetUrl() }}" tabindex="-1">{{ __('auth-login.forgot') }}</a>
                        @endif
                    </div>
                    <div class="input-wrap">
                        <input
                            id="login-password"
                            :type="show ? 'text' : 'password'"
                            wire:model="data.password"
                            class="input"
                            autocomplete="current-password"
                            required
                        />
                        <button
                            type="button"
                            class="input-eye"
                            @click="show = !show"
                            :aria-pressed="show"
                            aria-label="{{ __('auth-login.password_toggle') }}"
                            tabindex="-1"
                        >
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path d="M2.04 12.32a1 1 0 0 1 0-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3.01 9.96 7.18a1 1 0 0 1 0 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3.01-9.96-7.18Z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    @error('data.password')
                        <p class="lf-error">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Remember --}}
                <label class="lf-remember">
                    <input type="checkbox" wire:model="data.remember" class="lf-remember-input" />
                    <span class="lf-check">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 6 9 17l-5-5"/>
                        </svg>
                    </span>
                    <span>{{ __('auth-login.remember') }}</span>
                </label>

                {{-- Submit --}}
                <button type="submit" class="btn btn-primary login-btn" wire:loading.attr="disabled" wire:target="authenticate">
                    <x-argos-eye :size="18" />
                    {{ __('auth-login.submit') }}
                </button>

                {{-- SSO (disabled until a real OAuth login flow exists) --}}
                <div class="lf-divider">{{ __('auth-login.divider') }}</div>
                <div class="sso-row">
                    <button type="button" class="btn btn-sso" disabled aria-disabled="true" title="{{ __('auth-login.sso_soon') }}">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                            <path d="M23.6 9.59 23.57 9.5 20.28.96A.86.86 0 0 0 19.94.5a.88.88 0 0 0-1.01.6L16.71 7H7.29L5.07 1.1A.88.88 0 0 0 4.06.5a.86.86 0 0 0-.34.46L.43 9.5l-.03.09a6.08 6.08 0 0 0 2.02 7.02l.04.03 5 3.74 2.47 1.87 1.5 1.14a1.02 1.02 0 0 0 1.23 0l1.5-1.14 2.47-1.87 5.04-3.76a6.08 6.08 0 0 0 1.96-7.03Z"/>
                        </svg>
                        GitLab
                    </button>
                    <button type="button" class="btn btn-sso" disabled aria-disabled="true" title="{{ __('auth-login.sso_soon') }}">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                            <path d="M12 .5C5.37.5 0 5.78 0 12.29c0 5.21 3.44 9.63 8.2 11.19.6.11.82-.26.82-.57 0-.28-.01-1.02-.02-2-3.34.72-4.04-1.61-4.04-1.61-.55-1.39-1.34-1.76-1.34-1.76-1.08-.74.09-.73.09-.73 1.2.08 1.83 1.23 1.83 1.23 1.07 1.83 2.81 1.3 3.5 1 .1-.78.42-1.31.76-1.61-2.67-.3-5.47-1.34-5.47-5.95 0-1.31.47-2.39 1.24-3.23-.12-.31-.54-1.53.12-3.2 0 0 1-.32 3.3 1.23a11.5 11.5 0 0 1 6 0c2.3-1.55 3.3-1.23 3.3-1.23.66 1.67.24 2.89.12 3.2.77.84 1.23 1.92 1.23 3.23 0 4.62-2.81 5.64-5.49 5.94.43.37.81 1.1.81 2.22 0 1.6-.01 2.9-.01 3.29 0 .32.21.69.82.57A12.04 12.04 0 0 0 24 12.29C24 5.78 18.63.5 12 .5Z"/>
                        </svg>
                        GitHub
                    </button>
                </div>
            </form>

            {{-- Local one-click developer login renders here (AUTH_LOGIN_FORM_AFTER). --}}
            {{ FilamentView::renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER) }}

            <p class="login-signup">
                {{ __('auth-login.signup_prefix') }}
                <a href="{{ $sourceUrl }}" target="_blank" rel="noopener">{{ __('auth-login.signup_link') }}</a>
            </p>
        </div>

        <div class="login-legal">
            Argos v{{ $version }} · {{ __('auth-login.legal_license_pre') }}
            <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener">AGPL-3.0</a>
        </div>
    </section>
</div>
