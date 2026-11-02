/**
 * Logique d'affichage pure de l'application de terrain (aucun DOM, aucun réseau) : statuts affichés,
 * liste des réceptions, réserve restante, indicateur « disponible hors ligne ».
 */

import { STATUTS } from './file.js';

export const LIBELLES_STATUT = Object.freeze({
    [STATUTS.DISPONIBLE]: 'Disponible hors connexion',
    [STATUTS.EN_ATTENTE]: 'En attente d\'envoi',
    [STATUTS.SYNCHRONISE]: 'Synchronisé',
    [STATUTS.A_VERIFIER]: 'À vérifier',
});

export const CLASSES_STATUT = Object.freeze({
    [STATUTS.DISPONIBLE]: 'bg-sky-100 text-sky-700',
    [STATUTS.EN_ATTENTE]: 'bg-amber-100 text-amber-800',
    [STATUTS.SYNCHRONISE]: 'bg-green-100 text-green-700',
    [STATUTS.A_VERIFIER]: 'bg-red-100 text-red-700',
});

export const LIBELLES_ETAPE = Object.freeze({
    livraison: 'Livraison',
    redistribution: 'Redistribution',
});

export const LIBELLES_VERIFICATION = Object.freeze({
    verifiee_code: 'Identité vérifiée par le code',
    a_verifier: 'Identité à vérifier par le service logistique',
    echec: 'Code non reconnu : identité à vérifier par le service logistique',
    non_verifiee: 'Identité non vérifiée',
    compte: 'Identité vérifiée par le compte',
});

const declarationsActives = (file) => file.filter((e) => e.type === 'declaration');

/**
 * Les éléments de la file visibles pour un compte : les siens, et ceux sans titulaire connu. Les envois d'un autre
 * compte (même « à vérifier ») restent sur la tablette mais ne s'affichent ni ne se modifient ici.
 */
export const duCompte = (file, utilisateurId) => file.filter((e) => e.utilisateur_id == null || e.utilisateur_id === utilisateurId);

/** Message court du résultat d'une déclaration déjà envoyée (affiché sous le statut). */
export function detailSynchronisation(declaration) {
    const reponse = declaration?.reponse?.declaration;
    if (!reponse) return null;
    const libelle = LIBELLES_VERIFICATION[reponse.verification_identite] ?? null;
    if (reponse.contestation === 'contestee') return 'Réception contestée : en cours de vérification.';

    return libelle;
}

/**
 * Statut affiché d'une réception : celui de la file si elle l'a prise en charge, sinon celui de l'affectation.
 * Une déclaration enregistrée mais à vérifier par le central s'affiche « À vérifier ».
 */
export function statutAffiche(element, declaration) {
    if (declaration) {
        if (declaration.statut === STATUTS.SYNCHRONISE && declaration.reponse?.declaration?.statut === 'a_verifier') {
            return STATUTS.A_VERIFIER;
        }

        return declaration.statut;
    }

    return element?.remise?.statut === 'a_verifier' ? STATUTS.A_VERIFIER : STATUTS.DISPONIBLE;
}

/**
 * Les réceptions à afficher : les affectations téléchargées (hors préparations de redistribution, qui relèvent
 * de l'écran de l'appareil désigné) et les déclarations de la file dont l'affectation n'est plus téléchargée.
 *
 * @returns {Array<{id: string, remise_id: number, titre: string, de: string, a: string, etape: string, statut: string, detail: ?string, element: ?object, declaration: ?object}>}
 */
