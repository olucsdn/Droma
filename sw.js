/**
 * Droma — Service Worker v7.0 (Modal Customizado & Offline-First)
 * Garante que CSS, JS e recursos visuais NUNCA falhem, mesmo sob rede instável ou offline.
 */
const CACHE_NAME = 'droma-cache-v23';

// Apenas assets estáticos essenciais que NUNCA exigem autenticação ou redirecionamento
const STATIC_PRECACHE = [
  'assets/css/tokens.css',
  'assets/css/components.css',
  'assets/css/style_index.css',
  'assets/css/style_form.css',
  'assets/css/style_login.css',
  'assets/css/style_landing.css',
  'js/theme.js',
  'js/app.js',
  'js/offline-manager.js',
  'js/money-mask.js',
  'js/password-meter.js',
  'js/push-notifications.js',
  'manifest.json',
  'offline.html',
  'favicon.ico',
  'img/favicon-darkmode.png',
  'img/icon-192.png',
  'img/icon-512.png',
  'img/icon-192.svg',
  'img/icon-512.svg'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async cache => {
      for (const asset of STATIC_PRECACHE) {
        try {
          await cache.add(asset);
        } catch (err) {
          console.debug('[SW Precache] Falha ao pré-cachear:', asset, err);
        }
      }
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.map(key => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Validador estrito de integridade de tipo MIME para prevenir poluição de cache estático com HTML de erro/login
function isValidAssetResponse(response, pathname) {
  if (!response || response.status !== 200) {
    return false;
  }
  const ct = (response.headers.get('content-type') || '').toLowerCase();
  if (ct.includes('text/html')) {
    return false;
  }
  if (/\.css$/i.test(pathname) && !ct.includes('css')) {
    return false;
  }
  if (/\.js$/i.test(pathname) && !ct.includes('javascript') && !ct.includes('ecmascript')) {
    return false;
  }
  return true;
}

self.addEventListener('fetch', event => {
  const request = event.request;

  // Apenas requisições GET são tratadas
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Não intercepta chamadas de API internas
  // Não intercepta chamadas de API internas ou requisições de download/exportação de arquivos
  if (
    url.pathname.includes('/api/') ||
    url.searchParams.has('download') ||
    url.searchParams.has('export') ||
    url.pathname.includes('export')
  ) {
    return;
  }

  // 1. Navegação de Páginas HTML (Network-Only com fallback estrito para offline.html)
  // Páginas autenticadas, dinâmicas, redirects ou erros NUNCA são cacheados para evitar vazamento de dados
  if (request.mode === 'navigate' || (request.headers.get('accept') && request.headers.get('accept').includes('text/html'))) {
    event.respondWith(
      (async () => {
        try {
          return await fetch(request);
        } catch (err) {
          const offlinePage = await caches.match('offline.html', { ignoreSearch: true });
          if (offlinePage) return offlinePage;

          return new Response('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Droma Offline</title></head><body style="background:#070A0E;color:#F1F5F9;font-family:sans-serif;padding:24px;text-align:center;"><h2>Sem sinal de internet</h2><p>Conecte-se para atualizar sua conta.</p><a href="./" style="color:#D4AF6C;">Tentar novamente</a></body></html>', {
            headers: { 'Content-Type': 'text/html; charset=utf-8' }
          });
        }
      })()
    );
    return;
  }

  // 2. Arquivos Estáticos (CSS, JS, Imagens, Fontes, SVGs)
  if (/\.(css|js|svg|png|jpg|jpeg|webp|woff2?|ico)$/i.test(url.pathname)) {
    event.respondWith(
      (async () => {
        const filename = url.pathname.split('/').pop(); // ex: "style_index.css"

        // Para CSS e JS: Prioriza a rede (Network-First) para refletir atualizações imediatamente, com cache como fallback offline
        if (/\.(css|js)$/i.test(url.pathname)) {
          try {
            const networkResponse = await fetch(request);
            if (isValidAssetResponse(networkResponse, url.pathname)) {
              const copy = networkResponse.clone();
              caches.open(CACHE_NAME).then(cache => {
                cache.put(request, copy);
                if (filename) cache.put(filename, copy.clone()).catch(() => {});
              });
              return networkResponse;
            }
          } catch (err) {
            // Rede offline ou inacessível: prossegue para o cache abaixo
          }
        }

        // Busca no cache primeiro por request, por nome do arquivo ou por pathname
        const cached = await caches.match(request, { ignoreSearch: true }) ||
                       (filename ? await caches.match(filename, { ignoreSearch: true }) : null) ||
                       await caches.match(url.pathname, { ignoreSearch: true });

        // Tenta buscar da rede para atualizar o cache (Stale-While-Revalidate para imagens/fontes)
        const networkFetch = fetch(request)
          .then(networkResponse => {
            if (isValidAssetResponse(networkResponse, url.pathname)) {
              const copy = networkResponse.clone();
              caches.open(CACHE_NAME).then(cache => {
                cache.put(request, copy);
                if (filename) cache.put(filename, copy.clone()).catch(() => {});
              });
            }
            return networkResponse;
          })
          .catch(() => null);

        // Se temos em cache, retorna imediatamente
        if (cached) {
          return cached;
        }

        // Se NÃO temos em cache, aguarda a rede
        const networkResponse = await networkFetch;
        if (networkResponse) {
          return networkResponse;
        }

        // Se a rede falhou e era CSS, tenta servir uma folha de estilos básica cacheada em vez de arquivo vazio
        if (url.pathname.endsWith('.css')) {
          const fallbackCss = await caches.match('style_index.css', { ignoreSearch: true }) ||
                              await caches.match('style_form.css', { ignoreSearch: true });
          if (fallbackCss) return fallbackCss;

          return new Response('/* Offline fallback CSS */', {
            headers: { 'Content-Type': 'text/css; charset=utf-8' }
          });
        }

        // Fallback genérico para imagens ou JS indisponíveis
        return new Response('', { status: 408, statusText: 'Resource Offline' });
      })()
    );
    return;
  }
});

// 3. Web Push & Notificações de Vencimento
self.addEventListener('push', event => {
  let payload = {
    title: 'Droma Financy',
    body: 'Você tem lembretes financeiros pendentes.',
    url: 'calendar'
  };
  if (event.data) {
    try {
      const parsed = event.data.json();
      payload = Object.assign(payload, parsed);
    } catch (e) {
      payload.body = event.data.text() || payload.body;
    }
  }
  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: 'img/icon-192.png',
      badge: 'img/favicon-darkmode.png',
      tag: 'droma-push',
      data: { url: payload.url || 'calendar' }
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) ? event.notification.data.url : './';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      for (const client of clientList) {
        if ('focus' in client) {
          client.focus();
          if ('navigate' in client) {
            return client.navigate(targetUrl);
          }
          return client;
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});
