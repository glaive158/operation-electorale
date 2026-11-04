import { test } from 'node:test';
import assert from 'node:assert/strict';
import { creerFile, horodatage, STATUTS } from '../../resources/js/terrain/file.js';

/** Adaptateur de stockage en mémoire : mêmes contrats asynchrones que l'adaptateur IndexedDB. */
function stockageMemoire() {
    const donnees = new Map();
    return {
        donnees,
        async get(id) { return donnees.has(id) ? structuredClone(donnees.get(id)) : undefined; },
        async put(element) { donnees.set(element.id, structuredClone(element)); },
        async list() { return [...donnees.values()].map((e) => structuredClone(e)); },
        async delete(id) { donnees.delete(id); },
    };
}

const CLE_D = 'decl-0000-0000-0001';
const CLE_R = 'redi-0000-0000-0001';
const CLE_L = 'libe-0000-0000-0001';
const EMP_SIGNATURE = 'a'.repeat(64);
const EMP_PHOTO = 'b'.repeat(64);
const HORO = '2026-11-05T10:00:00+00:00';

const piece = (empreinte, role) => ({ type: 'piece', id: empreinte, empreinte, role, statut: 'en_attente' });
const declaration = (extra = {}) => ({
    type: 'declaration',
    id: CLE_D,
    cle: CLE_D,
    remise_id: 7,
    horodatage_terminal: HORO,
    bloc_preuve: 'QkxPQw==',
    pieces: [{ empreinte: EMP_SIGNATURE, role: 'signature' }, { empreinte: EMP_PHOTO, role: 'photo' }],
    statut: 'en_attente',
    ...extra,
});

function nouvelle(options = {}) {
    const stockage = stockageMemoire();
    let maintenant = 1_000_000;
    const file = creerFile({ stockage, horloge: () => maintenant, delaiReessaiMs: 30_000, ...options });
    return { file, stockage, avancer: (ms) => { maintenant += ms; } };
}

test('file : horodatage au format Y-m-d\\TH:i:s+HH:MM (sans Z ni millisecondes), avec un décalage fixe', () => {
    const date = new Date('2026-11-05T10:00:00.789Z');
    assert.equal(horodatage(date, 0), '2026-11-05T10:00:00+00:00');
    assert.equal(horodatage(date, 60), '2026-11-05T11:00:00+01:00');
    assert.equal(horodatage(date, -330), '2026-11-05T04:30:00-05:30');
    assert.equal(horodatage(new Date('2026-12-31T23:30:00Z'), 60), '2027-01-01T00:30:00+01:00');
    assert.match(horodatage(date), /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/);
    assert.throws(() => horodatage(new Date('invalide'), 0), RangeError);
});

test('file : pièces avant la déclaration, quel que soit l\'ordre d\'ajout', async () => {
    const { file } = nouvelle();
    await file.ajouter(declaration());
    await file.ajouter(piece(EMP_SIGNATURE, 'signature'));
    await file.ajouter(piece(EMP_PHOTO, 'photo'));

    const vus = [];
    for (let i = 0; i < 3; i++) {
        const e = await file.prochain();
        assert.ok(e, `élément ${i}`);
        vus.push(e.type);
        await file.marquer(e.id, { status: 201, corps: { ok: true } });
    }
    assert.deepEqual(vus, ['piece', 'piece', 'declaration']);
    assert.equal(await file.prochain(), null);
});

test('file : la déclaration attend une pièce encore en attente, pas une pièce à vérifier', async () => {
    const { file } = nouvelle();
    await file.ajouter(piece(EMP_SIGNATURE, 'signature'));
    await file.ajouter(piece(EMP_PHOTO, 'photo'));
    await file.ajouter(declaration());

    await file.marquer(EMP_SIGNATURE, { status: 200, corps: {} });
    // La photo est refusée (422) : elle sera « attendue » côté serveur, la déclaration part quand même.
    assert.equal((await file.prochain()).id, EMP_PHOTO);
    await file.marquer(EMP_PHOTO, { status: 422, corps: { message: 'Le fichier doit être une image JPEG ou PNG.' } });
    assert.equal((await file.prochain()).id, CLE_D);
});

