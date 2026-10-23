/**
 * git credential helper (`git-credential-argos get|store|erase`).
 *
 * Implements the git credential protocol by asking the daemon's loopback
 * endpoint; `store` and `erase` are no-ops because nothing is persisted.
 */
import { stdin, stdout } from 'node:process';

const port = Number(process.env.ARGOS_CREDENTIAL_PORT ?? '7778');
const action = process.argv[2];

async function main(): Promise<void> {
    // Drain the request on stdin (protocol/host/path) — the daemon only serves
    // the workspace repository, so the attributes are not needed.
    stdin.resume();
    await new Promise((resolve) => stdin.on('end', resolve));

    if (action !== 'get') {
        return;
    }

    const response = await fetch(`http://127.0.0.1:${port}/git-credential`, { method: 'POST' });
    if (!response.ok) {
        process.exitCode = 1;

        return;
    }
    const credential = (await response.json()) as { username: string; password: string };
    stdout.write(`username=${credential.username}\npassword=${credential.password}\n`);
}

main().catch(() => {
    process.exitCode = 1;
});
