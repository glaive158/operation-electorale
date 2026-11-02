/*
 * Service worker de l'application de terrain, servi sous /terrain/sw.js (étendue /terrain/).
 *
 * - La coquille (/terrain/app), les modules compilés (/build/assets/) et l'icône : cache d'abord, la coquille
 *   étant rafraîchie en arrière-plan. Le nom du cache porte la version de ce script, qui change à chaque build.
 * - L'API (/api/) : réseau d'abord ; seules les lectures d'affectations, de clé de preuve et d'appareil sont
 *   gardées en cache de repli. Ce cache est vidé à chaque changement de compte (message `purger-api`).
 * - Une nouvelle version attend : elle ne prend la main que sur message de l'application, qui ne l'envoie
 *   que lorsque la file d'envoi est vide (message `activer` avec `file_vide: true`).
 */

const VERSION = '__VERSION__';
const PREFIXE = 'terrain-coquille-';
const CACHE = PREFIXE + VERSION;
const PREFIXE_API = 'terrain-api-';
const CACHE_API = PREFIXE_API + VERSION;
const COQUILLE = '/terrain/app';
const FIXES = [COQUILLE, '/terrain/icon.svg', '/terrain/manifest.webmanifest'];
const LECTURES_API = /^\/api\/terrain\/(affectations|cle-preuve|appareil)$/;
const RESSOURCE_COMPILEE = /^\/build\/assets\//;

function ouvrable(requete) {
    const url = new URL(requete.url);

    return url.origin === self.location.origin ? url : null;
}

/** Une réponse utilisable comme coquille : ni erreur, ni redirection vers la connexion. */
function conservable(reponse) {
    return reponse && reponse.ok && !reponse.redirected && reponse.type !== 'opaque';
}

async function mettreEnCache(cache, adresse) {
    try {
        const reponse = await fetch(adresse, { cache: 'reload', credentials: 'same-origin' });
        if (conservable(reponse)) {
            await cache.put(adresse, reponse.clone());

            return reponse;
        }
    } catch {
        // Hors connexion : l'application renverra la liste des ressources à charger.
    }

    return null;
}

function adressesDe(html) {
    const trouvees = new Set();
    for (const [, adresse] of html.matchAll(/(?:src|href)="([^"]+)"/g)) {
        const url = new URL(adresse, self.location.origin);
        if (url.origin === self.location.origin && (RESSOURCE_COMPILEE.test(url.pathname) || FIXES.includes(url.pathname))) {
            trouvees.add(url.pathname);
        }
    }

    return [...trouvees];
}

async function precharger() {
    const cache = await caches.open(CACHE);
    const coquille = await mettreEnCache(cache, COQUILLE);
    if (!coquille) return;
    const adresses = adressesDe(await coquille.clone().text());
    await Promise.all([...FIXES, ...adresses].filter((a) => a !== COQUILLE).map((a) => mettreEnCache(cache, a)));
}

self.addEventListener('install', (evenement) => {
    // Pas de skipWaiting ici : l'application décide du moment, quand la file d'envoi est vide.
    evenement.waitUntil(precharger());
});

self.addEventListener('activate', (evenement) => {
    evenement.waitUntil((async () => {
        for (const nom of await caches.keys()) {
            const ancien = (nom.startsWith(PREFIXE) && nom !== CACHE) || (nom.startsWith(PREFIXE_API) && nom !== CACHE_API);
            if (ancien) await caches.delete(nom);
        }
        await self.clients.claim();
    })());
});

async function coquilleCacheDAbord(evenement, requete) {
    const cache = await caches.open(CACHE);
    const enCache = await cache.match(COQUILLE);
    if (enCache) {
        // Rafraîchie pour la prochaine ouverture ; une panne réseau ou une redirection ne remplace rien.
        evenement.waitUntil(mettreEnCache(cache, COQUILLE));

        return enCache;
    }
    const reponse = await fetch(requete);
    if (conservable(reponse)) await cache.put(COQUILLE, reponse.clone());

    return reponse;
}

async function ressourceCacheDAbord(requete) {
    const cache = await caches.open(CACHE);
    const enCache = await cache.match(requete);
    if (enCache) return enCache;
    const reponse = await fetch(requete);
    if (conservable(reponse)) await cache.put(requete, reponse.clone());

    return reponse;
}

async function apiReseauDAbord(requete, url) {
    try {
        const reponse = await fetch(requete);
        if (reponse.ok && LECTURES_API.test(url.pathname)) {
            const cache = await caches.open(CACHE_API);
            await cache.put(url.pathname, reponse.clone());
        }

        return reponse;
    } catch (erreur) {
        if (LECTURES_API.test(url.pathname)) {
            const cache = await caches.open(CACHE_API);
            const enCache = await cache.match(url.pathname);
            if (enCache) return enCache;
        }
        throw erreur;
    }
}

self.addEventListener('fetch', (evenement) => {
    const requete = evenement.request;
    const url = ouvrable(requete);
    if (!url || requete.method !== 'GET') return;

    if (url.pathname.startsWith('/api/')) {
        evenement.respondWith(apiReseauDAbord(requete, url));
    } else if (url.pathname === COQUILLE) {
        // Les autres pages de /terrain/ (écrans web du service logistique) ne sont jamais servies depuis le cache.
        evenement.respondWith(coquilleCacheDAbord(evenement, requete));
    } else if (RESSOURCE_COMPILEE.test(url.pathname) || FIXES.includes(url.pathname)) {
        evenement.respondWith(ressourceCacheDAbord(requete));
    }
});

self.addEventListener('message', (evenement) => {
    const message = evenement.data ?? {};

    if (message.type === 'activer') {
        // Uniquement si l'application affirme que rien n'attend d'être envoyé.
        if (message.file_vide === true) self.skipWaiting();
    } else if (message.type === 'precharger' && Array.isArray(message.urls)) {
        evenement.waitUntil((async () => {
            const cache = await caches.open(CACHE);
            for (const adresse of message.urls) {
                const url = new URL(String(adresse), self.location.origin);
                if (url.origin === self.location.origin && (RESSOURCE_COMPILEE.test(url.pathname) || FIXES.includes(url.pathname))) {
                    if (!(await cache.match(url.pathname))) await mettreEnCache(cache, url.pathname);
                }
            }
        })());
    } else if (message.type === 'purger-api') {
        evenement.waitUntil(caches.delete(CACHE_API));
    }
});
