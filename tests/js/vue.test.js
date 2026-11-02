import assert from 'node:assert/strict';
import { test } from 'node:test';

import { STATUTS } from '../../resources/js/terrain/file.js';
import {
    construireListe, dechargesDeRedistribution, duCompte, detailSynchronisation, enAttenteDEnvoi, indicateurHorsLigne, LIBELLES_STATUT,
    reserveRestante, statutAffiche, texteHorsLigne,
} from '../../resources/js/terrain/vue.js';

const remise = (id, statut = 'en_attente') => ({
    id: `remise-${id}`,
    remise: { id, etape: 'livraison', statut, de: 'Magasin central', a: 'Sous-préfecture' },
    decharge: { id: 5, identifiant: 'D-5', numero: '5' },
    lignes: [],
});
const preparation = (dechargeId, lignes = [{ materiel_id: 3, libelle: 'Urnes', quantite: 30 }]) => ({ id: `preparation-${dechargeId}`, remise: null, decharge: { id: dechargeId, identifiant: `D-${dechargeId}`, numero: String(dechargeId) }, lignes });
const declaration = (remiseId, statut, extra = {}) => ({ type: 'declaration', id: `cle-${remiseId}`, remise_id: remiseId, decharge_id: 5, statut, ...extra });

test('les quatre libelles de statut sont ceux de l\'ecran', () => {
    assert.deepEqual(LIBELLES_STATUT, {
        disponible: 'Disponible hors connexion',
        en_attente: 'En attente d\'envoi',
        synchronise: 'Synchronisé',
        a_verifier: 'À vérifier',
    });
});

test('statut affiche : celui de la file, sinon celui de l\'affectation', () => {
    assert.equal(statutAffiche(remise(1), null), STATUTS.DISPONIBLE);
    assert.equal(statutAffiche(remise(1, 'a_verifier'), null), STATUTS.A_VERIFIER);
    assert.equal(statutAffiche(remise(1), declaration(1, 'en_attente')), STATUTS.EN_ATTENTE);
    assert.equal(statutAffiche(remise(1), declaration(1, 'a_verifier')), STATUTS.A_VERIFIER);
    assert.equal(statutAffiche(remise(1), declaration(1, 'synchronise', { reponse: { declaration: { statut: 'retenue' } } })), STATUTS.SYNCHRONISE);
    assert.equal(statutAffiche(remise(1), declaration(1, 'synchronise', { reponse: { declaration: { statut: 'a_verifier' } } })), STATUTS.A_VERIFIER, 'à vérifier par le central');
});

test('la liste ne montre ni les preparations de redistribution ni les remises a arbitrer', () => {
    const lignes = construireListe([remise(1), preparation(8), remise(2, 'a_arbitrer')], []);

    assert.deepEqual(lignes.map((l) => l.remise_id), [1]);
});

test('la liste garde les declarations dont l\'affectation n\'est plus telechargee, triees a faire d\'abord', () => {
    const file = [
        declaration(3, 'synchronise', { resume: { numero: '9', de: 'A', a: 'B', etape: 'livraison' }, reponse: { declaration: { statut: 'retenue', verification_identite: 'verifiee_code', contestation: 'aucune' } } }),
        declaration(2, 'en_attente'),
        declaration(4, 'a_verifier', { message: 'Cette remise n\'attend pas de déclaration.' }),
    ];

    const lignes = construireListe([remise(1), remise(2)], file);

    assert.deepEqual(lignes.map((l) => [l.remise_id, l.statut]), [[4, 'a_verifier'], [1, 'disponible'], [2, 'en_attente'], [3, 'synchronise']]);
    assert.equal(lignes[0].detail, 'Cette remise n\'attend pas de déclaration.');
    assert.equal(lignes[3].titre, 'Décharge n° 9');
    assert.equal(lignes[3].detail, 'Identité vérifiée par le code');
});

test('detail d\'une declaration contestee', () => {
    assert.equal(detailSynchronisation({ reponse: { declaration: { verification_identite: 'verifiee_code', contestation: 'contestee' } } }), 'Réception contestée : en cours de vérification.');
    assert.equal(detailSynchronisation(null), null);
});

test('envois en attente comptes', () => {
    assert.equal(enAttenteDEnvoi([{ statut: 'en_attente' }, { statut: 'synchronise' }, { statut: 'en_attente' }, { statut: 'a_verifier' }]), 2);
});

