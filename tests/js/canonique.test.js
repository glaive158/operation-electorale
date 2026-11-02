import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { json, empreinte, normaliser } from '../../resources/js/terrain/canonique.js';

const vecteurs = JSON.parse(readFileSync(new URL('../fixtures/canonique.json', import.meta.url), 'utf8'));

test('canonique : vecteurs partagés avec PHP (json et empreinte)', async () => {
    assert.ok(vecteurs.length >= 11);
    for (const v of vecteurs) {
        assert.equal(json(v.entree), v.json, `json : ${v.note}`);
        assert.equal(await empreinte(v.entree), v.empreinte, `empreinte : ${v.note}`);
    }
});

test('canonique : les clés sont triées par octets UTF-8, pas par unités UTF-16', () => {
    // U+FF5E (3 octets UTF-8 : EF BD 9E, 1 unité UTF-16) contre U+1F600 (4 octets : F0 9F 98 80, 2 unités UTF-16 D83D DE00).
    // En UTF-16, D83D < FF5E ; en octets, EF < F0 : l'ordre est inversé.
    const entree = { '\u{1F600}': 1, '～': 2 };
    assert.equal(json(entree), '{"～":2,"\u{1F600}":1}');
});

test('canonique : une liste de dix éléments et un objet aux clés 0..10 ne se confondent pas', () => {
    const objet = {};
    for (let i = 0; i <= 10; i++) objet[String(i)] = i;
    // Triées comme des chaînes : 0,1,10,2,... ce n'est pas une liste (array_is_list est faux côté PHP).
    assert.equal(json(objet), '{"0":0,"1":1,"10":10,"2":2,"3":3,"4":4,"5":5,"6":6,"7":7,"8":8,"9":9}');
    assert.equal(json({ 0: 'a', 1: 'b', 2: 'c' }), '["a","b","c"]');
});

test('canonique : échappement des chaînes comme json_encode (UNESCAPED_UNICODE | UNESCAPED_SLASHES)', () => {
    assert.equal(json({ a: 'x"y\\z/é<>&\'' }), '{"a":"x\\"y\\\\z/é<>&\'"}');
    assert.equal(json({ a: '\b\f\n\r\t' }), '{"a":"\\b\\f\\n\\r\\t"}');
    assert.equal(json({ a: '\u0000\u0001\u001f\u007f' }), '{"a":"\\u0000\\u0001\\u001f\u007f"}');
    // PHP échappe les séparateurs de ligne Unicode même avec JSON_UNESCAPED_UNICODE.
    assert.equal(json({ a: '  ' }), '{"a":"\\u2028\\u2029"}');
    assert.equal(json({ 'clé"': 1 }), '{"clé\\"":1}');
});

test('canonique : nombres non entiers, valeurs non représentables et chaînes invalides sont refusés', () => {
    assert.throws(() => json({ a: 1.5 }), TypeError);
    assert.throws(() => json({ a: NaN }), TypeError);
    assert.throws(() => json({ a: Infinity }), TypeError);
    assert.throws(() => json({ a: 2 ** 60 }), TypeError);
    assert.throws(() => json({ a: undefined }), TypeError);
    assert.throws(() => json({ a: () => 1 }), TypeError);
    assert.throws(() => json({ a: new Date() }), TypeError);
    assert.throws(() => json({ a: '\uD800' }), TypeError);
    assert.throws(() => json({ a: '\uDC00x' }), TypeError);
    assert.equal(json({ a: '\u{1F600}' }), '{"a":"\u{1F600}"}');
});

test('canonique : entiers négatifs, zéro et zéro négatif', () => {
    assert.equal(json([0, -0, -5, 10000000]), '[0,0,-5,10000000]');
});

test('canonique : normaliser retire les blancs de bord comme le serveur et laisse le reste intact', () => {
    const brut = { nom: '  Awa Diop \n', obs: ' x​', n: 3, liste: [' a ', { k: ' b' }], nul: null, ok: true };
    assert.deepEqual(normaliser(brut), { nom: 'Awa Diop', obs: 'x', n: 3, liste: ['a', { k: 'b' }], nul: null, ok: true });
    assert.equal(normaliser('a  b'), 'a  b');
    assert.equal(brut.nom, '  Awa Diop \n', 'l\'entrée n\'est pas modifiée');
});
