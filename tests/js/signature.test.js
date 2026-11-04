import assert from 'node:assert/strict';
import { test } from 'node:test';

import { dimensionsReduites } from '../../resources/js/terrain/photo.js';
import { creerSignature, LONGUEUR_MINIMALE } from '../../resources/js/terrain/signature.js';
import { Element } from './support/faux-dom.js';

function canvasFictif() {
    const canvas = new Element('canvas');
    canvas.appels = [];
    canvas.contexte = new Proxy({}, {
        get: (c, nom) => (nom in c ? c[nom] : (...args) => canvas.appels.push([nom, ...args])),
        set: (c, nom, valeur) => { c[nom] = valeur; return true; },
    });
    // Affiché deux fois plus petit que sa taille interne (800x300 pour 400x150).
    canvas.getBoundingClientRect = () => ({ left: 10, top: 20, width: 400, height: 150 });

    return canvas;
}

const evt = (x, y, id = 1) => ({ clientX: x, clientY: y, pointerId: id });

test('la signature est vide tant que le trace est trop court', async () => {
    const canvas = canvasFictif();
    const signature = creerSignature(canvas);
    assert.equal(signature.estVide(), true);

    await canvas.emettre('pointerdown', evt(10, 20));
    await canvas.emettre('pointerup', evt(10, 20));
    assert.equal(signature.estVide(), true, 'un simple appui ne signe pas');

    await canvas.emettre('pointerdown', evt(10, 20));
    await canvas.emettre('pointermove', evt(60, 20));
    await canvas.emettre('pointermove', evt(60, 60));
    await canvas.emettre('pointerup', evt(60, 60));
    assert.equal(signature.estVide(), false);
});

test('les coordonnees de l\'ecran sont ramenees a la taille interne du canvas', async () => {
    const canvas = canvasFictif();
    creerSignature(canvas);

    await canvas.emettre('pointerdown', evt(10 + 100, 20 + 50));
    await canvas.emettre('pointermove', evt(10 + 200, 20 + 50));

    const traits = canvas.appels.filter(([nom]) => nom === 'moveTo' || nom === 'lineTo');
    assert.deepEqual(traits, [['moveTo', 200, 100], ['lineTo', 400, 100]]);
});

test('longueur du trace : mesuree en pixels du canvas et seuil documente', async () => {
    const canvas = canvasFictif();
    const signature = creerSignature(canvas);
    await canvas.emettre('pointerdown', evt(10, 20));
    // 10 px écran = 20 px canvas : 1 px de moins que le seuil, puis juste au-dessus.
    await canvas.emettre('pointermove', evt(10 + (LONGUEUR_MINIMALE - 2) / 2, 20));
    assert.equal(signature.estVide(), true);
    await canvas.emettre('pointermove', evt(10 + (LONGUEUR_MINIMALE + 2) / 2, 20));
    assert.equal(signature.estVide(), false);
});

test('effacer remet la signature a zero ; un autre pointeur est ignore ; detruire retire les ecouteurs', async () => {
    const canvas = canvasFictif();
    const signature = creerSignature(canvas);
    await canvas.emettre('pointerdown', evt(10, 20, 1));
    await canvas.emettre('pointermove', evt(310, 20, 2));
    assert.equal(signature.estVide(), true, 'second doigt ignoré');
    await canvas.emettre('pointermove', evt(310, 20, 1));
    assert.equal(signature.estVide(), false);

    signature.effacer();
    assert.equal(signature.estVide(), true);

    signature.detruire();
    await canvas.emettre('pointerdown', evt(10, 20));
    await canvas.emettre('pointermove', evt(310, 20));
    assert.equal(signature.estVide(), true);
});

test('export PNG', async () => {
    const canvas = canvasFictif();
    const signature = creerSignature(canvas);

    const blob = await signature.versPng();

    assert.equal(blob.type, 'image/png');
});

test('photo : reduction proportionnelle, jamais d\'agrandissement', () => {
    assert.deepEqual(dimensionsReduites(4000, 3000), { largeur: 1600, hauteur: 1200 });
    assert.deepEqual(dimensionsReduites(3000, 4000), { largeur: 1200, hauteur: 1600 });
    assert.deepEqual(dimensionsReduites(800, 600), { largeur: 800, hauteur: 600 });
    assert.deepEqual(dimensionsReduites(1, 5000), { largeur: 1, hauteur: 1600 });
});
