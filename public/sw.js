const VERSION = 'v1';

const CACHE = `movingupper-${VERSION}`;

const PRECARGA = [
  '/offline.html',
  '/images/brand/icon-192.png',
  '/images/brand/movingupper-mark.png',
];

self.addEventListener('install', evento => {
  evento.waitUntil(caches.open(CACHE).then(cache => cache.addAll(PRECARGA)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', evento => {
  evento.waitUntil(
    caches.keys()
      .then(claves => Promise.all(claves.filter(c => c.startsWith('movingupper-') && c !== CACHE).map(c => caches.delete(c))))
      .then(() => self.clients.claim()),
  );
});

function esEstatico(url) {
  if (url.origin === self.location.origin) {
    return /^\/(css|js|images)\//.test(url.pathname) || url.pathname === '/favicon.ico';
  }
  return url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';
}

self.addEventListener('fetch', evento => {
  const { request } = evento;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  if (request.mode === 'navigate') {
    evento.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
    return;
  }

  if (esEstatico(url)) {
    evento.respondWith(caches.open(CACHE).then(async cache => {
      const guardada = await cache.match(request);
      const deRed = fetch(request).then(respuesta => {
        if (respuesta.ok || respuesta.type === 'opaque') cache.put(request, respuesta.clone());
        return respuesta;
      }).catch(() => guardada);
      return guardada || deRed;
    }));
  }
  // Todo lo demás (ZPL, Excel, JSON…) pasa directo a la red.
});
