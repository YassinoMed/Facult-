/* UniRegistre SW v1 — app-shell GET same-origin uniquement.
 * JAMAIS de cache : POST/PUT/PATCH/DELETE, /api/*, uploads, inscription.
 * Données sensibles : aucune mise en cache locale (lecture réseau seule).
 */
const VERSION = 'uniregistre-v1';
const HORS_LIGNE = '/hors-ligne';

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.add(HORS_LIGNE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((cles) => Promise.all(cles.filter((c) => c !== VERSION).map((c) => caches.delete(c))))
      .then(() => self.clients.claim())
  );
});

function cachable(request) {
  if (request.method !== 'GET') return false;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return false;
  if (url.pathname.startsWith('/api/')) return false;
  if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/')) return true;
  return false;
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match(HORS_LIGNE))
    );
    return;
  }

  if (!cachable(request)) return;

  event.respondWith(
    caches.match(request).then(
      (cached) => cached ?? fetch(request).then((response) => {
        const copie = response.clone();
        caches.open(VERSION).then((cache) => cache.put(request, copie));
        return response;
      })
    )
  );
});
