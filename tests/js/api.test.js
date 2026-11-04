import assert from 'node:assert/strict';
import { test } from 'node:test';

import { creerApi } from '../../resources/js/terrain/api.js';

function faux(reponse) {
    const appels = [];
    const fetchFn = async (url, options) => {
        appels.push({ url, options });
        const r = typeof reponse === 'function' ? await reponse(url, options) : reponse;
        if (r instanceof Error) throw r;

        return new Response(r.corps === undefined ? null : JSON.stringify(r.corps), { status: r.status, headers: r.corps === undefined ? {} : { 'Content-Type': 'application/json' } });
    };

    return { appels, fetchFn };
}

test('jeton Bearer, JSON, pas de cookies ; en-tete appareil seulement a la demande', async () => {
    const { appels, fetchFn } = faux({ status: 200, corps: { ok: true } });
    const api = creerApi({ jeton: () => 'tok', appareil: () => 'uuid-1', fetchFn });

    const a = await api.envoyer('POST', '/pieces/x', { json: { a: 1 } });
    await api.envoyer('GET', '/appareil', { avecAppareil: true });

    assert.deepEqual(a, { status: 200, corps: { ok: true } });
    assert.equal(appels[0].url, '/api/terrain/pieces/x');
    assert.equal(appels[0].options.headers.Authorization, 'Bearer tok');
    assert.equal(appels[0].options.headers.Accept, 'application/json');
    assert.equal(appels[0].options.headers['Content-Type'], 'application/json');
    assert.equal(appels[0].options.body, '{"a":1}');
    assert.equal(appels[0].options.credentials, 'omit');
    assert.equal(appels[0].options.headers['X-Appareil'], undefined);
    assert.equal(appels[1].options.headers['X-Appareil'], 'uuid-1');
});

test('sans jeton : 401 immediat, aucune requete', async () => {
    const { appels, fetchFn } = faux({ status: 200, corps: {} });
    const api = creerApi({ jeton: () => null, fetchFn });

    const r = await api.envoyer('GET', '/affectations');

    assert.equal(r.status, 401);
    assert.equal(appels.length, 0);
});

test('multipart : le corps est transmis tel quel, sans Content-Type force', async () => {
    const { appels, fetchFn } = faux({ status: 201, corps: { piece: {} } });
    const api = creerApi({ jeton: () => 'tok', fetchFn });
    const donnees = new FormData();
    donnees.append('empreinte', 'a'.repeat(64));

    await api.envoyer('POST', '/pieces', { formData: donnees });

    assert.equal(appels[0].options.body, donnees);
    assert.equal(appels[0].options.headers['Content-Type'], undefined);
});

test('panne reseau : status 0 ; reponse sans JSON : corps nul', async () => {
    const api = creerApi({ jeton: () => 'tok', fetchFn: faux(new TypeError('Failed to fetch')).fetchFn });
    assert.deepEqual(await api.envoyer('GET', '/affectations'), { status: 0, corps: null });

    const tete = creerApi({ jeton: () => 'tok', fetchFn: faux({ status: 404 }).fetchFn });
    assert.deepEqual(await tete.envoyer('HEAD', '/pieces/x'), { status: 404, corps: null });
});

test('connexion : appareil = UUID de l\'installation, sans jeton', async () => {
    const { appels, fetchFn } = faux({ status: 201, corps: { token: 't' } });
    const api = creerApi({ jeton: () => 'ancien', fetchFn });

    const r = await api.connexion('a@b.sn', 'secret', '3f2504e0-4f89-41d3-9a0c-0305e82c3301');

    assert.equal(r.status, 201);
    assert.equal(appels[0].url, '/api/terrain/jetons');
    assert.equal(appels[0].options.method, 'POST');
    assert.equal(appels[0].options.headers.Authorization, undefined);
    assert.deepEqual(JSON.parse(appels[0].options.body), { email: 'a@b.sn', password: 'secret', appareil: '3f2504e0-4f89-41d3-9a0c-0305e82c3301' });
});
