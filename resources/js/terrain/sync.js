/**
 * Synchronisation de la tablette : envoi de la file (pièces, déclarations, redistributions, libérations,
 * déclarations de restitution et prises en charge) puis téléchargement des affectations et des restitutions. Un seul passage à la fois (single-flight) ; déclenché au démarrage, au
 * retour du réseau (`online`), à la demande et toutes les 60 secondes.
 *
 * Règles d'envoi :
 *  - une déclaration part avec son bloc chiffré et sa clé d'origine, TELS QUELS à chaque nouvel essai (409,
 *    réseau, 5xx) : le bloc n'est jamais refait ;
 *  - les pièces partent d'abord ; avant une déclaration, chaque pièce est vérifiée (HEAD) : 404 => on envoie les
 *    octets (POST). Un HEAD 200 n'est vrai que si CE compte a déposé la pièce ;
 *  - une pièce interrompue (réseau, 408, 429, 5xx), photo comme signature, ne bloque pas sa déclaration : celle-ci
 *    part avec l'empreinte de la pièce (« attendue » côté serveur), la pièce reste en file avec ses octets et le
 *    serveur la rattache à son arrivée. Une pièce refusée (autre 4xx) passe « à vérifier », comme avant ;
 *  - 401 => la file est conservée et suspendue, l'application demande de se reconnecter ; après une reconnexion
 *    réussie, reconnecte() la fait reprendre.
 */

import { STATUTS, TYPES_DECLARATION } from './file.js';

export const INTERVALLE_MS = 60_000;

/** Corps de POST /remises/{remise}/declarations, reconstruit à l'identique d'un essai à l'autre. */
export function corpsDeclaration(e) {
    return {
        mode: 'tablette',
        cle: e.cle,
        horodatage_terminal: e.horodatage_terminal,
        signataire: e.signataire,
        lignes: e.lignes,
        bloc_preuve: e.bloc_preuve,
        pieces: (e.pieces ?? []).map(({ empreinte, role }) => ({ empreinte, role })),
        gps: e.gps ?? null,
    };
}

/** Corps de POST /restitutions/{restitution}/declarations : comme une réception, sans position. */
export function corpsDeclarationRestitution(e) {
    return {
        mode: 'tablette',
        cle: e.cle,
        horodatage_terminal: e.horodatage_terminal,
        signataire: e.signataire,
        lignes: e.lignes,
        bloc_preuve: e.bloc_preuve,
        pieces: (e.pieces ?? []).map(({ empreinte, role }) => ({ empreinte, role })),
    };
}

export function corpsRedistribution(e) {
    return { cle: e.cle, horodatage_terminal: e.horodatage_terminal, lignes: e.lignes };
}

const RETENTABLE = (status) => status === 0 || status === 401 || status === 408 || status === 429 || status >= 500;

/**
 * @param {object} options
 * @param {object} options.file                    file d'envoi (creerFile)
 * @param {object} options.api                     creerApi()
 * @param {{lire: Function, supprimer?: Function}} options.pieces   octets des pièces ({empreinte, blob})
 * @param {{lire: Function, ecrire: Function}} [options.meta]  réglages locaux (réserves de l'appareil)
 * @param {() => Promise<{status: number}|void>} [options.telecharger]  téléchargement des affectations
 * @param {() => boolean} [options.estEnLigne]
 * @param {() => void} [options.surSessionExpiree]
 * @param {(info: object) => void} [options.surChangement]
 */
