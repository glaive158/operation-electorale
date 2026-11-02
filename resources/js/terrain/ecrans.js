/**
 * Écrans de l'application de terrain. Chaque écran est une fonction qui reçoit le contexte de l'application
 * (stockage, file, api, sync, état) et renvoie un nœud DOM. Style repris des écrans de terrain existants.
 */

import { rogner } from './canonique.js';
import { h, remplacer } from './dom.js';
import { horodatage, STATUTS } from './file.js';
import { identifiantAleatoire, sha256Hex } from './octets.js';
import { preparerPhoto } from './photo.js';
import { construireBloc, contenuPreuve, importerCle, normaliserLignes } from './preuve.js';
import { creerSignature } from './signature.js';
import {
    CLASSES_STATUT, construireListe, dechargesDeRedistribution, indicateurHorsLigne, LIBELLES_ETAPE, LIBELLES_STATUT,
    duCompte, identiteNonVerifiee, reserveRestante, texteHorsLigne, texteStockage,
} from './vue.js';

const CARTE = 'bg-white rounded-2xl shadow-sm border border-slate-100 p-5 space-y-4';
const CHAMP = 'w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none';
const BOUTON = 'px-6 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 bg-sen-green disabled:opacity-50';
const BOUTON_SECONDAIRE = 'px-4 py-2 rounded-lg border border-slate-300 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50';
const BOUTON_DANGER = 'px-4 py-2 rounded-lg text-white text-sm font-medium hover:opacity-90 bg-sen-red disabled:opacity-50';
const ETIQUETTE = 'block text-xs text-slate-500 mb-1';

const badge = (statut) => h('span', { class: `px-2 py-0.5 rounded-full text-xs font-medium ${CLASSES_STATUT[statut] ?? 'bg-slate-200 text-slate-600'}` }, LIBELLES_STATUT[statut] ?? statut);

const alerte = (texte, type = 'erreur') => h('div', {
    role: type === 'erreur' ? 'alert' : 'status',
    class: `p-3 rounded-xl text-sm ${type === 'erreur' ? 'bg-sen-red text-white' : 'bg-green-50 text-green-800 border border-green-200'}`,
}, texte);

const lien = (hash, texte) => h('a', { href: hash, class: BOUTON_SECONDAIRE }, texte);

/** Signée sans code : dit partout que l'identité du signataire n'est pas vérifiée. */
const badgeIdentite = () => h('span', { class: 'px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800' }, 'Identité non vérifiée');

const TEXTE_LECTURE_SEULE = 'À signer sur la tablette de l\'agent, ou à confirmer depuis votre compte.';

/** Une réception que ce compte ne déclare pas sur sa tablette : renvoi vers la page web de la décharge. */
const lectureSeule = (dechargeId) => [
    h('p', { class: 'text-sm text-slate-600' }, TEXTE_LECTURE_SEULE),
    h('div', { class: 'flex justify-end' }, h('a', { href: `/terrain/decharges/${dechargeId}`, class: BOUTON_SECONDAIRE }, 'Ouvrir la décharge')),
];

/* ───────────── Connexion ───────────── */

export function ecranConnexion(ctx, { message = null, erreur = null } = {}) {
    const email = h('input', { type: 'email', id: 'connexion-email', required: true, autocomplete: 'username', class: CHAMP, value: ctx.etat.session?.email ?? '' });
    const motDePasse = h('input', { type: 'password', id: 'connexion-mdp', required: true, autocomplete: 'current-password', class: CHAMP });
    const bouton = h('button', { type: 'submit', class: BOUTON }, 'Se connecter');
    const zone = h('div', { class: 'space-y-3' }, erreur ? alerte(erreur) : null);

    const formulaire = h('form', {
        class: 'space-y-4',
        onsubmit: async (evenement) => {
            evenement.preventDefault();
            bouton.disabled = true;
            const resultat = await ctx.connecter(email.value.trim(), motDePasse.value);
            motDePasse.value = '';
            bouton.disabled = false;
            if (resultat.erreur) remplacer(zone, alerte(resultat.erreur));
        },
    },
    h('div', {}, h('label', { for: 'connexion-email', class: ETIQUETTE }, 'Adresse e-mail'), email),
    h('div', {}, h('label', { for: 'connexion-mdp', class: ETIQUETTE }, 'Mot de passe'), motDePasse),
    zone,
    h('div', { class: 'flex justify-end' }, bouton));

    return h('div', { class: 'max-w-md mx-auto space-y-5' },
        h('div', { class: CARTE },
            h('div', {}, h('h2', { class: 'font-semibold text-slate-800' }, 'Connexion à l\'application de terrain'),
                message ? h('p', { class: 'text-sm text-amber-700 mt-1' }, message) : h('p', { class: 'text-sm text-slate-600 mt-1' }, 'La connexion demande le réseau. Ensuite, l\'application fonctionne sans connexion.')),
            formulaire),
        h('div', { class: CARTE },
            h('h3', { class: 'font-semibold text-slate-800 text-sm' }, 'Identifiant de cette tablette'),
            h('p', { class: 'font-mono text-sm break-all text-slate-800', id: 'identifiant-appareil' }, ctx.etat.appareil),
            h('p', { class: 'text-xs text-slate-500' }, 'Le préfet communique cet identifiant au service logistique central pour désigner cette tablette pour la redistribution. Connectez-vous d\'abord, puis demandez la désignation.')));
}

