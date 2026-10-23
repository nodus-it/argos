/**
 * Workspace signals on the user's private channel: refresh overviews and,
 * when the workspace in question is not in front of the user, show a
 * browser notification and count it in the tab title.
 */
export function listenForWorkspaceSignals(echo, userId) {
    const baseTitle = document.title;
    let unseen = 0;

    const resetTitle = () => {
        unseen = 0;
        document.title = baseTitle;
    };
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            resetTitle();
        }
    });
    window.addEventListener('focus', resetTitle);

    echo.private(`user.${userId}`).listen('.workspace.signal', (signal) => {
        window.dispatchEvent(new CustomEvent('argos-workspace-signal', { detail: signal }));
        if (signal.kind === 'summary') {
            return;
        }

        const watching = document.visibilityState === 'visible' && document.hasFocus()
            && window.ArgosCurrentWorkspace === signal.workspace_id;
        if (watching) {
            return;
        }

        unseen += 1;
        document.title = `(${unseen}) ${baseTitle}`;

        if ('Notification' in window && Notification.permission === 'granted') {
            const notification = new Notification(signal.title, {
                body: signal.body ? `${signal.workspace_name} · ${signal.body}` : signal.workspace_name,
                tag: `${signal.workspace_id}:${signal.kind}`,
            });
            notification.onclick = () => {
                window.focus();
                window.location.href = signal.url;
            };
        }
    });
}
