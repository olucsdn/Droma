/**
 * Wert — Service Worker v1.0
 * Fornece cache de assets essenciais para experiência mobile PWA instantânea.
 */
const CACHE_NAME = 'wert-cache-v1';
const ASSETS_TO_CACHE = [
  'style_index.css',
  'style_form.css',
  'style_login.css',
  'js/chart.umd.min.js',
  'manifest.json',
  'img/icon-192.svg',
  'img/icon-512.svg'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(ASSETS_TO_CACHE);
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
  // Ignora requisições POST ou para outros domínios
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // Apenas faz cache de arquivos estáticos conhecidos (CSS, JS, imagens, fontes)
  if (/\.(css|js|svg|png|jpg|woff2?)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(event.request).then(cached => {
        if (cached) return cached;
        return fetch(event.request).then(response => {
          if (!response || response.status !== 200 || response.type !== 'basic') {
            return response;
          }
          const responseToCache = response.clone();
          caches.open(CACHE_NAME).then(cache => {
            cache.put(event.request, responseToCache);
          });
          return response;
        });
      })
    );
  }
});
