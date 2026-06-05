import { defineConfig, devices } from '@playwright/test';

/**
 * Argos browser-E2E config.
 *
 * Local-only. Runs against the running Docker Compose stack (nginx on
 * ARGOS_PORT, default 8080) — NOT `php artisan serve` — because the full flow
 * needs the queue (Horizon/Redis) and the deterministic fake mode wired into
 * the same server. Bring the stack up first:
 *
 *   docker compose -f .tools/docker/docker-compose.yml up -d
 *
 * and run the app container with ARGOS_E2E_FAKE=1 for the gemockt suite. The
 * reset helper re-seeds via `migrate:fresh` inside the app container before
 * each test. CI integration intentionally out of scope.
 */
const PORT = process.env.ARGOS_PORT ?? '8080';
const BASE_URL = `http://127.0.0.1:${PORT}`;

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
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
