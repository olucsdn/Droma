/**
 * Droma — Service Worker v4.0 (Offline-First PWA)
 * Suporte a cache resiliente com ignoreSearch para preservação completa de CSS e assets.
 */
const CACHE_NAME = 'droma-cache-v4';

const PRECACHE_ASSETS = [
  './',
  'add_expense',
  'add_expense.php',
  'offline.html',
  'style_index.css',
  'style_form.css',
  'style_login.css',
  'js/theme.js',
  'js/app.js',
  'js/offline-manager.js',
  'manifest.json',
  'img/favicon-darkmode.png',
  'img/icon-192.png',
  'img/icon-512.png',
  'img/icon-192.svg',
  'img/icon-512.svg'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async cache => {
      for (const asset of PRECACHE_ASSETS) {
        try {
          await cache.add(asset);
        } catch (err) {
          console.debug('[SW Precache] Aviso ao cachear asset:', asset, err);
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

  // Apenas métodos GET são cacheados (POSTs passam direto ou são geridos pelo IndexedDB)
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Não intercepta chamadas de API internas
  if (url.pathname.includes('/api/')) return;

  // 1. Requisições de Navegação de Páginas HTML (Network-First com Fallback Offline)
  if (request.mode === 'navigate' || (request.headers.get('accept') && request.headers.get('accept').includes('text/html'))) {
    event.respondWith(
      fetch(request)
        .then(networkResponse => {
          if (networkResponse && networkResponse.status === 200) {
            const copy = networkResponse.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
          }
          return networkResponse;
        })
        .catch(async () => {
          // Quando sem sinal: tenta a página solicitada em cache (ignorando query strings como ?tipo=expense)
          const cachedPage = await caches.match(request, { ignoreSearch: true });
          if (cachedPage) return cachedPage;

          // Se for formulário de novo lançamento (add_expense ou add_expense.php)
          if (url.pathname.includes('add_expense')) {
            const addPage = await caches.match('add_expense', { ignoreSearch: true }) || await caches.match('add_expense.php', { ignoreSearch: true });
            if (addPage) return addPage;
          }

          // Se for página inicial
          if (url.pathname === '/' || url.pathname.endsWith('/index.php')) {
            const homePage = await caches.match('./', { ignoreSearch: true });
            if (homePage) return homePage;
          }

          // Fallback final: tela offline.html
          const offlinePage = await caches.match('offline.html', { ignoreSearch: true });
          if (offlinePage) return offlinePage;

          return new Response('Sem conexão com a internet. O Droma sincronizará seus dados ao retornar.', {
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          });
        })
    );
    return;
  }

  // 2. Arquivos Estáticos (CSS, JS, Imagens, Fontes, SVG) -> Cache-First / Stale-While-Revalidate com ignoreSearch
  if (/\.(css|js|svg|png|jpg|jpeg|webp|woff2?|ico)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(request, { ignoreSearch: true }).then(cachedResponse => {
        const fetchPromise = fetch(request).then(networkResponse => {
          if (networkResponse && networkResponse.status === 200) {
            const copy = networkResponse.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
          }
          return networkResponse;
        }).catch(() => null);

        // Retorna o cacheado imediatamente se existir, ou aguarda o fetch de rede
        return cachedResponse || fetchPromise;
      })
    );
  }
});
