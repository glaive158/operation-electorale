import assert from 'node:assert/strict';
import { test } from 'node:test';

import { creerFauxDocument, Element, parId, parTexte, trouverTous } from './support/faux-dom.js';
import { creerFauxIndexedDB } from './support/faux-indexeddb.js';

// Environnement de navigateur minimal, posé avant d'importer l'application.
const indexedDB = creerFauxIndexedDB();
globalThis.document = Object.assign(creerFauxDocument(), {
    getElementById: (id) => (id === 'terrain-amorce' ? { textContent: '{"api":"/api/terrain","service_worker":"/terrain/sw.js","portee":"/terrain/"}' } : null),
});
globalThis.window = { addEventListener() {}, removeEventListener() {}, confirm: () => true };
globalThis.indexedDB = indexedDB;
globalThis.location = { hash: '', origin: 'https://tablette.exemple', reload() {} };

const { demarrer } = await import('../../resources/js/terrain/app.js');

const tick = (ms = 10) => new Promise((r) => { setTimeout(r, ms); });
const attendre = async (condition, essais = 100) => {
    for (let i = 0; i < essais; i += 1) {
        if (await condition()) return;
        await tick(5);
    }
    throw new Error('Condition jamais remplie.');
};

function reseau(extra = {}) {
    const appels = [];
    globalThis.fetch = async (url, options = {}) => {
        const chemin = url.replace('/api/terrain', '');
        appels.push({ methode: options.method, chemin, entetes: options.headers, corps: options.body });
        const reponse = (extra[`${options.method} ${chemin}`] ?? extra[chemin] ?? defauts[`${options.method} ${chemin}`] ?? { status: 404, corps: { message: 'Ressource introuvable.' } });
        const r = typeof reponse === 'function' ? reponse(options) : reponse;

        return new Response(r.corps === undefined ? null : JSON.stringify(r.corps), { status: r.status, headers: { 'Content-Type': 'application/json' } });
    };

    return appels;
}

const element = {
    remise: { id: 12, etape: 'livraison', statut: 'en_attente', de: 'Magasin central', a: 'Sous-préfecture de Thiès' },
    decharge: { id: 5, identifiant: 'DCH-5', numero: '5' },
    autorite_attendue: { user_id: 7, nom: 'Awa Ba', qualite: 'Sous-préfet', delegues: [] },
    lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 20 }],
    empreinte_element: 'x',
};
const defauts = {
    'POST /jetons': { status: 201, corps: { token: 'tok-agent', utilisateur: { id: 3, nom: 'Agent Demo', role: 'agent_transport' }, expire_le: '2026-12-05T10:00:00+00:00' } },
    'GET /affectations': { status: 200, corps: { genere_le: 'x', elements: [element] } },
    'GET /cle-preuve': { status: 200, corps: { pem: 'PEM', empreinte: 'e' } },
    'GET /appareil': { status: 200, corps: { designe: false, appareil: null } },
};

