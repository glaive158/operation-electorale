import assert from 'node:assert/strict';
import { test } from 'node:test';

import { empreinte } from '../../resources/js/terrain/canonique.js';
import { creerFile, STATUTS } from '../../resources/js/terrain/file.js';
import { contenuPreuveRestitution } from '../../resources/js/terrain/preuve.js';
import {
    ecranRestitution, ecranRestitutions, etatRestitution, idPriseEnCharge, MESSAGE_LECTURE_SEULE, prendreEnCharge,
} from '../../resources/js/terrain/restitution.js';
import { creerStockage, ouvrirBase } from '../../resources/js/terrain/stockage.js';
import { corpsDeclarationRestitution, creerSync, telechargerDonnees } from '../../resources/js/terrain/sync.js';
import { creerFauxDocument, parAria, parId, parTexte, trouverTous } from './support/faux-dom.js';
import { creerFauxIndexedDB } from './support/faux-indexeddb.js';

globalThis.document = creerFauxDocument();
globalThis.window = { confirm: () => true };

const CODE = '482915';
const REFUS_ARBITRAGE = 'Une déclaration de restitution est à vérifier : le responsable doit arbitrer avant la prise en charge.';

async function paireDeCles() {
    const paire = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-1' }, true, ['encrypt', 'decrypt']);
    const spki = Buffer.from(await crypto.subtle.exportKey('spki', paire.publicKey)).toString('base64');

    return { ...paire, pem: `-----BEGIN PUBLIC KEY-----\n${spki.match(/.{1,64}/g).join('\n')}\n-----END PUBLIC KEY-----\n` };
}

const elementRestitution = (surcharge = {}) => ({
    id: 41,
    statut: 'a_preparer',
    cycle: 'Présidentielle 2027',
    detenteur: { type: 'destinataire', id: 18, nom: 'Sous-préfecture de Sindia' },
    autorite_attendue: { user_id: 7, nom: ' Awa BA ', qualite: 'Sous-préfète', delegues: [{ user_id: 9, nom: 'Moussa Fall', qualite: 'Sous-préfet' }] },
    lignes: [{ materiel_id: 5, libelle: 'Isoloirs', attendu: 4 }, { materiel_id: 3, libelle: 'Urnes', attendu: 100 }],
    declaration_retenue: false,
    arbitrage_en_attente: false,
    peut_declarer_tablette: true,
    peut_prendre_en_charge: false,
    empreinte_element: 'x',
    ...surcharge,
});

async function contexte({ avecCle = true, enLigne = true, restitutions = [elementRestitution()] } = {}) {
    const paire = await paireDeCles();
    const indexedDB = creerFauxIndexedDB();
    const stockage = creerStockage(await ouvrirBase({ indexedDB }));
    if (avecCle) await stockage.meta.ecrire('cle_preuve', { pem: paire.pem, empreinte: 'e' });
    await stockage.meta.ecrire('restitutions', restitutions);
    const file = creerFile({ stockage: stockage.file });
    const appelsApi = [];
    const cycles = [];
    const reponsesApi = [];
    const signalements = [];

    return {
        etat: { session: { utilisateur: { id: 3, nom: 'Agent de retour' } }, appareil: 'uuid-tablette', enLigne },
        stockage, file, paire, appelsApi, cycles, indexedDB, reponsesApi, signalements,
        nettoyages: [],
        api: { envoyer: async (m, c, o) => { appelsApi.push({ m, c, o }); return reponsesApi.shift() ?? { status: 200, corps: null }; } },
        sync: { cycle: async () => { cycles.push(1); return {}; } },
        rafraichir: async () => {}, rafraichirEntete: async () => {}, signaler(texte) { signalements.push(texte); }, coquilleEnCache: async () => true,
    };
}

const saisir = (champ, valeur) => { champ.value = valeur; };
const tracer = async (canvas) => {
    await canvas.emettre('pointerdown', { clientX: 20, clientY: 20, pointerId: 1 });
    await canvas.emettre('pointermove', { clientX: 220, clientY: 90, pointerId: 1 });
    await canvas.emettre('pointerup', { clientX: 400, clientY: 20, pointerId: 1 });
};
const texte = (noeud) => noeud.textContent;

