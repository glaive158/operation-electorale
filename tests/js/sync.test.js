import assert from 'node:assert/strict';
import { test } from 'node:test';

import { creerFile, STATUTS } from '../../resources/js/terrain/file.js';
import { corpsDeclaration, creerSync, idElement, INTERVALLE_MS, telechargerDonnees } from '../../resources/js/terrain/sync.js';

const EMPREINTE_A = 'a'.repeat(64);
const EMPREINTE_B = 'b'.repeat(64);

function memoire() {
    const donnees = new Map();

    return {
        get: async (id) => (donnees.has(id) ? structuredClone(donnees.get(id)) : undefined),
        put: async (e) => { donnees.set(e.id ?? e.cle, structuredClone(e)); },
        list: async () => [...donnees.values()].map((e) => structuredClone(e)),
        delete: async (id) => { donnees.delete(id); },
    };
}

/** Fausse API : `reponses(methode, chemin, options)` renvoie {status, corps}. Toutes les requêtes sont journalisées. */
function fausseApi(reponses) {
    const appels = [];

    return {
        appels,
        async envoyer(methode, chemin, options = {}) {
            appels.push({ methode, chemin, options });

            return (await reponses(methode, chemin, options, appels)) ?? { status: 200, corps: null };
        },
    };
}

const declaration = (surcharge = {}) => ({
    type: 'declaration', id: 'cle-declaration-0001', cle: 'cle-declaration-0001', remise_id: 12, decharge_id: 5,
    horodatage_terminal: '2026-11-05T10:00:00+01:00', bloc_preuve: 'QkxPQw==', signataire: { user_id: 7, nom: 'Awa Ba', qualite: 'Préfet' },
    lignes: [{ materiel_id: 3, recu: 20, defectueux: 1, observation: null }], pieces: [{ empreinte: EMPREINTE_A, role: 'signature' }], gps: null,
    ...surcharge,
});

function installer({ reponses, pieces = new Map(), ...options } = {}) {
    let maintenant = 1_000_000;
    const file = creerFile({ stockage: memoire(), horloge: () => maintenant, delaiReessaiMs: 30_000 });
    const api = fausseApi(reponses ?? (() => ({ status: 200, corps: null })));
    const sync = creerSync({
        file, api,
        pieces: { lire: async (e) => pieces.get(e), supprimer: async (e) => { pieces.delete(e); } },
        ...options,
    });

    return { file, api, sync, pieces, avancer: (ms) => { maintenant += ms; } };
}

test('une declaration part avec son corps exact, une fois les pieces deposees (HEAD 200 : rien a renvoyer)', async () => {
    const { file, api, sync } = installer({
        reponses: (m, chemin) => {
            if (m === 'HEAD') return { status: 200, corps: null };
            if (m === 'POST' && chemin.endsWith('/declarations')) return { status: 201, corps: { declaration: { id: 40, statut: 'retenue', verification_identite: 'verifiee_code', contestation: 'aucune', anomalies: [] } } };
        },
    });
    await file.ajouter({ type: 'piece', id: EMPREINTE_A, empreinte: EMPREINTE_A, role: 'signature' });
    await file.ajouter(declaration());

    const resultat = await sync.cycle();

    assert.equal(resultat.envoyes, 2);
    assert.deepEqual(api.appels.map((a) => `${a.methode} ${a.chemin}`), [
        `HEAD /pieces/${EMPREINTE_A}`, // la pièce, puis
        `HEAD /pieces/${EMPREINTE_A}`, // la vérification avant la déclaration (P5-R48)
        'POST /remises/12/declarations',
    ]);
    const envoi = api.appels.at(-1).options.json;
    assert.deepEqual(envoi, corpsDeclaration(declaration()));
    assert.equal(envoi.mode, 'tablette');
    assert.equal(envoi.bloc_preuve, 'QkxPQw==');
    assert.deepEqual(envoi.pieces, [{ empreinte: EMPREINTE_A, role: 'signature' }]);
    const [piece, envoyee] = await file.lister();
    assert.equal(piece.statut, STATUTS.SYNCHRONISE);
    assert.equal(envoyee.statut, STATUTS.SYNCHRONISE);
    assert.equal(envoyee.reponse.declaration.id, 40);
});

