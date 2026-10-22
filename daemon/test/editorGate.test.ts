import { createServer, type Server } from 'node:http';
import { connect, type AddressInfo } from 'node:net';
import { afterEach, describe, expect, it } from 'vitest';
import { editorToken, startEditorGate } from '../src/editorGate.js';

const portOf = (server: Server): number => (server.address() as AddressInfo).port;

describe('editor gate', () => {
    const servers: Server[] = [];
    afterEach(() => {
        for (const server of servers.splice(0)) {
            server.close();
        }
    });

    async function pair(): Promise<{ gate: number; seen: Record<string, string | string[] | undefined>[] }> {
        const seen: Record<string, string | string[] | undefined>[] = [];
        const editor = createServer((request, response) => {
            seen.push(request.headers);
            response.writeHead(200, { 'Content-Type': 'text/plain' }).end('editor');
        });
        editor.on('upgrade', (request, socket) => {
            seen.push(request.headers);
            socket.end('HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n\r\nhello');
        });
        await new Promise<void>((resolve) => editor.listen(0, '127.0.0.1', resolve));
        const gate = await startEditorGate({ token: 'secret', port: 0, editorPort: portOf(editor), host: '127.0.0.1' });
        servers.push(editor, gate);

        return { gate: portOf(gate), seen };
    }

    it('derives the same token as the manager', () => {
        // Workspace::editorToken() — hash_hmac('sha256', 'argos-editor', $daemonToken).
        expect(editorToken('daemon-token-x')).toBe('6111190285ab17fd32d8200df7fc30f000c81cc35bdef7c7bb07e917978232d9');
    });

    it('turns away requests without the route token and keeps the token from the editor', async () => {
        const { gate, seen } = await pair();

        expect((await fetch(`http://127.0.0.1:${gate}/`)).status).toBe(403);
        expect((await fetch(`http://127.0.0.1:${gate}/`, { headers: { 'X-Argos-Editor-Token': 'wrong' } })).status).toBe(403);

        const ok = await fetch(`http://127.0.0.1:${gate}/?folder=x`, { headers: { 'X-Argos-Editor-Token': 'secret' } });
        expect(ok.status).toBe(200);
        expect(await ok.text()).toBe('editor');
        expect(seen).toHaveLength(1);
        expect(seen[0]?.['x-argos-editor-token']).toBeUndefined();
    });

    it('forwards WebSocket upgrades only with the token', async () => {
        const { gate, seen } = await pair();
        const upgrade = (token: string | null): Promise<string> =>
            new Promise((resolve) => {
                const socket = connect(gate, '127.0.0.1', () => {
                    socket.write(
                        'GET /socket HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n' +
                            (token !== null ? `X-Argos-Editor-Token: ${token}\r\n` : '') +
                            '\r\n',
                    );
                });
                let data = '';
                socket.on('data', (chunk) => (data += chunk.toString()));
                socket.on('close', () => resolve(data));
            });

        expect(await upgrade(null)).toContain('403');
        const answer = await upgrade('secret');
        expect(answer).toContain('101 Switching Protocols');
        expect(answer).toContain('hello');
        expect(seen[0]?.['x-argos-editor-token']).toBeUndefined();
    });
});
