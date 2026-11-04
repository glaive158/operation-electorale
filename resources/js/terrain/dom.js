/**
 * Construction d'éléments DOM sans innerHTML : tout texte passe par des nœuds texte (aucune injection possible)
 * et aucun attribut `style` n'est posé (la politique de sécurité de la page l'interdit).
 */

const PROPRIETES = new Set(['value', 'checked', 'disabled', 'selected', 'readOnly']);

/**
 * @param {string} balise
 * @param {Record<string, any>} [attributs]  `class`, `onclick`…, ou n'importe quel attribut ; false/null ignorés
 * @param {...any} enfants  nœuds, textes, listes (aplaties) ; null/false ignorés
 */
export function h(balise, attributs = {}, ...enfants) {
    const element = document.createElement(balise);
    for (const [nom, valeur] of Object.entries(attributs ?? {})) {
        if (valeur === false || valeur === null || valeur === undefined) continue;
        if (nom === 'class') {
            element.className = valeur;
        } else if (nom.startsWith('on') && typeof valeur === 'function') {
            element.addEventListener(nom.slice(2).toLowerCase(), valeur);
        } else if (PROPRIETES.has(nom)) {
            element[nom] = valeur;
        } else {
            element.setAttribute(nom, valeur === true ? '' : String(valeur));
        }
    }
    ajouter(element, enfants);

    return element;
}

function ajouter(parent, enfants) {
    for (const enfant of enfants) {
        if (enfant === null || enfant === undefined || enfant === false) continue;
        if (Array.isArray(enfant)) {
            ajouter(parent, enfant);
        } else if (typeof enfant === 'object') {
            parent.appendChild(enfant);
        } else {
            parent.appendChild(document.createTextNode(String(enfant)));
        }
    }
}

/** Remplace tout le contenu d'un conteneur. */
export function remplacer(conteneur, ...enfants) {
    while (conteneur.firstChild) conteneur.removeChild(conteneur.firstChild);
    ajouter(conteneur, enfants);
}
