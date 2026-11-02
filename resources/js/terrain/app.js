/**
 * Point d'entrée de l'application de terrain : stockage local, connexion par jeton, synchronisation,
 * service worker et écrans. Tout fonctionne sans réseau une fois la coquille et les affectations téléchargées.
 */

import { creerApi } from './api.js';
import { h, remplacer } from './dom.js';
import { ecranConnexion, ecranListe, ecranReception, ecranRedistribution } from './ecrans.js';
import { creerFile, STATUTS } from './file.js';
import { creerStockage, demanderStockagePersistant, identifiantAppareil, ouvrirBase } from './stockage.js';
import { creerSync, telechargerDonnees } from './sync.js';
import { enAttenteDEnvoi } from './vue.js';

const COQUILLE = '/terrain/app';

function lireAmorce() {
    try {
        return JSON.parse(document.getElementById('terrain-amorce')?.textContent ?? '{}');
    } catch {
        return {};
    }
}

function messageErreur(reponse) {
    if (reponse.status === 0) return 'Pas de réseau : la connexion à l\'application demande d\'être en ligne.';
    if (reponse.status === 429) return 'Trop de tentatives : patientez une minute avant de réessayer.';
    const premiere = reponse.corps?.errors ? Object.values(reponse.corps.errors)[0]?.[0] : null;

    return reponse.corps?.message && !reponse.corps?.errors ? reponse.corps.message : (premiere ?? reponse.corps?.message ?? 'Connexion refusée.');
}

async function coquilleEnCache() {
    try {
        return Boolean(await globalThis.caches?.match(COQUILLE));
    } catch {
        return false;
    }
}

