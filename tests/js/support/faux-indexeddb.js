/**
 * IndexedDB minimal en mémoire pour les tests : ouverture avec montée de version, magasins à clé
 * (`keyPath`), get / put / getAll / delete, transactions. Les valeurs sont clonées comme le fait le navigateur.
 */

export function creerFauxIndexedDB() {
    const bases = new Map();

    function requete(action, transaction) {
        const r = { result: undefined, error: null };
        transaction.enCours += 1;
        queueMicrotask(() => {
            try {
                r.result = action();
                r.onsuccess?.({ target: r });
            } catch (erreur) {
                r.error = erreur;
                r.onerror?.({ target: r });
                transaction.onerror?.({ target: transaction });
            }
            transaction.enCours -= 1;
            if (transaction.enCours === 0) queueMicrotask(() => transaction.oncomplete?.());
        });

        return r;
    }

    function ouvrirTransaction(base, noms) {
        const transaction = { enCours: 0, error: null };
        transaction.objectStore = (nom) => {
            const magasin = base.magasins.get(nom);
            if (!magasin) throw new Error(`Magasin inconnu : ${nom}`);
            const cle = (valeur) => valeur[magasin.keyPath];

            return {
                get: (id) => requete(() => structuredClone(magasin.donnees.get(id)), transaction),
                put: (valeur) => requete(() => { magasin.donnees.set(cle(valeur), structuredClone(valeur)); return cle(valeur); }, transaction),
                getAll: () => requete(() => [...magasin.donnees.values()].map((v) => structuredClone(v)), transaction),
                delete: (id) => requete(() => { magasin.donnees.delete(id); }, transaction),
            };
        };
        void noms;

        return transaction;
    }

    return {
        bases,
        open(nom, version) {
            const demande = { result: null, error: null, transaction: null };
            queueMicrotask(() => {
                const base = bases.get(nom) ?? { version: 0, magasins: new Map() };
                const facade = {
                    objectStoreNames: { contains: (n) => base.magasins.has(n) },
                    createObjectStore: (n, options) => { base.magasins.set(n, { keyPath: options.keyPath, donnees: new Map() }); },
                    deleteObjectStore: (n) => { base.magasins.delete(n); },
                    transaction: (noms) => ouvrirTransaction(base, noms),
                    close() {},
                };
                demande.result = facade;
                if (version > base.version) {
                    demande.transaction = { abort() {} };
                    const ancienne = base.version;
                    bases.set(nom, base);
                    try {
                        demande.onupgradeneeded?.({ oldVersion: ancienne, newVersion: version });
                    } catch (erreur) {
                        demande.error = erreur;
                        demande.onerror?.({ target: demande });

                        return;
                    }
                    base.version = version;
                }
                bases.set(nom, base);
                demande.onsuccess?.({ target: demande });
            });

            return demande;
        },
    };
}
