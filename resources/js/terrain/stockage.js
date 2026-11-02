/**
 * Stockage de la tablette : IndexedDB « logistique-terrain ».
 *
 * Magasins :
 *   elements  affectations téléchargées (clé `id`)
 *   file      file d'envoi (clé `id`) : déclarations, pièces, redistributions, libérations
 *   pieces    octets des pièces (signature, photo) en attente d'envoi (clé `empreinte`)
 *   meta      réglages locaux (clé `cle`, valeur `valeur`) : appareil, session, clé de preuve, réserves…
 *
 * Règle de montée de version : une migration par numéro de version, jouée dans l'ordre depuis la version
 * installée ; elle ne fait qu'AJOUTER (magasins, index) ou transformer des données. Aucun magasin n'est
 * jamais supprimé ni vidé, aucun élément en attente n'est jamais effacé : `proteger` refuse l'appel.
 * Le code personnel n'est stocké nulle part.
 */

export const NOM_BASE = 'logistique-terrain';

export const MAGASINS = Object.freeze({
    ELEMENTS: 'elements',
    FILE: 'file',
    PIECES: 'pieces',
    META: 'meta',
});

/**
 * Migrations successives : MIGRATIONS[n] fait passer de la version n à la version n + 1.
 * Ajouter ici la suivante à la fin, sans jamais modifier une migration déjà livrée.
 */
export const MIGRATIONS = [
    (base) => {
        base.createObjectStore(MAGASINS.ELEMENTS, { keyPath: 'id' });
        base.createObjectStore(MAGASINS.FILE, { keyPath: 'id' });
        base.createObjectStore(MAGASINS.PIECES, { keyPath: 'empreinte' });
        base.createObjectStore(MAGASINS.META, { keyPath: 'cle' });
    },
];

export const VERSION = MIGRATIONS.length;

/** La base vue par une migration : on peut ajouter, jamais supprimer. */
export function proteger(base) {
    return {
        get objectStoreNames() {
            return base.objectStoreNames;
        },
        createObjectStore: (...args) => base.createObjectStore(...args),
        deleteObjectStore() {
            throw new Error('Une migration ne supprime jamais un magasin de la tablette.');
        },
    };
}

/** Joue les migrations de `ancienne` (exclue) à `nouvelle` (incluse), dans l'ordre. */
export function migrer(base, transaction, ancienne, nouvelle, migrations = MIGRATIONS) {
    if (!Number.isInteger(ancienne) || !Number.isInteger(nouvelle) || ancienne < 0 || nouvelle < ancienne || nouvelle > migrations.length) {
        throw new RangeError(`Montée de version impossible : ${ancienne} -> ${nouvelle}.`);
    }
    const protegee = proteger(base);
    for (let version = ancienne; version < nouvelle; version += 1) {
        migrations[version](protegee, transaction);
    }
}

const enPromesse = (requete) => new Promise((resolve, reject) => {
    requete.onsuccess = () => resolve(requete.result);
    requete.onerror = () => reject(requete.error ?? new Error('Erreur IndexedDB.'));
});

/** Ouvre (et migre si besoin) la base. */
export function ouvrirBase({ indexedDB = globalThis.indexedDB, nom = NOM_BASE, migrations = MIGRATIONS } = {}) {
    return new Promise((resolve, reject) => {
        if (!indexedDB) {
            reject(new Error('IndexedDB indisponible : stockage hors connexion impossible.'));

            return;
        }
        const requete = indexedDB.open(nom, migrations.length);
        requete.onupgradeneeded = (evenement) => {
            try {
                migrer(requete.result, requete.transaction, evenement.oldVersion, migrations.length, migrations);
            } catch (erreur) {
                requete.transaction?.abort();
                reject(erreur);
            }
        };
        requete.onsuccess = () => {
            const base = requete.result;
            // Une autre fenêtre veut migrer : on lâche la base plutôt que de la bloquer.
            base.onversionchange = () => base.close();
            resolve(base);
        };
        requete.onerror = () => reject(requete.error ?? new Error('Ouverture de la base impossible.'));
        requete.onblocked = () => reject(new Error('Base verrouillée par une autre fenêtre : fermez les autres onglets du terrain.'));
    });
}

function execute(base, magasin, mode, action) {
    return new Promise((resolve, reject) => {
        const transaction = base.transaction(magasin, mode);
        let resultat;
        transaction.oncomplete = () => resolve(resultat);
        transaction.onerror = () => reject(transaction.error ?? new Error('Transaction IndexedDB en erreur.'));
        transaction.onabort = () => reject(transaction.error ?? new Error('Transaction IndexedDB annulée.'));
        enPromesse(action(transaction.objectStore(magasin))).then((valeur) => { resultat = valeur; }, reject);
    });
}

/**
 * Un magasin vu comme l'attend file.js : get(id), put(element), list(), delete(id), tous asynchrones.
 * Pas de `clear` : on n'efface jamais un magasin d'un coup.
 */
export function magasin(base, nom) {
    return {
        get: (id) => execute(base, nom, 'readonly', (m) => m.get(id)),
        put: (valeur) => execute(base, nom, 'readwrite', (m) => m.put(valeur)).then(() => undefined),
        list: () => execute(base, nom, 'readonly', (m) => m.getAll()),
        delete: (id) => execute(base, nom, 'readwrite', (m) => m.delete(id)).then(() => undefined),
    };
}

/** Réglages locaux : lire(cle) -> valeur | undefined, ecrire(cle, valeur), supprimer(cle), lister(prefixe). */
export function meta(base) {
    const m = magasin(base, MAGASINS.META);

    return {
        async lire(cle) {
            return (await m.get(cle))?.valeur;
        },
        ecrire: (cle, valeur) => m.put({ cle, valeur }),
        supprimer: (cle) => m.delete(cle),
        async lister(prefixe) {
            return (await m.list()).filter((e) => e.cle.startsWith(prefixe)).map((e) => e.valeur);
        },
    };
}

/** Les quatre magasins de l'application, prêts à l'emploi. */
export function creerStockage(base) {
    return {
        base,
        elements: magasin(base, MAGASINS.ELEMENTS),
        file: magasin(base, MAGASINS.FILE),
        pieces: magasin(base, MAGASINS.PIECES),
        meta: meta(base),
    };
}

/**
 * Identifiant de cette installation : un UUID tiré une fois et conservé. Le préfet le donne au central pour
 * désigner la tablette (il ne sert pas à s'authentifier : c'est le jeton ouvert avec lui qui est épinglé).
 */
export async function identifiantAppareil(reglages, generer = () => globalThis.crypto.randomUUID()) {
    const existant = await reglages.lire('appareil');
    if (typeof existant === 'string' && existant !== '') return existant;
    const nouveau = generer().toLowerCase();
    await reglages.ecrire('appareil', nouveau);

    return nouveau;
}
