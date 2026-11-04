import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const SOURCE = readFileSync(new URL('../../resources/pwa/terrain-sw.js', import.meta.url), 'utf8');
const ORIGINE = 'https://tablette.exemple';

const HTML_COQUILLE = `<!DOCTYPE html><html><head>
<link rel="manifest" href="/terrain/manifest.webmanifest"><link rel="icon" href="/terrain/icon.svg">
<link rel="modulepreload" href="/build/assets/app-123.js"><link rel="stylesheet" href="/build/assets/app-456.css">
<script type="module" src="/build/assets/app-123.js"></script><script src="https://cdn.exemple/x.js"></script></head></html>`;

/** Fait tourner le service worker dans un contexte isolé avec de faux caches et un faux réseau. */
function installer({ version = 'v1', reseau = {}, caches: existants = {} } = {}) {
    const ecouteurs = {};
    const stockage = new Map(Object.entries(existants).map(([nom, contenu]) => [nom, new Map(Object.entries(contenu))]));
    const journalReseau = [];
    const etat = { skipWaiting: 0, claim: 0, enLigne: true };

    const ouvrir = (nom) => {
        if (!stockage.has(nom)) stockage.set(nom, new Map());
        const cache = stockage.get(nom);
        const cle = (r) => (typeof r === 'string' ? r : new URL(r.url).pathname);

        return {
            match: async (r) => cache.get(cle(r))?.clone(),
            put: async (r, reponse) => { cache.set(cle(r), reponse); },
        };
    };
    const fetchFn = async (cible) => {
        const url = new URL(typeof cible === 'string' ? cible : cible.url, ORIGINE);
        journalReseau.push(url.pathname);
        if (!etat.enLigne) throw new TypeError('Failed to fetch');
        const reponse = reseau[url.pathname];
        if (typeof reponse === 'function') return reponse();

        return reponse ? reponse.clone() : new Response('introuvable', { status: 404 });
    };
    const contexte = {
        self: {
            location: { origin: ORIGINE },
            addEventListener: (nom, f) => { ecouteurs[nom] = f; },
            skipWaiting: () => { etat.skipWaiting += 1; },
            clients: { claim: async () => { etat.claim += 1; } },
        },
        caches: {
            open: async (n) => ouvrir(n),
            keys: async () => [...stockage.keys()],
            delete: async (n) => stockage.delete(n),
        },
        fetch: fetchFn, Response, URL,
        console,
    };
    vm.createContext(contexte);
    vm.runInContext(SOURCE.replace('__VERSION__', version), contexte);

    const attendre = async (evenement) => {
        await Promise.all(evenement.attentes);

        return evenement;
    };

    return {
        stockage, journalReseau, etat,
        async declencher(nom, donnees = {}) {
            const attentes = [];
            const evenement = { attentes, waitUntil: (p) => attentes.push(p), ...donnees };

            ecouteurs[nom](evenement);

            return attendre(evenement);
        },
        async requete(chemin, { methode = 'GET', mode = 'cors', origine = ORIGINE } = {}) {
            const evenement = await this.declencher('fetch', {
                request: { url: `${origine}${chemin}`, method: methode, mode },
                respondWith(p) { this.promesse = Promise.resolve(p); },
            });

            return evenement.promesse ? { reponse: await evenement.promesse } : { reponse: null };
        },
        cache: (nom) => stockage.get(nom),
    };
}

const html = () => new Response(HTML_COQUILLE, { status: 200, headers: { 'Content-Type': 'text/html' } });
const reseauComplet = () => ({
    '/terrain/app': html(),
    '/terrain/manifest.webmanifest': new Response('{}'), '/terrain/icon.svg': new Response('<svg/>'),
    '/build/assets/app-123.js': new Response('js'), '/build/assets/app-456.css': new Response('css'),
});

test('le cache porte la version du script et l\'installation precharge la coquille et ses modules sans skipWaiting', async () => {
    const sw = installer({ version: 'a1b2c3', reseau: reseauComplet() });

    await sw.declencher('install');

    assert.deepEqual([...sw.stockage.keys()], ['terrain-coquille-a1b2c3']);
    assert.deepEqual([...sw.cache('terrain-coquille-a1b2c3').keys()].sort(), [
        '/build/assets/app-123.js', '/build/assets/app-456.css', '/terrain/app', '/terrain/icon.svg', '/terrain/manifest.webmanifest',
    ]);
    assert.equal(sw.etat.skipWaiting, 0, 'une nouvelle version attend le feu vert de l\'application');
    assert.equal(sw.journalReseau.includes('/x.js'), false, 'rien d\'une autre origine');
});

test('installation tout ou rien : coquille redirigee, absente ou reseau coupe => l\'installation echoue', async () => {
    const redirigee = new Response('connexion', { status: 200 });
    Object.defineProperty(redirigee, 'redirected', { value: true });
    const sw = installer({ reseau: { '/terrain/app': () => redirigee } });
    await assert.rejects(sw.declencher('install'), /redirigée/);

    const absente = installer({ reseau: {} });
    await assert.rejects(absente.declencher('install'), /404/);

    const horsLigne = installer({ reseau: reseauComplet() });
    horsLigne.etat.enLigne = false;
    await assert.rejects(horsLigne.declencher('install'), /Failed to fetch/);
});