test('file : la libération d\'une réserve part après les redistributions de la même décharge', async () => {
    const { file } = nouvelle();
    await file.ajouter({ type: 'liberation', id: CLE_L, decharge_id: 4, statut: 'en_attente' });
    await file.ajouter({ type: 'redistribution', id: CLE_R, cle: CLE_R, decharge_id: 4, horodatage_terminal: HORO, statut: 'en_attente' });
    await file.ajouter({ type: 'redistribution', id: 'redi-0000-0000-0002', cle: 'redi-0000-0000-0002', decharge_id: 9, horodatage_terminal: HORO, statut: 'en_attente' });

    const ordre = [];
    for (let e = await file.prochain(); e; e = await file.prochain()) {
        ordre.push(e.id);
        await file.marquer(e.id, { status: 201, corps: {} });
    }
    // La libération (ajoutée la première) attend la redistribution de sa décharge, mais pas celle d'une autre.
    assert.deepEqual(ordre, [CLE_R, CLE_L, 'redi-0000-0000-0002']);
});

test('file : 2xx ⇒ synchronisé, la réponse du serveur est conservée sur l\'élément', async () => {
    const { file, stockage } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    const corps = { declaration: { id: 31, statut: 'en_attente', verification_identite: 'verifiee_code', anomalies: [] } };
    const e = await file.marquer(CLE_D, { status: 201, corps });
    assert.equal(e.statut, STATUTS.SYNCHRONISE);
    assert.deepEqual(e.reponse, corps);
    assert.equal(e.http, 201);
    assert.deepEqual((await stockage.get(CLE_D)).reponse, corps);
    assert.equal(await file.prochain(), null);
});

test('file : 401 conserve la file, la suspend, et la reprise ne perd rien', async () => {
    const { file } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    await file.ajouter({ type: 'redistribution', id: CLE_R, cle: CLE_R, decharge_id: 4, horodatage_terminal: HORO, statut: 'en_attente' });

    const premier = await file.prochain();
    assert.equal(premier.id, CLE_D);
    const e = await file.marquer(CLE_D, { status: 401, corps: { message: 'Authentification requise.' } });
    assert.equal(e.statut, STATUTS.EN_ATTENTE);
    assert.equal(file.estSuspendue(), true);
    assert.equal(await file.prochain(), null, 'file en pause : rien à envoyer sans jeton');
    assert.equal((await file.lister()).length, 2);

    file.reprendre();
    assert.equal(file.estSuspendue(), false);
    const repris = await file.prochain();
    assert.equal(repris.id, CLE_D, 'le même élément est renvoyé en premier');
    assert.equal(repris.cle, premier.cle);
    assert.equal(repris.bloc_preuve, premier.bloc_preuve);
});

test('file : 409 ⇒ reste en attente, réessayé plus tard avec la MÊME clé et le MÊME bloc', async () => {
    const { file, avancer } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    const avant = await file.prochain();

    const e = await file.marquer(CLE_D, { status: 409, corps: { message: 'Déclaration en cours de traitement, réessayez.' } });
    assert.equal(e.statut, STATUTS.EN_ATTENTE);
    assert.equal(file.estSuspendue(), false);
    assert.equal(await file.prochain(), null, 'pas de nouvel essai immédiat');
    avancer(29_999);
    assert.equal(await file.prochain(), null);
    avancer(1);
    const apres = await file.prochain();
    assert.equal(apres.cle, avant.cle);
    assert.equal(apres.bloc_preuve, avant.bloc_preuve);
    assert.equal(apres.horodatage_terminal, avant.horodatage_terminal);
    assert.equal(apres.tentatives, 1);
});

test('file : un 409 ne bloque pas les éléments indépendants', async () => {
    const { file } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    await file.ajouter({ type: 'redistribution', id: CLE_R, cle: CLE_R, decharge_id: 4, horodatage_terminal: HORO, statut: 'en_attente' });
    await file.marquer(CLE_D, { status: 409, corps: { message: 'Déclaration en cours de traitement, réessayez.' } });
    assert.equal((await file.prochain()).id, CLE_R);
});

test('file : 422 « Clé d\'idempotence… » ⇒ à vérifier, jamais renvoyé ; 404 ⇒ à vérifier', async () => {
    const { file } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    await file.ajouter({ type: 'redistribution', id: CLE_R, cle: CLE_R, decharge_id: 4, horodatage_terminal: HORO, statut: 'en_attente' });

    const a = await file.marquer(CLE_D, { status: 422, corps: { message: 'Clé d\'idempotence déjà utilisée pour un autre contenu.' } });
    assert.equal(a.statut, STATUTS.A_VERIFIER);
    assert.equal(a.message, 'Clé d\'idempotence déjà utilisée pour un autre contenu.');
    const b = await file.marquer(CLE_R, { status: 404, corps: { message: 'Ressource introuvable.' } });
    assert.equal(b.statut, STATUTS.A_VERIFIER);
    assert.equal(await file.prochain(), null);
    // Rien n'est perdu : les deux éléments restent lisibles avec leur contenu.
    assert.equal((await file.lister()).length, 2);
    assert.equal((await file.lister()).find((e) => e.id === CLE_D).bloc_preuve, 'QkxPQw==');
});