test('HEAD 404 : les octets de la piece sont envoyes en multipart, puis la declaration', async () => {
    const octets = new Blob([new Uint8Array([1, 2, 3])], { type: 'image/png' });
    const { file, api, sync, pieces } = installer({
        pieces: new Map([[EMPREINTE_A, { empreinte: EMPREINTE_A, blob: octets }]]),
        reponses: (m, chemin) => {
            if (m === 'HEAD') return { status: 404, corps: null };
            if (chemin === '/pieces') return { status: 201, corps: { piece: { empreinte: EMPREINTE_A } } };
            return { status: 201, corps: { declaration: { id: 1, statut: 'retenue' } } };
        },
    });
    await file.ajouter(declaration());

    await sync.cycle();

    const posts = api.appels.filter((a) => a.methode === 'POST' && a.chemin === '/pieces');
    assert.equal(posts.length, 1);
    assert.ok(posts[0].options.formData instanceof FormData);
    assert.equal(posts[0].options.formData.get('empreinte'), EMPREINTE_A);
    assert.equal(posts[0].options.formData.get('fichier').size, 3);
    assert.deepEqual(api.appels.map((a) => `${a.methode} ${a.chemin}`), [
        `HEAD /pieces/${EMPREINTE_A}`, 'POST /pieces', 'POST /remises/12/declarations',
    ]);
    // La déclaration est enregistrée : les octets n'ont plus à rester sur la tablette.
    assert.equal(pieces.has(EMPREINTE_A), false);
});

test('piece absente de la tablette : la declaration part quand meme (piece attendue cote serveur)', async () => {
    const { file, api, sync } = installer({
        reponses: (m) => (m === 'HEAD' ? { status: 404, corps: null } : { status: 201, corps: { declaration: { id: 2, statut: 'a_verifier' } } }),
    });
    await file.ajouter(declaration());

    await sync.cycle();

    assert.deepEqual(api.appels.map((a) => `${a.methode} ${a.chemin}`), [`HEAD /pieces/${EMPREINTE_A}`, 'POST /remises/12/declarations']);
    assert.equal((await file.lister())[0].statut, STATUTS.SYNCHRONISE);
});

test('409 puis 201 : meme cle, meme bloc, meme corps a chaque nouvel essai', async () => {
    let tentative = 0;
    const { file, api, sync, avancer } = installer({
        reponses: (m, chemin) => {
            if (m === 'HEAD') return { status: 200, corps: null };
            if (chemin.endsWith('/declarations')) {
                tentative += 1;
                return tentative === 1
                    ? { status: 409, corps: { message: 'Déclaration en cours de traitement, réessayez.' } }
                    : { status: 200, corps: { declaration: { id: 9, statut: 'retenue' } } };
            }
        },
    });
    await file.ajouter(declaration());

    await sync.cycle();
    assert.equal((await file.lister())[0].statut, STATUTS.EN_ATTENTE);
    await sync.cycle(); // trop tôt : le délai n'est pas écoulé
    assert.equal(api.appels.filter((a) => a.chemin.endsWith('/declarations')).length, 1);
    avancer(31_000);
    await sync.cycle();

    const envois = api.appels.filter((a) => a.chemin.endsWith('/declarations')).map((a) => a.options.json);
    assert.equal(envois.length, 2);
    assert.deepEqual(envois[1], envois[0]);
    assert.equal(envois[1].cle, 'cle-declaration-0001');
    assert.equal(envois[1].bloc_preuve, 'QkxPQw==');
    assert.equal((await file.lister())[0].statut, STATUTS.SYNCHRONISE);
});

test('panne reseau et erreur 5xx : la declaration reste en attente, inchangee', async () => {
    for (const status of [0, 503]) {
        const { file, sync } = installer({ reponses: (m) => (m === 'HEAD' ? { status: 200, corps: null } : { status, corps: null }) });
        await file.ajouter(declaration());
        const avant = (await file.lister())[0];

        await sync.cycle();

        const apres = (await file.lister())[0];
        assert.equal(apres.statut, STATUTS.EN_ATTENTE, `status ${status}`);
        assert.equal(apres.bloc_preuve, avant.bloc_preuve);
        assert.equal(apres.cle, avant.cle);
    }
});

