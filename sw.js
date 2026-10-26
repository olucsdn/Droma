/**
 * Droma — Service Worker v5.0 (Offline-First Resiliente)
 * Garante que CSS, JS e recursos visuais NUNCA falhem, mesmo sob rede instável ou offline.
 */
const CACHE_NAME = 'droma-cache-v5';

// Apenas assets estáticos essenciais que NUNCA exigem autenticação ou redirecionamento
const STATIC_PRECACHE = [
  'style_index.css',
  'style_form.css',
  'style_login.css',
  'js/theme.js',
  'js/app.js',
  'js/offline-manager.js',
  'manifest.json',
  'offline.html',
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

self.addEventListener('fetch', event => {
  const request = event.request;

  // Apenas requisições GET são tratadas
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Não intercepta chamadas de API internas
  if (url.pathname.includes('/api/')) return;

  // 1. Navegação de Páginas HTML (Network-First com fallback de cache dinâmico)
  if (request.mode === 'navigate' || (request.headers.get('accept') && request.headers.get('accept').includes('text/html'))) {
    event.respondWith(
      (async () => {
        try {
          const networkResponse = await fetch(request);
          if (networkResponse && networkResponse.status === 200) {
            const copy = networkResponse.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
          }
          return networkResponse;
        } catch (err) {
          // Offline: tenta em cache
          const cachedPage = await caches.match(request, { ignoreSearch: true }) ||
                             await caches.match(url.pathname, { ignoreSearch: true });
          if (cachedPage) return cachedPage;

          if (url.pathname.includes('add_expense')) {
            const addPage = await caches.match('add_expense', { ignoreSearch: true }) ||
                            await caches.match('add_expense.php', { ignoreSearch: true });
            if (addPage) return addPage;
          }

          if (url.pathname === '/' || url.pathname.endsWith('/index.php')) {
            const homePage = await caches.match('./', { ignoreSearch: true });
            if (homePage) return homePage;
          }

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
        const cleanPath = url.pathname.replace(/^\//, ''); // ex: "style_index.css"

        // Busca no cache primeiro (por URL completa, com ignoreSearch, ou por cleanPath)
        const cached = await caches.match(request, { ignoreSearch: true }) ||
                       await caches.match(cleanPath, { ignoreSearch: true }) ||
                       await caches.match('/' + cleanPath, { ignoreSearch: true });

        // Tenta buscar da rede para atualizar o cache
        const networkFetch = fetch(request)
          .then(networkResponse => {
            if (networkResponse && networkResponse.status === 200) {
              const copy = networkResponse.clone();
              caches.open(CACHE_NAME).then(cache => {
                cache.put(request, copy);
                cache.put(cleanPath, copy.clone());
              });
            }
            return networkResponse;
          })
          .catch(() => null);

        // Se temos em cache, retorna IMEDIATAMENTE (stale-while-revalidate)
        if (cached) {
          return cached;
        }

        // Se NÃO temos em cache, aguarda a rede
        const networkResponse = await networkFetch;
        if (networkResponse) {
          return networkResponse;
        }

        // Se a rede falhou e era CSS, retorna fallback seguro para nunca deixar o HTML quebrado
        if (url.pathname.endsWith('.css')) {
          return new Response('/* Offline safe CSS fallback */', {
            headers: { 'Content-Type': 'text/css; charset=utf-8' }
          });
        }

        // Fallback genérico para imagens ou JS
        return new Response('', { status: 408, statusText: 'Resource Offline' });
      })()
    );
  }
});