test('demarrage : identifiant de tablette genere une fois, ecran de connexion, puis connexion avec cet identifiant et telechargement', async () => {
    const appels = reseau();
    const racine = new Element('div');

    const ctx = await demarrer(racine);

    // Écran de connexion avec l'identifiant de l'installation (UUID), conservé dans la base locale.
    const identifiant = ctx.etat.appareil;
    assert.match(identifiant, /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/);
    assert.equal(parId(racine, 'identifiant-appareil').textContent, identifiant);
    assert.equal(await ctx.stockage.meta.lire('appareil'), identifiant);
    assert.equal(appels.length, 0, 'aucun appel avant la connexion');
    assert.equal(parTexte(racine, 'button', 'Synchroniser'), null);

    // Connexion.
    parId(racine, 'connexion-email').value = 'agent@exemple.sn';
    parId(racine, 'connexion-mdp').value = 'mot-de-passe';
    await trouverTous(racine, (e) => e.tagName === 'FORM')[0].emettre('submit');
    await attendre(async () => (await ctx.stockage.meta.lire('derniere_sync')) !== undefined);

    const connexion = appels.find((a) => a.chemin === '/jetons');
    assert.deepEqual(JSON.parse(connexion.corps), { email: 'agent@exemple.sn', password: 'mot-de-passe', appareil: identifiant });
    assert.equal(connexion.entetes.Authorization, undefined);
    assert.equal((await ctx.stockage.meta.lire('session')).token, 'tok-agent');
    assert.equal(JSON.stringify(await ctx.stockage.meta.lire('session')).includes('mot-de-passe'), false, 'le mot de passe n\'est jamais conservé');

    // Les données sont téléchargées avec le jeton, l'identifiant de la tablette accompagnant la demande d'appareil.
    const affectations = appels.find((a) => a.chemin === '/affectations');
    assert.equal(affectations.entetes.Authorization, 'Bearer tok-agent');
    assert.equal(appels.find((a) => a.chemin === '/appareil').entetes['X-Appareil'], identifiant);
    assert.deepEqual((await ctx.stockage.elements.list()).map((e) => e.id), ['remise-12']);
    assert.equal((await ctx.stockage.meta.lire('cle_preuve')).pem, 'PEM');

    // La liste s'affiche, et l'écran de redistribution reste fermé tant que la tablette n'est pas désignée.
    await ctx.rafraichir();
    const texte = racine.textContent;
    assert.match(texte, /Décharge n° 5/);
    assert.match(texte, /Disponible hors connexion/);
    assert.match(texte, /Rien en attente d'envoi/);
    assert.equal(parTexte(racine, 'a', 'Redistribution'), null);
    assert.ok(parTexte(racine, 'button', 'Synchroniser'));

    // Une seconde ouverture retrouve la même tablette et la session.
    ctx.arreter();
    const racine2 = new Element('div');
    const ctx2 = await demarrer(racine2);
    assert.equal(ctx2.etat.appareil, identifiant);
    assert.equal(ctx2.etat.session.utilisateur.id, 3);
    assert.match(racine2.textContent, /Décharge n° 5/);
    ctx2.arreter();
});

test('connexion refusee : message du serveur, aucune session', async () => {
    indexedDB.bases.clear();
    reseau({ 'POST /jetons': { status: 422, corps: { message: 'Identifiants invalides.' } } });
    const racine = new Element('div');
    const ctx = await demarrer(racine);

    parId(racine, 'connexion-email').value = 'agent@exemple.sn';
    parId(racine, 'connexion-mdp').value = 'faux';
    await trouverTous(racine, (e) => e.tagName === 'FORM')[0].emettre('submit');

    assert.match(racine.textContent, /Identifiants invalides\./);
    assert.equal(await ctx.stockage.meta.lire('session'), undefined);
    ctx.arreter();
});

test('des envois d\'un autre compte en attente : la connexion est refusee et le nouveau jeton revoque', async () => {
    indexedDB.bases.clear();
    const appels = reseau();
    const racine = new Element('div');
    const ctx = await demarrer(racine);
    await ctx.file.ajouter({
        type: 'declaration', id: 'cle-declaration-0001', cle: 'cle-declaration-0001', remise_id: 12, decharge_id: 5, utilisateur_id: 99,
        horodatage_terminal: '2026-11-05T10:00:00+01:00', bloc_preuve: 'QQ==', signataire: { user_id: 7, nom: 'A', qualite: 'B' }, lignes: [], pieces: [],
    });

    parId(racine, 'connexion-email').value = 'agent@exemple.sn';
    parId(racine, 'connexion-mdp').value = 'mot-de-passe';
    await trouverTous(racine, (e) => e.tagName === 'FORM')[0].emettre('submit');

    assert.match(racine.textContent, /envois d'un autre compte sont en attente/);
    assert.ok(appels.some((a) => a.methode === 'DELETE' && a.chemin === '/jetons/courant'));
    assert.equal(await ctx.stockage.meta.lire('session'), undefined);
    assert.equal((await ctx.file.lister())[0].statut, 'en_attente', 'rien n\'est perdu');
    ctx.arreter();
});

test('jeton refuse en cours de route (401) : retour a la connexion, file conservee', async () => {
    indexedDB.bases.clear();
    reseau();
    const racine = new Element('div');
    const ctx = await demarrer(racine);
    parId(racine, 'connexion-email').value = 'agent@exemple.sn';
    parId(racine, 'connexion-mdp').value = 'mot-de-passe';
    await trouverTous(racine, (e) => e.tagName === 'FORM')[0].emettre('submit');
    await attendre(async () => (await ctx.stockage.meta.lire('derniere_sync')) !== undefined);
    await ctx.file.ajouter({
        type: 'declaration', id: 'cle-declaration-0002', cle: 'cle-declaration-0002', remise_id: 12, decharge_id: 5, utilisateur_id: 3,
        horodatage_terminal: '2026-11-05T10:00:00+01:00', bloc_preuve: 'QQ==', signataire: { user_id: 7, nom: 'A', qualite: 'B' }, lignes: [], pieces: [],
    });

    reseau({ '/affectations': { status: 401, corps: { message: 'Authentification requise.' } }, 'POST /remises/12/declarations': { status: 401, corps: { message: 'Authentification requise.' } }, 'HEAD /pieces/x': { status: 401 } });
    await ctx.sync.cycle();
    await ctx.rafraichir();

    assert.equal(ctx.etat.sessionExpiree, true);
    assert.match(racine.textContent, /Session expirée : reconnectez-vous\. Vos envois en attente sont conservés\./);
    assert.equal(parId(racine, 'connexion-email').value, 'agent@exemple.sn', 'adresse pré-remplie');
    assert.equal((await ctx.file.lister())[0].statut, 'en_attente');

    // Reconnexion : la file reprend et envoie.
    const appels = reseau({ 'POST /remises/12/declarations': { status: 201, corps: { declaration: { id: 40, statut: 'retenue', verification_identite: 'verifiee_code', contestation: 'aucune', anomalies: [] } } } });
    parId(racine, 'connexion-mdp').value = 'mot-de-passe';
    await trouverTous(racine, (e) => e.tagName === 'FORM')[0].emettre('submit');
    await attendre(async () => (await ctx.file.lister())[0].statut === 'synchronise');
    assert.ok(appels.some((a) => a.chemin === '/remises/12/declarations'));
    ctx.arreter();
});

test('envois a verifier d\'un autre compte : ni bloquants ni visibles ; mise a jour du service worker apres une connexion reussie', async () => {
    indexedDB.bases.clear();
    reseau();
    const miseAJour = [];
    const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const enregistrement = { update: async () => { miseAJour.push(1); }, addEventListener() {}, installing: null, waiting: null };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { onLine: true, serviceWorker: { controller: null, register: async () => enregistrement, ready: new Promise(() => {}), addEventListener() {} } },
    });
    try {
        const racine = new Element('div');
        const ctx = await demarrer(racine);
        await ctx.file.ajouter({
            type: 'declaration', id: 'cle-declaration-0003', cle: 'cle-declaration-0003', remise_id: 12, decharge_id: 5, utilisateur_id: 99,
            horodatage_terminal: '2026-11-05T10:00:00+01:00', bloc_preuve: 'QQ==', signataire: { user_id: 7, nom: 'A', qualite: 'B' }, lignes: [], pieces: [],
        });
        await ctx.file.marquer('cle-declaration-0003', { status: 422, corps: { message: 'Message privé de l\'autre compte' } });
        assert.equal(miseAJour.length, 0);

        parId(racine, 'connexion-email').value = 'agent@exemple.sn';
        parId(racine, 'connexion-mdp').value = 'mot-de-passe';
        await trouverTous(racine, (e) => e.tagName === 'FORM')[0].emettre('submit');
        await attendre(async () => (await ctx.stockage.meta.lire('derniere_sync')) !== undefined);
        await ctx.rafraichir();

        assert.equal((await ctx.stockage.meta.lire('session')).utilisateur.id, 3, 'connecté malgré l\'envoi à vérifier d\'un autre compte');
        assert.equal(racine.textContent.includes('Message privé'), false);
        assert.equal((await ctx.file.lister())[0].statut, 'a_verifier', 'rien n\'est perdu');
        assert.equal(miseAJour.length, 1, 'registration.update() après la connexion');
        ctx.arreter();
    } finally {
        Object.defineProperty(globalThis, 'navigator', original);
    }
});