export function creerSync({ file, api, pieces, meta, telecharger, estEnLigne = () => true, surSessionExpiree = () => {}, surChangement = () => {} }) {
    let enCours = null;
    let redemande = false;
    let manuelDemande = false;

    async function poster(empreinte, blob) {
        const donnees = new FormData();
        donnees.append('fichier', blob, `${empreinte.slice(0, 12)}.${blob.type === 'image/png' ? 'png' : 'jpg'}`);
        donnees.append('empreinte', empreinte);

        return api.envoyer('POST', '/pieces', { formData: donnees });
    }

    /** HEAD puis POST si le serveur ne l'a pas : renvoie l'issue finale ({status, corps}). */
    async function deposerPiece(empreinte) {
        const tete = await api.envoyer('HEAD', `/pieces/${empreinte}`);
        if (tete.status === 200) return tete;
        if (RETENTABLE(tete.status)) return tete;
        const enregistree = await pieces.lire(empreinte);
        if (!enregistree?.blob) {
            return { status: 422, corps: { message: 'Pièce absente de la tablette : à reprendre.' } };
        }

        return poster(empreinte, enregistree.blob);
    }

    async function envoyerDeclaration(e, tentes) {
        const enFile = new Map((await file.lister()).filter((x) => x.type === 'piece').map((x) => [x.empreinte, x]));
        // Le serveur ne garde pas une pièce qu'il n'a pas : on renvoie celles que CE compte n'a pas déposées.
        for (const piece of e.pieces ?? []) {
            // Pièce encore en file (envoi interrompu) : elle partira seule ; la déclaration ne l'attend pas.
            if (enFile.get(piece.empreinte)?.statut === STATUTS.EN_ATTENTE) continue;
            const tete = await api.envoyer('HEAD', `/pieces/${piece.empreinte}`);
            if (tete.status === 200) continue;
            if (RETENTABLE(tete.status)) return tete;
            const enregistree = await pieces.lire(piece.empreinte);
            if (!enregistree?.blob) continue; // la déclaration part : la pièce reste « attendue » côté serveur
            const envoi = await poster(piece.empreinte, enregistree.blob);
            if (envoi.status === 401) return envoi;
            if (RETENTABLE(envoi.status)) {
                // Interrompue : remise en file (avec cet essai compté) pour partir plus tard ; la déclaration part.
                const reprise = await file.ajouter({ type: 'piece', id: piece.empreinte, empreinte: piece.empreinte, role: piece.role });
                if (reprise.statut !== STATUTS.EN_ATTENTE) return envoi;
                if (reprise.tentatives === 0) await file.marquer(piece.empreinte, envoi);
                tentes?.add(piece.empreinte);
            }
        }

        return e.type === 'declaration_restitution'
            ? api.envoyer('POST', `/restitutions/${e.restitution_id}/declarations`, { json: corpsDeclarationRestitution(e) })
            : api.envoyer('POST', `/remises/${e.remise_id}/declarations`, { json: corpsDeclaration(e) });
    }

    function envoyerElement(e, tentes) {
        switch (e.type) {
            case 'piece':
                return deposerPiece(e.empreinte);
            case 'declaration':
            case 'declaration_restitution':
                return envoyerDeclaration(e, tentes);
            case 'prise_en_charge':
                return api.envoyer('POST', `/restitutions/${e.restitution_id}/prise-en-charge`);
            case 'redistribution':
                return api.envoyer('POST', `/decharges/${e.decharge_id}/redistributions`, { json: corpsRedistribution(e), avecAppareil: true });
            case 'liberation':
                return api.envoyer('DELETE', `/decharges/${e.decharge_id}/reserve`, { avecAppareil: true });
            default:
                return Promise.resolve({ status: 422, corps: { message: `Type d'envoi inconnu : ${e.type}.` } });
        }
    }

    /**
     * Libère les octets de pièces qui n'ont plus à partir : ni encore en file ou à vérifier, ni annoncées par une
     * déclaration pas encore enregistrée (autre que `sauf`).
     */
    async function allegerPieces(empreintes, sauf = null) {
        if (typeof pieces.supprimer !== 'function') return;
        const elements = await file.lister();
        for (const empreinte of empreintes) {
            const element = elements.find((x) => x.type === 'piece' && x.empreinte === empreinte);
            const aGarder = element?.statut === STATUTS.A_VERIFIER || element?.statut === STATUTS.EN_ATTENTE;
            const utilisee = elements.some((x) => TYPES_DECLARATION.includes(x.type) && x.id !== sauf
                && x.statut !== STATUTS.SYNCHRONISE && (x.pieces ?? []).some((p) => p.empreinte === empreinte));
            if (!aGarder && !utilisee) await pieces.supprimer(empreinte);
        }
    }

    /**
     * La réserve gardée sur la tablette suit ce que le serveur a compté : une redistribution enregistrée la diminue
     * de ses lignes (sauf si elle est « à arbitrer » : rien n'a alors été compté), une libération la vide.
     */
    async function majReserve(element, issue) {
        if (!meta) return;
        const cle = `reserve:${element.decharge_id}`;
        const reserve = await meta.lire(cle);
        if (!reserve) return;
        if (element.type === 'liberation') {
            await meta.ecrire(cle, { ...reserve, lignes: [] });
        } else if (element.type === 'redistribution' && issue.corps?.redistribution?.a_arbitrer !== true) {
            const sortie = new Map();
            for (const l of element.lignes ?? []) sortie.set(l.materiel_id, (sortie.get(l.materiel_id) ?? 0) + l.quantite);
            await meta.ecrire(cle, {
                ...reserve,
                lignes: (reserve.lignes ?? []).map((l) => ({ ...l, quantite: Math.max(0, l.quantite - (sortie.get(l.materiel_id) ?? 0)) })),
            });
        }
    }

    async function envoyerFile(manuel = false) {
        let envoyes = 0;
        // Passage manuel : le délai de réessai est ignoré, mais un élément n'est tenté qu'une fois par passage.
        const tentes = new Set();
        for (;;) {
            const element = await file.prochain(manuel ? { ignorerPasAvant: true, exclure: tentes } : undefined);
            if (!element) return { envoyes, session: true };
            tentes.add(element.id);
            const issue = await envoyerElement(element, tentes);
            const marque = await file.marquer(element.id, issue);
            if (issue.status === 401) return { envoyes, session: false };
            if (marque.statut === STATUTS.SYNCHRONISE) {
                envoyes += 1;
                if (TYPES_DECLARATION.includes(element.type)) await allegerPieces((element.pieces ?? []).map((p) => p.empreinte), element.id);
                // Une pièce partie après sa déclaration (envoi interrompu) : ses octets ne servent plus.
                if (element.type === 'piece') await allegerPieces([element.empreinte]);
                if (element.type === 'redistribution' || element.type === 'liberation') await majReserve(element, issue);
            }
            // Pas de réponse du serveur ou limite atteinte : inutile d'insister dans ce passage. Une pièce interrompue
            // (envoi lourd sur un réseau faible) n'arrête pas le passage : sa déclaration, légère, part quand même.
            if (element.type !== 'piece' && (issue.status === 0 || issue.status === 429)) return { envoyes, session: true, reseau: issue.status === 0 };
        }
    }

    async function passage(manuel = false) {
        if (!estEnLigne()) {
            surChangement({ hors_ligne: true });

            return { hors_ligne: true };
        }
        const envoi = await envoyerFile(manuel);
        let session = envoi.session;
        if (session && !envoi.reseau && telecharger) {
            const telechargement = await telecharger();
            if (telechargement?.status === 401) session = false;
        }
        if (!session) surSessionExpiree();
        surChangement({ envoyes: envoi.envoyes, session });

        return { ...envoi, session };
    }

    /**
     * Un seul passage à la fois : un appel pendant un passage en demande un second, sans le doubler.
     * `manuel` (bouton « Synchroniser ») ignore le délai de réessai des éléments ; les passages automatiques le respectent.
     */
    function cycle({ manuel = false } = {}) {
        if (manuel) manuelDemande = true;
        if (enCours) {
            redemande = true;

            return enCours;
        }
        enCours = (async () => {
            let dernier;
            try {
                do {
                    redemande = false;
                    const manuelCePassage = manuelDemande;
                    manuelDemande = false;
                    dernier = await passage(manuelCePassage);
                } while (redemande && dernier.session && !dernier.hors_ligne && !dernier.reseau);
            } finally {
                enCours = null;
                manuelDemande = false;
            }

            return dernier;
        })();

        return enCours;
    }

    return {
        cycle,
        enCours: () => enCours !== null,

        /** Après une reconnexion réussie : la file reprend là où elle s'était arrêtée. */
        reconnecte() {
            file.reprendre();

            return cycle();
        },

        /**
         * Au démarrage, au retour du réseau et toutes les minutes.
         * @returns {() => void} arrêt
         */
        demarrer({ fenetre = globalThis, intervalleMs = INTERVALLE_MS, setIntervalFn = setInterval, clearIntervalFn = clearInterval } = {}) {
            const relancer = () => { cycle().catch(() => {}); };
            fenetre.addEventListener('online', relancer);
            const minuteur = setIntervalFn(relancer, intervalleMs);
            relancer();

            return () => {
                fenetre.removeEventListener('online', relancer);
                clearIntervalFn(minuteur);
            };
        },
    };
}

