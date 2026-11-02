/**
 * Forme canonique d'un contenu et son empreinte : le pendant JavaScript de App\Support\Canonique (PHP).
 * Les deux côtés sont testés sur les mêmes vecteurs (tests/fixtures/canonique.json).
 *
 * Règles (identiques au PHP) :
 * - JSON sans espace, écrit à la main : l'ordre des clés ne dépend jamais de JSON.stringify ;
 * - clés d'objet triées par octets UTF-8 (et non par unités UTF-16) ;
 * - listes conservées dans leur ordre ;
 * - un objet vide s'encode « [] » ; un objet dont les clés triées valent exactement « 0 », « 1 », … est
 *   encodé comme une liste (PHP : array_is_list après ksort SORT_STRING) ;
 * - chaînes écrites comme json_encode(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : « / » et les
 *   caractères non ASCII restent tels quels, seuls « " », « \ », les contrôles et U+2028/U+2029 sont échappés ;
 * - entiers uniquement (aucun flottant dans le domaine) ; null, true, false.
 *
 * Non pris en charge : un objet à clés entières de 11 clés ou plus (« 0 » à « 10 » et au-delà). Le PHP décide alors
 * selon l'ordre d'insertion (array_is_list avant ksort), que JavaScript ne conserve pas ; le domaine n'en a aucun
 * (les lignes sont des listes). Le JS suit la règle des clés triées comme des chaînes.
 */

const encodeur = new TextEncoder();

const ECHAPPEMENTS = {
    0x22: '\\"',
    0x5c: '\\\\',
    0x08: '\\b',
    0x0c: '\\f',
    0x0a: '\\n',
    0x0d: '\\r',
    0x09: '\\t',
};

const hex4 = (n) => n.toString(16).padStart(4, '0');

function chaine(texte) {
    let sortie = '"';
    for (let i = 0; i < texte.length; i++) {
        const c = texte.charCodeAt(i);
        if (c >= 0xd800 && c <= 0xdbff) {
            const suivant = texte.charCodeAt(i + 1);
            if (!(suivant >= 0xdc00 && suivant <= 0xdfff)) {
                throw new TypeError('Chaîne UTF-16 invalide (substitut isolé).');
            }
            sortie += texte[i] + texte[i + 1];
            i++;
        } else if (c >= 0xdc00 && c <= 0xdfff) {
            throw new TypeError('Chaîne UTF-16 invalide (substitut isolé).');
        } else if (ECHAPPEMENTS[c] !== undefined) {
            sortie += ECHAPPEMENTS[c];
        } else if (c < 0x20 || c === 0x2028 || c === 0x2029) {
            sortie += '\\u' + hex4(c);
        } else {
            sortie += texte[i];
        }
    }

    return sortie + '"';
}

/** Comparaison octet par octet des encodages UTF-8 (comme strcmp sur les clés PHP). */
function comparerOctets(a, b) {
    const x = encodeur.encode(a);
    const y = encodeur.encode(b);
    const n = Math.min(x.length, y.length);
    for (let i = 0; i < n; i++) {
        if (x[i] !== y[i]) return x[i] - y[i];
    }

    return x.length - y.length;
}

function estObjetSimple(valeur) {
    const proto = Object.getPrototypeOf(valeur);

    return proto === Object.prototype || proto === null;
}

function encoder(valeur) {
    if (valeur === null) return 'null';

    switch (typeof valeur) {
        case 'boolean':
            return valeur ? 'true' : 'false';
        case 'number':
            if (!Number.isSafeInteger(valeur)) {
                throw new TypeError(`Seuls les entiers sûrs sont admis dans un contenu canonique (reçu : ${valeur}).`);
            }

            return String(valeur);
        case 'string':
            return chaine(valeur);
        case 'object': {
            if (Array.isArray(valeur)) {
                return '[' + valeur.map(encoder).join(',') + ']';
            }
            if (!estObjetSimple(valeur)) {
                throw new TypeError('Seuls les objets simples et les listes sont admis dans un contenu canonique.');
            }
            const cles = Object.keys(valeur).sort(comparerOctets);
            if (cles.length === 0) return '[]';
            if (cles.every((cle, i) => cle === String(i))) {
                return '[' + cles.map((cle) => encoder(valeur[cle])).join(',') + ']';
            }

            return '{' + cles.map((cle) => chaine(cle) + ':' + encoder(valeur[cle])).join(',') + '}';
        }
        default:
            throw new TypeError(`Valeur non représentable dans un contenu canonique (${typeof valeur}).`);
    }
}

/** JSON canonique d'une valeur. */
export function json(valeur) {
    return encoder(valeur);
}

/** SHA-256 hexadécimal minuscule du JSON canonique (octets UTF-8). */
export async function empreinte(valeur) {
    const octets = encodeur.encode(json(valeur));
    const condense = new Uint8Array(await globalThis.crypto.subtle.digest('SHA-256', octets));

    return Array.from(condense, (o) => o.toString(16).padStart(2, '0')).join('');
}

// Même ensemble que Illuminate\Support\Str::trim (caractères blancs et invisibles), appliqué aux saisies
// par le middleware TrimStrings : le serveur rogne les chaînes reçues avant de recalculer l'empreinte.
const BLANCS = '\\s\\t\\n\\r\\v\\0'
    + '\\u0009\\u0020\\u0085\\u00A0\\u00AD\\u034F\\u061C\\u115F\\u1160\\u17B4\\u17B5\\u180E'
    + '\\u2000-\\u200F\\u202F\\u205F\\u2060-\\u2065\\u206A-\\u206F\\u3000\\u2800\\u3164\\uFEFF\\uFFA0'
    + '\\u{1D159}\\u{1D173}-\\u{1D17A}\\u{E0020}';
const BORDS = new RegExp(`^[${BLANCS}]+|[${BLANCS}]+$`, 'gu');

/** Rogne une chaîne comme le serveur. */
export function rogner(texte) {
    return texte.replace(BORDS, '');
}

/** Copie profonde dont toutes les chaînes (valeurs, pas les clés) sont rognées comme le serveur le fait. */
export function normaliser(valeur) {
    if (typeof valeur === 'string') return rogner(valeur);
    if (Array.isArray(valeur)) return valeur.map(normaliser);
    if (valeur !== null && typeof valeur === 'object') {
        return Object.fromEntries(Object.entries(valeur).map(([cle, v]) => [cle, normaliser(v)]));
    }

    return valeur;
}