/* ───────────── Liste des réceptions ───────────── */

export async function ecranListe(ctx) {
    const [elements, file, cle, derniere] = await Promise.all([
        ctx.stockage.elements.list(), ctx.file.lister(), ctx.stockage.meta.lire('cle_preuve'), ctx.stockage.meta.lire('derniere_sync'),
    ]);
    const lignes = construireListe(elements, file, ctx.etat.session?.utilisateur?.id ?? null);
    const indicateur = indicateurHorsLigne({ coquille: await ctx.coquilleEnCache(), cle: Boolean(cle), donnees: Boolean(derniere) });
    const session = ctx.etat.session;

    const carte = (ligne) => {
        const declaration = ligne.declaration;
        const actions = [];
        const aVerifier = declaration?.statut === STATUTS.A_VERIFIER;
        const consultation = ligne.statut === STATUTS.DISPONIBLE && ligne.element && ligne.lecture_seule;
        if (ligne.statut === STATUTS.DISPONIBLE && ligne.element && !ligne.lecture_seule) {
            actions.push(h('a', { href: `#/reception/${ligne.id}`, class: BOUTON }, 'Réceptionner'));
        } else if (declaration) {
            if (aVerifier) {
                actions.push(h('button', { type: 'button', class: BOUTON_SECONDAIRE, onclick: async () => { await ctx.file.reessayer(declaration.id); ctx.sync.cycle(); await ctx.rafraichir(); } }, 'Renvoyer'));
            }
            actions.push(h('a', { href: `#/reception/${ligne.id}`, class: BOUTON_SECONDAIRE }, 'Voir'));
        }
        const peutContester = declaration?.statut === STATUTS.SYNCHRONISE && declaration.reponse?.declaration?.id
            && declaration.reponse.declaration.contestation === 'aucune' && !declaration.contestation_locale
            && session?.utilisateur?.id === declaration.signataire?.user_id;
        if (peutContester) actions.push(boutonContestation(ctx, declaration));

        return h('li', { class: CARTE, 'data-remise': ligne.remise_id },
            h('div', { class: 'flex items-start justify-between gap-3' },
                h('div', {},
                    h('p', { class: 'font-semibold text-slate-800' }, ligne.titre),
                    h('p', { class: 'text-xs text-slate-500' }, `${LIBELLES_ETAPE[ligne.etape] ?? ligne.etape} : ${ligne.de} → ${ligne.a}`)),
                h('div', { class: 'flex flex-col items-end gap-1' }, badge(ligne.statut), ligne.identite_non_verifiee ? badgeIdentite() : null)),
            ligne.detail ? h('p', { class: 'text-sm text-slate-600' }, ligne.detail) : null,
            ...(consultation ? lectureSeule(ligne.element.decharge.id) : []),
            actions.length ? h('div', { class: 'flex flex-wrap gap-2 justify-end' }, actions) : null);
    };
    const stockage = texteStockage(ctx.etat.stockageProtege);

    return h('div', { class: 'max-w-2xl mx-auto space-y-4' },
        h('div', { class: 'space-y-1' },
            h('div', { class: `p-3 rounded-xl text-sm ${indicateur.pret ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-amber-50 text-amber-800 border border-amber-200'}`, id: 'indicateur-hors-ligne' }, texteHorsLigne(indicateur)),
            stockage ? h('p', { class: `text-xs text-right ${ctx.etat.stockageProtege ? 'text-slate-500' : 'text-amber-700'}`, id: 'etat-stockage' }, stockage) : null),
        lignes.length
            ? h('ul', { class: 'space-y-3' }, lignes.map(carte))
            : h('div', { class: CARTE }, h('p', { class: 'text-sm text-slate-600' }, 'Aucune réception à faire pour le moment. Synchronisez avec le réseau pour télécharger vos affectations.')));
}

function boutonContestation(ctx, declaration) {
    const motif = h('textarea', { class: CHAMP, rows: '2', maxlength: '500', 'aria-label': 'Motif de la contestation', placeholder: 'Pourquoi contestez-vous cette réception ?' });
    const zone = h('div', { class: 'hidden w-full space-y-2' }, motif);
    const envoyer = h('button', { type: 'button', class: BOUTON_DANGER }, 'Envoyer la contestation');
    const ouvrir = h('button', { type: 'button', class: BOUTON_SECONDAIRE, onclick: () => { zone.classList.remove('hidden'); ouvrir.classList.add('hidden'); } }, 'Ce n\'est pas moi : contester');
    envoyer.addEventListener('click', async () => {
        if (motif.value.trim() === '') return;
        envoyer.disabled = true;
        const reponse = await ctx.api.envoyer('POST', `/declarations/${declaration.reponse.declaration.id}/contestation`, { json: { motif: motif.value.trim() } });
        if (reponse.status === 200) {
            await ctx.stockage.file.put({ ...declaration, contestation_locale: true });
            await ctx.rafraichir();

            return;
        }
        envoyer.disabled = false;
        ctx.signaler(reponse.status === 0 ? 'Contestation impossible hors connexion.' : (reponse.corps?.message ?? 'Contestation refusée.'));
    });
    zone.appendChild(envoyer);

    return h('div', { class: 'w-full flex flex-col items-end gap-2' }, ouvrir, zone);
}

