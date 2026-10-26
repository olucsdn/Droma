/**
 * Droma — Service Worker v3.0 (Offline-First PWA)
 * Suporte a cache resiliente para navegação sem internet e retorno de páginas em modo offline.
 */
const CACHE_NAME = 'droma-cache-v3';

const PRECACHE_ASSETS = [
  './',
  'add_expense',
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
      // Adiciona individualmente para tolerar falhas caso algum asset opcional mude
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
          // Quando sem sinal: tenta a página solicitada em cache
          const cachedPage = await caches.match(request);
          if (cachedPage) return cachedPage;

          // Se for nova despesa/receita, tenta add_expense em cache
          if (url.pathname.includes('add_expense')) {
            const addPage = await caches.match('add_expense');
            if (addPage) return addPage;
          }

          // Fallback final: tela elegante offline.html
          const offlinePage = await caches.match('offline.html');
          if (offlinePage) return offlinePage;

          return new Response('Sem conexão com a internet. O Droma sincronizará seus dados ao retornar.', {
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          });
        })
    );
    return;
  }

  // 2. Arquivos Estáticos (CSS, JS, Imagens, Fontes, SVG) -> Cache-First / Stale-While-Revalidate
  if (/\.(css|js|svg|png|jpg|jpeg|webp|woff2?|ico)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then(cachedResponse => {
        const fetchPromise = fetch(request).then(networkResponse => {
          if (networkResponse && networkResponse.status === 200) {
            const copy = networkResponse.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
          }
          return networkResponse;
        }).catch(() => null);

        return cachedResponse || fetchPromise;
      })
    );
  }
});