test('mise a jour du service worker : seulement sur la liste, sans champ actif et file vide', async () => {
    indexedDB.bases.clear();
    reseau();
    const racine = new Element('div');
    const ctx = await demarrer(racine);
    try {
    const messages = [];
    ctx.etat.miseAJour = { postMessage: (m) => messages.push(m) };

    globalThis.location.hash = '#/reception/remise-12';
    await ctx.demanderMiseAJour();
    assert.deepEqual(messages, [], 'pas pendant une saisie de réception');

    globalThis.location.hash = '#/';
    const champ = new Element('input');
    racine.enfants[1].appendChild(champ); // la zone d'écran (<main>)
    globalThis.document.activeElement = champ;
    await ctx.demanderMiseAJour();
    assert.deepEqual(messages, [], 'pas avec un champ actif');
    globalThis.document.activeElement = null;

    await ctx.file.ajouter({ type: 'liberation', id: 'liberation-1', decharge_id: 5 });
    await ctx.demanderMiseAJour();
    assert.deepEqual(messages, [], 'pas tant qu\'un envoi attend');
    await ctx.file.marquer('liberation-1', 'synchronise');

    await ctx.demanderMiseAJour();
    assert.deepEqual(messages, [{ type: 'activer', file_vide: true }]);
    } finally {
        globalThis.location.hash = '';
        ctx.arreter();
    }
});