/* ───────────── Réception ───────────── */

function lireEntier(champ) {
    const texte = champ.value.trim();

    return /^\d{1,8}$/.test(texte) ? Number(texte) : null;
}

export async function ecranReception(ctx, id) {
    const element = await ctx.stockage.elements.get(id);
    const file = duCompte(await ctx.file.lister(), ctx.etat.session?.utilisateur?.id ?? null);
    const remiseId = element?.remise?.id ?? Number(String(id).replace('remise-', ''));
    const existante = file.find((e) => e.type === 'declaration' && e.remise_id === remiseId);
    if (existante) return recapitulatif(ctx, existante);
    if (!element?.remise || element.remise.statut === 'a_arbitrer') {
        return h('div', { class: `${CARTE} max-w-xl mx-auto` }, h('p', { class: 'text-sm text-slate-600' }, 'Cette réception n\'est plus à faire.'), lien('#/', 'Retour à la liste'));
    }
    if (element.peut_declarer_tablette === false) {
        return h('div', { class: `${CARTE} max-w-xl mx-auto` },
            h('h2', { class: 'font-semibold text-slate-800' }, `Décharge n° ${element.decharge.numero ?? element.decharge.identifiant}`),
            ...lectureSeule(element.decharge.id), lien('#/', 'Retour à la liste'));
    }

    const racine = h('div', { class: 'max-w-2xl mx-auto space-y-4' });
    const attendue = element.autorite_attendue ?? {};
    const signataires = attendue.user_id
        ? [{ user_id: attendue.user_id, nom: attendue.nom || 'Autorité', qualite: attendue.qualite || 'Autorité', titre: 'Autorité' },
            ...(attendue.delegues ?? []).map((d) => ({ user_id: d.user_id, nom: d.nom || 'Délégué', qualite: d.qualite || 'Délégué', titre: 'Délégué' }))]
        : [];
    const choix = signataires.find((s) => s.user_id === ctx.etat.session?.utilisateur?.id) ?? signataires[0] ?? null;

    const saisies = element.lignes.map((l) => ({ materiel_id: l.materiel_id, libelle: l.libelle, attendu: l.quantite, recu: String(l.quantite), defectueux: '0', observation: '' }));
    const etat = { etape: 'lignes', signataire: choix, photo: null, gps: null, occupe: false, signature: null, canvas: null, repli: false };
    // Sans autorité rattachée (aucun compte) : le nom et la qualité sont saisis, la signature se fait sans code.
    const saisieSignataire = { nom: rogner(String(attendue.nom ?? '')), qualite: rogner(String(attendue.qualite ?? '')) };

    function entete() {
        return h('div', { class: CARTE },
            h('div', { class: 'flex items-start justify-between gap-3' },
                h('div', {}, h('h2', { class: 'font-semibold text-slate-800' }, `Décharge n° ${element.decharge.numero ?? element.decharge.identifiant}`),
                    h('p', { class: 'text-xs text-slate-500' }, `${LIBELLES_ETAPE[element.remise.etape] ?? element.remise.etape} : ${element.remise.de} → ${element.remise.a}`)),
                badge(STATUTS.DISPONIBLE)));
    }

    /* Étape 1 : quantités par ligne */
    function etapeLignes() {
        const erreur = h('div', {});
        const champs = saisies.map((s) => ({
            recu: h('input', { type: 'number', inputmode: 'numeric', min: '0', step: '1', value: s.recu, class: CHAMP, 'aria-label': `Reçu : ${s.libelle}` }),
            defectueux: h('input', { type: 'number', inputmode: 'numeric', min: '0', step: '1', value: s.defectueux, class: CHAMP, 'aria-label': `Défectueux : ${s.libelle}` }),
            observation: h('input', { type: 'text', maxlength: '500', value: s.observation, class: CHAMP, 'aria-label': `Observation : ${s.libelle}` }),
        }));
        const continuer = () => {
            champs.forEach((c, i) => { saisies[i].recu = c.recu.value; saisies[i].defectueux = c.defectueux.value; saisies[i].observation = c.observation.value; });
            for (let i = 0; i < champs.length; i += 1) {
                const recu = lireEntier(champs[i].recu);
                const defectueux = lireEntier(champs[i].defectueux);
                if (recu === null || defectueux === null) { remplacer(erreur, alerte(`Quantités invalides pour « ${saisies[i].libelle} » : entiers positifs ou nuls.`)); return; }
                if (defectueux > recu) { remplacer(erreur, alerte(`Pour « ${saisies[i].libelle} », le nombre de défectueux dépasse le nombre reçu.`)); return; }
            }
            etat.etape = 'autorite';
            dessiner();
        };

        return h('div', { class: 'space-y-4' }, entete(),
            h('div', { class: CARTE },
                h('h3', { class: 'font-semibold text-slate-800' }, 'Matériel reçu'),
                h('p', { class: 'text-xs text-slate-500' }, 'Pour chaque article, indiquez ce qui a été reçu, dont le nombre de pièces défectueuses.'),
                ...saisies.map((s, i) => h('div', { class: 'border-t border-slate-100 pt-3 space-y-2' },
                    h('p', { class: 'text-sm font-medium text-slate-800' }, `${s.libelle} `, h('span', { class: 'text-xs text-slate-500 font-normal' }, `(attendu : ${s.attendu})`)),
                    h('div', { class: 'grid grid-cols-2 gap-3' },
                        h('div', {}, h('label', { class: ETIQUETTE }, 'Reçu'), champs[i].recu),
                        h('div', {}, h('label', { class: ETIQUETTE }, 'Défectueux'), champs[i].defectueux)),
                    h('div', {}, h('label', { class: ETIQUETTE }, 'Observation (facultative)'), champs[i].observation))),
                erreur,
                h('div', { class: 'flex justify-between gap-2' }, lien('#/', 'Annuler'), h('button', { type: 'button', class: BOUTON, onclick: continuer }, 'Continuer'))));
    }

    /* Étape 2 : l'autorité signe */
    function etapeAutorite() {
        const erreur = h('div', {});
        const code = h('input', {
            type: 'password', id: 'reception-code', inputmode: 'numeric', pattern: '[0-9]{6}', minlength: '6', maxlength: '6',
            autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', class: `${CHAMP} tracking-widest`, 'aria-label': 'Code personnel de réception (6 chiffres)',
        });
        if (!etat.canvas) {
            etat.canvas = h('canvas', { width: '800', height: '300', class: 'w-full h-40 border border-slate-300 rounded-lg bg-white touch-none', 'aria-label': 'Zone de signature' });
            etat.signature = creerSignature(etat.canvas);
        }
        const apercu = h('div', {}, etat.photo ? h('img', { src: etat.photo.url, alt: 'Photo de la réception', class: 'max-h-40 rounded-lg border border-slate-200' }) : null);
        const entreePhoto = h('input', {
            type: 'file', accept: 'image/*', capture: 'environment', class: 'text-sm', 'aria-label': 'Photo de la réception',
            onchange: async () => {
                const fichier = entreePhoto.files?.[0];
                if (!fichier) return;
                try {
                    const photo = await preparerPhoto(fichier);
                    if (etat.photo) URL.revokeObjectURL(etat.photo.url);
                    etat.photo = { ...photo, url: URL.createObjectURL(photo.blob) };
                    remplacer(apercu, h('img', { src: etat.photo.url, alt: 'Photo de la réception', class: 'max-h-40 rounded-lg border border-slate-200' }));
                } catch (e) {
                    remplacer(erreur, alerte(e.message));
                }
            },
        });
        const etatGps = h('p', { class: 'text-xs text-slate-500' }, etat.gps ? `Position enregistrée (± ${etat.gps.precision ?? '?'} m).` : 'Position non enregistrée (facultative).');
        const boutonGps = h('button', {
            type: 'button', class: BOUTON_SECONDAIRE,
            onclick: () => {
                if (!navigator.geolocation) { etatGps.textContent = 'Position indisponible sur cet appareil.'; return; }
                etatGps.textContent = 'Recherche de la position…';
                navigator.geolocation.getCurrentPosition(
                    (p) => {
                        etat.gps = { lat: Number(p.coords.latitude.toFixed(6)), lng: Number(p.coords.longitude.toFixed(6)), precision: Math.round(p.coords.accuracy) };
                        etatGps.textContent = `Position enregistrée (± ${etat.gps.precision} m).`;
                    },
                    () => { etatGps.textContent = 'Position indisponible : la réception reste valable sans elle.'; },
                    { enableHighAccuracy: true, timeout: 10_000, maximumAge: 60_000 },
                );
            },
        }, 'Enregistrer ma position');

        // Un code saisi ne survit ni à un retour en arrière ni à un changement d'écran.
        ctx.nettoyages?.push(() => { code.value = ''; });
        const selection = signataires.length > 1
            ? h('div', {}, h('label', { class: ETIQUETTE, for: 'reception-signataire' }, 'Qui signe ?'),
                h('select', {
                    id: 'reception-signataire', class: CHAMP,
                    onchange: (e) => { etat.signataire = signataires.find((s) => String(s.user_id) === e.target.value) ?? etat.signataire; },
                }, signataires.map((s) => h('option', { value: String(s.user_id), selected: s.user_id === etat.signataire?.user_id }, `${s.titre} : ${s.nom} (${s.qualite})`))))
            : null;

        const champNom = signataires.length ? null : h('input', { type: 'text', maxlength: '150', value: saisieSignataire.nom, class: CHAMP, 'aria-label': 'Nom du signataire', oninput: (e) => { saisieSignataire.nom = e.target.value; } });
        const champQualite = signataires.length ? null : h('input', { type: 'text', maxlength: '150', value: saisieSignataire.qualite, class: CHAMP, 'aria-label': 'Qualité du signataire', oninput: (e) => { saisieSignataire.qualite = e.target.value; } });

        const bouton = signataires.length ? h('button', { type: 'button', class: BOUTON }, 'Valider la réception') : null;
        const boutonRepli = h('button', { type: 'button', class: BOUTON_SECONDAIRE }, 'Signer sans code (identité non vérifiée)');
        const boutons = [bouton, boutonRepli].filter(Boolean);

        /** Enregistre la réception : avec le code (bloc chiffré) ou, en repli, sans code (aucun bloc). */
        async function enregistrer(repli) {
            if (etat.occupe) return;
            remplacer(erreur);
            // Toute vérification préalable qui échoue efface aussi le code saisi. Le repli n'utilise jamais le code.
            const refuser = (texte) => { code.value = ''; remplacer(erreur, alerte(texte)); };
            if (repli) code.value = '';
            let signataire = etat.signataire;
            if (!signataires.length) {
                const nom = rogner(champNom.value ?? '');
                const qualite = rogner(champQualite.value ?? '');
                if (nom === '' || qualite === '') { refuser('Le nom et la qualité du signataire sont obligatoires.'); return; }
                signataire = { user_id: null, nom, qualite };
            }
            if (!repli && !/^[0-9]{6}$/.test(code.value)) { refuser('Le code personnel comporte 6 chiffres.'); return; }
            if (etat.signature.estVide()) { refuser('La signature est obligatoire : signez dans le cadre.'); return; }
            if (repli && !etat.photo && !window.confirm('Sans code, l\'identité du signataire ne sera pas vérifiée : une photo de la réception est fortement conseillée. Enregistrer sans photo ?')) return;
            etat.occupe = true;
            boutons.forEach((b) => { b.disabled = true; });
            try {
                await valider({ code, repli, signataire });
                etat.repli = repli;
                etat.etape = 'enregistree';
                dessiner();
            } catch (e) {
                remplacer(erreur, alerte(e.message || 'Validation impossible.'));
                boutons.forEach((b) => { b.disabled = false; });
            } finally {
                code.value = '';
                etat.occupe = false;
            }
        }
        bouton?.addEventListener('click', () => enregistrer(false));
        boutonRepli.addEventListener('click', () => enregistrer(true));

        const bloqueur = !signataires.length
            ? h('div', { role: 'status', class: 'p-3 rounded-xl text-sm bg-amber-50 text-amber-800 border border-amber-200' }, 'Aucune autorité n\'est rattachée à ce destinataire : pas de code possible. Saisissez le nom et la qualité du signataire ; la réception sera enregistrée sans vérification d\'identité.')
            : null;

        return h('div', { class: 'space-y-4' }, entete(),
            h('div', { class: CARTE },
                h('h3', { class: 'font-semibold text-slate-800' }, 'Signature de l\'autorité'),
                etat.signataire
                    ? h('div', { class: 'text-sm text-slate-700', id: 'signataire-attendu' }, h('p', {}, h('span', { class: 'text-xs text-slate-500' }, 'Nom attendu : '), etat.signataire.nom), h('p', {}, h('span', { class: 'text-xs text-slate-500' }, 'Qualité attendue : '), etat.signataire.qualite))
                    : null,
                bloqueur, selection,
                signataires.length ? null : h('div', { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3' },
                    h('div', {}, h('label', { class: ETIQUETTE }, 'Nom du signataire'), champNom),
                    h('div', {}, h('label', { class: ETIQUETTE }, 'Qualité du signataire'), champQualite)),
                signataires.length ? h('div', {}, h('label', { class: ETIQUETTE, for: 'reception-code' }, 'Code personnel (6 chiffres)'), code,
                    h('p', { class: 'text-xs text-slate-500 mt-1' }, 'Le code est chiffré à la validation puis effacé : il n\'est jamais conservé sur la tablette.'),
                    h('p', { class: 'text-xs text-slate-500 mt-1' }, 'Code oublié ou verrouillé : « Signer sans code » enregistre la réception, identité non vérifiée (à confirmer depuis le compte du signataire ou par le service logistique).')) : null,
                h('div', {}, h('p', { class: ETIQUETTE }, 'Signature (au doigt)'), etat.canvas,
                    h('button', { type: 'button', class: `${BOUTON_SECONDAIRE} mt-2`, onclick: () => etat.signature.effacer() }, 'Effacer la signature')),
                h('div', {}, h('label', { class: ETIQUETTE }, 'Photo de la réception (facultative)'), entreePhoto, apercu),
                h('div', { class: 'space-y-1' }, boutonGps, etatGps),
                erreur,
                h('div', { class: 'flex flex-wrap justify-between gap-2' }, h('button', { type: 'button', class: BOUTON_SECONDAIRE, onclick: () => { code.value = ''; etat.etape = 'lignes'; dessiner(); } }, 'Retour'),
                    h('div', { class: 'flex flex-wrap justify-end gap-2' }, boutonRepli, bouton))));
    }

    /*
     * Validation : le bloc est chiffré une fois, avec le code effacé aussitôt, puis tout part dans la file.
     * Repli (code oublié ou verrouillé, aucune autorité rattachée) : aucun bloc (`bloc_preuve: null`) ; le serveur
     * enregistre la réception « à vérifier », identité non vérifiée. Clé d'idempotence et contenu suivent les mêmes règles.
     */
    async function valider({ code, repli = false, signataire }) {
        const cle = identifiantAleatoire();
        const horodatage_terminal = horodatage();
        const lignes = saisies.map((s) => ({ materiel_id: s.materiel_id, recu: Number(s.recu), defectueux: Number(s.defectueux), observation: s.observation }));
        let contenu;
        let bloc = null;
        if (repli) {
            code.value = '';
            contenu = {
                signataire: { user_id: signataire.user_id ?? null, nom: rogner(String(signataire.nom ?? '')), qualite: rogner(String(signataire.qualite ?? '')) },
                lignes: normaliserLignes(lignes),
            };
        } else {
            const cleServeur = await ctx.stockage.meta.lire('cle_preuve');
            if (!cleServeur?.pem) throw new Error('Clé de preuve non téléchargée : connectez la tablette au réseau puis synchronisez.');
            const clePublique = await importerCle(cleServeur.pem);
            contenu = contenuPreuve({
                decharge_id: element.decharge.id,
                decharge_identifiant: element.decharge.identifiant,
                remise_id: element.remise.id,
                cle,
                signataire: { user_id: signataire.user_id, nom: signataire.nom, qualite: signataire.qualite },
                lignes,
                horodatage_terminal,
            });

            // Le code n'est lu qu'ici, passé à construireBloc et effacé du champ dans la foulée.
            const promesse = construireBloc({ code: code.value, cle, contenu, clePublique });
            code.value = '';
            ({ bloc } = await promesse);
        }

        const pieces = [];
        const signaturePng = await etat.signature.versPng();
        pieces.push({ empreinte: await sha256Hex(signaturePng), role: 'signature', blob: signaturePng, type: 'image/png' });
        if (etat.photo) pieces.push({ empreinte: etat.photo.empreinte, role: 'photo', blob: etat.photo.blob, type: etat.photo.type });

        for (const piece of pieces) {
            await ctx.stockage.pieces.put({ empreinte: piece.empreinte, blob: piece.blob, type: piece.type, role: piece.role });
            await ctx.file.ajouter({ type: 'piece', id: piece.empreinte, empreinte: piece.empreinte, role: piece.role });
        }
        await ctx.file.ajouter({
            type: 'declaration',
            id: cle,
            cle,
            remise_id: element.remise.id,
            decharge_id: element.decharge.id,
            horodatage_terminal,
            bloc_preuve: bloc,
            ...(repli ? { repli: true } : {}),
            signataire: contenu.signataire,
            lignes: contenu.lignes,
            pieces: pieces.map(({ empreinte, role }) => ({ empreinte, role })),
            gps: etat.gps,
            utilisateur_id: ctx.etat.session?.utilisateur?.id ?? null,
            resume: { decharge: element.decharge.identifiant, numero: element.decharge.numero, de: element.remise.de, a: element.remise.a, etape: element.remise.etape },
        });
        if (etat.photo) URL.revokeObjectURL(etat.photo.url);
        etat.signature.detruire();
        ctx.sync.cycle().then(() => ctx.rafraichirEntete()).catch(() => {});
        await ctx.rafraichirEntete();
    }

    function etapeEnregistree() {
        if (etat.repli) {
            return h('div', { class: `${CARTE}`, id: 'reception-enregistree' },
                h('div', { class: 'flex items-start justify-between gap-3' }, h('h2', { class: 'font-semibold text-slate-800' }, 'Enregistrée — identité non vérifiée'),
                    h('div', { class: 'flex flex-col items-end gap-1' }, badge(STATUTS.EN_ATTENTE), badgeIdentite())),
                h('p', { class: 'text-sm text-slate-600' }, 'La réception est enregistrée sur la tablette et sera envoyée dès que le réseau le permet. Signée sans code, l\'identité du signataire n\'est pas vérifiée : le signataire peut la confirmer depuis son compte, sinon le service logistique la vérifie.'),
                h('div', { class: 'flex justify-end' }, lien('#/', 'Retour à la liste')));
        }

        return h('div', { class: `${CARTE}`, id: 'reception-enregistree' },
            h('div', { class: 'flex items-start justify-between gap-3' }, h('h2', { class: 'font-semibold text-slate-800' }, 'Enregistrée — vérification d\'identité en attente'), badge(STATUTS.EN_ATTENTE)),
            h('p', { class: 'text-sm text-slate-600' }, 'La réception est enregistrée sur la tablette. Elle sera envoyée dès que le réseau le permet ; le serveur vérifie alors l\'identité du signataire avec le code chiffré. Le code n\'a pas été conservé.'),
            h('div', { class: 'flex justify-end' }, lien('#/', 'Retour à la liste')));
    }

    function dessiner() {
        remplacer(racine, etat.etape === 'lignes' ? etapeLignes() : etat.etape === 'autorite' ? etapeAutorite() : etapeEnregistree());
    }
    dessiner();

    return racine;
}

function recapitulatif(ctx, declaration) {
    const statut = declaration.statut === STATUTS.SYNCHRONISE && declaration.reponse?.declaration?.statut === 'a_verifier' ? STATUTS.A_VERIFIER : declaration.statut;
    const sansCode = identiteNonVerifiee(declaration);
    const titre = declaration.statut !== STATUTS.EN_ATTENTE ? 'Réception enregistrée'
        : (sansCode ? 'Enregistrée — identité non vérifiée' : 'Enregistrée — vérification d\'identité en attente');

    return h('div', { class: `${CARTE} max-w-xl mx-auto`, id: 'reception-recapitulatif' },
        h('div', { class: 'flex items-start justify-between gap-3' },
            h('h2', { class: 'font-semibold text-slate-800' }, titre),
            h('div', { class: 'flex flex-col items-end gap-1' }, badge(statut), sansCode ? badgeIdentite() : null)),
        h('p', { class: 'text-sm text-slate-600' }, `Signée le ${declaration.horodatage_terminal.replace('T', ' à ').slice(0, 21)} par ${declaration.signataire?.nom ?? ''}.`),
        declaration.message ? h('p', { class: 'text-sm text-slate-600' }, declaration.message) : null,
        h('ul', { class: 'text-sm text-slate-700 list-disc pl-5' }, (declaration.lignes ?? []).map((l) => h('li', {}, `Article ${l.materiel_id} : reçu ${l.recu}, défectueux ${l.defectueux}${l.observation ? ` — ${l.observation}` : ''}`))),
        h('div', { class: 'flex justify-end' }, lien('#/', 'Retour à la liste')));
}

/* ───────────── Redistribution (appareil désigné) ───────────── */

export async function ecranRedistribution(ctx) {
    const info = await ctx.stockage.meta.lire('appareil_designe');
    const designe = info?.designe === true;
    if (!designe) {
        return h('div', { class: `${CARTE} max-w-xl mx-auto` },
            h('h2', { class: 'font-semibold text-slate-800' }, 'Redistribution'),
            h('p', { class: 'text-sm text-slate-600' }, 'Cette tablette n\'est pas désignée pour la redistribution. Le service logistique central désigne la tablette du préfet à partir de son identifiant.'),
            lien('#/', 'Retour à la liste'));
    }

    const [elements, reserves, toute] = await Promise.all([ctx.stockage.elements.list(), ctx.stockage.meta.lister('reserve:'), ctx.file.lister()]);
    const file = duCompte(toute, ctx.etat.session?.utilisateur?.id ?? null);
    const decharges = dechargesDeRedistribution({ designe, elements, reserves, file });
    const enLigne = ctx.etat.enLigne;

    const carte = ({ decharge, mobilisable, reserve }) => {
        const erreur = h('div', {});
        const restantes = reserve ? reserveRestante(reserve, file) : [];
        const libere = file.some((e) => e.type === 'liberation' && e.decharge_id === decharge.id && e.statut === STATUTS.EN_ATTENTE);

        /* Réserver (en ligne) */
        const champsReserve = mobilisable.map((l) => h('input', { type: 'number', min: '0', max: String(l.quantite), step: '1', inputmode: 'numeric', value: String(l.quantite), class: CHAMP, 'aria-label': `À réserver : ${l.libelle}` }));
        const reserver = mobilisable.length ? h('div', { class: 'space-y-3' },
            h('h4', { class: 'text-sm font-semibold text-slate-800' }, 'Réserver pour cette tablette'),
            h('p', { class: 'text-xs text-slate-500' }, 'À faire avec le réseau, avant de partir : la réserve est ensuite utilisable sans connexion.'),
            ...mobilisable.map((l, i) => h('div', {}, h('label', { class: ETIQUETTE }, `${l.libelle} (mobilisable : ${l.quantite})`), champsReserve[i])),
            h('button', {
                type: 'button', class: BOUTON, disabled: !enLigne,
                onclick: async (evenement) => {
                    const lignes = mobilisable.map((l, i) => ({ materiel_id: l.materiel_id, quantite: lireEntier(champsReserve[i]) ?? -1 }));
                    if (lignes.some((l) => l.quantite < 0)) { remplacer(erreur, alerte('Quantités invalides.')); return; }
                    const utiles = lignes.filter((l) => l.quantite > 0);
                    if (!utiles.length) { remplacer(erreur, alerte('Indiquez au moins une quantité à réserver.')); return; }
                    evenement.target.disabled = true;
                    const reponse = await ctx.api.envoyer('POST', `/decharges/${decharge.id}/reserve`, { json: { lignes: utiles }, avecAppareil: true });
                    if (reponse.status === 200 && reponse.corps?.reserve) {
                        await ctx.stockage.meta.ecrire(`reserve:${decharge.id}`, { ...reponse.corps.reserve, decharge_id: decharge.id, decharge });
                        await ctx.rafraichir();

                        return;
                    }
                    evenement.target.disabled = false;
                    remplacer(erreur, alerte(reponse.status === 0 ? 'Réserve impossible hors connexion.' : (reponse.corps?.message ?? 'Réserve refusée.')));
                },
            }, 'Réserver'),
            enLigne ? null : h('p', { class: 'text-xs text-amber-700' }, 'Hors connexion : la réserve se fait avec le réseau.')) : null;

        /* Redistribuer depuis la réserve (hors connexion possible) */
        const aRedistribuer = restantes.filter((l) => l.restant > 0);
        const champsRedistribution = aRedistribuer.map((l) => h('input', { type: 'number', min: '0', max: String(l.restant), step: '1', inputmode: 'numeric', value: '0', class: CHAMP, 'aria-label': `À redistribuer : ${l.libelle}` }));
        const redistribuer = aRedistribuer.length && !libere ? h('div', { class: 'space-y-3' },
            h('h4', { class: 'text-sm font-semibold text-slate-800' }, 'Réserve de la tablette'),
            ...aRedistribuer.map((l, i) => h('div', {}, h('label', { class: ETIQUETTE }, `${l.libelle} (reste : ${l.restant})`), champsRedistribution[i])),
            h('div', { class: 'flex flex-wrap gap-2 justify-end' },
                h('button', {
                    type: 'button', class: BOUTON_SECONDAIRE,
                    onclick: async () => {
                        if (!window.confirm('Libérer le reste de la réserve ? Il redevient disponible pour la redistribution en ligne.')) return;
                        await ctx.file.ajouter({ type: 'liberation', id: `liberation-${decharge.id}-${identifiantAleatoire()}`, decharge_id: decharge.id, utilisateur_id: ctx.etat.session?.utilisateur?.id ?? null });
                        ctx.sync.cycle().catch(() => {});
                        await ctx.rafraichir();
                    },
                }, 'Libérer le reste'),
                h('button', {
                    type: 'button', class: BOUTON,
                    onclick: async () => {
                        const lignes = aRedistribuer.map((l, i) => ({ materiel_id: l.materiel_id, quantite: lireEntier(champsRedistribution[i]) ?? -1 }));
                        if (lignes.some((l) => l.quantite < 0)) { remplacer(erreur, alerte('Quantités invalides.')); return; }
                        const depasse = aRedistribuer.find((l, i) => lignes[i].quantite > l.restant);
                        if (depasse) { remplacer(erreur, alerte(`Quantité supérieure à la réserve pour « ${depasse.libelle} ».`)); return; }
                        const utiles = lignes.filter((l) => l.quantite > 0);
                        if (!utiles.length) { remplacer(erreur, alerte('Indiquez au moins une quantité à redistribuer.')); return; }
                        const cle = identifiantAleatoire();
                        await ctx.file.ajouter({
                            type: 'redistribution', id: cle, cle, decharge_id: decharge.id, horodatage_terminal: horodatage(), lignes: utiles,
                            utilisateur_id: ctx.etat.session?.utilisateur?.id ?? null,
                        });
                        ctx.sync.cycle().catch(() => {});
                        await ctx.rafraichir();
                    },
                }, 'Enregistrer la redistribution'))) : null;

        /* Historique des envois de cette décharge */
        const envois = file.filter((e) => (e.type === 'redistribution' || e.type === 'liberation') && e.decharge_id === decharge.id);
        const historique = envois.length ? h('ul', { class: 'space-y-2 border-t border-slate-100 pt-3' }, envois.map((e) => {
            const arbitrer = e.reponse?.redistribution?.a_arbitrer === true;
            const libelle = e.type === 'liberation' ? 'Libération du reste' : `Redistribution (${(e.lignes ?? []).map((l) => `${l.quantite} × article ${l.materiel_id}`).join(', ')})`;

            return h('li', { class: 'text-sm' },
                h('div', { class: 'flex items-center justify-between gap-2' }, h('span', { class: 'text-slate-700' }, libelle), badge(arbitrer ? STATUTS.A_VERIFIER : e.statut)),
                arbitrer ? h('p', { class: 'text-xs text-slate-600' }, `À arbitrer par le central${e.reponse.redistribution.raison ? ` : ${e.reponse.redistribution.raison}` : ''}`) : null,
                e.statut === STATUTS.A_VERIFIER ? h('p', { class: 'text-xs text-slate-600' }, e.message ?? '') : null,
                e.statut === STATUTS.A_VERIFIER ? h('button', { type: 'button', class: BOUTON_SECONDAIRE, onclick: async () => { await ctx.file.reessayer(e.id); ctx.sync.cycle().catch(() => {}); await ctx.rafraichir(); } }, 'Renvoyer') : null);
        })) : null;

        return h('li', { class: CARTE, 'data-decharge': decharge.id },
            h('h3', { class: 'font-semibold text-slate-800' }, `Décharge n° ${decharge.numero ?? decharge.identifiant ?? decharge.id}`),
            reserver, redistribuer,
            !reserver && !redistribuer && !envois.length ? h('p', { class: 'text-sm text-slate-600' }, 'Rien à redistribuer pour cette décharge.') : null,
            historique, erreur);
    };

    return h('div', { class: 'max-w-2xl mx-auto space-y-4' },
        h('div', { class: 'p-3 rounded-xl text-sm bg-green-50 text-green-800 border border-green-200' }, `Tablette désignée pour la redistribution${info.appareil?.prefecture ? ` : ${info.appareil.prefecture.libelle}` : ''}.`),
        decharges.length
            ? h('ul', { class: 'space-y-3' }, decharges.map(carte))
            : h('div', { class: CARTE }, h('p', { class: 'text-sm text-slate-600' }, 'Aucune redistribution à préparer pour le moment.')));
}
