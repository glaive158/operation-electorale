/*
 * Service worker de l'application de terrain, servi sous /terrain/sw.js (étendue /terrain/).
 *
 * - La coquille (/terrain/app), les modules compilés (/build/assets/) et l'icône : cache d'abord, la coquille
 *   étant rafraîchie en arrière-plan. Le nom du cache porte la version de ce script, qui change à chaque build.
 * - L'installation est tout ou rien : si la coquille ou une de ses ressources ne peut pas être récupérée, elle
 *   échoue et le navigateur garde l'ancienne version.
 * - L'API (/api/) n'est jamais interceptée ni mise en cache : IndexedDB est le seul stockage hors ligne des données.
 * - Une nouvelle version attend : elle ne prend la main que sur message de l'application, qui ne l'envoie
 *   que lorsque la file d'envoi est vide (message `activer` avec `file_vide: true`).
 */

const VERSION = '__VERSION__';
const PREFIXE = 'terrain-coquille-';
const CACHE = PREFIXE + VERSION;
const COQUILLE = '/terrain/app';
const FIXES = [COQUILLE, '/terrain/icon.svg', '/terrain/manifest.webmanifest'];
const RESSOURCE_COMPILEE = /^\/build\/assets\//;

function ouvrable(requete) {
    const url = new URL(requete.url);

    return url.origin === self.location.origin ? url : null;
}

/** Une réponse utilisable comme coquille : ni erreur, ni redirection vers la connexion. */
function conservable(reponse) {
    return reponse && reponse.ok && !reponse.redirected && reponse.type !== 'opaque';
}

/** Récupère et conserve une ressource ; échoue (exception) si elle n'est pas une réponse 200 directe. */
async function recuperer(cache, adresse) {
    const reponse = await fetch(adresse, { cache: 'reload', credentials: 'same-origin' });
    if (reponse.status !== 200 || reponse.redirected || reponse.type === 'opaque') {
        throw new Error(`Ressource indisponible : ${adresse} (${reponse.status}${reponse.redirected ? ', redirigée' : ''}).`);
    }
    await cache.put(adresse, reponse.clone());

    return reponse;
}

/** Mise à jour en arrière-plan : une erreur ne remplace jamais ce qui est déjà conservé. */
async function rafraichir(cache, adresse) {
    try {
        await recuperer(cache, adresse);
    } catch {
        // Hors connexion ou session expirée : on garde la version conservée.
    }
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

/** Tout ou rien : la coquille et chacune de ses ressources, sinon l'installation échoue. */
async function precharger() {
    const cache = await caches.open(CACHE);
    const coquille = await recuperer(cache, COQUILLE);
    const adresses = adressesDe(await coquille.clone().text());
    await Promise.all([...new Set([...FIXES, ...adresses])].filter((a) => a !== COQUILLE).map((a) => recuperer(cache, a)));
}

self.addEventListener('install', (evenement) => {
    // Pas de skipWaiting ici : l'application décide du moment, quand la file d'envoi est vide.
    evenement.waitUntil(precharger());
});

self.addEventListener('activate', (evenement) => {
    evenement.waitUntil((async () => {
        for (const nom of await caches.keys()) {
            if (nom.startsWith(PREFIXE) && nom !== CACHE) await caches.delete(nom);
        }
        await self.clients.claim();
    })());
});

async function coquilleCacheDAbord(evenement, requete) {
    const cache = await caches.open(CACHE);
    const enCache = await cache.match(COQUILLE);
    if (enCache) {
        // Rafraîchie pour la prochaine ouverture ; une panne réseau ou une redirection ne remplace rien.
        evenement.waitUntil(rafraichir(cache, COQUILLE));

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

self.addEventListener('fetch', (evenement) => {
    const requete = evenement.request;
    const url = ouvrable(requete);
    if (!url || requete.method !== 'GET') return;

    // L'API passe directement au réseau : aucune réponse n'est interceptée ni conservée.
    if (url.pathname === COQUILLE) {
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
                    if (!(await cache.match(url.pathname))) await rafraichir(cache, url.pathname);
                }
            }
        })());
    }
});
