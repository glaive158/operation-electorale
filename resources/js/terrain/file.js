/**
 * File d'envoi de la tablette : logique pure, sans IndexedDB ni réseau.
 *
 * Interface du stockage injecté (tous les appels sont asynchrones, les éléments sont des objets clonables,
 * identifiés par leur champ `id` de type chaîne) :
 *   get(id)       -> Promise<element | undefined>
 *   put(element)  -> Promise<void>        insère ou remplace l'élément de même `id`
 *   list()        -> Promise<element[]>   tous les éléments, dans un ordre quelconque
 *   delete(id)    -> Promise<void>
 *
 * Éléments (champ `type`) :
 *   piece         { id = empreinte, empreinte (SHA-256 hex), role }                 -> POST /api/terrain/pieces
 *   declaration   { id, cle, horodatage_terminal, bloc_preuve, pieces: [{empreinte, role}], ... }
 *   redistribution{ id, cle, horodatage_terminal, decharge_id, ... }
 *   liberation    { id, decharge_id, ... }                                          -> DELETE .../reserve
 *   autre type    uniquement à l'état `disponible` (données téléchargées, rien à envoyer).
 * Le corps de la requête et l'URL restent l'affaire de sync.js ; la file garde l'élément tel qu'il a été
 * ajouté (cle, bloc_preuve, horodatage_terminal ne sont jamais modifiés : le bloc chiffré n'est jamais refait).
 *
 * Statuts : disponible, en_attente, synchronise, a_verifier.
 *
 * Résultat d'un envoi, passé à marquer(id, issue) : { status, corps } (status 0 ou absent = pas de réponse) :
 *   2xx                  -> synchronise, `reponse` = corps du serveur, `http` = status
 *   401                  -> reste en_attente et SUSPEND la file jusqu'à reprendre() (nouvelle connexion)
 *   409                  -> reste en_attente, réessayé après delaiReessaiMs, mêmes cle et bloc
 *   0, 408, 429, 5xx     -> reste en_attente, réessayé après delaiReessaiMs
 *   404, 422, autre 4xx  -> a_verifier (le message du serveur est conservé), jamais renvoyé sans reessayer()
 * Un statut direct (chaîne) est aussi accepté : marquer(id, 'synchronise').
 */

export const STATUTS = Object.freeze({
    DISPONIBLE: 'disponible',
    EN_ATTENTE: 'en_attente',
    SYNCHRONISE: 'synchronise',
    A_VERIFIER: 'a_verifier',
});

export const MESSAGE_EN_COURS = 'Déclaration en cours de traitement, réessayez.';
export const MESSAGE_CLE_REUTILISEE = 'Clé d\'idempotence déjà utilisée pour un autre contenu.';

const TYPES_ENVOYES = ['piece', 'declaration', 'redistribution', 'liberation'];
const FORMAT_CLE = /^[A-Za-z0-9-]{16,64}$/;
const FORMAT_EMPREINTE = /^[0-9a-f]{64}$/;
const FORMAT_HORODATAGE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/;
const DELAI_REESSAI_MS = 30_000;

const deux = (n) => String(n).padStart(2, '0');

/**
 * Horodatage au format attendu par le serveur : Y-m-d\TH:i:s+HH:MM (décalage obligatoire, ni « Z », ni millisecondes).
 *
 * @param {Date} date
 * @param {number} decalageMinutes  décalage à l'est de UTC en minutes ; par défaut celui de l'appareil
 */
export function horodatage(date = new Date(), decalageMinutes = -date.getTimezoneOffset()) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
        throw new RangeError('Date invalide.');
    }
    if (!Number.isInteger(decalageMinutes) || Math.abs(decalageMinutes) >= 24 * 60) {
        throw new RangeError('Décalage horaire invalide.');
    }
    const local = new Date(date.getTime() + decalageMinutes * 60_000);
    const signe = decalageMinutes < 0 ? '-' : '+';
    const absolu = Math.abs(decalageMinutes);

    return `${String(local.getUTCFullYear()).padStart(4, '0')}-${deux(local.getUTCMonth() + 1)}-${deux(local.getUTCDate())}`
        + `T${deux(local.getUTCHours())}:${deux(local.getUTCMinutes())}:${deux(local.getUTCSeconds())}`
        + `${signe}${deux(Math.floor(absolu / 60))}:${deux(absolu % 60)}`;
}