test('apres une panne, le passage automatique respecte le delai de 30 s ; le passage manuel l\'ignore', async () => {
    let panne = true;
    const { file, api, sync } = installer({
        reponses: (m) => {
            if (m === 'HEAD') return { status: 200, corps: null };
            if (m === 'POST') return panne ? { status: 503, corps: null } : { status: 201, corps: { declaration: { id: 40, statut: 'retenue', verification_identite: 'verifiee_code', contestation: 'aucune', anomalies: [] } } };
        },
    });
    await file.ajouter(declaration());
    await sync.cycle();
    assert.equal((await file.lister())[0].statut, STATUTS.EN_ATTENTE);
    assert.ok((await file.lister())[0].pas_avant, 'le delai de reessai est pose');
    const envois = () => api.appels.filter((a) => a.methode === 'POST').length;
    assert.equal(envois(), 1);

    // Le réseau revient : l'automatique n'envoie rien avant 30 s.
    panne = false;
    await sync.cycle();
    assert.equal(envois(), 1, 'automatique : delai respecte');
    assert.equal((await file.lister())[0].statut, STATUTS.EN_ATTENTE);

    // Le bouton « Synchroniser » envoie tout de suite.
    await sync.cycle({ manuel: true });
    assert.equal(envois(), 2, 'manuel : delai ignore');
    assert.equal((await file.lister())[0].statut, STATUTS.SYNCHRONISE);
});

test('passage manuel : un element en echec n\'est tente qu\'une fois par passage', async () => {
    const { file, api, sync } = installer({ reponses: (m) => (m === 'HEAD' ? { status: 200, corps: null } : { status: 503, corps: null }) });
    await file.ajouter(declaration());

    await sync.cycle({ manuel: true });
    await sync.cycle({ manuel: true });

    assert.equal(api.appels.filter((a) => a.methode === 'POST').length, 2, 'un essai par passage manuel');
});

test('panne reseau pendant la verification d\'une piece : on n\'insiste pas, rien n\'est envoye', async () => {
    const { file, api, sync } = installer({ reponses: () => ({ status: 0, corps: null }) });
    await file.ajouter(declaration());

    await sync.cycle();

    assert.deepEqual(api.appels.map((a) => a.methode), ['HEAD']);
    assert.equal((await file.lister())[0].statut, STATUTS.EN_ATTENTE);
});

test('401 : session expiree signalee, file conservee et suspendue ; reconnecte() la fait reprendre', async () => {
    let jeton = false;
    let expirations = 0;
    const { file, api, sync } = installer({
        surSessionExpiree: () => { expirations += 1; },
        reponses: (m) => {
            if (!jeton) return { status: 401, corps: { message: 'Authentification requise.' } };
            return m === 'HEAD' ? { status: 200, corps: null } : { status: 201, corps: { declaration: { id: 3, statut: 'retenue' } } };
        },
    });
    await file.ajouter(declaration());

    const resultat = await sync.cycle();

    assert.equal(resultat.session, false);
    assert.equal(expirations, 1);
    assert.equal(file.estSuspendue(), true);
    assert.equal((await file.lister())[0].statut, STATUTS.EN_ATTENTE);
    const avant = api.appels.length;
    await sync.cycle();
    assert.equal(api.appels.length, avant, 'suspendue : aucun envoi avant la reconnexion');

    jeton = true;
    await sync.reconnecte();

    assert.equal(file.estSuspendue(), false);
    assert.equal((await file.lister())[0].statut, STATUTS.SYNCHRONISE);
});

test('un seul passage a la fois : des appels simultanes n\'envoient pas deux fois', async () => {
    let enVol = 0;
    let maximum = 0;
    const { file, api, sync } = installer({
        reponses: async (m) => {
            enVol += 1;
            maximum = Math.max(maximum, enVol);
            await new Promise((r) => setTimeout(r, 5));
            enVol -= 1;
            return m === 'HEAD' ? { status: 200, corps: null } : { status: 201, corps: { declaration: { id: 1, statut: 'retenue' } } };
        },
    });
    await file.ajouter(declaration());

    const [a, b, c] = [sync.cycle(), sync.cycle(), sync.cycle()];
    assert.equal(sync.enCours(), true);
    await Promise.all([a, b, c]);

    assert.equal(maximum, 1);
    assert.equal(api.appels.filter((x) => x.chemin.endsWith('/declarations')).length, 1);
    assert.equal(sync.enCours(), false);
});

test('un element ajoute pendant un passage est pris par le passage suivant, sans second passage parallele', async () => {
    let debloquer;
    const bloque = new Promise((r) => { debloquer = r; });
    const { file, api, sync } = installer({
        reponses: async (m, chemin) => {
            if (chemin === '/decharges/5/reserve') { await bloque; }
            return { status: 200, corps: null };
        },
    });
    await file.ajouter({ type: 'liberation', id: 'liberation-1', decharge_id: 5 });

    const premier = sync.cycle();
    await new Promise((r) => setTimeout(r, 2));
    await file.ajouter({ type: 'liberation', id: 'liberation-2', decharge_id: 6 });
    sync.cycle();
    debloquer();
    await premier;

    assert.deepEqual(api.appels.filter((a) => a.methode === 'DELETE').map((a) => a.chemin), ['/decharges/5/reserve', '/decharges/6/reserve']);
});

