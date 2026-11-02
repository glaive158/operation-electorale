/**
 * DOM minimal pour exécuter les écrans sans navigateur : éléments, nœuds texte, écouteurs, classes, valeurs
 * de champs. Pas de mise en page ni de CSS.
 */

class Noeud {
    constructor() {
        this.parent = null;
        this.enfants = [];
    }

    appendChild(noeud) {
        noeud.parent?.removeChild(noeud);
        noeud.parent = this;
        this.enfants.push(noeud);

        return noeud;
    }

    removeChild(noeud) {
        const i = this.enfants.indexOf(noeud);
        if (i >= 0) this.enfants.splice(i, 1);
        noeud.parent = null;

        return noeud;
    }

    get firstChild() {
        return this.enfants[0] ?? null;
    }

    contains(noeud) {
        for (let n = noeud; n; n = n.parent) if (n === this) return true;

        return false;
    }
}

export class Texte extends Noeud {
    constructor(texte) {
        super();
        this.texte = texte;
    }

    get textContent() {
        return this.texte;
    }
}

export class Element extends Noeud {
    constructor(balise) {
        super();
        this.tagName = balise.toUpperCase();
        this.attributs = new Map();
        this.ecouteurs = new Map();
        this.className = '';
        this.value = '';
        this.checked = false;
        this.disabled = false;
        this.files = [];
        this.donnees = {};
        const classes = () => new Set(this.className.split(/\s+/).filter(Boolean));
        this.classList = {
            add: (c) => { const s = classes(); s.add(c); this.className = [...s].join(' '); },
            remove: (c) => { const s = classes(); s.delete(c); this.className = [...s].join(' '); },
            contains: (c) => classes().has(c),
        };
        if (balise === 'canvas') this.initCanvas();
    }

    initCanvas() {
        this.width = 800;
        this.height = 300;
        this.contexte = new Proxy({}, { get: (cible, nom) => (nom in cible ? cible[nom] : () => {}), set: (cible, nom, valeur) => { cible[nom] = valeur; return true; } });
        this.getContext = () => this.contexte;
        this.getBoundingClientRect = () => ({ left: 0, top: 0, width: 800, height: 300 });
        this.setPointerCapture = () => {};
        this.toBlob = (rappel, type) => rappel(new Blob([new Uint8Array([137, 80, 78, 71, ...Array.from({ length: 16 }, (_, i) => i)])], { type }));
    }

    setAttribute(nom, valeur) {
        this.attributs.set(nom, String(valeur));
        if (nom === 'value') this.value = String(valeur);
    }

    getAttribute(nom) {
        return this.attributs.get(nom) ?? null;
    }

    addEventListener(type, fonction) {
        if (!this.ecouteurs.has(type)) this.ecouteurs.set(type, []);
        this.ecouteurs.get(type).push(fonction);
    }

    removeEventListener(type, fonction) {
        this.ecouteurs.set(type, (this.ecouteurs.get(type) ?? []).filter((f) => f !== fonction));
    }

    /** Déclenche les écouteurs ; renvoie la promesse du dernier. */
    async emettre(type, evenement = {}) {
        const e = { type, target: this, preventDefault() {}, ...evenement };
        let dernier;
        for (const f of this.ecouteurs.get(type) ?? []) dernier = f(e);

        return dernier;
    }

    click() {
        return this.emettre('click');
    }

    get textContent() {
        return this.enfants.map((e) => e.textContent).join('');
    }
}

export function creerFauxDocument() {
    return {
        createElement: (balise) => new Element(balise),
        createTextNode: (texte) => new Texte(texte),
        activeElement: null,
    };
}

/** Tous les éléments du sous-arbre (profondeur d'abord). */
export function* parcourir(noeud) {
    for (const enfant of noeud.enfants ?? []) {
        if (enfant instanceof Element) yield enfant;
        yield* parcourir(enfant);
    }
}

export const trouver = (racine, predicat) => [...parcourir(racine)].find(predicat) ?? null;
export const trouverTous = (racine, predicat) => [...parcourir(racine)].filter(predicat);
export const parAria = (racine, libelle) => trouver(racine, (e) => e.getAttribute('aria-label') === libelle);
export const parTexte = (racine, balise, texte) => trouver(racine, (e) => e.tagName === balise.toUpperCase() && e.textContent.includes(texte));
export const parId = (racine, id) => trouver(racine, (e) => e.getAttribute('id') === id);
