/**
 * Appels à l'API de terrain (jeton Bearer). Aucune exception : le résultat est toujours { status, corps },
 * avec status 0 quand le réseau ne répond pas (la file de sync.js réessaiera).
 */

const DELAI_MS = 30_000;

/**
 * @param {object} options
 * @param {() => (string|null|undefined)} options.jeton       jeton courant (lu à chaque appel)
 * @param {() => (string|null|undefined)} [options.appareil]  identifiant de la tablette (en-tête X-Appareil)
 * @param {string} [options.base]
 * @param {typeof fetch} [options.fetchFn]
 */
export function creerApi({ jeton, appareil = () => null, base = '/api/terrain', fetchFn = (...args) => globalThis.fetch(...args) }) {
    async function appeler(methode, chemin, { json, formData, avecAppareil = false, sansJeton = false } = {}) {
        const entetes = { Accept: 'application/json' };
        if (!sansJeton) {
            const porteur = jeton();
            if (!porteur) return { status: 401, corps: { message: 'Authentification requise.' } };
            entetes.Authorization = `Bearer ${porteur}`;
        }
        if (avecAppareil && appareil()) entetes['X-Appareil'] = appareil();
        let corps;
        if (json !== undefined) {
            entetes['Content-Type'] = 'application/json';
            corps = JSON.stringify(json);
        } else if (formData) {
            corps = formData;
        }

        const controle = new AbortController();
        const minuteur = setTimeout(() => controle.abort(), DELAI_MS);
        try {
            const reponse = await fetchFn(base + chemin, { method: methode, headers: entetes, body: corps, signal: controle.signal, credentials: 'omit' });
            let contenu = null;
            if (methode !== 'HEAD' && (reponse.headers.get('Content-Type') ?? '').includes('json')) {
                try {
                    contenu = await reponse.json();
                } catch {
                    contenu = null;
                }
            }

            return { status: reponse.status, corps: contenu };
        } catch {
            return { status: 0, corps: null };
        } finally {
            clearTimeout(minuteur);
        }
    }

    return {
        envoyer: appeler,

        /** Ouvre un jeton. `appareil` est l'UUID de l'installation. */
        connexion: (email, motDePasse, identifiant) => appeler('POST', '/jetons', {
            json: { email, password: motDePasse, appareil: identifiant },
            sansJeton: true,
        }),
    };
}
