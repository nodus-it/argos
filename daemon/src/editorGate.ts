import { createHmac, timingSafeEqual } from 'node:crypto';
import { createServer, request as httpRequest, type IncomingMessage, type Server } from 'node:http';
import { connect } from 'node:net';
import type { Duplex } from 'node:stream';

/** The header Traefik's code route adds after the workspace gate let a person through. */
export const EDITOR_TOKEN_HEADER = 'x-argos-editor-token';

/**
 * The editor token of a workspace: derived from its daemon token, so the
 * manager can compute it without storing another secret, and a leaked
 * Traefik route file opens only the editor, never the daemon API.
 */
export function editorToken(daemonToken: string): string {
    return createHmac('sha256', daemonToken).update('argos-editor').digest('hex');
}

function authorized(request: IncomingMessage, expected: Buffer): boolean {
    const given = request.headers[EDITOR_TOKEN_HEADER];
    if (typeof given !== 'string') {
        return false;
    }
    const actual = Buffer.from(given);

    return actual.length === expected.length && timingSafeEqual(actual, expected);
}

/** Request headers for the editor: the token stays at the gate. */
function forwardHeaders(request: IncomingMessage): IncomingMessage['headers'] {
    const headers = { ...request.headers };
    delete headers[EDITOR_TOKEN_HEADER];

    return headers;
}

/**
 * The editor's only door to the network. OpenVSCode Server listens on
 * loopback without a connection token; this gate listens on the workspace
 * network and forwards HTTP and WebSocket traffic only when Traefik's code
 * route vouches for the request — another workspace on the same Docker
 * network gets a 403 instead of a terminal.
 */
export function startEditorGate(options: { token: string; port: number; editorPort: number; host?: string }): Promise<Server> {
    const expected = Buffer.from(options.token);

    const server = createServer((request, response) => {
        if (!authorized(request, expected)) {
            response.writeHead(403).end();

            return;
        }
        const upstream = httpRequest(
            { host: '127.0.0.1', port: options.editorPort, method: request.method, path: request.url, headers: forwardHeaders(request) },
            (answer) => {
                response.writeHead(answer.statusCode ?? 502, answer.headers);
                answer.pipe(response);
            },
        );
        upstream.on('error', () => {
            if (!response.headersSent) {
                response.writeHead(502);
            }
            response.end();
        });
        request.pipe(upstream);
    });

    server.on('upgrade', (request: IncomingMessage, socket: Duplex, head: Buffer) => {
        if (!authorized(request, expected)) {
            socket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');

            return;
        }
        const upstream = connect(options.editorPort, '127.0.0.1', () => {
            const headers = forwardHeaders(request);
            const lines = [`${request.method ?? 'GET'} ${request.url ?? '/'} HTTP/1.1`];
            for (const [name, value] of Object.entries(headers)) {
                for (const item of Array.isArray(value) ? value : [value]) {
                    if (item !== undefined) {
                        lines.push(`${name}: ${item}`);
                    }
                }
            }
            upstream.write(lines.join('\r\n') + '\r\n\r\n');
            if (head.length > 0) {
                upstream.write(head);
            }
            upstream.pipe(socket);
            socket.pipe(upstream);
        });
        const close = (): void => {
            upstream.destroy();
            socket.destroy();
        };
        upstream.on('error', close);
        socket.on('error', close);
    });

    return new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(options.port, options.host ?? '0.0.0.0', () => resolve(server));
    });
}
