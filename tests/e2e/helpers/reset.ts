import { execSync } from 'node:child_process';

/**
 * Database reset + seeding helpers. They shell into the app container of the
 * running compose stack (the same server the browser hits) and run artisan, so
 * each test starts from a known, single-user state.
 *
 * Override the compose file via ARGOS_COMPOSE if needed.
 */
const COMPOSE = process.env.ARGOS_COMPOSE ?? '.tools/docker/docker-compose.yml';

function appExec(artisan: string): void {
  execSync(`docker compose -f ${COMPOSE} exec -T app php artisan ${artisan}`, {
    stdio: 'pipe',
  });
}

/**
 * Fresh schema + seed. Defaults to DatabaseSeeder (one admin user); pass
 * 'DemoSeeder' for the settings-walk so the masks have data to show.
 */
export function resetDatabase(seeder = 'DatabaseSeeder'): void {
  appExec(`migrate:fresh --seed --seeder="Database\\\\Seeders\\\\${seeder}" --force`);
}

/**
 * Seed an OAuth ConnectedAccount for the admin user (gated test command).
 * Used by the OAuth runs, which can't drive the real browser redirect offline.
 */
export function connectAccount(provider: string, instanceUrl?: string): void {
  const instance = instanceUrl ? ` --instance="${instanceUrl}"` : '';
  appExec(`argos:e2e:connect-account --provider="${provider}"${instance}`);
}