export function construireListe(elements, file, utilisateurId = null) {
    const declarations = declarationsActives(utilisateurId === null ? file : duCompte(file, utilisateurId));
    const parRemise = new Map(declarations.map((d) => [d.remise_id, d]));
    const lignes = [];
    const vus = new Set();

    for (const element of elements) {
        // Une remise « à arbitrer » est l'affaire du central : jamais une tâche pour la tablette.
        if (!element.remise || element.remise.statut === 'a_arbitrer') continue;
        const declaration = parRemise.get(element.remise.id) ?? null;
        vus.add(element.remise.id);
        lignes.push({
            id: element.id,
            remise_id: element.remise.id,
            titre: `Décharge n° ${element.decharge.numero ?? element.decharge.identifiant}`,
            de: element.remise.de ?? '',
            a: element.remise.a ?? '',
            etape: element.remise.etape,
            statut: statutAffiche(element, declaration),
            detail: declaration?.statut === STATUTS.A_VERIFIER || declaration?.statut === STATUTS.EN_ATTENTE
                ? (declaration.message ?? null) : detailSynchronisation(declaration),
            element,
            declaration,
        });
    }
    for (const declaration of declarations) {
        if (vus.has(declaration.remise_id)) continue;
        const resume = declaration.resume ?? {};
        lignes.push({
            id: `remise-${declaration.remise_id}`,
            remise_id: declaration.remise_id,
            titre: `Décharge n° ${resume.numero ?? resume.decharge ?? declaration.decharge_id}`,
            de: resume.de ?? '',
            a: resume.a ?? '',
            etape: resume.etape ?? 'livraison',
            statut: statutAffiche(null, declaration),
            detail: declaration.statut === STATUTS.A_VERIFIER || declaration.statut === STATUTS.EN_ATTENTE
                ? (declaration.message ?? null) : detailSynchronisation(declaration),
            element: null,
            declaration,
        });
    }

    // À faire d'abord, puis ce qui part, puis ce qui est fini.
    const rang = { [STATUTS.A_VERIFIER]: 0, [STATUTS.DISPONIBLE]: 1, [STATUTS.EN_ATTENTE]: 2, [STATUTS.SYNCHRONISE]: 3 };

    return lignes.sort((a, b) => rang[a.statut] - rang[b.statut] || a.remise_id - b.remise_id);
}

/** Nombre d'éléments de la file que le serveur n'a pas encore enregistrés. */
export const enAttenteDEnvoi = (file) => file.filter((e) => e.statut === STATUTS.EN_ATTENTE).length;

/**
 * Réserve de la tablette pour une décharge, déduction faite des redistributions enregistrées sur la tablette
 * mais pas encore envoyées (les envoyées sont déjà déduites par le serveur).
 *
 * @returns {Array<{materiel_id: number, libelle: string, quantite: number, restant: number}>}
 */
export function reserveRestante(reserve, file) {
    const utilise = new Map();
    for (const e of file) {
        if (e.type !== 'redistribution' || e.decharge_id !== reserve.decharge_id || e.statut !== STATUTS.EN_ATTENTE) continue;
        for (const l of e.lignes ?? []) utilise.set(l.materiel_id, (utilise.get(l.materiel_id) ?? 0) + l.quantite);
    }

    return (reserve.lignes ?? []).map((l) => ({
        materiel_id: l.materiel_id,
        libelle: l.libelle ?? `Article ${l.materiel_id}`,
        quantite: l.quantite,
        restant: Math.max(0, l.quantite - (utilise.get(l.materiel_id) ?? 0)),
    }));
}

/**
 * Les décharges de l'écran de redistribution : celles qu'on peut encore préparer (affectation sans remise)
 * et celles dont la tablette détient une réserve. Rien si la tablette n'est pas désignée.
 */
export function dechargesDeRedistribution({ designe, elements, reserves, file }) {
    if (!designe) return [];
    const parDecharge = new Map();
    for (const element of elements) {
        if (element.remise) continue;
        parDecharge.set(element.decharge.id, { decharge: element.decharge, mobilisable: element.lignes ?? [], reserve: null });
    }
    for (const reserve of reserves) {
        const existante = parDecharge.get(reserve.decharge_id);
        const actives = reserveRestante(reserve, file);
        const aDesQuantites = actives.some((l) => l.quantite > 0);
        const enFile = file.some((e) => (e.type === 'redistribution' || e.type === 'liberation') && e.decharge_id === reserve.decharge_id);
        if (!existante && !aDesQuantites && !enFile) continue;
        parDecharge.set(reserve.decharge_id, {
            decharge: existante?.decharge ?? reserve.decharge ?? { id: reserve.decharge_id, numero: String(reserve.decharge_id) },
            mobilisable: existante?.mobilisable ?? [],
            reserve,
        });
    }

    return [...parDecharge.values()].sort((a, b) => a.decharge.id - b.decharge.id);
}

/** « Tout est disponible hors ligne » : la coquille, la clé de preuve et les affectations sont dans la tablette. */
export function indicateurHorsLigne({ coquille, cle, donnees }) {
    const manque = [];
    if (!coquille) manque.push('application');
    if (!cle) manque.push('clé de preuve');
    if (!donnees) manque.push('affectations');

    return { pret: manque.length === 0, manque };
}

export const TEXTE_HORS_LIGNE_PRET = 'Tout est disponible hors ligne';

export function texteHorsLigne({ pret, manque }) {
    return pret ? TEXTE_HORS_LIGNE_PRET : `Pas encore disponible hors ligne : à télécharger (${manque.join(', ')}). Connectez-vous au réseau.`;
}
