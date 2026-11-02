import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
    creerStockage, identifiantAppareil, MAGASINS, migrer, MIGRATIONS, NOM_BASE, ouvrirBase, proteger, VERSION,
} from '../../resources/js/terrain/stockage.js';
import { creerFauxIndexedDB } from './support/faux-indexeddb.js';

test('premiere ouverture : les quatre magasins existent', async () => {
    const indexedDB = creerFauxIndexedDB();
    await ouvrirBase({ indexedDB });

    const base = indexedDB.bases.get(NOM_BASE);
    assert.equal(base.version, VERSION);
    assert.deepEqual([...base.magasins.keys()].sort(), ['elements', 'file', 'meta', 'pieces']);
    assert.equal(base.magasins.get('pieces').keyPath, 'empreinte');
    assert.equal(base.magasins.get('meta').keyPath, 'cle');
});

test('une migration ne peut jamais supprimer un magasin', () => {
    const supprimes = [];
    const base = { objectStoreNames: { contains: () => true }, createObjectStore: () => {}, deleteObjectStore: (n) => supprimes.push(n) };

    assert.throws(() => proteger(base).deleteObjectStore('file'), /ne supprime jamais/);
    assert.deepEqual(supprimes, []);

    const mauvaise = [() => {}, (b) => b.deleteObjectStore('elements')];
    assert.throws(() => migrer(base, null, 0, 2, mauvaise), /ne supprime jamais/);
    assert.deepEqual(supprimes, []);
});

test('montee de version : les migrations manquantes seules sont jouees, dans l\'ordre, et les donnees restent', async () => {
    const indexedDB = creerFauxIndexedDB();

    // Version 1 livree : une déclaration en attente d'envoi et un réglage.
    const v1 = creerStockage(await ouvrirBase({ indexedDB }));
    await v1.file.put({ id: 'cle-1', type: 'declaration', statut: 'en_attente', bloc_preuve: 'AAAA' });
    await v1.meta.ecrire('appareil', 'uuid-1');

    // Version 2 (hypothétique) : ajoute un magasin, ne touche à rien d'autre.
    const appels = [];
    const migrations = [
        ...MIGRATIONS,
        (base) => { appels.push('v2'); base.createObjectStore('journal', { keyPath: 'id' }); },
        (base) => { appels.push('v3'); base.createObjectStore('autre', { keyPath: 'id' }); },
    ];
    const v3 = creerStockage(await ouvrirBase({ indexedDB, migrations }));

    assert.deepEqual(appels, ['v2', 'v3']);
    assert.equal(indexedDB.bases.get(NOM_BASE).version, 3);
    assert.deepEqual([...indexedDB.bases.get(NOM_BASE).magasins.keys()].sort(), ['autre', 'elements', 'file', 'journal', 'meta', 'pieces']);
    assert.deepEqual(await v3.file.get('cle-1'), { id: 'cle-1', type: 'declaration', statut: 'en_attente', bloc_preuve: 'AAAA' });
    assert.equal(await v3.meta.lire('appareil'), 'uuid-1');

    // Une migration qui supprimerait un magasin fait échouer l'ouverture sans rien effacer.
    const destructrice = [...migrations, (base) => base.deleteObjectStore('file')];
    await assert.rejects(ouvrirBase({ indexedDB, migrations: destructrice }), /ne supprime jamais/);
    assert.ok(indexedDB.bases.get(NOM_BASE).magasins.has('file'));
    assert.equal((await v3.file.list()).length, 1);
});

test('migrer refuse des versions incohérentes', () => {
    const base = { createObjectStore() {} };
    assert.throws(() => migrer(base, null, 2, 1), RangeError);
    assert.throws(() => migrer(base, null, 0, MIGRATIONS.length + 1), RangeError);
    assert.throws(() => migrer(base, null, -1, 1), RangeError);
});

test('magasins : get, put, list, delete, et interface attendue par la file', async () => {
    const stockage = creerStockage(await ouvrirBase({ indexedDB: creerFauxIndexedDB() }));
    for (const methode of ['get', 'put', 'list', 'delete']) assert.equal(typeof stockage.file[methode], 'function');
    assert.equal(stockage.file.clear, undefined, 'pas de vidage d\'un magasin');

    await stockage.elements.put({ id: 'remise-1', n: 1 });
    await stockage.elements.put({ id: 'remise-2', n: 2 });
    await stockage.elements.put({ id: 'remise-1', n: 3 });
    assert.deepEqual((await stockage.elements.list()).map((e) => e.n).sort(), [2, 3]);
    await stockage.elements.delete('remise-2');
    assert.equal(await stockage.elements.get('remise-2'), undefined);

    await stockage.pieces.put({ empreinte: 'a'.repeat(64), blob: new Blob(['x'], { type: 'image/png' }) });
    assert.equal((await stockage.pieces.get('a'.repeat(64))).blob.size, 1);
});

test('meta : lire, ecrire, supprimer, lister par prefixe', async () => {
    const { meta } = creerStockage(await ouvrirBase({ indexedDB: creerFauxIndexedDB() }));

    assert.equal(await meta.lire('inconnu'), undefined);
    await meta.ecrire('reserve:1', { decharge_id: 1 });
    await meta.ecrire('reserve:2', { decharge_id: 2 });
    await meta.ecrire('session', { token: 'x' });
    assert.deepEqual((await meta.lister('reserve:')).map((r) => r.decharge_id).sort(), [1, 2]);
    await meta.supprimer('reserve:1');
    assert.equal((await meta.lister('reserve:')).length, 1);
});

test('identifiant de la tablette : genere une fois, conserve ensuite', async () => {
    const { meta } = creerStockage(await ouvrirBase({ indexedDB: creerFauxIndexedDB() }));

    const premier = await identifiantAppareil(meta, () => '3F2504E0-4F89-41D3-9A0C-0305E82C3301');
    assert.equal(premier, '3f2504e0-4f89-41d3-9a0c-0305e82c3301');
    assert.equal(await identifiantAppareil(meta, () => { throw new Error('ne doit pas regenerer'); }), premier);
    assert.match(await identifiantAppareil((await ouvrirBase({ indexedDB: creerFauxIndexedDB() }).then(creerStockage)).meta), /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/);
    assert.deepEqual(Object.keys(MAGASINS).sort(), ['ELEMENTS', 'FILE', 'META', 'PIECES']);
});
