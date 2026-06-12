import { defineConfig, devices } from '@playwright/test';

/**
 * Argos browser-E2E config.
 *
 * Local-only. Runs against the running Docker Compose stack (nginx on
 * ARGOS_PORT, default 8080) — NOT `php artisan serve` — because the full flow
 * needs the queue (Horizon/Redis) and the deterministic fake mode wired into
 * the same server. Bring the stack up in E2E mode first — this pins
 * ARGOS_E2E_FAKE=1 + an http/127.0.0.1 APP_URL and the matching non-secure,
 * host-only session cookie, INDEPENDENT of the personal .env (which may be
 * tuned for domain dev with secure cookies that the browser can't return over
 * http/127.0.0.1, bouncing the Filament login):
 *
 *   composer test:browser:up   # = bash .tools/bin/e2e-up.sh
 *
 * The reset helper re-seeds via `migrate:fresh` inside the app container before
 * each test. CI integration intentionally out of scope.
 */
const PORT = process.env.ARGOS_PORT ?? '8080';
const BASE_URL = `http://127.0.0.1:${PORT}`;

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  // The full-flow specs drive a 6-step browser journey (login → onboarding →
  // project → task → concept → implement) where the two phase steps poll the
  // async queue with reloads. That legitimately outlasts Playwright's 30s
  // default test timeout under CI load — the sole reason those specs flaked.
  timeout: 120_000,
  // `trace: 'on-first-retry'` below only does anything with retries enabled.
  // One CI retry turns an async-timing flake into a transparent re-run instead
  // of a red job (and captures a trace on the first attempt); locally we want
  // failures to surface immediately, so none.
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  // Reuse the already-running compose stack. If it is not up, the command
  // exits immediately and Playwright reports the URL as unreachable — a clear
  // "bring the stack up first" signal rather than silently booting a different
  // server.
  webServer: {
    command: `echo "Expecting the Argos compose stack on ${BASE_URL} (docker compose ... up -d)"`,
    url: BASE_URL,
    reuseExistingServer: true,
    timeout: 30_000,
  },
});