async function passerALaSignature(racine) {
    saisir(parAria(racine, 'Rendu : Urnes'), '90');
    saisir(parAria(racine, 'Observation : Urnes'), '  10 urnes perdues  ');
    await parTexte(racine, 'button', 'Continuer').click();
}

test('restitution : quantites rendues, signature, code chiffre puis efface, declaration mise en file avec le bloc', async () => {
    const ctx = await contexte();
    const racine = await ecranRestitution(ctx, '41');

    assert.match(texte(racine), /Sous-préfecture de Sindia/);
    assert.match(texte(racine), /Matériel rendu/);
    await passerALaSignature(racine);

    assert.match(texte(parId(racine, 'signataire-attendu')), /Nom attendu : +Awa BA/);
    const champCode = parId(racine, 'restitution-code');
    assert.equal(champCode.getAttribute('type'), 'password');
    assert.equal(champCode.getAttribute('inputmode'), 'numeric');
    assert.equal(champCode.getAttribute('autocomplete'), 'off');
    const photo = trouverTous(racine, (e) => e.tagName === 'INPUT' && e.getAttribute('type') === 'file')[0];
    assert.equal(photo.getAttribute('accept'), 'image/*');

    saisir(champCode, CODE);
    await tracer(parAria(racine, 'Zone de signature'));
    await parTexte(racine, 'button', 'Valider la restitution').click();

    assert.equal(champCode.value, '');
    const elements = await ctx.file.lister();
    const piece = elements.find((e) => e.type === 'piece');
    const declaration = elements.find((e) => e.type === 'declaration_restitution');
    assert.equal(piece.role, 'signature');
    assert.equal(declaration.statut, STATUTS.EN_ATTENTE);
    assert.equal(declaration.restitution_id, 41);
    assert.equal(declaration.utilisateur_id, 3);
    assert.match(declaration.cle, /^[A-Za-z0-9-]{16,64}$/);
    assert.match(declaration.horodatage_terminal, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
    assert.deepEqual(declaration.signataire, { user_id: 7, nom: 'Awa BA', qualite: 'Sous-préfète' });
    assert.deepEqual(declaration.lignes, [
        { materiel_id: 3, restitue: 90, observation: '10 urnes perdues' },
        { materiel_id: 5, restitue: 4, observation: null },
    ]);
    assert.deepEqual(declaration.pieces, [{ empreinte: piece.empreinte, role: 'signature' }]);
    assert.equal(ctx.cycles.length, 1, 'un envoi est lancé');
    assert.match(texte(racine), /Restitution enregistrée/);

    // Le bloc déchiffré atteste exactement le contenu que le serveur recalcule (PreuveService::contenuPreuveRestitution).
    const clair = JSON.parse(Buffer.from(await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, ctx.paire.privateKey, Buffer.from(declaration.bloc_preuve, 'base64'))).toString());
    const contenu = contenuPreuveRestitution({
        restitution_id: 41, cle: declaration.cle, signataire: { nom: 'Awa BA', qualite: 'Sous-préfète' }, signataire_user_id: 7,
        lignes: declaration.lignes, horodatage_terminal: declaration.horodatage_terminal,
    });
    assert.deepEqual(clair, { cle: declaration.cle, code: CODE, empreinte: await empreinte(contenu) });

    // Le corps envoyé reprend le contenu attesté, sans position.
    const corps = corpsDeclarationRestitution(declaration);
    assert.deepEqual(corps, {
        mode: 'tablette', cle: declaration.cle, horodatage_terminal: declaration.horodatage_terminal,
        signataire: declaration.signataire, lignes: contenu.lignes, bloc_preuve: declaration.bloc_preuve,
        pieces: [{ empreinte: piece.empreinte, role: 'signature' }],
    });

    // Aucune trace du code dans le stockage ni à l'écran.
    const tout = JSON.stringify([...ctx.indexedDB.bases.values()].map((b) => [...b.magasins.values()].map((m) => [...m.donnees.values()])));
    assert.equal(tout.includes(CODE), false);
    assert.equal(texte(racine).includes(CODE), false);

    // Un second passage sur la même restitution montre la déclaration enregistrée, sans nouveau formulaire.
    const encore = await ecranRestitution(ctx, '41');
    assert.equal(parId(encore, 'restitution-code'), null);
    assert.match(texte(encore), /En attente d'envoi/);
});

test('restitution : validations, retour et changement d\'ecran effacent le code', async () => {
    const ctx = await contexte();
    const racine = await ecranRestitution(ctx, '41');

    saisir(parAria(racine, 'Rendu : Urnes'), '-2');
    await parTexte(racine, 'button', 'Continuer').click();
    assert.match(texte(racine), /Quantité invalide pour « Urnes »/);
    assert.equal(parId(racine, 'restitution-code'), null);

    saisir(parAria(racine, 'Rendu : Urnes'), '100');
    await parTexte(racine, 'button', 'Continuer').click();
    const champCode = parId(racine, 'restitution-code');
    saisir(champCode, '12');
    await parTexte(racine, 'button', 'Valider la restitution').click();
    assert.match(texte(racine), /Le code personnel comporte 6 chiffres/);
    saisir(champCode, CODE);
    await parTexte(racine, 'button', 'Valider la restitution').click();
    assert.match(texte(racine), /La signature est obligatoire/);
    assert.equal(champCode.value, '');

    saisir(champCode, CODE);
    await parTexte(racine, 'button', 'Retour').click();
    assert.equal(champCode.value, '', 'Retour efface le code');
    await parTexte(racine, 'button', 'Continuer').click();
    const nouveau = parId(racine, 'restitution-code');
    saisir(nouveau, CODE);
    for (const nettoyer of ctx.nettoyages.splice(0)) nettoyer();
    assert.equal(nouveau.value, '', 'un changement d\'écran efface le code');
    assert.equal((await ctx.file.lister()).length, 0);
});

test('restitution : repli sans code, aucun bloc, identite non verifiee', async () => {
    const ctx = await contexte();
    const racine = await ecranRestitution(ctx, '41');
    await passerALaSignature(racine);
    await tracer(parAria(racine, 'Zone de signature'));
    await parTexte(racine, 'button', 'Signer sans code (identité non vérifiée)').click();

    const declaration = (await ctx.file.lister()).find((e) => e.type === 'declaration_restitution');
    assert.equal(declaration.bloc_preuve, null);
    assert.equal(declaration.repli, true);
    assert.deepEqual(declaration.signataire, { user_id: 7, nom: 'Awa BA', qualite: 'Sous-préfète' });
    assert.match(texte(racine), /identité non vérifiée/);
});

test('restitution : sans cle de preuve, rien n\'est enregistre', async () => {
    const ctx = await contexte({ avecCle: false });
    const racine = await ecranRestitution(ctx, '41');
    await passerALaSignature(racine);
    saisir(parId(racine, 'restitution-code'), CODE);
    await tracer(parAria(racine, 'Zone de signature'));
    await parTexte(racine, 'button', 'Valider la restitution').click();

    assert.match(texte(racine), /Clé de preuve non téléchargée/);
    assert.equal(parId(racine, 'restitution-code').value, '');
    assert.equal((await ctx.file.lister()).length, 0);
});

test('restitution : lecture seule quand la tablette ne peut pas la declarer', async () => {
    const ctx = await contexte({ restitutions: [elementRestitution({ peut_declarer_tablette: false })] });

    const ecran = await ecranRestitution(ctx, '41');
    assert.match(texte(ecran), new RegExp(MESSAGE_LECTURE_SEULE));
    assert.equal(MESSAGE_LECTURE_SEULE, 'À déclarer depuis le compte du détenteur');
    assert.equal(parTexte(ecran, 'button', 'Continuer'), null);
    assert.equal(parId(ecran, 'restitution-code'), null);

    const liste = await ecranRestitutions(ctx);
    assert.match(texte(liste), /À déclarer depuis le compte du détenteur/);
    assert.equal(trouverTous(liste, (e) => e.tagName === 'A' && e.getAttribute('href') === '#/restitution/41' && e.textContent === 'Déclarer').length, 0);
});

test('restitution : liste, declaration a faire puis refus du serveur affiche avec Renvoyer', async () => {
    const ctx = await contexte({ restitutions: [elementRestitution(), elementRestitution({ id: 42, detenteur: { type: 'destinataire', id: 19, nom: 'Préfecture de Thiès' } })] });

    let liste = await ecranRestitutions(ctx);
    assert.match(texte(liste), /Sous-préfecture de Sindia/);
    assert.match(texte(liste), /Préfecture de Thiès/);
    assert.ok(trouverTous(liste, (e) => e.tagName === 'A' && e.getAttribute('href') === '#/restitution/41').length);

    await ctx.file.ajouter({
        type: 'declaration_restitution', id: 'cle-restitution-000001', cle: 'cle-restitution-000001', restitution_id: 41,
        horodatage_terminal: '2026-11-20T09:30:00+00:00', bloc_preuve: 'QkxPQw==', pieces: [], utilisateur_id: 3,
        signataire: { user_id: 7, nom: 'Awa BA', qualite: 'Sous-préfète' }, lignes: [{ materiel_id: 3, restitue: 90, observation: null }],
    });
    await ctx.file.marquer('cle-restitution-000001', { status: 422, corps: { message: 'La restitution est déjà prise en charge.' } });

    liste = await ecranRestitutions(ctx);
    assert.match(texte(liste), /La restitution est déjà prise en charge\./);
    const renvoyer = parTexte(liste, 'button', 'Renvoyer');
    assert.ok(renvoyer);
    await renvoyer.click();
    assert.equal((await ctx.file.lister())[0].statut, STATUTS.EN_ATTENTE);
    assert.equal(ctx.cycles.length, 1);
});

test('prise en charge : en ligne, appel direct puis retour pris en charge', async () => {
    const ctx = await contexte({ restitutions: [elementRestitution({ declaration_retenue: true, peut_prendre_en_charge: true })] });
    ctx.reponsesApi.push({ status: 200, corps: { restitution: { id: 41, statut: 'en_retour', agent_id: 3 } } });

    const liste = await ecranRestitutions(ctx);
    await parTexte(liste, 'button', 'Prise en charge').click();

    assert.deepEqual(ctx.appelsApi.map((a) => [a.m, a.c]), [['POST', '/restitutions/41/prise-en-charge']]);
    const [element] = await ctx.file.lister();
    assert.equal(element.id, idPriseEnCharge(41));
    assert.equal(element.type, 'prise_en_charge');
    assert.equal(element.statut, STATUTS.SYNCHRONISE);

    const apres = await ecranRestitutions(ctx);
    assert.match(texte(apres), /Retour pris en charge/);
    assert.equal(parTexte(apres, 'button', 'Prise en charge'), null);
});

test('prise en charge : refus du serveur pendant un arbitrage, message affiche et rien en file', async () => {
    const ctx = await contexte({ restitutions: [elementRestitution({ declaration_retenue: true, peut_prendre_en_charge: true })] });
    ctx.reponsesApi.push({ status: 422, corps: { message: REFUS_ARBITRAGE } });

    const resultat = await prendreEnCharge(ctx, elementRestitution({ declaration_retenue: true, peut_prendre_en_charge: true }));

    assert.equal(resultat.etat, 'refusee');
    assert.equal(resultat.message, REFUS_ARBITRAGE);
    assert.deepEqual(await ctx.file.lister(), []);

    ctx.reponsesApi.push({ status: 422, corps: { message: REFUS_ARBITRAGE } });
    const liste = await ecranRestitutions(ctx);
    await parTexte(liste, 'button', 'Prise en charge').click();
    assert.match(texte(liste), /Une déclaration de restitution est à vérifier : /);
});

test('prise en charge : hors connexion ou sans reponse, mise en file et envoyee plus tard', async () => {
    const ctx = await contexte({ enLigne: false, restitutions: [elementRestitution({ declaration_retenue: true, peut_prendre_en_charge: true })] });

    const resultat = await prendreEnCharge(ctx, elementRestitution({ peut_prendre_en_charge: true }));
    assert.equal(resultat.etat, 'en_attente');
    assert.equal(ctx.appelsApi.length, 0, 'aucun appel hors connexion');
    const [element] = await ctx.file.lister();
    assert.deepEqual([element.type, element.restitution_id, element.statut, element.utilisateur_id], ['prise_en_charge', 41, STATUTS.EN_ATTENTE, 3]);
    assert.match(texte(await ecranRestitutions(ctx)), /En attente d'envoi/);

    // En ligne mais sans réponse du serveur : même chose.
    const autre = await contexte({ restitutions: [elementRestitution({ peut_prendre_en_charge: true })] });
    autre.reponsesApi.push({ status: 0, corps: null });
    assert.equal((await prendreEnCharge(autre, elementRestitution({ peut_prendre_en_charge: true }))).etat, 'en_attente');
    assert.equal((await autre.file.lister())[0].statut, STATUTS.EN_ATTENTE);
});

test('prise en charge : proposee des que la declaration de la tablette est retenue par le serveur', async () => {
    const ctx = await contexte();
    await ctx.file.ajouter({
        type: 'declaration_restitution', id: 'cle-restitution-000002', cle: 'cle-restitution-000002', restitution_id: 41,
        horodatage_terminal: '2026-11-20T09:30:00+00:00', bloc_preuve: 'QkxPQw==', pieces: [], utilisateur_id: 3,
        signataire: { user_id: 7, nom: 'Awa BA', qualite: 'Sous-préfète' }, lignes: [{ materiel_id: 3, restitue: 90, observation: null }],
    });
    let etat = etatRestitution(elementRestitution(), await ctx.file.lister(), 3);
    assert.equal(etat.peutPrendreEnCharge, false, 'pas avant l\'envoi de la déclaration');

    await ctx.file.marquer('cle-restitution-000002', { status: 201, corps: { declaration: { id: 8, statut: 'retenue', verification_identite: 'verifiee_code', anomalies: [] } } });
    etat = etatRestitution(elementRestitution(), await ctx.file.lister(), 3);
    assert.equal(etat.peutPrendreEnCharge, true);
    assert.ok(parTexte(await ecranRestitutions(ctx), 'button', 'Prise en charge'));

    // Une déclaration enregistrée « à vérifier » attend l'arbitrage : pas de prise en charge proposée.
    await ctx.stockage.file.put({ ...(await ctx.file.lister())[0], reponse: { declaration: { id: 8, statut: 'a_verifier' } } });
    assert.equal(etatRestitution(elementRestitution(), await ctx.file.lister(), 3).peutPrendreEnCharge, false);
});

/* ───────────── File et synchronisation ───────────── */

function memoire() {
    const donnees = new Map();

    return {
        get: async (id) => (donnees.has(id) ? structuredClone(donnees.get(id)) : undefined),
        put: async (e) => { donnees.set(e.id, structuredClone(e)); },
        list: async () => [...donnees.values()].map((e) => structuredClone(e)),
        delete: async (id) => { donnees.delete(id); },
    };
}

const EMPREINTE = 'c'.repeat(64);
const declarationRestitution = (surcharge = {}) => ({
    type: 'declaration_restitution', id: 'cle-restitution-000003', cle: 'cle-restitution-000003', restitution_id: 41,
    horodatage_terminal: '2026-11-20T09:30:00+00:00', bloc_preuve: 'QkxPQw==', pieces: [{ empreinte: EMPREINTE, role: 'signature' }],
    signataire: { user_id: 7, nom: 'Awa BA', qualite: 'Sous-préfète' }, lignes: [{ materiel_id: 3, restitue: 90, observation: null }],
    ...surcharge,
});

test('file : la declaration de restitution attend ses pieces, la prise en charge attend la declaration', async () => {
    const file = creerFile({ stockage: memoire() });
    await assert.rejects(file.ajouter({ type: 'declaration_restitution', cle: 'cle-restitution-000004', horodatage_terminal: '2026-11-20T09:30:00+00:00', bloc_preuve: null }), /Restitution manquante/);
    await assert.rejects(file.ajouter({ type: 'prise_en_charge', id: 'prise-en-charge-x' }), /Restitution manquante/);

    await file.ajouter({ type: 'piece', empreinte: EMPREINTE, role: 'signature' });
    await file.ajouter(declarationRestitution());
    await file.ajouter({ type: 'prise_en_charge', id: idPriseEnCharge(41), restitution_id: 41 });

    assert.equal((await file.prochain()).type, 'piece');
    await file.marquer(EMPREINTE, { status: 201 });
    assert.equal((await file.prochain()).type, 'declaration_restitution');
    await file.marquer('cle-restitution-000003', { status: 201, corps: { declaration: { id: 8, statut: 'retenue' } } });
    assert.equal((await file.prochain()).type, 'prise_en_charge');
});

test('sync : declaration de restitution et prise en charge partent vers leurs routes', async () => {
    const file = creerFile({ stockage: memoire() });
    const appels = [];
    const api = {
        async envoyer(methode, chemin, options = {}) {
            appels.push({ methode, chemin, options });
            if (methode === 'HEAD') return { status: 200, corps: null };
            if (chemin.endsWith('/declarations')) return { status: 201, corps: { declaration: { id: 8, statut: 'retenue', verification_identite: 'verifiee_code', anomalies: [] } } };
            if (chemin.endsWith('/prise-en-charge')) return { status: 422, corps: { message: REFUS_ARBITRAGE } };

            return { status: 200, corps: null };
        },
    };
    const supprimees = [];
    const sync = creerSync({ file, api, pieces: { lire: async () => undefined, supprimer: async (e) => { supprimees.push(e); } } });
    await file.ajouter(declarationRestitution());
    await file.ajouter({ type: 'prise_en_charge', id: idPriseEnCharge(41), restitution_id: 41 });

    await sync.cycle();

    const posts = appels.filter((a) => a.methode === 'POST');
    assert.deepEqual(posts.map((a) => a.chemin), ['/restitutions/41/declarations', '/restitutions/41/prise-en-charge']);
    assert.deepEqual(posts[0].options.json, corpsDeclarationRestitution(declarationRestitution()));
    assert.equal(posts[0].options.json.gps, undefined);
    const [declaration, prise] = await file.lister();
    assert.equal(declaration.statut, STATUTS.SYNCHRONISE);
    assert.deepEqual(supprimees, [EMPREINTE], 'les octets de la signature ne servent plus');
    assert.equal(prise.statut, STATUTS.A_VERIFIER);
    assert.equal(prise.message, REFUS_ARBITRAGE);
});

test('telechargement : les restitutions sont rangees, celles que la file attend sont gardees', async () => {
    const indexedDB = creerFauxIndexedDB();
    const stockage = creerStockage(await ouvrirBase({ indexedDB }));
    const file = creerFile({ stockage: memoire() });
    await stockage.meta.ecrire('restitutions', [elementRestitution({ id: 40 }), elementRestitution({ id: 39 })]);
    await file.ajouter(declarationRestitution({ restitution_id: 40, pieces: [] }));
    const api = {
        async envoyer(methode, chemin) {
            if (chemin === '/affectations') return { status: 200, corps: { genere_le: 'x', elements: [], restitutions: [elementRestitution()] } };

            return { status: 404, corps: null };
        },
    };

    const resultat = await telechargerDonnees({ api, elements: stockage.elements, file, meta: stockage.meta, maintenant: () => '2026-11-20T10:00:00Z' });

    assert.equal(resultat.status, 200);
    const rangees = await stockage.meta.lire('restitutions');
    assert.deepEqual(rangees.map((r) => r.id).sort(), [40, 41]);
    assert.equal(rangees.find((r) => r.id === 41).telecharge_le, '2026-11-20T10:00:00Z');
});
