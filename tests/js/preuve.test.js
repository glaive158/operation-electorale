import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { construireBloc, contenuPreuve, importerCle } from '../../resources/js/terrain/preuve.js';
import { empreinte, json } from '../../resources/js/terrain/canonique.js';

const subtle = globalThis.crypto.subtle;
const CODE = '908173';
const CLE = 'a1b2c3d4-e5f6-4a7b-8c9d-0123456789ab';

function enBase64(octets) {
    return Buffer.from(octets).toString('base64');
}

async function genererPaire() {
    const paire = await subtle.generateKey(
        { name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-1' },
        true,
        ['encrypt', 'decrypt'],
    );
    const spki = new Uint8Array(await subtle.exportKey('spki', paire.publicKey));
    const lignes = enBase64(spki).match(/.{1,64}/g).join('\n');
    return { privee: paire.privateKey, pem: `-----BEGIN PUBLIC KEY-----\n${lignes}\n-----END PUBLIC KEY-----\n` };
}

async function dechiffrer(privee, blocBase64) {
    const clair = await subtle.decrypt({ name: 'RSA-OAEP' }, privee, Buffer.from(blocBase64, 'base64'));
    return new TextDecoder().decode(clair);
}

const contenu = () => contenuPreuve({
    decharge_id: 4,
    decharge_identifiant: 'DCH-2026-0004',
    remise_id: 7,
    cle: CLE,
    signataire: { user_id: 12, nom: ' Awa Diop ', qualite: 'Préfète' },
    lignes: [
        { materiel_id: 5, recu: 4, defectueux: 0, observation: '   ' },
        { materiel_id: 3, recu: 10, defectueux: 2, observation: ' Caisse écrasée / vérifiée ' },
    ],
    horodatage_terminal: '2026-11-05T10:00:00+00:00',
});

test('preuve : le bloc se déchiffre avec la clé privée de test et contient l\'empreinte du contenu', async () => {
    const { privee, pem } = await genererPaire();
    const cle = await importerCle(pem);
    const { bloc, empreinte: emp } = await construireBloc({ code: CODE, cle: CLE, contenu: contenu(), clePublique: cle });

    assert.equal(emp, await empreinte(contenu()));
    assert.match(emp, /^[0-9a-f]{64}$/);
    // Un chiffré RSA fait exactement la taille du module (256 octets pour 2048 bits).
    assert.equal(Buffer.from(bloc, 'base64').length, 256);

    const clair = await dechiffrer(privee, bloc);
    assert.equal(clair, json({ code: CODE, cle: CLE, empreinte: emp }));
    assert.deepEqual(JSON.parse(clair), { code: CODE, cle: CLE, empreinte: emp });
});

test('preuve : construireBloc accepte directement le PEM de la clé publique', async () => {
    const { privee, pem } = await genererPaire();
    const { bloc, empreinte: emp } = await construireBloc({ code: CODE, cle: CLE, contenu: contenu(), clePublique: pem });
    assert.equal(JSON.parse(await dechiffrer(privee, bloc)).empreinte, emp);
});

test('preuve : le code n\'est pas dans l\'objet renvoyé', async () => {
    const { pem } = await genererPaire();
    const resultat = await construireBloc({ code: CODE, cle: CLE, contenu: contenu(), clePublique: await importerCle(pem) });
    assert.deepEqual(Object.keys(resultat).sort(), ['bloc', 'empreinte']);
    assert.ok(!JSON.stringify(resultat).includes(CODE));
});

test('preuve : deux blocs du même clair diffèrent (OAEP aléatoire) : le bloc doit donc être conservé tel quel', async () => {
    const { pem } = await genererPaire();
    const cle = await importerCle(pem);
    const a = await construireBloc({ code: CODE, cle: CLE, contenu: contenu(), clePublique: cle });
    const b = await construireBloc({ code: CODE, cle: CLE, contenu: contenu(), clePublique: cle });
    assert.notEqual(a.bloc, b.bloc);
    assert.equal(a.empreinte, b.empreinte);
});

test('preuve : contenuPreuve rogne les saisies (nom, qualité, observations) et normalise les lignes', () => {
    const c = contenu();
    assert.equal(c.signataire.nom, 'Awa Diop');
    assert.deepEqual(c.lignes.map((l) => l.materiel_id), [3, 5], 'lignes triées par article');
    assert.equal(c.lignes[1].observation, null);
    assert.equal(c.lignes[0].observation, 'Caisse écrasée / vérifiée');
});

test('preuve : construireBloc empreinte le contenu tel que reçu, sans le rogner', async () => {
    const { pem } = await genererPaire();
    const cle = await importerCle(pem);
    const c = contenu();
    // Un identifiant de décharge vient du serveur, qui ne le rogne pas : il est empreinté tel quel.
    c.decharge_identifiant = '  DCH-2026-0004\n';
    const { empreinte: emp } = await construireBloc({ code: CODE, cle: CLE, contenu: c, clePublique: cle });
    assert.equal(emp, await empreinte(c));
    assert.notEqual(emp, await empreinte({ ...c, decharge_identifiant: 'DCH-2026-0004' }));
});

test('preuve : vecteurs partagés avec PHP (contenuPreuve, json et empreinte)', async () => {
    const vecteurs = JSON.parse(readFileSync(new URL('../fixtures/contenu-preuve.json', import.meta.url), 'utf8'));
    assert.ok(vecteurs.length >= 2);
    for (const v of vecteurs) {
        const c = contenuPreuve(v.entree);
        assert.equal(json(c), v.json, v.note);
        assert.equal(await empreinte(c), v.empreinte, v.note);
    }
});

test('preuve : entrées invalides refusées sans produire de bloc', async () => {
    const { pem } = await genererPaire();
    const clePublique = await importerCle(pem);
    for (const mauvais of ['', '12345', '1234567', 'abcdef', '12 456', '１２３４５６', 482915, null]) {
        await assert.rejects(
            construireBloc({ code: mauvais, cle: CLE, contenu: contenu(), clePublique }),
            (e) => /code/i.test(e.message) && (typeof mauvais !== 'string' || mauvais === '' || !e.message.includes(mauvais)),
        );
    }
    await assert.rejects(construireBloc({ code: CODE, cle: 'court', contenu: contenu(), clePublique }), /cl[ée]/i);
    await assert.rejects(construireBloc({ code: CODE, cle: CLE, contenu: contenu(), clePublique: null }), /cl[ée]/i);
    await assert.rejects(importerCle('pas une clé'), Error);
});