/**
 * Les restitutions téléchargées (à part des affectations : leur forme diffère) sont rangées dans les réglages
 * locaux. Une restitution que la file attend encore (déclaration ou prise en charge non enregistrée) est gardée
 * même si le serveur ne la renvoie plus. Une réponse sans liste laisse les précédentes.
 */
async function rangerRestitutions({ restitutions, file, meta, maintenant }) {
    if (!Array.isArray(restitutions)) return;
    const attendues = new Set(file.filter((f) => (f.type === 'declaration_restitution' || f.type === 'prise_en_charge')
        && (f.statut === STATUTS.EN_ATTENTE || f.statut === STATUTS.A_VERIFIER)).map((f) => f.restitution_id));
    const recues = restitutions.map((r) => ({ ...r, telecharge_le: maintenant() }));
    const ids = new Set(recues.map((r) => r.id));
    const gardees = ((await meta.lire('restitutions')) ?? []).filter((r) => !ids.has(r.id) && attendues.has(r.id));
    await meta.ecrire('restitutions', [...recues, ...gardees]);
}

/** Identifiant local d'une affectation téléchargée. */
export const idElement = (element) => (element.remise ? `remise-${element.remise.id}` : `preparation-${element.decharge.id}`);

/**
 * Télécharge les affectations, la clé de preuve et l'état de l'appareil, et les range dans la tablette.
 * Ne supprime jamais un élément que la file attend encore. Les erreurs réseau laissent les données précédentes.
 *
 * @returns {Promise<{status: number, designe?: boolean}>}
 */
