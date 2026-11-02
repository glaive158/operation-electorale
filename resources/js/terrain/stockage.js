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

/**
 * La transaction de montée de version vue par une migration : on peut lire et écrire (transformer des données),
 * jamais vider un magasin (`clear`) ni supprimer un enregistrement (`delete`), ni supprimer un index.
 */
export function protegerTransaction(transaction) {
    if (!transaction) return transaction;
    const interdit = (nom) => () => { throw new Error(`Une migration ne peut pas appeler ${nom} : aucune donnée de la tablette n'est effacée.`); };

    return {
        objectStore: (nom) => {
            const magasin = transaction.objectStore(nom);

            return new Proxy(magasin, {
                get(cible, propriete) {
                    if (propriete === 'clear' || propriete === 'delete' || propriete === 'deleteIndex') return interdit(`${nom}.${String(propriete)}`);
                    const valeur = Reflect.get(cible, propriete, cible);

                    return typeof valeur === 'function' ? valeur.bind(cible) : valeur;
                },
            });
        },
        abort: () => transaction.abort(),
        get oncomplete() { return transaction.oncomplete; },
        set oncomplete(f) { transaction.oncomplete = f; },
    };
}

/** Joue les migrations de `ancienne` (exclue) à `nouvelle` (incluse), dans l'ordre. */
export function migrer(base, transaction, ancienne, nouvelle, migrations = MIGRATIONS) {
    if (!Number.isInteger(ancienne) || !Number.isInteger(nouvelle) || ancienne < 0 || nouvelle < ancienne || nouvelle > migrations.length) {
        throw new RangeError(`Montée de version impossible : ${ancienne} -> ${nouvelle}.`);
    }
    const protegee = proteger(base);
    for (let version = ancienne; version < nouvelle; version += 1) {
        migrations[version](protegee, protegerTransaction(transaction));
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
        let termine = false;
        const echouer = (erreur) => {
            if (termine) return;
            termine = true;
            reject(erreur);
        };
        const requete = indexedDB.open(nom, migrations.length);
        requete.onupgradeneeded = (evenement) => {
            try {
                migrer(requete.result, requete.transaction, evenement.oldVersion, migrations.length, migrations);
            } catch (erreur) {
                requete.transaction?.abort();
                echouer(erreur);
            }
        };
        requete.onsuccess = () => {
            const base = requete.result;
            if (termine) {
                // Ouverture aboutie après un refus (autre fenêtre qui se ferme tard) : on ne garde pas de connexion orpheline.
                base.close();

                return;
            }
            termine = true;
            // Une autre fenêtre veut migrer : on lâche la base plutôt que de la bloquer.
            base.onversionchange = () => base.close();
            resolve(base);
        };
        requete.onerror = () => echouer(requete.error ?? new Error('Ouverture de la base impossible.'));
        requete.onblocked = () => echouer(new Error('Base verrouillée par une autre fenêtre : fermez les autres onglets du terrain.'));
    });
}

function execute(base, magasin, mode, action, options = undefined) {
    return new Promise((resolve, reject) => {
        const transaction = options ? base.transaction(magasin, mode, options) : base.transaction(magasin, mode);
        let resultat;
        transaction.oncomplete = () => resolve(resultat);
        transaction.onerror = () => reject(transaction.error ?? new Error('Transaction IndexedDB en erreur.'));
        transaction.onabort = () => reject(transaction.error ?? new Error('Transaction IndexedDB annulée.'));
        enPromesse(action(transaction.objectStore(magasin))).then((valeur) => { resultat = valeur; }, reject);
    });
}

/** Durabilité stricte : l'écriture n'est confirmée qu'une fois sur le disque (option ignorée là où elle n'existe pas). */
const STRICTE = Object.freeze({ durability: 'strict' });

/**
 * Un magasin vu comme l'attend file.js : get(id), put(element), list(), delete(id), tous asynchrones.
 * Pas de `clear` : on n'efface jamais un magasin d'un coup. `durabiliteStricte` : écritures en durabilité stricte
 * (file d'envoi et octets des pièces : une coupure juste après « Enregistrée » ne doit rien perdre).
 */
export function magasin(base, nom, { durabiliteStricte = false } = {}) {
    const ecriture = durabiliteStricte ? STRICTE : undefined;

    return {
        get: (id) => execute(base, nom, 'readonly', (m) => m.get(id)),
        put: (valeur) => execute(base, nom, 'readwrite', (m) => m.put(valeur), ecriture).then(() => undefined),
        list: () => execute(base, nom, 'readonly', (m) => m.getAll()),
        delete: (id) => execute(base, nom, 'readwrite', (m) => m.delete(id), ecriture).then(() => undefined),
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
        file: magasin(base, MAGASINS.FILE, { durabiliteStricte: true }),
        pieces: magasin(base, MAGASINS.PIECES, { durabiliteStricte: true }),
        meta: meta(base),
    };
}

/**
 * Demande au navigateur de ne pas effacer la base de la tablette sous pression d'espace (stockage persistant).
 * Ne lève jamais : sans API, sur refus ou en erreur, renvoie false (stockage « non garanti »).
 *
 * @param {{storage?: {persisted?: Function, persist?: Function}}} [navigateur]
 * @returns {Promise<boolean>} true si le stockage est protégé
 */
export async function demanderStockagePersistant(navigateur = globalThis.navigator) {
    const storage = navigateur?.storage;
    if (typeof storage?.persist !== 'function') return false;
    try {
        if (typeof storage.persisted === 'function' && (await storage.persisted()) === true) return true;

        return (await storage.persist()) === true;
    } catch {
        return false;
    }
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