test('redistribution puis liberation : en-tete appareil, ordre respecte, reponse a_arbitrer conservee', async () => {
    const { file, api, sync } = installer({
        reponses: (m, chemin) => {
            if (m === 'POST') return { status: 201, corps: { redistribution: { id: 1, statut: 'a_arbitrer', a_arbitrer: true, raison: 'Dépassement de la réserve.' } } };
            return { status: 200, corps: { reserve: { lignes: [] }, libere: [] } };
        },
    });
    // La libération est ajoutée AVANT la redistribution : elle doit pourtant partir après.
    await file.ajouter({ type: 'liberation', id: 'liberation-1', decharge_id: 5 });
    await file.ajouter({ type: 'redistribution', id: 'cle-redistribution-0001', cle: 'cle-redistribution-0001', decharge_id: 5, horodatage_terminal: '2026-11-06T09:30:00+00:00', lignes: [{ materiel_id: 3, quantite: 10 }] });

    await sync.cycle();

    assert.deepEqual(api.appels.map((a) => `${a.methode} ${a.chemin}`), ['POST /decharges/5/redistributions', 'DELETE /decharges/5/reserve']);
    assert.ok(api.appels.every((a) => a.options.avecAppareil === true));
    assert.deepEqual(api.appels[0].options.json, {
        cle: 'cle-redistribution-0001', horodatage_terminal: '2026-11-06T09:30:00+00:00', lignes: [{ materiel_id: 3, quantite: 10 }],
    });
    const elements = await file.lister();
    assert.equal(elements.find((e) => e.type === 'redistribution').statut, STATUTS.SYNCHRONISE);
    assert.equal(elements.find((e) => e.type === 'redistribution').reponse.redistribution.a_arbitrer, true);
});

test('422 : l\'element passe a verifier avec le message du serveur, jamais renvoye tout seul', async () => {
    const { file, api, sync } = installer({
        reponses: (m) => (m === 'HEAD' ? { status: 200, corps: null } : { status: 422, corps: { message: 'Cette remise n\'attend pas de déclaration.' } }),
    });
    await file.ajouter(declaration());

    await sync.cycle();
    const [e] = await file.lister();
    assert.equal(e.statut, STATUTS.A_VERIFIER);
    assert.equal(e.message, 'Cette remise n\'attend pas de déclaration.');
    const avant = api.appels.length;
    await sync.cycle();
    assert.equal(api.appels.length, avant);
});

test('hors connexion : aucun appel', async () => {
    const { file, api, sync } = installer({ estEnLigne: () => false });
    await file.ajouter(declaration());

    const resultat = await sync.cycle();

    assert.equal(resultat.hors_ligne, true);
    assert.equal(api.appels.length, 0);
});

test('demarrer : envoi au demarrage, sur online, toutes les 60 s ; l\'arret retire tout', async () => {
    const ecouteurs = new Map();
    const fenetre = {
        addEventListener: (n, f) => ecouteurs.set(n, f),
        removeEventListener: (n) => ecouteurs.delete(n),
    };
    const minuteurs = [];
    let passages = 0;
    const { sync } = installer({ telecharger: async () => { passages += 1; return { status: 200 }; } });

    const arreter = sync.demarrer({
        fenetre,
        setIntervalFn: (f, ms) => { minuteurs.push({ f, ms }); return minuteurs.length; },
        clearIntervalFn: (id) => { minuteurs[id - 1].efface = true; },
    });
    await new Promise((r) => setTimeout(r, 5));
    assert.equal(passages, 1, 'au démarrage');
    assert.equal(minuteurs[0].ms, INTERVALLE_MS);
    assert.equal(INTERVALLE_MS, 60_000);

    ecouteurs.get('online')();
    await new Promise((r) => setTimeout(r, 5));
    assert.equal(passages, 2, 'au retour du réseau');
    minuteurs[0].f();
    await new Promise((r) => setTimeout(r, 5));
    assert.equal(passages, 3, 'toutes les minutes');

    arreter();
    assert.equal(ecouteurs.has('online'), false);
    assert.equal(minuteurs[0].efface, true);
});