test('file : panne réseau, 429 et 5xx ⇒ reste en attente, réessayé plus tard, sans suspension', async () => {
    const { file, avancer } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    for (const status of [0, 429, 500, 503]) {
        const e = await file.marquer(CLE_D, { status });
        assert.equal(e.statut, STATUTS.EN_ATTENTE, `statut ${status}`);
        assert.equal(file.estSuspendue(), false);
        avancer(30_000);
        assert.equal((await file.prochain()).id, CLE_D);
    }
});

test('file : jamais d\'effacement d\'un élément en attente (ni à vérifier)', async () => {
    const { file, stockage } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    await assert.rejects(file.supprimer(CLE_D), /en attente/i);
    assert.ok(await stockage.get(CLE_D));

    // Ajouter de nouveau la même clé n'écrase pas l'élément en attente.
    await assert.rejects(file.ajouter(declaration({ bloc_preuve: 'AUTRE' })), /d[ée]j[àa]/i);
    assert.equal((await stockage.get(CLE_D)).bloc_preuve, 'QkxPQw==');

    // Une transition directe vers « disponible » est aussi refusée.
    await assert.rejects(file.marquer(CLE_D, 'disponible'), Error);
    assert.equal((await stockage.get(CLE_D)).statut, STATUTS.EN_ATTENTE);

    await file.marquer(CLE_D, { status: 422, corps: { message: 'x' } });
    await assert.rejects(file.supprimer(CLE_D), /v[ée]rifier/i);

    // Un élément synchronisé peut être purgé ; un élément disponible aussi.
    await file.ajouter({ type: 'affectation', id: 'aff-1', statut: 'disponible' });
    await file.supprimer('aff-1');
    assert.equal(await stockage.get('aff-1'), undefined);
    await file.ajouter(declaration({ id: 'decl-0000-0000-0002', cle: 'decl-0000-0000-0002', pieces: [] }));
    await file.marquer('decl-0000-0000-0002', { status: 200, corps: {} });
    await file.supprimer('decl-0000-0000-0002');
    assert.equal(await stockage.get('decl-0000-0000-0002'), undefined);
});

test('file : marquer ne modifie jamais cle, bloc, horodatage, et un synchronisé ne régresse pas', async () => {
    const { file, stockage } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    await file.marquer(CLE_D, { status: 201, corps: { declaration: { id: 1 } } });
    const e = await file.marquer(CLE_D, { status: 500 });
    assert.equal(e.statut, STATUTS.SYNCHRONISE);
    const stocke = await stockage.get(CLE_D);
    assert.equal(stocke.cle, CLE_D);
    assert.equal(stocke.bloc_preuve, 'QkxPQw==');
    assert.equal(stocke.horodatage_terminal, HORO);
});

test('file : marquer avec un statut direct (pièce déjà présente côté serveur)', async () => {
    const { file } = nouvelle();
    await file.ajouter(piece(EMP_SIGNATURE, 'signature'));
    const e = await file.marquer(EMP_SIGNATURE, 'synchronise');
    assert.equal(e.statut, STATUTS.SYNCHRONISE);
    await assert.rejects(file.marquer(EMP_SIGNATURE, 'inconnu'), /statut/i);
    await assert.rejects(file.marquer('absent', 'synchronise'), /introuvable/i);
});

test('file : un élément à vérifier peut être remis en attente à la demande (même clé, même bloc)', async () => {
    const { file } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    await file.marquer(CLE_D, { status: 404, corps: { message: 'Ressource introuvable.' } });
    const e = await file.reessayer(CLE_D);
    assert.equal(e.statut, STATUTS.EN_ATTENTE);
    assert.equal(e.bloc_preuve, 'QkxPQw==');
    assert.equal((await file.prochain()).id, CLE_D);
    await assert.rejects(file.reessayer('absent'), /introuvable/i);
});

