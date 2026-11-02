import assert from 'node:assert/strict';
import { test } from 'node:test';

import { ecranConnexion, ecranListe, ecranReception, ecranRedistribution } from '../../resources/js/terrain/ecrans.js';
import { creerFile } from '../../resources/js/terrain/file.js';
import { empreinte } from '../../resources/js/terrain/canonique.js';
import { contenuPreuve } from '../../resources/js/terrain/preuve.js';
import { creerStockage, ouvrirBase } from '../../resources/js/terrain/stockage.js';
import { corpsDeclaration } from '../../resources/js/terrain/sync.js';
import { creerFauxDocument, parAria, parId, parTexte, trouverTous } from './support/faux-dom.js';
import { creerFauxIndexedDB } from './support/faux-indexeddb.js';

globalThis.document = creerFauxDocument();
globalThis.window = { confirm: () => true };

const CODE = '482915';

async function paireDeCles() {
    const paire = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-1' }, true, ['encrypt', 'decrypt']);
    const spki = Buffer.from(await crypto.subtle.exportKey('spki', paire.publicKey)).toString('base64');

    return { ...paire, pem: `-----BEGIN PUBLIC KEY-----\n${spki.match(/.{1,64}/g).join('\n')}\n-----END PUBLIC KEY-----\n` };
}

const elementReception = (surcharge = {}) => ({
    id: 'remise-12',
    remise: { id: 12, etape: 'livraison', statut: 'en_attente', de: 'Magasin central', a: 'Sous-préfecture de Thiès' },
    decharge: { id: 5, identifiant: 'DCH-2026-0005', numero: '5', categorie: 'electeurs', nature: 'initiale' },
    autorite_attendue: { user_id: 7, nom: ' Awa BA ', qualite: 'Sous-préfet', delegues: [{ user_id: 9, nom: 'Moussa Fall', qualite: 'Chef de poste' }] },
    lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 20 }, { materiel_id: 4, libelle: 'Isoloirs', quantite: 10 }],
    empreinte_element: 'x',
    ...surcharge,
});

async function contexte({ avecCle = true, session = { utilisateur: { id: 3, nom: 'Agent' } } } = {}) {
    const paire = await paireDeCles();
    const indexedDB = creerFauxIndexedDB();
    const stockage = creerStockage(await ouvrirBase({ indexedDB }));
    if (avecCle) await stockage.meta.ecrire('cle_preuve', { pem: paire.pem, empreinte: 'e' });
    const file = creerFile({ stockage: stockage.file });
    const appelsApi = [];
    const cycles = [];
    const reponsesApi = [];
    const ctx = {
        etat: { session, appareil: 'uuid-tablette', enLigne: true },
        stockage, file, paire, appelsApi, cycles, indexedDB,
        api: { envoyer: async (m, c, o) => { appelsApi.push({ m, c, o }); return reponsesApi.shift() ?? { status: 200, corps: null }; } },
        sync: { cycle: async () => { cycles.push(1); return {}; } },
        rafraichir: async () => {}, rafraichirEntete: async () => {}, signaler() {}, coquilleEnCache: async () => true,
        reponsesApi,
    };

    return ctx;
}

const saisir = (champ, valeur) => { champ.value = valeur; };
const tracer = async (canvas) => {
    await canvas.emettre('pointerdown', { clientX: 20, clientY: 20, pointerId: 1 });
    await canvas.emettre('pointermove', { clientX: 220, clientY: 90, pointerId: 1 });
    await canvas.emettre('pointermove', { clientX: 400, clientY: 20, pointerId: 1 });
    await canvas.emettre('pointerup', { clientX: 400, clientY: 20, pointerId: 1 });
};
const toutLeTexte = (noeud) => noeud.textContent;

/** Remplit l'étape 1 (quantités) et passe à l'étape de l'autorité. */
async function passerALAutorite(racine, { recuUrnes = '19', defUrnes = '2' } = {}) {
    saisir(parAria(racine, 'Reçu : Urnes'), recuUrnes);
    saisir(parAria(racine, 'Défectueux : Urnes'), defUrnes);
    saisir(parAria(racine, 'Observation : Urnes'), '  1 urne cassée  ');
    await parTexte(racine, 'button', 'Continuer').click();
}