test('un 401 au telechargement signale aussi la session expiree', async () => {
    let expirations = 0;
    const { sync } = installer({ telecharger: async () => ({ status: 401 }), surSessionExpiree: () => { expirations += 1; } });

    const resultat = await sync.cycle();

    assert.equal(resultat.session, false);
    assert.equal(expirations, 1);
});

/* ───────────── Téléchargement ───────────── */

const elementRemise = (id, extra = {}) => ({
    remise: { id, etape: 'livraison', statut: 'en_attente', de: 'Magasin central', a: 'Sous-préfecture' },
    decharge: { id: 5, identifiant: 'D-5', numero: '5' }, autorite_attendue: { user_id: 7, nom: 'Awa Ba', qualite: 'Préfet', delegues: [] },
    lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 20 }], empreinte_element: 'x', ...extra,
});
const preparation = { remise: null, decharge: { id: 8, identifiant: 'D-8', numero: '8' }, autorite_attendue: { user_id: 7, nom: 'Awa Ba', qualite: 'Préfet', delegues: [] }, lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 30 }], empreinte_element: 'y' };

function bases() {
    const stockees = new Map();
    const reglages = new Map();

    return {
        stockees, reglages,
        elements: { get: async (i) => stockees.get(i), put: async (e) => { stockees.set(e.id, e); }, list: async () => [...stockees.values()], delete: async (i) => { stockees.delete(i); } },
        meta: {
            lire: async (c) => reglages.get(c), ecrire: async (c, v) => { reglages.set(c, v); },
            lister: async (p) => [...reglages].filter(([c]) => c.startsWith(p)).map(([, v]) => v),
        },
    };
}

test('telechargement : affectations, cle de preuve, appareil et reserves de l\'appareil designe', async () => {
    const { elements, meta, stockees, reglages } = bases();
    const file = creerFile({ stockage: memoire() });
    const api = fausseApi((m, chemin) => {
        if (chemin === '/affectations') return { status: 200, corps: { genere_le: 'x', elements: [elementRemise(12), preparation] } };
        if (chemin === '/cle-preuve') return { status: 200, corps: { pem: 'PEM', empreinte: 'e1' } };
        if (chemin === '/appareil') return { status: 200, corps: { designe: true, appareil: { identifiant: 'u', nom: 'T', prefecture: { id: 1, libelle: 'Préfecture' } } } };
        if (chemin === '/decharges/8/reserve') return { status: 200, corps: { reserve: { appareil: 'u', decharge_id: 8, lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 10, quantite_initiale: 10 }] } } };
    });

    const resultat = await telechargerDonnees({ api, elements, file, meta, maintenant: () => '2026-11-05T10:00:00.000Z' });

    assert.deepEqual(resultat, { status: 200, designe: true });
    assert.deepEqual([...stockees.keys()].sort(), ['preparation-8', 'remise-12']);
    assert.deepEqual(reglages.get('cle_preuve'), { pem: 'PEM', empreinte: 'e1' });
    assert.equal(reglages.get('appareil_designe').designe, true);
    assert.equal(reglages.get('reserve:8').lignes[0].quantite, 10);
    assert.equal(reglages.get('derniere_sync'), '2026-11-05T10:00:00.000Z');
    const appareil = api.appels.find((a) => a.chemin === '/appareil');
    assert.equal(appareil.options.avecAppareil, true);
    assert.equal(idElement(elementRemise(12)), 'remise-12');
    assert.equal(idElement(preparation), 'preparation-8');
});

test('telechargement : appareil non designe, aucune reserve demandee', async () => {
    const { elements, meta } = bases();
    const api = fausseApi((m, chemin) => {
        if (chemin === '/affectations') return { status: 200, corps: { elements: [preparation] } };
        if (chemin === '/appareil') return { status: 200, corps: { designe: false, appareil: null } };
        return { status: 503, corps: null };
    });

    const resultat = await telechargerDonnees({ api, elements, file: creerFile({ stockage: memoire() }), meta });

    assert.equal(resultat.designe, false);
    assert.equal(api.appels.some((a) => a.chemin.includes('/reserve')), false);
});

test('telechargement : un element encore attendu par la file n\'est jamais supprime ; les autres disparus le sont', async () => {
    const { elements, meta, stockees } = bases();
    const file = creerFile({ stockage: memoire() });
    await file.ajouter(declaration({ remise_id: 12, pieces: [] }));
    stockees.set('remise-12', { id: 'remise-12' });
    stockees.set('remise-13', { id: 'remise-13' });
    const api = fausseApi((m, chemin) => (chemin === '/affectations' ? { status: 200, corps: { elements: [elementRemise(14)] } } : { status: 503, corps: null }));

    await telechargerDonnees({ api, elements, file, meta });

    assert.deepEqual([...stockees.keys()].sort(), ['remise-12', 'remise-14']);
});