test('installation tout ou rien : une ressource referencee manquante fait echouer l\'installation', async () => {
    for (const manquante of ['/build/assets/app-123.js', '/build/assets/app-456.css', '/terrain/icon.svg', '/terrain/manifest.webmanifest']) {
        const reseau = reseauComplet();
        delete reseau[manquante];
        const sw = installer({ reseau });

        await assert.rejects(sw.declencher('install'), /404/, manquante);
    }
    // Une ressource redirigée (session expirée) échoue de même.
    const redirigee = new Response('x', { status: 200 });
    Object.defineProperty(redirigee, 'redirected', { value: true });
    const sw = installer({ reseau: { ...reseauComplet(), '/build/assets/app-123.js': () => redirigee } });
    await assert.rejects(sw.declencher('install'), /redirigée/);
});

test('activation : les caches des anciennes versions sont supprimes, les autres gardes, clients pris en charge', async () => {
    const sw = installer({
        version: 'v2',
        caches: { 'terrain-coquille-v1': { '/x': 1 }, 'terrain-coquille-v2': { '/terrain/app': 1 }, 'autre-site': {} },
    });

    await sw.declencher('activate');

    assert.deepEqual([...sw.stockage.keys()].sort(), ['autre-site', 'terrain-coquille-v2']);
    assert.equal(sw.etat.claim, 1);
});

test('la coquille : cache d\'abord, sans attendre le reseau ; rafraichie en arriere-plan', async () => {
    const sw = installer({ reseau: reseauComplet() });
    await sw.declencher('install');
    sw.journalReseau.length = 0;
    sw.etat.enLigne = false;

    const { reponse } = await sw.requete('/terrain/app?source=pwa', { mode: 'navigate' });

    assert.equal(await reponse.text(), HTML_COQUILLE, 'servie hors connexion, avec ou sans paramètre');
    sw.etat.enLigne = true;
    await sw.requete('/terrain/app', { mode: 'navigate' });
    assert.ok(sw.journalReseau.includes('/terrain/app'), 'rafraîchie quand le réseau est là');
});

test('la coquille absente du cache est demandee au reseau puis conservee', async () => {
    const sw = installer({ reseau: reseauComplet() });

    const { reponse } = await sw.requete('/terrain/app', { mode: 'navigate' });

    assert.equal(reponse.status, 200);
    assert.ok(sw.cache('terrain-coquille-v1').has('/terrain/app'));
});

test('modules compiles et icone : cache d\'abord, conserves a la premiere demande', async () => {
    const sw = installer({ reseau: reseauComplet() });

    const premiere = await sw.requete('/build/assets/app-123.js');
    assert.equal(await premiere.reponse.text(), 'js');
    sw.journalReseau.length = 0;
    sw.etat.enLigne = false;
    const seconde = await sw.requete('/build/assets/app-123.js');

    assert.equal(await seconde.reponse.text(), 'js');
    assert.deepEqual(sw.journalReseau, []);
});

test('l\'API n\'est jamais interceptee ni conservee : lectures comprises', async () => {
    const sw = installer({ reseau: { '/api/terrain/affectations': new Response('{"elements":[]}', { status: 200 }) } });

    for (const chemin of ['/api/terrain/affectations', '/api/terrain/cle-preuve', '/api/terrain/appareil', '/api/terrain/decharges/D-1']) {
        const { reponse } = await sw.requete(chemin);
        assert.equal(reponse, null, chemin);
    }
    assert.deepEqual([...sw.stockage.keys()], []);
    assert.deepEqual(sw.journalReseau, []);
});

test('ne touche ni aux ecritures, ni aux HEAD, ni aux pages web de /terrain/, ni aux autres origines', async () => {
    const sw = installer({ reseau: reseauComplet() });

    for (const [chemin, options] of [
        ['/api/terrain/pieces', { methode: 'POST' }],
        [`/api/terrain/pieces/${'a'.repeat(64)}`, { methode: 'HEAD' }],
        ['/terrain/decharges', { mode: 'navigate' }],
        ['/terrain/decharges/12', { mode: 'navigate' }],
        ['/terrain/suivi', { mode: 'navigate' }],
        ['/build/assets/app-123.js', { origine: 'https://autre.exemple' }],
        ['/login', { mode: 'navigate' }],
    ]) {
        const { reponse } = await sw.requete(chemin, options);
        assert.equal(reponse, null, `${options.methode ?? 'GET'} ${chemin}`);
    }
});

test('message activer : skipWaiting uniquement quand l\'application declare la file vide', async () => {
    const sw = installer();

    await sw.declencher('message', { data: { type: 'activer' } });
    await sw.declencher('message', { data: { type: 'activer', file_vide: false } });
    await sw.declencher('message', { data: { type: 'activer', file_vide: 'oui' } });
    await sw.declencher('message', { data: null });
    assert.equal(sw.etat.skipWaiting, 0);

    await sw.declencher('message', { data: { type: 'activer', file_vide: true } });
    assert.equal(sw.etat.skipWaiting, 1);
});

test('message precharger : ressources du site seulement, une fois chacune', async () => {
    const sw = installer({ reseau: reseauComplet() });

    await sw.declencher('message', { data: { type: 'precharger', urls: ['/terrain/app', '/build/assets/app-123.js', 'https://autre.exemple/build/assets/x.js', '/api/terrain/affectations', '/admin/secret'] } });

    assert.deepEqual([...sw.cache('terrain-coquille-v1').keys()].sort(), ['/build/assets/app-123.js', '/terrain/app']);
    assert.deepEqual(sw.journalReseau.sort(), ['/build/assets/app-123.js', '/terrain/app']);
    sw.journalReseau.length = 0;
    await sw.declencher('message', { data: { type: 'precharger', urls: ['/build/assets/app-123.js'] } });
    assert.deepEqual(sw.journalReseau, []);
});