test('reception : quantites, signature, code chiffre puis efface, declaration mise en file avec le bloc', async () => {
    const ctx = await contexte();
    await ctx.stockage.elements.put(elementReception());
    const racine = await ecranReception(ctx, 'remise-12');

    assert.match(toutLeTexte(racine), /Décharge n° 5/);
    assert.match(toutLeTexte(racine), /Matériel reçu/);
    await passerALAutorite(racine);

    // Écran de l'autorité : nom et qualité attendus, champ code numérique.
    assert.match(toutLeTexte(parId(racine, 'signataire-attendu')), /Nom attendu : +Awa BA/);
    assert.match(toutLeTexte(parId(racine, 'signataire-attendu')), /Qualité attendue : +Sous-préfet/);
    const champCode = parId(racine, 'reception-code');
    assert.equal(champCode.getAttribute('inputmode'), 'numeric');
    assert.equal(champCode.getAttribute('type'), 'password');
    assert.equal(champCode.getAttribute('autocomplete'), 'off');
    const photo = trouverTous(racine, (e) => e.tagName === 'INPUT' && e.getAttribute('type') === 'file')[0];
    assert.equal(photo.getAttribute('accept'), 'image/*');
    assert.equal(photo.getAttribute('capture'), 'environment');

    saisir(champCode, CODE);
    await tracer(parAria(racine, 'Zone de signature'));
    await parTexte(racine, 'button', 'Valider la réception').click();

    // Le code a quitté le champ et n'est stocké nulle part.
    assert.equal(champCode.value, '');
    const elements = await ctx.file.lister();
    const piece = elements.find((e) => e.type === 'piece');
    const declaration = elements.find((e) => e.type === 'declaration');
    assert.equal(piece.role, 'signature');
    assert.equal(declaration.statut, 'en_attente');
    assert.equal(declaration.remise_id, 12);
    assert.match(declaration.cle, /^[A-Za-z0-9-]{16,64}$/);
    assert.match(declaration.horodatage_terminal, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
    assert.doesNotMatch(declaration.horodatage_terminal, /Z|\./);
    assert.deepEqual(declaration.pieces, [{ empreinte: piece.empreinte, role: 'signature' }]);
    assert.deepEqual(declaration.signataire, { user_id: 7, nom: 'Awa BA', qualite: 'Sous-préfet' }, 'nom rogné');
    assert.deepEqual(declaration.lignes, [
        { materiel_id: 3, recu: 19, defectueux: 2, observation: '1 urne cassée' },
        { materiel_id: 4, recu: 10, defectueux: 0, observation: null },
    ]);
    assert.equal(ctx.cycles.length, 1, 'un envoi est lancé');
    assert.match(toutLeTexte(racine), /Enregistrée — vérification d'identité en attente/);

    // Les octets de la signature sont gardés pour l'envoi.
    const octets = await ctx.stockage.pieces.get(piece.empreinte);
    assert.equal(octets.blob.type, 'image/png');

    // Le bloc, déchiffré avec la clé privée, atteste exactement ce contenu et porte le code saisi.
    const clair = JSON.parse(Buffer.from(await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, ctx.paire.privateKey, Buffer.from(declaration.bloc_preuve, 'base64'))).toString());
    const contenu = contenuPreuve({
        decharge_id: 5, decharge_identifiant: 'DCH-2026-0005', remise_id: 12, cle: declaration.cle, signataire: declaration.signataire,
        lignes: declaration.lignes, horodatage_terminal: declaration.horodatage_terminal,
    });
    assert.deepEqual(clair, { cle: declaration.cle, code: CODE, empreinte: await empreinte(contenu) });

    // Le corps envoyé reprend exactement le contenu attesté.
    const corps = corpsDeclaration(declaration);
    assert.deepEqual({ lignes: corps.lignes, signataire: corps.signataire, horodatage_terminal: corps.horodatage_terminal }, {
        lignes: contenu.lignes, signataire: contenu.signataire, horodatage_terminal: contenu.horodatage_terminal,
    });

    // Aucune trace du code dans le stockage (hors blob chiffré), ni dans l'arbre d'affichage.
    const tout = JSON.stringify([...ctx.indexedDB.bases.values()].map((b) => [...b.magasins.values()].map((m) => [...m.donnees.values()])));
    assert.equal(tout.includes(CODE), false);
    assert.equal(toutLeTexte(racine).includes(CODE), false);
});

test('reception : un deuxieme ecran sur la meme remise montre la declaration deja enregistree', async () => {
    const ctx = await contexte();
    await ctx.stockage.elements.put(elementReception());
    const racine = await ecranReception(ctx, 'remise-12');
    await passerALAutorite(racine);
    saisir(parId(racine, 'reception-code'), CODE);
    await tracer(parAria(racine, 'Zone de signature'));
    await parTexte(racine, 'button', 'Valider la réception').click();

    const encore = await ecranReception(ctx, 'remise-12');

    assert.equal(encore.getAttribute('id'), 'reception-recapitulatif');
    assert.match(toutLeTexte(encore), /Enregistrée — vérification d'identité en attente/);
    assert.equal(parTexte(encore, 'button', 'Valider'), null);
    assert.equal((await ctx.file.lister()).filter((e) => e.type === 'declaration').length, 1);
});

test('reception : validations des quantites, du code et de la signature', async () => {
    const ctx = await contexte();
    await ctx.stockage.elements.put(elementReception());
    const racine = await ecranReception(ctx, 'remise-12');

    saisir(parAria(racine, 'Défectueux : Urnes'), '25');
    await parTexte(racine, 'button', 'Continuer').click();
    assert.match(toutLeTexte(racine), /le nombre de défectueux dépasse le nombre reçu/);
    assert.equal(parId(racine, 'reception-code'), null, 'reste à l\'étape des quantités');

    saisir(parAria(racine, 'Défectueux : Urnes'), '-1');
    await parTexte(racine, 'button', 'Continuer').click();
    assert.match(toutLeTexte(racine), /Quantités invalides/);

    saisir(parAria(racine, 'Défectueux : Urnes'), '0');
    await parTexte(racine, 'button', 'Continuer').click();
    const valider = () => parTexte(racine, 'button', 'Valider la réception').click();

    saisir(parId(racine, 'reception-code'), '12');
    await valider();
    assert.match(toutLeTexte(racine), /Le code personnel comporte 6 chiffres/);

    saisir(parId(racine, 'reception-code'), CODE);
    await valider();
    assert.match(toutLeTexte(racine), /La signature est obligatoire/);
    assert.equal((await ctx.file.lister()).length, 0, 'rien n\'est enregistré');
});

test('reception : sans cle de preuve, rien n\'est enregistre et le code est efface quand meme', async () => {
    const ctx = await contexte({ avecCle: false });
    await ctx.stockage.elements.put(elementReception());
    const racine = await ecranReception(ctx, 'remise-12');
    await passerALAutorite(racine);
    const champ = parId(racine, 'reception-code');
    saisir(champ, CODE);
    await tracer(parAria(racine, 'Zone de signature'));

    await parTexte(racine, 'button', 'Valider la réception').click();

    assert.match(toutLeTexte(racine), /Clé de preuve non téléchargée/);
    assert.equal(champ.value, '');
    assert.equal((await ctx.file.lister()).length, 0);
});

test('reception : un delegue peut signer ; son identite entre dans le contenu atteste', async () => {
    const ctx = await contexte({ session: { utilisateur: { id: 3, nom: 'Agent' } } });
    await ctx.stockage.elements.put(elementReception());
    const racine = await ecranReception(ctx, 'remise-12');
    await passerALAutorite(racine);

    const choix = parId(racine, 'reception-signataire');
    assert.ok(choix, 'une liste de signataires quand il y a un délégué');
    await choix.emettre('change', { target: { value: '9' } });
    saisir(parId(racine, 'reception-code'), CODE);
    await tracer(parAria(racine, 'Zone de signature'));
    await parTexte(racine, 'button', 'Valider la réception').click();

    const declaration = (await ctx.file.lister()).find((e) => e.type === 'declaration');
    assert.deepEqual(declaration.signataire, { user_id: 9, nom: 'Moussa Fall', qualite: 'Chef de poste' });
});

test('reception : une autorite sans compte rattache ne peut pas etre signee sur la tablette', async () => {
    const ctx = await contexte();
    await ctx.stockage.elements.put(elementReception({ autorite_attendue: { user_id: null, nom: null, qualite: null, delegues: [] } }));
    const racine = await ecranReception(ctx, 'remise-12');
    await passerALAutorite(racine);
    saisir(parId(racine, 'reception-code'), CODE);
    await tracer(parAria(racine, 'Zone de signature'));

    await parTexte(racine, 'button', 'Valider la réception').click();

    assert.match(toutLeTexte(racine), /Aucune autorité n'est rattachée/);
    assert.equal((await ctx.file.lister()).length, 0);
});

test('reception : une remise a arbitrer ou inconnue n\'ouvre aucun formulaire', async () => {
    const ctx = await contexte();
    await ctx.stockage.elements.put(elementReception({ remise: { id: 12, etape: 'livraison', statut: 'a_arbitrer', de: 'A', a: 'B' } }));

    assert.match(toutLeTexte(await ecranReception(ctx, 'remise-12')), /n'est plus à faire/);
    assert.match(toutLeTexte(await ecranReception(ctx, 'remise-99')), /n'est plus à faire/);
});

test('liste : statuts, indicateur hors ligne et bouton de reception', async () => {
    const ctx = await contexte();
    await ctx.stockage.elements.put(elementReception());
    await ctx.stockage.elements.put(elementReception({ id: 'remise-13', remise: { id: 13, etape: 'redistribution', statut: 'en_attente', de: 'Préfecture', a: 'Poste' } }));
    await ctx.stockage.elements.put({ id: 'preparation-8', remise: null, decharge: { id: 8, identifiant: 'D', numero: '8' }, lignes: [] });
    await ctx.file.ajouter({
        type: 'declaration', id: 'cle-declaration-0013', cle: 'cle-declaration-0013', remise_id: 13, decharge_id: 5, horodatage_terminal: '2026-11-05T10:00:00+01:00',
        bloc_preuve: 'QQ==', signataire: { user_id: 7, nom: 'A', qualite: 'B' }, lignes: [], pieces: [],
    });

    let liste = await ecranListe(ctx);
    assert.match(toutLeTexte(liste), /Pas encore disponible hors ligne/, 'sans données téléchargées');
    await ctx.stockage.meta.ecrire('derniere_sync', '2026-11-05T10:00:00Z');
    liste = await ecranListe(ctx);

    assert.equal(toutLeTexte(parId(liste, 'indicateur-hors-ligne')), 'Tout est disponible hors ligne');
    const texte = toutLeTexte(liste);
    assert.match(texte, /Disponible hors connexion/);
    assert.match(texte, /En attente d'envoi/);
    assert.equal(trouverTous(liste, (e) => e.tagName === 'LI').length, 2, 'la préparation n\'est pas une réception');
    assert.ok(parTexte(liste, 'a', 'Réceptionner'));
    assert.equal(parTexte(liste, 'a', 'Réceptionner').getAttribute('href'), '#/reception/remise-12');
});

test('connexion : formulaire, identifiant de la tablette affiche, mot de passe efface apres l\'envoi', async () => {
    const appels = [];
    const ctx = { etat: { session: { email: 'agent@exemple.sn' }, appareil: '3f2504e0-4f89-41d3-9a0c-0305e82c3301' }, connecter: async (email, mdp) => { appels.push([email, mdp]); return { erreur: 'Identifiants invalides.' }; } };
    const ecran = ecranConnexion(ctx, { message: 'Session expirée : reconnectez-vous.' });

    assert.equal(toutLeTexte(parId(ecran, 'identifiant-appareil')), '3f2504e0-4f89-41d3-9a0c-0305e82c3301');
    assert.match(toutLeTexte(ecran), /Session expirée/);
    const email = parId(ecran, 'connexion-email');
    const mdp = parId(ecran, 'connexion-mdp');
    assert.equal(email.value, 'agent@exemple.sn');
    saisir(mdp, 'secret-tres-long');
    const formulaire = trouverTous(ecran, (e) => e.tagName === 'FORM')[0];

    await formulaire.emettre('submit');

    assert.deepEqual(appels, [['agent@exemple.sn', 'secret-tres-long']]);
    assert.equal(mdp.value, '');
    assert.match(toutLeTexte(ecran), /Identifiants invalides/);
});

/* ───────────── Redistribution ───────────── */

const preparation = () => ({
    id: 'preparation-8', remise: null, decharge: { id: 8, identifiant: 'DCH-8', numero: '8' },
    autorite_attendue: { user_id: 7, nom: 'P', qualite: 'Préfet', delegues: [] }, lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 30 }],
});

test('redistribution : ecran refuse tant que la tablette n\'est pas designee', async () => {
    const ctx = await contexte();
    await ctx.stockage.elements.put(preparation());

    const ecran = await ecranRedistribution(ctx);

    assert.match(toutLeTexte(ecran), /n'est pas désignée/);
    assert.equal(parTexte(ecran, 'button', 'Réserver'), null);
});

test('redistribution : reserver en ligne, redistribuer hors connexion, liberer le reste', async () => {
    const ctx = await contexte();
    await ctx.stockage.meta.ecrire('appareil_designe', { designe: true, appareil: { prefecture: { id: 1, libelle: 'Préfecture de Thiès' } } });
    await ctx.stockage.elements.put(preparation());

    // 1. Réserver 25 sur 30.
    ctx.reponsesApi.push({ status: 200, corps: { reserve: { appareil: 'u', decharge_id: 8, lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 25, quantite_initiale: 25 }] } } });
    let ecran = await ecranRedistribution(ctx);
    assert.match(toutLeTexte(ecran), /Tablette désignée pour la redistribution : Préfecture de Thiès/);
    saisir(parAria(ecran, 'À réserver : Urnes'), '25');
    await parTexte(ecran, 'button', 'Réserver').click();
    assert.deepEqual(ctx.appelsApi[0], { m: 'POST', c: '/decharges/8/reserve', o: { json: { lignes: [{ materiel_id: 3, quantite: 25 }] }, avecAppareil: true } });
    assert.equal((await ctx.stockage.meta.lire('reserve:8')).lignes[0].quantite, 25);

    // 2. Hors connexion : redistribuer 10 puis 8, sans dépasser le reste (7).
    ctx.etat.enLigne = false;
    ecran = await ecranRedistribution(ctx);
    saisir(parAria(ecran, 'À redistribuer : Urnes'), '26');
    await parTexte(ecran, 'button', 'Enregistrer la redistribution').click();
    assert.match(toutLeTexte(ecran), /Quantité supérieure à la réserve/);
    saisir(parAria(ecran, 'À redistribuer : Urnes'), '10');
    await parTexte(ecran, 'button', 'Enregistrer la redistribution').click();

    ecran = await ecranRedistribution(ctx);
    assert.match(toutLeTexte(ecran), /reste : 15/, 'les 10 enregistrés sont déduits du reste affiché');
    saisir(parAria(ecran, 'À redistribuer : Urnes'), '16');
    await parTexte(ecran, 'button', 'Enregistrer la redistribution').click();
    assert.match(toutLeTexte(ecran), /Quantité supérieure à la réserve/);

    const redistributions = (await ctx.file.lister()).filter((e) => e.type === 'redistribution');
    assert.equal(redistributions.length, 1);
    assert.deepEqual(redistributions[0].lignes, [{ materiel_id: 3, quantite: 10 }]);
    assert.equal(redistributions[0].decharge_id, 8);
    assert.match(redistributions[0].cle, /^[A-Za-z0-9-]{16,64}$/);
    assert.match(redistributions[0].horodatage_terminal, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);

    // 3. Libérer le reste.
    await parTexte(ecran, 'button', 'Libérer le reste').click();
    const liberation = (await ctx.file.lister()).find((e) => e.type === 'liberation');
    assert.equal(liberation.decharge_id, 8);
    ecran = await ecranRedistribution(ctx);
    assert.equal(parTexte(ecran, 'button', 'Enregistrer la redistribution'), null, 'plus de saisie une fois la libération demandée');
});

test('redistribution : hors connexion la reserve n\'est pas possible, et une redistribution a arbitrer est signalee', async () => {
    const ctx = await contexte();
    ctx.etat.enLigne = false;
    await ctx.stockage.meta.ecrire('appareil_designe', { designe: true, appareil: null });
    await ctx.stockage.elements.put(preparation());
    await ctx.stockage.meta.ecrire('reserve:9', { decharge_id: 9, decharge: { id: 9, numero: '9' }, lignes: [] });
    await ctx.file.ajouter({ type: 'redistribution', id: 'cle-redistribution-0001', cle: 'cle-redistribution-0001', decharge_id: 9, horodatage_terminal: '2026-11-06T09:30:00+00:00', lignes: [{ materiel_id: 3, quantite: 5 }] });
    await ctx.file.marquer('cle-redistribution-0001', { status: 201, corps: { redistribution: { statut: 'a_arbitrer', a_arbitrer: true, raison: 'Appareil non désigné pour cette préfecture.' } } });

    const ecran = await ecranRedistribution(ctx);

    assert.equal(parTexte(ecran, 'button', 'Réserver').disabled, true);
    assert.match(toutLeTexte(ecran), /À arbitrer par le central : Appareil non désigné pour cette préfecture\./);
});