test('file : validation des éléments à envoyer (clé 16–64 [A-Za-z0-9-], horodatage, bloc, empreinte)', async () => {
    const { file } = nouvelle();
    await assert.rejects(file.ajouter(declaration({ id: 'x', cle: 'courte' })), /cl[ée]/i);
    await assert.rejects(file.ajouter(declaration({ cle: 'a'.repeat(65) })), /cl[ée]/i);
    await assert.rejects(file.ajouter(declaration({ cle: 'decl_0000_0000_0001' })), /cl[ée]/i);
    await assert.rejects(file.ajouter(declaration({ horodatage_terminal: '2026-11-05T10:00:00Z' })), /horodatage/i);
    await assert.rejects(file.ajouter(declaration({ horodatage_terminal: '2026-11-05T10:00:00.000+00:00' })), /horodatage/i);
    await assert.rejects(file.ajouter(declaration({ bloc_preuve: '' })), /bloc/i);
    await assert.rejects(file.ajouter(declaration({ bloc_preuve: undefined })), /bloc/i);
    await assert.rejects(file.ajouter(piece('ABC', 'photo')), /empreinte/i);
    await assert.rejects(file.ajouter({ type: 'inconnu', id: 'z', statut: 'en_attente' }), /type/i);
    await assert.rejects(file.ajouter({ type: 'piece', empreinte: EMP_PHOTO, statut: 'termine' }), /statut/i);
    assert.equal((await file.lister()).length, 0);
});

test('file : une pièce déjà présente (même empreinte) n\'est pas dupliquée ni écrasée', async () => {
    const { file } = nouvelle();
    await file.ajouter(piece(EMP_PHOTO, 'photo'));
    await file.marquer(EMP_PHOTO, { status: 201, corps: {} });
    const e = await file.ajouter(piece(EMP_PHOTO, 'photo'));
    assert.equal(e.statut, STATUTS.SYNCHRONISE);
    assert.equal((await file.lister()).length, 1);
});

test('file : les objets renvoyés sont des copies (modifier l\'une ne touche pas le stock)', async () => {
    const { file, stockage } = nouvelle();
    await file.ajouter(declaration({ pieces: [] }));
    const e = await file.prochain();
    e.bloc_preuve = 'ALTERE';
    e.cle = 'altere-altere-altere';
    assert.equal((await stockage.get(CLE_D)).bloc_preuve, 'QkxPQw==');
    assert.equal((await file.prochain()).cle, CLE_D);
});

test('file : ajouts concurrents, l\'ordre d\'arrivée est conservé', async () => {
    const { file } = nouvelle();
    const ids = ['redi-0000-0000-0001', 'redi-0000-0000-0002', 'redi-0000-0000-0003'];
    await Promise.all(ids.map((id) => file.ajouter({ type: 'redistribution', id, cle: id, decharge_id: 1, horodatage_terminal: HORO, statut: 'en_attente' })));
    const vus = [];
    for (let e = await file.prochain(); e; e = await file.prochain()) {
        vus.push(e.id);
        await file.marquer(e.id, { status: 201 });
    }
    assert.deepEqual(vus, ids);
});

test('file : une déclaration de repli (sans code) porte un bloc nul, gardé tel quel', async () => {
    const { file } = nouvelle();
    await file.ajouter(declaration({ bloc_preuve: null, repli: true, pieces: [] }));

    const e = await file.prochain();
    assert.equal(e.id, CLE_D);
    assert.equal(e.bloc_preuve, null);
    await file.marquer(CLE_D, { status: 409, corps: {} });
    assert.equal((await file.lister())[0].bloc_preuve, null, 'même bloc (nul) au nouvel essai');
});

test('file : une pièce en échec réessayable (réseau, 408, 429, 5xx) ne retient plus sa déclaration', async () => {
    for (const status of [0, 408, 429, 503]) {
        const { file, avancer } = nouvelle();
        await file.ajouter(piece(EMP_SIGNATURE, 'signature'));
        await file.ajouter(piece(EMP_PHOTO, 'photo'));
        await file.ajouter(declaration());

        // Jamais tentées : la déclaration attend ses pièces.
        assert.equal((await file.prochain()).id, EMP_SIGNATURE);
        await file.marquer(EMP_SIGNATURE, { status: 201, corps: {} });
        assert.equal((await file.prochain()).id, EMP_PHOTO);
        await file.marquer(EMP_PHOTO, { status, corps: null });

        // La photo attend son délai de réessai, la déclaration part sans elle (passage automatique comme manuel).
        assert.equal((await file.prochain()).id, CLE_D, `status ${status}`);
        assert.equal((await file.prochain({ ignorerPasAvant: true, exclure: new Set([EMP_PHOTO]) })).id, CLE_D);
        await file.marquer(CLE_D, { status: 201, corps: {} });
        const photo = (await file.lister()).find((e) => e.id === EMP_PHOTO);
        assert.equal(photo.statut, STATUTS.EN_ATTENTE, 'la photo reste à envoyer');
        assert.equal(await file.prochain(), null, 'délai de réessai');
        avancer(31_000);
        assert.equal((await file.prochain()).id, EMP_PHOTO, 'la photo est reprise ensuite');
    }
});