test('demarrage : stockage persistant demande au navigateur, resultat affiche a cote de l\'indicateur hors ligne', async () => {
    indexedDB.bases.clear();
    reseau();
    const demandes = [];
    const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { onLine: true, storage: { persisted: async () => false, persist: async () => { demandes.push(1); return true; } } },
    });
    try {
        const racine = new Element('div');
        const ctx = await demarrer(racine);
        await attendre(() => ctx.etat.stockageProtege === true);
        assert.equal(demandes.length, 1);

        parId(racine, 'connexion-email').value = 'agent@exemple.sn';
        parId(racine, 'connexion-mdp').value = 'mot-de-passe';
        await trouverTous(racine, (e) => e.tagName === 'FORM')[0].emettre('submit');
        await attendre(async () => (await ctx.stockage.meta.lire('derniere_sync')) !== undefined);
        await ctx.rafraichir();

        assert.equal(parId(racine, 'etat-stockage').textContent, 'Stockage protégé');
        ctx.arreter();
    } finally {
        Object.defineProperty(globalThis, 'navigator', original);
    }
});

test('restitution : lien dans l\'en-tete une fois des restitutions telechargees, ecran de liste et de declaration', async () => {
    indexedDB.bases.clear();
    globalThis.location.hash = '';
    const restitution = {
        id: 41, statut: 'a_preparer', cycle: 'Présidentielle 2027', detenteur: { type: 'destinataire', id: 18, nom: 'Sous-préfecture de Sindia' },
        autorite_attendue: { user_id: 7, nom: 'Awa Ba', qualite: 'Sous-préfète', delegues: [] },
        lignes: [{ materiel_id: 3, libelle: 'Urnes', attendu: 100 }],
        declaration_retenue: false, arbitrage_en_attente: false, peut_declarer_tablette: true, peut_prendre_en_charge: false, empreinte_element: 'x',
    };
    reseau({ 'GET /affectations': { status: 200, corps: { genere_le: 'x', elements: [element], restitutions: [restitution] } } });
    const racine = new Element('div');
    const ctx = await demarrer(racine);
    try {
        assert.equal(parTexte(racine, 'a', 'Restitution'), null, 'pas de lien avant la connexion');
        parId(racine, 'connexion-email').value = 'agent@exemple.sn';
        parId(racine, 'connexion-mdp').value = 'mot-de-passe';
        await trouverTous(racine, (e) => e.tagName === 'FORM')[0].emettre('submit');
        await attendre(async () => (await ctx.stockage.meta.lire('derniere_sync')) !== undefined);
        await ctx.rafraichir();

        const lienRestitution = parTexte(racine, 'a', 'Restitution');
        assert.equal(lienRestitution.getAttribute('href'), '#/restitutions');

        globalThis.location.hash = '#/restitutions';
        await ctx.rafraichir();
        assert.match(racine.textContent, /Sous-préfecture de Sindia/);
        assert.ok(trouverTous(racine, (e) => e.tagName === 'A' && e.getAttribute('href') === '#/restitution/41').length);

        globalThis.location.hash = '#/restitution/41';
        await ctx.rafraichir();
        assert.ok(parTexte(racine, 'button', 'Continuer'));
        assert.match(racine.textContent, /Matériel rendu/);
    } finally {
        globalThis.location.hash = '';
        ctx.arreter();
    }
});
