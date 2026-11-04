/**
 * Photo de la réception : l'image choisie ou prise est redessinée en JPEG de taille raisonnable
 * (le serveur n'accepte que JPEG ou PNG, 8 Mo au plus, et les téléphones livrent parfois du HEIC ou des
 * images de 12 millions de pixels).
 */

import { sha256Hex } from './octets.js';

export const COTE_MAXIMAL = 1600;
export const QUALITE_JPEG = 0.85;

/** Dimensions réduites pour tenir dans un carré de `maximum` pixels, sans agrandir. */
export function dimensionsReduites(largeur, hauteur, maximum = COTE_MAXIMAL) {
    const echelle = Math.min(1, maximum / Math.max(largeur, hauteur));

    return { largeur: Math.max(1, Math.round(largeur * echelle)), hauteur: Math.max(1, Math.round(hauteur * echelle)) };
}

/**
 * @param {File|Blob} fichier
 * @returns {Promise<{blob: Blob, empreinte: string, type: string}>}
 */
export async function preparerPhoto(fichier) {
    let image;
    try {
        image = await createImageBitmap(fichier);
    } catch {
        throw new Error('Image illisible : reprenez la photo.');
    }
    try {
        const { largeur, hauteur } = dimensionsReduites(image.width, image.height);
        const canvas = document.createElement('canvas');
        canvas.width = largeur;
        canvas.height = hauteur;
        canvas.getContext('2d').drawImage(image, 0, 0, largeur, hauteur);
        const blob = await new Promise((resolve, reject) => {
            canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Photo non exploitable.'))), 'image/jpeg', QUALITE_JPEG);
        });

        return { blob, empreinte: await sha256Hex(blob), type: 'image/jpeg' };
    } finally {
        image.close?.();
    }
}
