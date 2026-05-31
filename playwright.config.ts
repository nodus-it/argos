import { defineConfig, devices } from '@playwright/test';

/**
 * Argos browser-E2E config (Wave-1 retro M11b).
 *
 * Local-only for now. Boots `php artisan serve` on 127.0.0.1:8000 against
 * the configured .env DB. Run `.tools/bin/dev-reset.sh` before the first
 * test session to seed the DemoSeeder admin user + demo task — the specs
 * expect that exact data set.
 *
 * CI integration intentionally out of scope (see retro M11c).
 */
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:8000',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: {
    command: 'php artisan serve --host=127.0.0.1 --port=8000',
    url: 'http://127.0.0.1:8000',
    reuseExistingServer: true,
    timeout: 30_000,
    // php's built-in webserver does not forward $_SERVER['HOME'] to request
    // scope — the default-SQLite-path in config/database.php then falls back
    // to '/root' and the file does not exist. Pass HOME through so the
    // default resolves to ~/.config/argos/argos.db as in CLI runs.
    env: {
      HOME: process.env.HOME ?? '',
    },
  },
});
