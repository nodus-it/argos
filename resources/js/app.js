import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Realtime (Laravel Reverb). The server renders `window.ArgosRealtime` (see
 * AdminPanelProvider) because app images are prebuilt: key and path are
 * runtime config, the host is whatever the browser is on (Traefik routes
 * /app/ to the reverb service).
 */
const config = window.ArgosRealtime;

if (config && config.key) {
    window.Pusher = Pusher;

    const secure = window.location.protocol === 'https:';
    const port = Number(window.location.port || (secure ? 443 : 80));

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: config.authEndpoint,
        auth: { headers: { 'X-CSRF-TOKEN': config.csrfToken } },
    });
}