function valider(element) {
    if (element === null || typeof element !== 'object') {
        throw new TypeError('Élément de file invalide.');
    }
    const statut = element.statut ?? STATUTS.EN_ATTENTE;
    if (!Object.values(STATUTS).includes(statut)) {
        throw new TypeError(`Statut inconnu : ${statut}.`);
    }
    const copie = structuredClone(element);
    copie.statut = statut;

    if (typeof copie.type !== 'string' || copie.type === '') {
        throw new TypeError('Type d\'élément manquant.');
    }
    if (statut === STATUTS.DISPONIBLE) {
        if (typeof copie.id !== 'string' || copie.id === '') throw new TypeError('Identifiant d\'élément manquant.');

        return copie;
    }
    if (!TYPES_ENVOYES.includes(copie.type)) {
        throw new TypeError(`Type d'élément à envoyer inconnu : ${copie.type}.`);
    }

    if (copie.type === 'piece') {
        if (typeof copie.empreinte !== 'string' || !FORMAT_EMPREINTE.test(copie.empreinte)) {
            throw new TypeError('Empreinte de pièce invalide (SHA-256 hexadécimal minuscule).');
        }
        copie.id = copie.empreinte;
    } else if (copie.type === 'liberation') {
        if (typeof copie.id !== 'string' || copie.id === '') throw new TypeError('Identifiant d\'élément manquant.');
        if (!Number.isInteger(copie.decharge_id)) throw new TypeError('Décharge manquante.');
    } else {
        if (typeof copie.cle !== 'string' || !FORMAT_CLE.test(copie.cle)) {
            throw new TypeError('Clé d\'idempotence invalide (16 à 64 caractères A-Z a-z 0-9 -).');
        }
        if (typeof copie.horodatage_terminal !== 'string' || !FORMAT_HORODATAGE.test(copie.horodatage_terminal)) {
            throw new TypeError('Horodatage invalide : Y-m-dTH:i:s+HH:MM attendu.');
        }
        if (copie.type === 'declaration') {
            if (typeof copie.bloc_preuve !== 'string' || copie.bloc_preuve === '') {
                throw new TypeError('Bloc de preuve manquant.');
            }
            copie.pieces = (copie.pieces ?? []).map((p) => (typeof p === 'string' ? { empreinte: p } : p));
            for (const p of copie.pieces) {
                if (typeof p.empreinte !== 'string' || !FORMAT_EMPREINTE.test(p.empreinte)) {
                    throw new TypeError('Empreinte de pièce invalide (SHA-256 hexadécimal minuscule).');
                }
            }
        }
        if (copie.type === 'redistribution' && !Number.isInteger(copie.decharge_id)) {
            throw new TypeError('Décharge manquante.');
        }
        copie.id ??= copie.cle;
    }

    return copie;
}

/**
 * @param {object} options
 * @param {{get: Function, put: Function, list: Function, delete: Function}} options.stockage
 * @param {() => number} [options.horloge]       millisecondes (injectable pour les tests)
 * @param {number} [options.delaiReessaiMs]      attente avant un nouvel essai (409, panne réseau, 429, 5xx)
 */
