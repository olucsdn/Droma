/**
 * Droma — Gerenciador de Notificações Push (Service Worker + Notification API)
 */
(function () {
    'use strict';

    const KEY_ENABLED = 'droma_push_enabled';
    const KEY_LAST_DATE = 'droma_push_last_date';

    function isSupported() {
        return typeof window !== 'undefined' && 'Notification' in window && 'serviceWorker' in navigator;
    }

    function getPermission() {
        return isSupported() ? Notification.permission : 'denied';
    }

    function isEnabled() {
        try {
            return isSupported() && Notification.permission === 'granted' && localStorage.getItem(KEY_ENABLED) === 'true';
        } catch (e) {
            return false;
        }
    }

    async function showViaServiceWorker(title, body, url) {
        if (!isSupported() || Notification.permission !== 'granted') return false;
        try {
            const reg = await navigator.serviceWorker.ready;
            await reg.showNotification(title, {
                body: body,
                icon: 'img/icon-192.png',
                badge: 'img/favicon-darkmode.png',
                tag: 'droma-due-alerts',
                renotify: true,
                data: { url: url || 'calendar' }
            });
            return true;
        } catch (e) {
            return false;
        }
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(base64);
        const arr = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) {
            arr[i] = raw.charCodeAt(i);
        }
        return arr;
    }

    async function subscribeWebPush(csrfToken) {
        if (!('PushManager' in window)) return { subscribed: false, reason: 'no_push_manager' };
        try {
            const cfgResp = await fetch('push_subscribe', { credentials: 'same-origin' });
            if (!cfgResp.ok) return { subscribed: false };
            const cfg = await cfgResp.json();
            if (!cfg || !cfg.configured || !cfg.publicKey) {
                return { subscribed: false, configured: false };
            }

            const reg = await navigator.serviceWorker.ready;
            let sub = await reg.pushManager.getSubscription();
            if (!sub) {
                sub = await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(cfg.publicKey)
                });
            }

            const saveResp = await fetch('push_subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken || ''
                },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'subscribe', subscription: sub.toJSON() })
            });
            return { subscribed: saveResp.ok, configured: true };
        } catch (e) {
            return { subscribed: false };
        }
    }

    async function enable(csrfToken) {
        if (!isSupported()) return { ok: false, reason: 'unsupported' };
        let perm = Notification.permission;
        if (perm === 'default') {
            perm = await Notification.requestPermission();
        }
        if (perm !== 'granted') {
            return { ok: false, reason: 'denied' };
        }
        try {
            localStorage.setItem(KEY_ENABLED, 'true');
        } catch (e) {}
        const pushRes = await subscribeWebPush(csrfToken);
        return { ok: true, webPush: pushRes.subscribed };
    }

    async function disable(csrfToken) {
        try {
            localStorage.setItem(KEY_ENABLED, 'false');
        } catch (e) {}
        try {
            if ('PushManager' in window && navigator.serviceWorker) {
                const reg = await navigator.serviceWorker.ready;
                const sub = await reg.pushManager.getSubscription();
                if (sub) {
                    const endpoint = sub.endpoint;
                    await sub.unsubscribe();
                    await fetch('push_subscribe', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken || ''
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ action: 'unsubscribe', endpoint: endpoint })
                    });
                }
            }
        } catch (e) {}
    }

    async function checkAndNotify(force) {
        if (!isEnabled()) return;
        const today = new Date().toISOString().slice(0, 10);
        try {
            if (!force && localStorage.getItem(KEY_LAST_DATE) === today) {
                return;
            }
        } catch (e) {}

        try {
            const resp = await fetch('due_alerts', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            if (!resp.ok) return;
            const data = await resp.json();
            if (data && data.count > 0) {
                const formatted = Number(data.total || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const title = data.count === 1 ? '1 conta vence em breve' : data.count + ' contas vencem em breve';
                const body = 'Total pendente nos próximos 3 dias: ' + formatted + '. Toque para abrir o calendário.';
                const shown = await showViaServiceWorker(title, body, 'calendar');
                if (shown) {
                    try { localStorage.setItem(KEY_LAST_DATE, today); } catch (e) {}
                }
            }
        } catch (e) {}
    }

    window.DromaPush = {
        isSupported: isSupported,
        getPermission: getPermission,
        isEnabled: isEnabled,
        enable: enable,
        disable: disable,
        checkAndNotify: checkAndNotify,
        sendTest: async function (csrfToken) {
            if (csrfToken) {
                try {
                    const resp = await fetch('push_subscribe', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ action: 'test' })
                    });
                    if (resp.ok) {
                        const data = await resp.json();
                        if (data && data.ok) return true;
                    }
                } catch (e) {}
            }
            return showViaServiceWorker(
                'Droma — Notificações Ativas',
                'Você receberá lembretes de contas a vencer nos próximos 3 dias.',
                'calendar'
            );
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { checkAndNotify(false); });
    } else {
        checkAndNotify(false);
    }
})();
