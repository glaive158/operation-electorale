/**
 * Signature au doigt : un canvas dessiné par les événements pointeur, exporté en PNG.
 * Le canvas a une taille interne fixe ; les coordonnées de l'écran y sont ramenées par proportion.
 */

/** Longueur de tracé (en pixels du canvas) au-dessous de laquelle on considère qu'il n'y a pas de signature. */
export const LONGUEUR_MINIMALE = 40;

/**
 * @param {HTMLCanvasElement} canvas
 * @param {{epaisseur?: number, couleur?: string, longueurMinimale?: number}} [options]
 */
export function creerSignature(canvas, { epaisseur = 3, couleur = '#0f172a', longueurMinimale = LONGUEUR_MINIMALE } = {}) {
    const contexte = canvas.getContext('2d');
    let longueur = 0;
    let precedent = null;
    let pointeur = null;

    function fond() {
        contexte.fillStyle = '#ffffff';
        contexte.fillRect(0, 0, canvas.width, canvas.height);
        contexte.strokeStyle = couleur;
        contexte.lineWidth = epaisseur;
        contexte.lineCap = 'round';
        contexte.lineJoin = 'round';
    }

    function point(evenement) {
        const zone = canvas.getBoundingClientRect();
        const largeur = zone.width || canvas.width;
        const hauteur = zone.height || canvas.height;

        return {
            x: ((evenement.clientX - zone.left) * canvas.width) / largeur,
            y: ((evenement.clientY - zone.top) * canvas.height) / hauteur,
        };
    }

    function debut(evenement) {
        if (pointeur !== null) return;
        pointeur = evenement.pointerId ?? 'souris';
        precedent = point(evenement);
        canvas.setPointerCapture?.(evenement.pointerId);
        contexte.beginPath();
        contexte.arc(precedent.x, precedent.y, epaisseur / 2, 0, Math.PI * 2);
        contexte.fillStyle = couleur;
        contexte.fill();
        evenement.preventDefault?.();
    }

    function deplacement(evenement) {
        if (pointeur === null || (evenement.pointerId ?? 'souris') !== pointeur) return;
        const courant = point(evenement);
        contexte.beginPath();
        contexte.moveTo(precedent.x, precedent.y);
        contexte.lineTo(courant.x, courant.y);
        contexte.stroke();
        longueur += Math.hypot(courant.x - precedent.x, courant.y - precedent.y);
        precedent = courant;
        evenement.preventDefault?.();
    }

    function fin(evenement) {
        if (pointeur === null || (evenement.pointerId ?? 'souris') !== pointeur) return;
        pointeur = null;
        precedent = null;
    }

    const ecouteurs = [['pointerdown', debut], ['pointermove', deplacement], ['pointerup', fin], ['pointercancel', fin], ['pointerleave', fin]];
    for (const [nom, fonction] of ecouteurs) canvas.addEventListener(nom, fonction);
    fond();

    return {
        effacer() {
            longueur = 0;
            precedent = null;
            pointeur = null;
            fond();
        },
        /** Vrai tant que le tracé est trop court pour être une signature. */
        estVide: () => longueur < longueurMinimale,
        /** @returns {Promise<Blob>} PNG de la signature sur fond blanc */
        versPng: () => new Promise((resolve, reject) => {
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Export de la signature impossible.'))), 'image/png');
        }),
        detruire() {
            for (const [nom, fonction] of ecouteurs) canvas.removeEventListener(nom, fonction);
        },
    };
}