export async function demarrer(racine) {
    const amorce = lireAmorce();
    let stockage;
    try {
        stockage = creerStockage(await ouvrirBase());
    } catch (erreur) {
        remplacer(racine, h('p', { class: 'p-6 text-sm text-red-700' }, `Stockage de la tablette indisponible : ${erreur.message}`));

        return;
    }

    const appareil = await identifiantAppareil(stockage.meta);
    const etat = {
        appareil,
        session: (await stockage.meta.lire('session')) ?? null,
        sessionExpiree: false,
        enregistrement: null,
        rechargementEnAttente: false,
        enLigne: navigator.onLine !== false,
        miseAJour: null,
        message: null,
        /** Stockage persistant accordé par le navigateur : undefined tant qu'il n'a pas répondu. */
        stockageProtege: undefined,
    };
    const api = creerApi({ jeton: () => (etat.sessionExpiree ? null : etat.session?.token ?? null), appareil: () => appareil, base: amorce.api ?? '/api/terrain' });
    const file = creerFile({ stockage: stockage.file });

    const enTete = h('header', { class: 'bg-slate-900 text-white' });
    const ecran = h('main', { class: 'p-4 pb-16' });
    const pied = h('footer', { class: 'px-4 pb-6 text-xs text-slate-500 text-center' });
    remplacer(racine, enTete, ecran, pied);

    /** Sur la liste, sans champ en cours de saisie : jamais pendant une saisie de données. */
    function ecranSansSaisie() {
        const route = location.hash.replace(/^#/, '');
        const surListe = route === '' || route === '/';
        const actif = document.activeElement;
        const enSaisie = actif && ecran.contains(actif) && ['INPUT', 'TEXTAREA', 'SELECT'].includes(actif.tagName);

        return surListe && !enSaisie;
    }

    async function demanderMiseAJour() {
        // Une nouvelle version n'est activée que lorsque rien n'attend d'être envoyé et qu'aucune saisie n'est en cours.
        if (!etat.miseAJour || !ecranSansSaisie()) return;
        const elements = await file.lister();
        const rienAEnvoyer = !elements.some((e) => e.statut === STATUTS.EN_ATTENTE);
        if (rienAEnvoyer && ecranSansSaisie()) etat.miseAJour.postMessage({ type: 'activer', file_vide: true });
    }

    async function rafraichirEntete() {
        const elements = await file.lister();
        const enAttente = enAttenteDEnvoi(elements);
        const designe = (await stockage.meta.lire('appareil_designe'))?.designe === true;
        const derniere = await stockage.meta.lire('derniere_sync');
        const liens = etat.session && !etat.sessionExpiree
            ? [h('a', { href: '#/', class: 'text-sm underline' }, 'Réceptions'), designe ? h('a', { href: '#/redistribution', class: 'text-sm underline' }, 'Redistribution') : null]
            : [];

        remplacer(enTete,
            h('div', { class: 'px-4 py-3 flex flex-wrap items-center gap-x-4 gap-y-1' },
                h('span', { class: 'font-bold text-sm' }, 'Terrain'),
                h('span', { class: `text-xs px-2 py-0.5 rounded-full ${etat.enLigne ? 'bg-green-600' : 'bg-amber-600'}`, id: 'etat-reseau' }, etat.enLigne ? 'En ligne' : 'Hors connexion'),
                etat.session ? h('span', { class: 'text-xs text-slate-300' }, etat.session.utilisateur?.nom ?? '') : null,
                h('span', { class: 'text-xs text-slate-300', id: 'compteur-file' }, enAttente ? `${enAttente} envoi(s) en attente` : 'Rien en attente d\'envoi'),
                derniere ? h('span', { class: 'text-xs text-slate-400' }, `Dernière synchronisation : ${new Date(derniere).toLocaleString('fr-FR')}`) : null,
                h('span', { class: 'flex gap-3 ml-auto items-center' }, liens,
                    etat.session && !etat.sessionExpiree ? h('button', { type: 'button', class: 'text-sm px-3 py-1 rounded bg-sen-green hover:opacity-90', id: 'bouton-sync', onclick: () => { sync.cycle({ manuel: true }).then(rafraichirEntete); } }, 'Synchroniser') : null)),
            etat.miseAJour ? h('div', { class: 'bg-amber-100 text-amber-900 text-xs px-4 py-2' }, 'Une nouvelle version est prête : elle sera appliquée quand tous les envois seront terminés.') : null,
            etat.message ? h('div', { class: 'bg-red-600 text-white text-xs px-4 py-2', role: 'alert' }, etat.message) : null);
        remplacer(pied, `Identifiant de la tablette : ${appareil}`);
    }

    const ctx = {
        etat, stockage, file, api, coquilleEnCache,
        nettoyages: [],
        demanderMiseAJour,
        sync: null,
        rafraichirEntete,
        signaler(texte) { etat.message = texte; rafraichirEntete(); },
        async rafraichir() { await rafraichirEntete(); await dessiner(); },
        async connecter(email, motDePasse) {
            const reponse = await api.connexion(email, motDePasse, appareil);
            if (reponse.status !== 201 || !reponse.corps?.token) return { erreur: messageErreur(reponse) };

            // Des envois d'un autre compte attendent sur cette tablette : ils ne partiraient pas avec ce jeton.
            // Ceux « à vérifier » n'attendent que leur titulaire : ils restent cachés à ce compte, sans le bloquer.
            const autres = (await file.lister()).filter((e) => e.statut === STATUTS.EN_ATTENTE
                && e.utilisateur_id != null && e.utilisateur_id !== reponse.corps.utilisateur.id);
            if (autres.length) {
                await creerApi({ jeton: () => reponse.corps.token, base: amorce.api ?? '/api/terrain' }).envoyer('DELETE', '/jetons/courant');

                return { erreur: 'Des envois d\'un autre compte sont en attente sur cette tablette : le compte concerné doit se reconnecter pour les envoyer.' };
            }

            etat.session = { token: reponse.corps.token, utilisateur: reponse.corps.utilisateur, expire_le: reponse.corps.expire_le, email };
            etat.sessionExpiree = false;
            etat.message = null;
            await stockage.meta.ecrire('session', etat.session);
            // Une session retrouvée : le navigateur peut de nouveau récupérer une version plus récente du service worker.
            etat.enregistrement?.update().catch(() => {});
            sync.reconnecte().then(rafraichirEntete);
            location.hash = '#/';
            await rafraichirEntete();
            await dessiner();

            return {};
        },
    };

    const sync = creerSync({
        file,
        api,
        meta: stockage.meta,
        pieces: { lire: (empreinte) => stockage.pieces.get(empreinte), supprimer: (empreinte) => stockage.pieces.delete(empreinte) },
        telecharger: () => telechargerDonnees({ api, elements: stockage.elements, file, meta: stockage.meta }),
        estEnLigne: () => navigator.onLine !== false && etat.session !== null && !etat.sessionExpiree,
        surSessionExpiree() {
            etat.sessionExpiree = true;
        },
        surChangement() {
            rafraichirEntete();
            const route = location.hash;
            const enSaisie = ecran.contains(document.activeElement) && ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName);
            // Les écrans avec saisie ne sont jamais redessinés en arrière-plan.
            if ((route === '' || route === '#/' || route === '#') && !enSaisie) dessiner();
            demanderMiseAJour();
        },
    });
    ctx.sync = sync;

    async function dessiner() {
        let contenu;
        try {
            if (!etat.session || etat.sessionExpiree) {
                contenu = ecranConnexion(ctx, { message: etat.sessionExpiree ? 'Session expirée : reconnectez-vous. Vos envois en attente sont conservés.' : null });
            } else {
                const route = location.hash.replace(/^#/, '') || '/';
                if (route.startsWith('/reception/')) contenu = await ecranReception(ctx, decodeURIComponent(route.slice('/reception/'.length)));
                else if (route === '/redistribution') contenu = await ecranRedistribution(ctx);
                else contenu = await ecranListe(ctx);
            }
        } catch (erreur) {
            contenu = h('p', { class: 'p-4 text-sm text-red-700' }, `Affichage impossible : ${erreur.message}`);
        }
        remplacer(ecran, contenu);
    }

    window.addEventListener('hashchange', () => {
        etat.message = null;
        // Un code saisi ne survit pas à un changement d'écran.
        for (const nettoyer of ctx.nettoyages.splice(0)) nettoyer();
        rafraichirEntete();
        dessiner().then(() => {
            if (etat.rechargementEnAttente && ecranSansSaisie()) location.reload();
            else demanderMiseAJour();
        });
    });
    window.addEventListener('online', () => { etat.enLigne = true; rafraichirEntete(); });
    window.addEventListener('offline', () => { etat.enLigne = false; rafraichirEntete(); });

    await rafraichirEntete();
    await dessiner();
    ctx.arreter = sync.demarrer({ fenetre: window });
    // La file d'envoi ne doit pas être effacée par le navigateur sous pression d'espace : on demande la protection,
    // sans bloquer le démarrage, et la liste affiche le résultat.
    demanderStockagePersistant(globalThis.navigator).then((protege) => {
        etat.stockageProtege = protege;
        if (etat.session && !etat.sessionExpiree && ecranSansSaisie()) dessiner();
    });
    enregistrerServiceWorker(amorce, etat, demanderMiseAJour, rafraichirEntete, ecranSansSaisie);

    return ctx;
}

/** Enregistre le service worker et lui confie les ressources déjà chargées par la page (hors connexion ensuite). */
function enregistrerServiceWorker(amorce, etat, demanderMiseAJour, rafraichirEntete, ecranSansSaisie) {
    if (!('serviceWorker' in navigator) || !amorce.service_worker) return;

    navigator.serviceWorker.register(amorce.service_worker, { scope: amorce.portee ?? '/terrain/' }).then((enregistrement) => {
        etat.enregistrement = enregistrement;
        const surveiller = (worker) => {
            worker.addEventListener('statechange', () => {
                if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                    etat.miseAJour = worker;
                    rafraichirEntete();
                    demanderMiseAJour();
                }
            });
        };
        if (enregistrement.waiting && navigator.serviceWorker.controller) {
            etat.miseAJour = enregistrement.waiting;
            demanderMiseAJour();
        }
        enregistrement.addEventListener('updatefound', () => enregistrement.installing && surveiller(enregistrement.installing));

        const urls = [...document.querySelectorAll('script[src], link[rel="stylesheet"][href], link[rel="modulepreload"][href]')]
            .map((e) => new URL(e.getAttribute('src') ?? e.getAttribute('href'), location.origin).pathname);
        navigator.serviceWorker.ready.then((pret) => pret.active?.postMessage({ type: 'precharger', urls: [COQUILLE, ...urls] }));
    }).catch(() => {});

    let rechargement = false;
    const avaitUnControleur = Boolean(navigator.serviceWorker.controller);
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        // La nouvelle version a pris la main (la file était vide) : on recharge une fois pour l'utiliser.
        // Au tout premier chargement, le service worker prend la main sans qu'il y ait rien à recharger.
        if (rechargement || !avaitUnControleur) return;
        // Jamais de rechargement pendant une saisie : il attend le retour à la liste.
        if (!ecranSansSaisie()) {
            etat.rechargementEnAttente = true;

            return;
        }
        rechargement = true;
        location.reload();
    });
}

const racine = typeof document !== 'undefined' ? document.getElementById('terrain-racine') : null;
if (racine) {
    demarrer(racine);
}
