/**
 * Bloc de preuve d'une déclaration : à la saisie du code personnel, la tablette chiffre
 * {code, cle, empreinte} avec la clé publique du serveur (RSA-OAEP, SHA-1 : la seule combinaison que le
 * openssl de PHP sait déchiffrer). Le code n'est ni renvoyé ni conservé : il n'existe, côté tablette, que le
 * temps de cet appel. Le bloc est produit UNE fois, mémorisé tel quel, puis renvoyé tel quel (un
 * re-chiffrement du même clair donne un autre bloc, que le serveur refuserait comme un autre envoi).
 */

import { empreinte, json, rogner } from './canonique.js';

const ALGORITHME = { name: 'RSA-OAEP', hash: 'SHA-1' };
const FORMAT_CLE = /^[A-Za-z0-9-]{16,64}$/;
const FORMAT_CODE = /^[0-9]{6}$/;

function enBase64(octets) {
    let binaire = '';
    for (let i = 0; i < octets.length; i += 0x8000) {
        binaire += String.fromCharCode(...octets.subarray(i, i + 0x8000));
    }

    return btoa(binaire);
}

/** Importe une clé publique PEM (SPKI) pour chiffrer en RSA-OAEP SHA-1. */
export async function importerCle(pem) {
    if (typeof pem !== 'string') {
        throw new TypeError('Clé publique attendue au format PEM.');
    }
    const base64 = pem.replace(/-----[A-Z ]+-----/g, '').replace(/\s+/g, '');
    if (base64 === '') {
        throw new Error('Clé publique illisible.');
    }
    let der;
    try {
        der = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    } catch {
        throw new Error('Clé publique illisible.');
    }

    return globalThis.crypto.subtle.importKey('spki', der, ALGORITHME, false, ['encrypt']);
}

/**
 * Contenu attesté par le bloc : le miroir exact de PreuveService::contenuPreuve (PHP). Les saisies sont
 * rognées, une observation vide devient null, les lignes sont triées par article, les nombres sont des entiers.
 * C'est cet objet (champs identiques) que la tablette envoie ensuite au serveur.
 */
export function contenuPreuve({ decharge_id, decharge_identifiant, remise_id, cle, signataire, lignes, horodatage_terminal }) {
    const entier = (n) => {
        if (!Number.isSafeInteger(n)) throw new TypeError(`Entier attendu (reçu : ${n}).`);

        return n;
    };
    const lignesNormalisees = lignes.map((ligne) => {
        const observation = typeof ligne.observation === 'string' ? rogner(ligne.observation) : null;

        return {
            materiel_id: entier(ligne.materiel_id),
            recu: entier(ligne.recu ?? 0),
            defectueux: entier(ligne.defectueux ?? 0),
            observation: observation === '' ? null : observation,
        };
    });
    lignesNormalisees.sort((a, b) => a.materiel_id - b.materiel_id);

    return {
        decharge_id: entier(decharge_id),
        decharge_identifiant: String(decharge_identifiant),
        remise_id: entier(remise_id),
        cle,
        signataire: {
            user_id: entier(signataire.user_id),
            nom: rogner(String(signataire.nom ?? '')),
            qualite: rogner(String(signataire.qualite ?? '')),
        },
        lignes: lignesNormalisees,
        horodatage_terminal,
    };
}

/**
 * Construit le bloc de preuve.
 *
 * @param {object} p
 * @param {string} p.code          code personnel saisi, 6 chiffres (jamais renvoyé ni conservé)
 * @param {string} p.cle           clé d'idempotence de la déclaration (16 à 64 [A-Za-z0-9-])
 * @param {object} p.contenu       contenu de la déclaration (voir contenuPreuve) ; son empreinte (sans autre transformation) entre dans le bloc
 * @param {CryptoKey|string} p.clePublique  clé importée par importerCle(), ou son PEM
 * @returns {Promise<{bloc: string, empreinte: string}>}  bloc en base64 (taille du module RSA) et empreinte du contenu
 */
export async function construireBloc({ code, cle, contenu, clePublique }) {
    if (typeof code !== 'string' || !FORMAT_CODE.test(code)) {
        throw new TypeError('Code personnel invalide : 6 chiffres attendus.');
    }
    if (typeof cle !== 'string' || !FORMAT_CLE.test(cle)) {
        throw new TypeError('Clé d\'idempotence invalide (16 à 64 caractères A-Z a-z 0-9 -).');
    }
    if (contenu === null || typeof contenu !== 'object' || (contenu.cle !== undefined && contenu.cle !== cle)) {
        throw new TypeError('Contenu absent ou portant une autre clé d\'idempotence.');
    }
    const publique = typeof clePublique === 'string' ? await importerCle(clePublique) : clePublique;
    if (!publique || typeof publique !== 'object' || publique.type !== 'public') {
        throw new TypeError('Clé publique de preuve manquante : synchroniser avant de signer.');
    }

    // Le contenu est empreinté tel qu'il est reçu : seules les saisies de l'utilisateur sont rognées, et
    // seulement dans contenuPreuve (le serveur ne rogne que ce qu'il reçoit, pas ses propres données).
    const empreinteContenu = await empreinte(contenu);
    const clair = new TextEncoder().encode(json({ code, cle, empreinte: empreinteContenu }));
    try {
        const chiffre = new Uint8Array(await globalThis.crypto.subtle.encrypt(ALGORITHME, publique, clair));

        return { bloc: enBase64(chiffre), empreinte: empreinteContenu };
    } finally {
        clair.fill(0);
    }
}
