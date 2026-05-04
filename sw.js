const CACHE_NAME = 'newsroom-v1';
const ASSETS = [
  './index.php',
  './style.css',
  './manifest.json',
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
  'https://cdn.jsdelivr.net/npm/sweetalert2@11'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS))
  );
});

self.addEventListener('fetch', (event) => {
  // Only cache GET requests (ignore API POSTs)
  if (event.request.method !== 'GET') return;
  
  // Ignore API endpoints for offline caching to avoid serving stale data
  if (event.request.url.includes('api.php')) return;

  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});