export async function telechargerDonnees({ api, elements, file, meta, maintenant = () => new Date().toISOString() }) {
    const affectations = await api.envoyer('GET', '/affectations');
    if (affectations.status !== 200 || !Array.isArray(affectations.corps?.elements)) {
        return { status: affectations.status };
    }

    const recus = affectations.corps.elements.map((e) => ({ ...e, id: idElement(e), telecharge_le: maintenant() }));
    const file_ = await file.lister();
    const attendus = new Set();
    for (const f of file_) {
        if (f.statut !== STATUTS.EN_ATTENTE && f.statut !== STATUTS.A_VERIFIER) continue;
        if (f.type === 'declaration') attendus.add(`remise-${f.remise_id}`);
        if (f.type === 'redistribution' || f.type === 'liberation') attendus.add(`preparation-${f.decharge_id}`);
    }
    const nouveaux = new Set(recus.map((e) => e.id));
    for (const ancien of await elements.list()) {
        if (!nouveaux.has(ancien.id) && !attendus.has(ancien.id)) await elements.delete(ancien.id);
    }
    for (const element of recus) await elements.put(element);
    await rangerRestitutions({ restitutions: affectations.corps.restitutions, file: file_, meta, maintenant });

    const cle = await api.envoyer('GET', '/cle-preuve');
    if (cle.status === 200 && typeof cle.corps?.pem === 'string') {
        await meta.ecrire('cle_preuve', { pem: cle.corps.pem, empreinte: cle.corps.empreinte });
    }

    let designe = false;
    const appareil = await api.envoyer('GET', '/appareil', { avecAppareil: true });
    if (appareil.status === 200) {
        designe = appareil.corps?.designe === true;
        await meta.ecrire('appareil_designe', { designe, appareil: appareil.corps?.appareil ?? null });
    }
    if (designe) {
        const connues = (await meta.lister('reserve:')).map((r) => r.decharge_id);
        const candidates = new Set([...recus.filter((e) => !e.remise).map((e) => e.decharge.id), ...connues]);
        for (const dechargeId of candidates) {
            const reserve = await api.envoyer('GET', `/decharges/${dechargeId}/reserve`, { avecAppareil: true });
            if (reserve.status === 200 && reserve.corps?.reserve) {
                const precedente = await meta.lire(`reserve:${dechargeId}`);
                await meta.ecrire(`reserve:${dechargeId}`, { ...precedente, ...reserve.corps.reserve, decharge_id: dechargeId });
            }
        }
    }
    await meta.ecrire('derniere_sync', maintenant());

    return { status: 200, designe };
}