test('telechargement : 401 et pannes laissent les donnees precedentes', async () => {
    const { elements, meta, stockees, reglages } = bases();
    stockees.set('remise-1', { id: 'remise-1' });
    reglages.set('cle_preuve', { pem: 'ANCIENNE' });

    for (const status of [401, 0, 503]) {
        const api = fausseApi(() => ({ status, corps: null }));
        const resultat = await telechargerDonnees({ api, elements, file: creerFile({ stockage: memoire() }), meta });
        assert.equal(resultat.status, status);
    }
    assert.deepEqual([...stockees.keys()], ['remise-1']);
    assert.deepEqual(reglages.get('cle_preuve'), { pem: 'ANCIENNE' });
});

test('les pieces d\'une declaration envoyee sont liberees, sauf celle restee a verifier', async () => {
    const pieces = new Map([[EMPREINTE_A, { blob: 1 }], [EMPREINTE_B, { blob: 2 }]]);
    const { file, sync } = installer({
        pieces,
        reponses: (m, chemin) => {
            if (m === 'HEAD') return { status: 200, corps: null };
            if (chemin === '/pieces') return { status: 422, corps: { message: 'Le fichier doit être une image JPEG ou PNG.' } };
            return { status: 201, corps: { declaration: { id: 1, statut: 'retenue' } } };
        },
    });
    // La pièce B est refusée (a_verifier) : ses octets restent. La pièce A est déjà chez le serveur.
    await file.ajouter({ type: 'piece', id: EMPREINTE_A, empreinte: EMPREINTE_A, role: 'signature' });
    await file.ajouter(declaration({ pieces: [{ empreinte: EMPREINTE_A, role: 'signature' }, { empreinte: EMPREINTE_B, role: 'photo' }] }));
    await file.ajouter({ type: 'piece', id: EMPREINTE_B, empreinte: EMPREINTE_B, role: 'photo' });
    await file.marquer(EMPREINTE_B, { status: 422, corps: { message: 'refusée' } });

    await sync.cycle();

    assert.equal(pieces.has(EMPREINTE_A), false);
    assert.equal(pieces.has(EMPREINTE_B), true);
});

test('une redistribution enregistree diminue la reserve gardee sur la tablette ; une liberation la vide', async () => {
    const reglages = new Map([['reserve:5', { decharge_id: 5, lignes: [{ materiel_id: 3, quantite: 25 }, { materiel_id: 4, quantite: 5 }] }]]);
    const meta = { lire: async (c) => reglages.get(c), ecrire: async (c, v) => { reglages.set(c, v); } };
    const { file, sync } = installer({ meta, reponses: (m) => (m === 'POST' ? { status: 201, corps: { redistribution: { statut: 'en_attente', a_arbitrer: false } } } : { status: 200, corps: null }) });
    await file.ajouter({ type: 'redistribution', id: 'cle-redistribution-0001', cle: 'cle-redistribution-0001', decharge_id: 5, horodatage_terminal: '2026-11-06T09:30:00+00:00', lignes: [{ materiel_id: 3, quantite: 10 }] });
    await file.ajouter({ type: 'redistribution', id: 'cle-redistribution-0002', cle: 'cle-redistribution-0002', decharge_id: 5, horodatage_terminal: '2026-11-06T09:31:00+00:00', lignes: [{ materiel_id: 3, quantite: 20 }, { materiel_id: 4, quantite: 1 }] });

    await sync.cycle();
    assert.deepEqual(reglages.get('reserve:5').lignes, [{ materiel_id: 3, quantite: 0 }, { materiel_id: 4, quantite: 4 }], 'plancher à zéro');

    await file.ajouter({ type: 'liberation', id: 'liberation-1', decharge_id: 5 });
    await sync.cycle();
    assert.deepEqual(reglages.get('reserve:5').lignes, []);
});

