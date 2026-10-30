const CACHE_NAME = 'lf-v4';
const STATIC_ASSETS = [
    './assets/css/style.css?v=4',
    './assets/js/site.js?v=4'
];

// Install: cache static assets
self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then(keys => 
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Fetch: network-first for API, cache-first for static
self.addEventListener('fetch', (e) => {
    if (e.request.url.includes('/api/')) {
        // Network first for API calls
        e.respondWith(
            fetch(e.request).catch(() => 
                new Response(JSON.stringify({ success: false, message: 'أنت غير متصل بالإنترنت' }), 
                    { headers: { 'Content-Type': 'application/json' } })
            )
        );
    } else {
        // Cache first for static assets
        e.respondWith(
            caches.match(e.request).then(cached => cached || fetch(e.request))
        );
    }
});