test('reserve restante : les redistributions non envoyees sont deduites, pas les envoyees', () => {
    const reserve = { decharge_id: 5, lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 10 }, { materiel_id: 4, libelle: 'Isoloirs', quantite: 5 }] };
    const file = [
        { type: 'redistribution', decharge_id: 5, statut: 'en_attente', lignes: [{ materiel_id: 3, quantite: 4 }] },
        { type: 'redistribution', decharge_id: 5, statut: 'en_attente', lignes: [{ materiel_id: 3, quantite: 3 }] },
        { type: 'redistribution', decharge_id: 5, statut: 'synchronise', lignes: [{ materiel_id: 3, quantite: 9 }] },
        { type: 'redistribution', decharge_id: 6, statut: 'en_attente', lignes: [{ materiel_id: 3, quantite: 9 }] },
    ];

    assert.deepEqual(reserveRestante(reserve, file).map((l) => [l.materiel_id, l.quantite, l.restant]), [[3, 10, 3], [4, 5, 5]]);
    assert.equal(reserveRestante({ decharge_id: 5, lignes: [{ materiel_id: 3, quantite: 2 }] }, file)[0].restant, 0, 'jamais négatif');
});

test('redistribution : rien tant que la tablette n\'est pas designee, meme avec des preparations', () => {
    assert.deepEqual(dechargesDeRedistribution({ designe: false, elements: [preparation(8)], reserves: [{ decharge_id: 8, lignes: [{ materiel_id: 3, quantite: 5 }] }], file: [] }), []);
});

test('redistribution : preparations et reserves de la tablette designee, sans les reserves vides et inactives', () => {
    const elements = [preparation(8), remise(1), preparation(9)];
    const reserves = [
        { decharge_id: 8, lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 10 }] },
        { decharge_id: 7, decharge: { id: 7, numero: '7' }, lignes: [{ materiel_id: 3, libelle: 'Urnes', quantite: 4 }] },
        { decharge_id: 6, lignes: [] },
    ];

    const decharges = dechargesDeRedistribution({ designe: true, elements, reserves, file: [] });

    assert.deepEqual(decharges.map((d) => d.decharge.id), [7, 8, 9]);
    assert.equal(decharges.find((d) => d.decharge.id === 8).reserve.lignes[0].quantite, 10);
    assert.equal(decharges.find((d) => d.decharge.id === 9).reserve, null);
    // Une réserve vide reste visible tant que des envois la concernent (historique).
    const avecHistorique = dechargesDeRedistribution({ designe: true, elements: [], reserves: [{ decharge_id: 6, decharge: { id: 6, numero: '6' }, lignes: [] }], file: [{ type: 'liberation', decharge_id: 6, statut: 'en_attente' }] });
    assert.deepEqual(avecHistorique.map((d) => d.decharge.id), [6]);
});

test('indicateur « Tout est disponible hors ligne »', () => {
    assert.deepEqual(indicateurHorsLigne({ coquille: true, cle: true, donnees: true }), { pret: true, manque: [] });
    assert.equal(texteHorsLigne({ pret: true, manque: [] }), 'Tout est disponible hors ligne');
    const incomplet = indicateurHorsLigne({ coquille: true, cle: false, donnees: false });
    assert.deepEqual(incomplet, { pret: false, manque: ['clé de preuve', 'affectations'] });
    assert.match(texteHorsLigne(incomplet), /clé de preuve, affectations/);
});

test('la liste ne montre que les declarations du compte connecte', () => {
    const file = [
        declaration(1, 'en_attente', { utilisateur_id: 3 }),
        declaration(2, 'a_verifier', { utilisateur_id: 99, message: 'secret d\'un autre', resume: { numero: '2' } }),
        declaration(4, 'a_verifier', { utilisateur_id: 3, resume: { numero: '4' } }),
        declaration(5, 'synchronise', { resume: { numero: '5' } }),
    ];

    const lignes = construireListe([remise(1), remise(2)], file, 3);

    assert.deepEqual(lignes.map((l) => [l.remise_id, l.statut]), [[4, 'a_verifier'], [2, 'disponible'], [1, 'en_attente'], [5, 'synchronise']]);
    assert.equal(lignes.some((l) => l.detail === 'secret d\'un autre'), false);
    assert.deepEqual(duCompte(file, 3).map((e) => e.remise_id), [1, 4, 5]);
    assert.deepEqual(duCompte(file, 99).map((e) => e.remise_id), [2, 5], 'sans titulaire connu : visible de tous');
});