test('une redistribution a arbitrer ne diminue pas la reserve (rien n\'a ete compte) ; refus 422 non plus', async () => {
    const reglages = new Map([['reserve:5', { decharge_id: 5, lignes: [{ materiel_id: 3, quantite: 25 }] }]]);
    const meta = { lire: async (c) => reglages.get(c), ecrire: async (c, v) => { reglages.set(c, v); } };
    let reponse = { status: 201, corps: { redistribution: { statut: 'a_arbitrer', a_arbitrer: true } } };
    const { file, sync } = installer({ meta, reponses: () => reponse });
    await file.ajouter({ type: 'redistribution', id: 'cle-redistribution-0001', cle: 'cle-redistribution-0001', decharge_id: 5, horodatage_terminal: '2026-11-06T09:30:00+00:00', lignes: [{ materiel_id: 3, quantite: 10 }] });
    await sync.cycle();
    reponse = { status: 422, corps: { message: 'refus' } };
    await file.ajouter({ type: 'redistribution', id: 'cle-redistribution-0002', cle: 'cle-redistribution-0002', decharge_id: 5, horodatage_terminal: '2026-11-06T09:31:00+00:00', lignes: [{ materiel_id: 3, quantite: 10 }] });
    await sync.cycle();

    assert.equal(reglages.get('reserve:5').lignes[0].quantite, 25);
});

/** Signature A et photo B en file avant leur déclaration ; `echec` répond au premier dépôt de la pièce visée. */
function pieceInterrompue({ visee = EMPREINTE_B, echec }) {
    const octets = (n) => new Blob([new Uint8Array([n])], { type: 'image/png' });
    let premierDepot = true;
    const recus = new Set();

    return installer({
        pieces: new Map([[EMPREINTE_A, { empreinte: EMPREINTE_A, blob: octets(1) }], [EMPREINTE_B, { empreinte: EMPREINTE_B, blob: octets(2) }]]),
        reponses: (m, chemin, options) => {
            if (m === 'HEAD') return { status: recus.has(chemin.slice('/pieces/'.length)) ? 200 : 404, corps: null };
            if (chemin === '/pieces') {
                const empreinte = options.formData.get('empreinte');
                if (empreinte === visee && premierDepot) {
                    premierDepot = false;

                    return { status: echec, corps: null };
                }
                recus.add(empreinte);

                return { status: 201, corps: { piece: { empreinte } } };
            }

            return { status: 201, corps: { declaration: { id: 41, statut: 'retenue', verification_identite: 'verifiee_code', contestation: 'aucune' } } };
        },
    });
}

async function mettreEnFile(file) {
    await file.ajouter({ type: 'piece', id: EMPREINTE_A, empreinte: EMPREINTE_A, role: 'signature' });
    await file.ajouter({ type: 'piece', id: EMPREINTE_B, empreinte: EMPREINTE_B, role: 'photo' });
    const d = declaration({ pieces: [{ empreinte: EMPREINTE_A, role: 'signature' }, { empreinte: EMPREINTE_B, role: 'photo' }] });
    await file.ajouter(d);

    return d;
}

test('photo interrompue (reseau, 408, 429, 5xx) : la declaration est enregistree sans l\'attendre, la photo part ensuite', async () => {
    for (const echec of [0, 408, 429, 503]) {
        const { file, api, sync, pieces, avancer } = pieceInterrompue({ echec });
        const d = await mettreEnFile(file);

        await sync.cycle();

        const chemins = api.appels.map((a) => `${a.methode} ${a.chemin}`);
        assert.deepEqual(chemins, [
            `HEAD /pieces/${EMPREINTE_A}`, 'POST /pieces', // signature déposée
            `HEAD /pieces/${EMPREINTE_B}`, 'POST /pieces', // photo interrompue
            `HEAD /pieces/${EMPREINTE_A}`, 'POST /remises/12/declarations', // la déclaration part sans attendre la photo
        ], `status ${echec}`);
        assert.deepEqual(api.appels.at(-1).options.json, corpsDeclaration(d), 'même corps : la photo y est annoncée, « attendue » côté serveur');
        let [signature, photo, envoyee] = await file.lister();
        assert.equal(envoyee.statut, STATUTS.SYNCHRONISE);
        assert.equal(signature.statut, STATUTS.SYNCHRONISE);
        assert.equal(photo.statut, STATUTS.EN_ATTENTE, 'la photo reste en file');
        assert.equal(pieces.has(EMPREINTE_A), false);
        assert.equal(pieces.has(EMPREINTE_B), true, 'ses octets restent sur la tablette');

        // Passage suivant, après le délai de réessai : la photo part et ses octets sont libérés.
        api.appels.length = 0;
        avancer(31_000);
        await sync.cycle();

        assert.deepEqual(api.appels.map((a) => `${a.methode} ${a.chemin}`), [`HEAD /pieces/${EMPREINTE_B}`, 'POST /pieces']);
        [signature, photo, envoyee] = await file.lister();
        assert.equal(photo.statut, STATUTS.SYNCHRONISE);
        assert.equal(pieces.has(EMPREINTE_B), false);
        assert.equal(envoyee.tentatives, 1, 'la déclaration n\'est jamais renvoyée');
    }
});