export function creerFile({ stockage, horloge = () => Date.now(), delaiReessaiMs = DELAI_REESSAI_MS }) {
    for (const methode of ['get', 'put', 'list', 'delete']) {
        if (typeof stockage?.[methode] !== 'function') {
            throw new TypeError(`Stockage invalide : méthode ${methode} manquante.`);
        }
    }

    let suspendue = false;
    let chaine = Promise.resolve();
    /** Les écritures s'enchaînent : deux appels simultanés ne se marchent jamais dessus. */
    const exclusif = (fn) => {
        const resultat = chaine.then(fn);
        chaine = resultat.catch(() => {});

        return resultat;
    };

    const lire = async (id) => {
        const element = await stockage.get(id);
        if (!element) throw new Error(`Élément introuvable : ${id}.`);

        return element;
    };

    return {
        /**
         * Ajoute un élément (par défaut en_attente). Refuse d'écraser un élément qui n'est pas simplement
         * `disponible` : jamais de perte d'un envoi. Une pièce déjà connue (même empreinte) est renvoyée telle quelle.
         */
        ajouter: (element) => exclusif(async () => {
            const nouveau = valider(element);
            const existant = await stockage.get(nouveau.id);
            if (existant && existant.statut !== STATUTS.DISPONIBLE) {
                if (existant.type === 'piece' && nouveau.type === 'piece') return structuredClone(existant);
                throw new Error(`Élément déjà présent dans la file : ${nouveau.id}.`);
            }
            const ordre = existant?.ordre ?? 1 + (await stockage.list()).reduce((max, e) => Math.max(max, e.ordre ?? 0), 0);
            const stocke = { tentatives: 0, ...nouveau, ordre };
            await stockage.put(stocke);

            return structuredClone(stocke);
        }),

        /** Le prochain élément à envoyer (copie), ou null : file suspendue, rien en attente, ou tout est bloqué. */
        async prochain() {
            if (suspendue) return null;
            const maintenant = horloge();
            const tous = await stockage.list();
            const enAttente = tous.filter((e) => e.statut === STATUTS.EN_ATTENTE);
            const piecesEnAttente = new Set(enAttente.filter((e) => e.type === 'piece').map((e) => e.empreinte));
            const redistributions = new Set(enAttente.filter((e) => e.type === 'redistribution').map((e) => e.decharge_id));

            const envoyable = (e) => {
                if (e.pas_avant !== undefined && e.pas_avant > maintenant) return false;
                if (e.type === 'declaration') return !e.pieces.some((p) => piecesEnAttente.has(p.empreinte));
                if (e.type === 'liberation') return !redistributions.has(e.decharge_id);

                return true;
            };
            const suivant = enAttente.filter(envoyable).sort((a, b) => (a.ordre ?? 0) - (b.ordre ?? 0))[0];

            return suivant ? structuredClone(suivant) : null;
        },

        /**
         * Enregistre l'issue d'un envoi (voir l'en-tête du fichier). Seul un élément en_attente change d'état
         * sous l'effet d'une réponse du serveur ; un élément déjà synchronisé ne régresse jamais.
         */
        marquer: (id, issue) => exclusif(async () => {
            const element = await lire(id);

            if (typeof issue === 'string') {
                if (!Object.values(STATUTS).includes(issue)) throw new TypeError(`Statut inconnu : ${issue}.`);
                if (element.statut === STATUTS.SYNCHRONISE) return structuredClone(element);
                if (issue === STATUTS.DISPONIBLE && element.statut !== STATUTS.DISPONIBLE) {
                    throw new Error('Un élément à envoyer ne redevient pas « disponible » : il ne peut pas être perdu.');
                }
                element.statut = issue;
                if (issue === STATUTS.EN_ATTENTE) delete element.pas_avant;
                await stockage.put(element);

                return structuredClone(element);
            }

            if (element.statut !== STATUTS.EN_ATTENTE) return structuredClone(element);
            const status = Number.isInteger(issue?.status) ? issue.status : 0;
            const corps = issue?.corps;
            const message = typeof corps?.message === 'string' ? corps.message : undefined;
            element.tentatives = (element.tentatives ?? 0) + 1;
            element.http = status;

            if (status >= 200 && status < 300) {
                element.statut = STATUTS.SYNCHRONISE;
                element.reponse = corps ?? null;
                delete element.pas_avant;
                delete element.message;
            } else if (status === 401) {
                suspendue = true;
                element.message = message ?? 'Authentification requise.';
            } else if (status === 409 || status === 0 || status === 408 || status === 429 || status >= 500) {
                element.pas_avant = horloge() + delaiReessaiMs;
                element.message = message ?? (status === 0 ? 'Pas de réponse du serveur.' : undefined);
            } else {
                element.statut = STATUTS.A_VERIFIER;
                element.message = message ?? `Refusé par le serveur (${status}).`;
                delete element.pas_avant;
            }
            await stockage.put(element);

            return structuredClone(element);
        }),

        /** Remet en attente un élément à vérifier (décision de l'utilisateur) : mêmes cle et bloc. */
        reessayer: (id) => exclusif(async () => {
            const element = await lire(id);
            if (element.statut === STATUTS.A_VERIFIER) {
                element.statut = STATUTS.EN_ATTENTE;
                delete element.pas_avant;
                await stockage.put(element);
            }

            return structuredClone(element);
        }),

        /** Supprime un élément disponible ou synchronisé ; jamais un élément en attente ni à vérifier. */
        supprimer: (id) => exclusif(async () => {
            const element = await stockage.get(id);
            if (!element) return;
            if (element.statut === STATUTS.EN_ATTENTE) {
                throw new Error(`Suppression refusée : l'élément ${id} est en attente d'envoi.`);
            }
            if (element.statut === STATUTS.A_VERIFIER) {
                throw new Error(`Suppression refusée : l'élément ${id} est à vérifier.`);
            }
            await stockage.delete(id);
        }),

        /** Tous les éléments (copies), dans l'ordre d'ajout. */
        async lister() {
            return (await stockage.list()).map((e) => structuredClone(e)).sort((a, b) => (a.ordre ?? 0) - (b.ordre ?? 0));
        },

        /** À appeler après une nouvelle connexion : l'envoi reprend, rien n'a été perdu. */
        reprendre() {
            suspendue = false;
        },

        estSuspendue: () => suspendue,
    };
}