test('signature interrompue : elle ne bloque pas non plus la declaration (son empreinte est dans la declaration)', async () => {
    const { file, api, sync, pieces, avancer } = pieceInterrompue({ visee: EMPREINTE_A, echec: 503 });
    const d = await mettreEnFile(file);

    await sync.cycle();

    assert.deepEqual(api.appels.map((a) => `${a.methode} ${a.chemin}`), [
        `HEAD /pieces/${EMPREINTE_A}`, 'POST /pieces',
        `HEAD /pieces/${EMPREINTE_B}`, 'POST /pieces',
        `HEAD /pieces/${EMPREINTE_B}`, 'POST /remises/12/declarations',
    ]);
    assert.deepEqual(api.appels.at(-1).options.json.pieces, corpsDeclaration(d).pieces);
    assert.equal((await file.lister())[0].statut, STATUTS.EN_ATTENTE);
    assert.equal(pieces.has(EMPREINTE_A), true);

    avancer(31_000);
    await sync.cycle();
    assert.equal((await file.lister())[0].statut, STATUTS.SYNCHRONISE);
    assert.equal(pieces.has(EMPREINTE_A), false);
});

test('passage manuel : la photo en echec est tentee une fois, la declaration part dans le meme passage', async () => {
    const { file, api, sync, pieces } = pieceInterrompue({ echec: 503 });
    await mettreEnFile(file);

    await sync.cycle({ manuel: true });

    assert.equal(api.appels.filter((a) => a.chemin === '/pieces' && a.options.formData.get('empreinte') === EMPREINTE_B).length, 1);
    assert.equal((await file.lister())[2].statut, STATUTS.SYNCHRONISE);

    // Un nouveau passage manuel reprend la photo tout de suite (délai de réessai ignoré).
    await sync.cycle({ manuel: true });
    assert.equal((await file.lister())[1].statut, STATUTS.SYNCHRONISE);
    assert.equal(pieces.has(EMPREINTE_B), false);
});

test('piece hors file renvoyee avec la declaration et interrompue : elle est mise en file, la declaration part', async () => {
    const { file, api, sync, pieces, avancer } = pieceInterrompue({ echec: 0 });
    const d = declaration({ pieces: [{ empreinte: EMPREINTE_B, role: 'photo' }] });
    await file.ajouter(d);

    await sync.cycle();

    assert.deepEqual(api.appels.map((a) => `${a.methode} ${a.chemin}`), [`HEAD /pieces/${EMPREINTE_B}`, 'POST /pieces', 'POST /remises/12/declarations']);
    const photo = (await file.lister()).find((e) => e.type === 'piece');
    assert.equal(photo.empreinte, EMPREINTE_B);
    assert.equal(photo.statut, STATUTS.EN_ATTENTE);
    assert.equal(pieces.has(EMPREINTE_B), true);

    avancer(31_000);
    await sync.cycle();
    assert.equal((await file.lister()).find((e) => e.type === 'piece').statut, STATUTS.SYNCHRONISE);
    assert.equal(pieces.has(EMPREINTE_B), false);
});

test('piece refusee (4xx hors 408 et 429) : a verifier, octets gardes ; la declaration part comme avant', async () => {
    const { file, api, sync, pieces } = pieceInterrompue({ echec: 422 });
    await mettreEnFile(file);
    // Toujours refusée.
    const repondre = api.envoyer.bind(api);
    api.envoyer = async (m, chemin, options = {}) => {
        if (chemin === '/pieces' && options.formData.get('empreinte') === EMPREINTE_B) {
            api.appels.push({ methode: m, chemin, options });

            return { status: 422, corps: { message: 'Le fichier doit être une image JPEG ou PNG.' } };
        }

        return repondre(m, chemin, options);
    };

    await sync.cycle();

    const [, photo, envoyee] = await file.lister();
    assert.equal(photo.statut, STATUTS.A_VERIFIER);
    assert.equal(photo.message, 'Le fichier doit être une image JPEG ou PNG.');
    assert.equal(envoyee.statut, STATUTS.SYNCHRONISE);
    assert.equal(pieces.has(EMPREINTE_B), true);
});
