/**
 * Restitution sur la tablette de l'agent de retour : le détenteur déclare ce qu'il rend (quantités, observation,
 * signature, code personnel chiffré dans le bloc de preuve, photo facultative), puis l'agent prend le retour en
 * charge une fois la déclaration retenue par le serveur. Tout se fait hors connexion : la déclaration et la prise
 * en charge partent par la file d'envoi, avec les mêmes règles que les réceptions (clé, bloc, pièces).
 *
 * Les restitutions téléchargées sont rangées dans les réglages locaux (`restitutions`), à part des affectations.
 */

import { rogner } from './canonique.js';
import { h, remplacer } from './dom.js';
import {
    alerte, badge, badgeIdentite, BOUTON, BOUTON_SECONDAIRE, CARTE, CHAMP, ETIQUETTE, lien, lireEntier,
} from './ecrans.js';
import { horodatage, STATUTS } from './file.js';
import { identifiantAleatoire, sha256Hex } from './octets.js';
import { preparerPhoto } from './photo.js';
import { construireBloc, contenuPreuveRestitution, importerCle, normaliserLignesRestitution } from './preuve.js';
import { creerSignature } from './signature.js';
import { duCompte } from './vue.js';

export const MESSAGE_LECTURE_SEULE = 'À déclarer depuis le compte du détenteur';
export const MESSAGE_ARBITRAGE = 'Arbitrage du responsable en attente';

/** Plafond d'une quantité, celui de l'API (lignes.*.restitue). */
export const QUANTITE_MAX = 10_000_000;

export const LIBELLES_RESTITUTION = Object.freeze({
    a_preparer: 'À préparer',
    en_retour: 'En retour',
    recue_central: 'Reçue au central',
    controlee: 'Contrôlée',
});

const LIBELLES_DECLARATION = Object.freeze({
    retenue: 'Déclaration retenue',
    concordante: 'Déclaration concordante',
    a_verifier: 'Déclaration à vérifier par le service logistique',
    valide: 'Déclaration enregistrée',
});

/** Statuts de la réponse du serveur pour lesquels la déclaration de la tablette fait foi. */
const RETENUES = ['retenue', 'concordante'];

/** Identifiant de la prise en charge d'une restitution dans la file : une seule par restitution. */
export const idPriseEnCharge = (restitutionId) => `prise-en-charge-${restitutionId}`;

/** Une réponse qui ne dit rien du fond (réseau, session, limite, serveur) : la prise en charge part par la file. */
const A_REESSAYER = (status) => status === 0 || status === 401 || status === 408 || status === 409 || status === 429 || status >= 500;

async function restitutionsLocales(ctx) {
    return (await ctx.stockage.meta.lire('restitutions')) ?? [];
}

/**
 * État d'une restitution sur la tablette (logique pure) : la déclaration et la prise en charge de la file pour ce
 * compte, et ce qui peut encore se faire ici.
 */
export function etatRestitution(element, file, utilisateurId = null) {
    const elements = utilisateurId === null ? file : duCompte(file, utilisateurId);
    const declaration = elements.filter((e) => e.type === 'declaration_restitution' && e.restitution_id === element.id).at(-1) ?? null;
    const priseEnCharge = elements.find((e) => e.type === 'prise_en_charge' && e.restitution_id === element.id) ?? null;
    const reponse = declaration?.statut === STATUTS.SYNCHRONISE ? declaration.reponse?.declaration?.statut ?? null : null;
    const aPreparer = element.statut === 'a_preparer';
    const prise = priseEnCharge?.statut === STATUTS.SYNCHRONISE || element.statut !== 'a_preparer';
    const retenueIci = RETENUES.includes(reponse);

    return {
        declaration,
        priseEnCharge,
        prise,
        peutDeclarer: aPreparer && element.peut_declarer_tablette === true && declaration === null,
        lectureSeule: aPreparer && element.peut_declarer_tablette !== true && declaration === null && element.declaration_retenue !== true,
        arbitrage: element.arbitrage_en_attente === true,
        // Une déclaration attend l'arbitrage du responsable : le serveur refuserait la prise en charge.
        peutPrendreEnCharge: aPreparer && priseEnCharge === null && element.arbitrage_en_attente !== true
            && (element.peut_prendre_en_charge === true || (element.peut_declarer_tablette === true && retenueIci)),
    };
}

/**
 * Prise en charge du retour par l'agent. En ligne, l'appel part tout de suite : un refus du serveur (déclaration à
 * vérifier, rien de retenu…) est rendu tel quel et rien n'est mis en file. Hors connexion ou sans réponse, la prise
 * en charge est mise en file et part à la prochaine synchronisation.
 *
 * @returns {Promise<{etat: 'synchronise'|'en_attente'|'refusee', message?: string}>}
 */
export async function prendreEnCharge(ctx, element) {
    const base = {
        type: 'prise_en_charge', id: idPriseEnCharge(element.id), restitution_id: element.id,
        utilisateur_id: ctx.etat.session?.utilisateur?.id ?? null,
        resume: { detenteur: element.detenteur?.nom ?? '', cycle: element.cycle ?? '' },
    };
    const mettreEnFile = async () => {
        await ctx.file.ajouter(base);
        ctx.sync.cycle().then(() => ctx.rafraichirEntete()).catch(() => {});

        return { etat: STATUTS.EN_ATTENTE };
    };
    if (ctx.etat.enLigne === false) return mettreEnFile();

    const reponse = await ctx.api.envoyer('POST', `/restitutions/${element.id}/prise-en-charge`);
    if (reponse.status >= 200 && reponse.status < 300) {
        await ctx.file.ajouter({ ...base, statut: STATUTS.SYNCHRONISE, http: reponse.status, reponse: reponse.corps ?? null });
        const restitutions = await restitutionsLocales(ctx);
        await ctx.stockage.meta.ecrire('restitutions', restitutions.map((r) => (r.id === element.id
            ? { ...r, statut: reponse.corps?.restitution?.statut ?? 'en_retour', peut_prendre_en_charge: false, peut_declarer_tablette: false }
            : r)));

        return { etat: STATUTS.SYNCHRONISE };
    }
    if (A_REESSAYER(reponse.status)) return mettreEnFile();

    return { etat: 'refusee', message: reponse.corps?.message ?? `Prise en charge refusée par le serveur (${reponse.status}).` };
}

function boutonPriseEnCharge(ctx, element, zone) {
    const bouton = h('button', { type: 'button', class: BOUTON }, 'Prise en charge');
    bouton.addEventListener('click', async () => {
        bouton.disabled = true;
        remplacer(zone);
        const resultat = await prendreEnCharge(ctx, element);
        if (resultat.etat === 'refusee') {
            bouton.disabled = false;
            remplacer(zone, alerte(resultat.message));

            return;
        }
        await ctx.rafraichir();
    });

    return bouton;
}

function boutonRenvoyer(ctx, id) {
    return h('button', {
        type: 'button', class: BOUTON_SECONDAIRE,
        onclick: async () => { await ctx.file.reessayer(id); ctx.sync.cycle(); await ctx.rafraichir(); },
    }, 'Renvoyer');
}

/** Ce qu'il advient de la déclaration et de la prise en charge, en clair. */
function details(etat) {
    const lignes = [];
    const { declaration, priseEnCharge } = etat;
    if (declaration) {
        if (declaration.statut === STATUTS.SYNCHRONISE) {
            lignes.push(LIBELLES_DECLARATION[declaration.reponse?.declaration?.statut] ?? 'Déclaration enregistrée');
        } else if (declaration.message) {
            lignes.push(declaration.message);
        } else if (declaration.bloc_preuve === null) {
            lignes.push('Déclaration signée sans code : identité non vérifiée.');
        } else {
            lignes.push('Déclaration en attente d\'envoi.');
        }
    }
    if (etat.prise) {
        lignes.push('Retour pris en charge.');
    } else if (priseEnCharge) {
        lignes.push(priseEnCharge.message ?? 'Prise en charge en attente d\'envoi.');
    }

    return lignes;
}

/* ───────────── Liste des restitutions ───────────── */

export async function ecranRestitutions(ctx) {
    const utilisateurId = ctx.etat.session?.utilisateur?.id ?? null;
    const [restitutions, file] = await Promise.all([restitutionsLocales(ctx), ctx.file.lister()]);

    const carte = (element) => {
        const etat = etatRestitution(element, file, utilisateurId);
        const zone = h('div', {});
        const actions = [];
        const aVerifier = [etat.declaration, etat.priseEnCharge].find((e) => e?.statut === STATUTS.A_VERIFIER) ?? null;
        if (etat.peutDeclarer) actions.push(h('a', { href: `#/restitution/${element.id}`, class: BOUTON }, 'Déclarer'));
        if (etat.declaration) actions.push(h('a', { href: `#/restitution/${element.id}`, class: BOUTON_SECONDAIRE }, 'Voir'));
        if (aVerifier) actions.push(boutonRenvoyer(ctx, aVerifier.id));
        if (etat.peutPrendreEnCharge) actions.push(boutonPriseEnCharge(ctx, element, zone));
        const enCours = etat.priseEnCharge && etat.priseEnCharge.statut !== STATUTS.SYNCHRONISE ? etat.priseEnCharge : etat.declaration;
        const statut = enCours?.statut ?? (etat.prise ? STATUTS.SYNCHRONISE : STATUTS.DISPONIBLE);

        return h('li', { class: CARTE, 'data-restitution': element.id },
            h('div', { class: 'flex items-start justify-between gap-3' },
                h('div', {},
                    h('p', { class: 'font-semibold text-slate-800' }, element.detenteur?.nom ?? `Restitution n° ${element.id}`),
                    h('p', { class: 'text-xs text-slate-500' }, `${element.cycle ?? ''} — restitution n° ${element.id} (${LIBELLES_RESTITUTION[element.statut] ?? element.statut})`)),
                h('div', { class: 'flex flex-col items-end gap-1' }, badge(statut), etat.declaration?.bloc_preuve === null ? badgeIdentite() : null)),
            h('ul', { class: 'text-sm text-slate-700 list-disc pl-5' }, (element.lignes ?? []).map((l) => h('li', {}, `${l.libelle} : ${l.attendu} à rendre`))),
            ...details(etat).map((t) => h('p', { class: 'text-sm text-slate-600' }, t)),
            etat.lectureSeule ? h('p', { class: 'text-sm text-slate-600' }, MESSAGE_LECTURE_SEULE) : null,
            etat.arbitrage && etat.prise === false ? h('p', { role: 'status', class: 'text-sm text-amber-700' }, `${MESSAGE_ARBITRAGE} : la prise en charge attend sa décision.`) : null,
            zone,
            actions.length ? h('div', { class: 'flex flex-wrap gap-2 justify-end' }, actions) : null);
    };

    return h('div', { class: 'max-w-2xl mx-auto space-y-4' },
        h('div', { class: CARTE },
            h('h2', { class: 'font-semibold text-slate-800' }, 'Restitution du matériel'),
            h('p', { class: 'text-sm text-slate-600' }, 'Le détenteur déclare ce qu\'il rend, signe et saisit son code personnel ; une fois la déclaration retenue, vous prenez le retour en charge.')),
        restitutions.length
            ? h('ul', { class: 'space-y-3' }, restitutions.map(carte))
            : h('div', { class: CARTE }, h('p', { class: 'text-sm text-slate-600' }, 'Aucune restitution à faire pour le moment. Synchronisez avec le réseau pour la télécharger.')),
        h('div', { class: 'flex justify-end' }, lien('#/', 'Retour aux réceptions')));
}

/* ───────────── Déclaration d'une restitution ───────────── */

function recapitulatif(ctx, element, etat) {
    const declaration = etat.declaration;
    const zone = h('div', {});
    const titre = declaration.statut === STATUTS.EN_ATTENTE && declaration.bloc_preuve === null
        ? 'Restitution enregistrée — identité non vérifiée'
        : 'Restitution enregistrée';

    return h('div', { class: `${CARTE} max-w-xl mx-auto`, id: 'restitution-recapitulatif' },
        h('div', { class: 'flex items-start justify-between gap-3' },
            h('h2', { class: 'font-semibold text-slate-800' }, titre),
            h('div', { class: 'flex flex-col items-end gap-1' }, badge(declaration.statut), declaration.bloc_preuve === null ? badgeIdentite() : null)),
        h('p', { class: 'text-sm text-slate-600' }, `${element?.detenteur?.nom ?? declaration.resume?.detenteur ?? ''} — signée le ${declaration.horodatage_terminal.replace('T', ' à ').slice(0, 21)} par ${declaration.signataire?.nom ?? ''}.`),
        ...details(etat).map((t) => h('p', { class: 'text-sm text-slate-600' }, t)),
        h('ul', { class: 'text-sm text-slate-700 list-disc pl-5' }, (declaration.lignes ?? []).map((l) => {
            const libelle = element?.lignes?.find((x) => x.materiel_id === l.materiel_id)?.libelle ?? `Article ${l.materiel_id}`;

            return h('li', {}, `${libelle} : rendu ${l.restitue}${l.observation ? ` — ${l.observation}` : ''}`);
        })),
        zone,
        h('div', { class: 'flex flex-wrap justify-end gap-2' },
            element && etat.peutPrendreEnCharge ? boutonPriseEnCharge(ctx, element, zone) : null,
            lien('#/restitutions', 'Retour aux restitutions')));
}

export async function ecranRestitution(ctx, id) {
    const utilisateurId = ctx.etat.session?.utilisateur?.id ?? null;
    const restitutionId = Number(id);
    const element = (await restitutionsLocales(ctx)).find((r) => r.id === restitutionId) ?? null;
    const file = await ctx.file.lister();
    const existante = etatRestitution(element ?? { id: restitutionId, statut: 'a_preparer' }, file, utilisateurId);
    if (existante.declaration) return recapitulatif(ctx, element, existante);

    const carteSimple = (...contenu) => h('div', { class: `${CARTE} max-w-xl mx-auto` }, ...contenu, lien('#/restitutions', 'Retour aux restitutions'));
    if (!element || element.statut !== 'a_preparer') {
        return carteSimple(h('p', { class: 'text-sm text-slate-600' }, 'Cette restitution n\'est plus à déclarer.'));
    }
    if (element.peut_declarer_tablette !== true) {
        return carteSimple(
            h('h2', { class: 'font-semibold text-slate-800' }, element.detenteur?.nom ?? `Restitution n° ${element.id}`),
            h('p', { class: 'text-sm text-slate-600' }, MESSAGE_LECTURE_SEULE));
    }

    const racine = h('div', { class: 'max-w-2xl mx-auto space-y-4' });
    const attendue = element.autorite_attendue ?? {};
    const signataires = attendue.user_id
        ? [{ user_id: attendue.user_id, nom: rogner(String(attendue.nom || 'Autorité')), qualite: rogner(String(attendue.qualite || 'Autorité')), titre: 'Autorité' },
            ...(attendue.delegues ?? []).map((d) => ({ user_id: d.user_id, nom: rogner(String(d.nom || 'Délégué')), qualite: rogner(String(d.qualite || 'Délégué')), titre: 'Délégué' }))]
        : [];
    const saisies = (element.lignes ?? []).map((l) => ({ materiel_id: l.materiel_id, libelle: l.libelle, attendu: l.attendu, restitue: String(l.attendu), observation: '' }));
    const etat = { etape: 'lignes', signataire: signataires[0] ?? null, photo: null, occupe: false, signature: null, canvas: null, repli: false };
    const saisieSignataire = { nom: rogner(String(attendue.nom ?? '')), qualite: rogner(String(attendue.qualite ?? '')) };

    const entete = () => h('div', { class: CARTE },
        h('div', { class: 'flex items-start justify-between gap-3' },
            h('div', {}, h('h2', { class: 'font-semibold text-slate-800' }, element.detenteur?.nom ?? `Restitution n° ${element.id}`),
                h('p', { class: 'text-xs text-slate-500' }, `${element.cycle ?? ''} — restitution n° ${element.id}`)),
            badge(STATUTS.DISPONIBLE)));

    /* Étape 1 : ce que le détenteur rend */
    function etapeLignes() {
        const erreur = h('div', {});
        const champs = saisies.map((s) => ({
            restitue: h('input', { type: 'number', inputmode: 'numeric', min: '0', step: '1', value: s.restitue, class: CHAMP, 'aria-label': `Rendu : ${s.libelle}` }),
            observation: h('input', { type: 'text', maxlength: '500', value: s.observation, class: CHAMP, 'aria-label': `Observation : ${s.libelle}` }),
        }));
        const continuer = () => {
            champs.forEach((c, i) => { saisies[i].restitue = c.restitue.value; saisies[i].observation = c.observation.value; });
            for (let i = 0; i < champs.length; i += 1) {
                const quantite = lireEntier(champs[i].restitue);
                if (quantite === null) {
                    remplacer(erreur, alerte(`Quantité invalide pour « ${saisies[i].libelle} » : entier positif ou nul.`));

                    return;
                }
                if (quantite > QUANTITE_MAX) {
                    remplacer(erreur, alerte(`Quantité trop grande pour « ${saisies[i].libelle} » : 10 000 000 au plus.`));

                    return;
                }
            }
            etat.etape = 'signature';
            dessiner();
        };

        return h('div', { class: 'space-y-4' }, entete(),
            h('div', { class: CARTE },
                h('h3', { class: 'font-semibold text-slate-800' }, 'Matériel rendu'),
                h('p', { class: 'text-xs text-slate-500' }, 'Pour chaque article, indiquez ce que le détenteur rend. Un retour supérieur à l\'attendu sera vérifié par le service logistique.'),
                ...saisies.map((s, i) => h('div', { class: 'border-t border-slate-100 pt-3 space-y-2' },
                    h('p', { class: 'text-sm font-medium text-slate-800' }, `${s.libelle} `, h('span', { class: 'text-xs text-slate-500 font-normal' }, `(à rendre : ${s.attendu})`)),
                    h('div', {}, h('label', { class: ETIQUETTE }, 'Rendu'), champs[i].restitue),
                    h('div', {}, h('label', { class: ETIQUETTE }, 'Observation (facultative)'), champs[i].observation))),
                erreur,
                h('div', { class: 'flex justify-between gap-2' }, lien('#/restitutions', 'Annuler'), h('button', { type: 'button', class: BOUTON, onclick: continuer }, 'Continuer'))));
    }

    /* Étape 2 : le détenteur signe */
    function etapeSignature() {
        const erreur = h('div', {});
        const code = h('input', {
            type: 'password', id: 'restitution-code', inputmode: 'numeric', pattern: '[0-9]{6}', minlength: '6', maxlength: '6',
            autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', class: `${CHAMP} tracking-widest`, 'aria-label': 'Code personnel (6 chiffres)',
        });
        // Un code saisi ne survit ni à un retour en arrière ni à un changement d'écran.
        ctx.nettoyages?.push(() => { code.value = ''; });
        if (!etat.canvas) {
            etat.canvas = h('canvas', { width: '800', height: '300', class: 'w-full h-40 border border-slate-300 rounded-lg bg-white touch-none', 'aria-label': 'Zone de signature' });
            etat.signature = creerSignature(etat.canvas);
        }
        const image = () => h('img', { src: etat.photo.url, alt: 'Photo du matériel rendu', class: 'max-h-40 rounded-lg border border-slate-200' });
        const apercu = h('div', {}, etat.photo ? image() : null);
        const entreePhoto = h('input', {
            type: 'file', accept: 'image/*', capture: 'environment', class: 'text-sm', 'aria-label': 'Photo du matériel rendu',
            onchange: async () => {
                const fichier = entreePhoto.files?.[0];
                if (!fichier) return;
                try {
                    const photo = await preparerPhoto(fichier);
                    if (etat.photo) URL.revokeObjectURL(etat.photo.url);
                    etat.photo = { ...photo, url: URL.createObjectURL(photo.blob) };
                    remplacer(apercu, image());
                } catch (e) {
                    remplacer(erreur, alerte(e.message));
                }
            },
        });
        const selection = signataires.length > 1
            ? h('div', {}, h('label', { class: ETIQUETTE, for: 'restitution-signataire' }, 'Qui signe ?'),
                h('select', {
                    id: 'restitution-signataire', class: CHAMP,
                    onchange: (e) => { etat.signataire = signataires.find((s) => String(s.user_id) === e.target.value) ?? etat.signataire; },
                }, signataires.map((s) => h('option', { value: String(s.user_id), selected: s.user_id === etat.signataire?.user_id }, `${s.titre} : ${s.nom} (${s.qualite})`))))
            : null;
        const champNom = signataires.length ? null : h('input', { type: 'text', maxlength: '150', value: saisieSignataire.nom, class: CHAMP, 'aria-label': 'Nom du signataire', oninput: (e) => { saisieSignataire.nom = e.target.value; } });
        const champQualite = signataires.length ? null : h('input', { type: 'text', maxlength: '150', value: saisieSignataire.qualite, class: CHAMP, 'aria-label': 'Qualité du signataire', oninput: (e) => { saisieSignataire.qualite = e.target.value; } });
        const bouton = signataires.length ? h('button', { type: 'button', class: BOUTON }, 'Valider la restitution') : null;
        const boutonRepli = h('button', { type: 'button', class: BOUTON_SECONDAIRE }, 'Signer sans code (identité non vérifiée)');
        const boutons = [bouton, boutonRepli].filter(Boolean);

        async function enregistrer(repli) {
            if (etat.occupe) return;
            remplacer(erreur);
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
            if (repli && !etat.photo && !window.confirm('Sans code, l\'identité du détenteur ne sera pas vérifiée : une photo du matériel rendu est fortement conseillée. Enregistrer sans photo ?')) return;
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

        return h('div', { class: 'space-y-4' }, entete(),
            h('div', { class: CARTE },
                h('h3', { class: 'font-semibold text-slate-800' }, 'Signature du détenteur'),
                etat.signataire
                    ? h('div', { class: 'text-sm text-slate-700', id: 'signataire-attendu' }, h('p', {}, h('span', { class: 'text-xs text-slate-500' }, 'Nom attendu : '), etat.signataire.nom), h('p', {}, h('span', { class: 'text-xs text-slate-500' }, 'Qualité attendue : '), etat.signataire.qualite))
                    : h('div', { role: 'status', class: 'p-3 rounded-xl text-sm bg-amber-50 text-amber-800 border border-amber-200' }, 'Aucune autorité n\'est rattachée à ce détenteur : pas de code possible. Saisissez le nom et la qualité du signataire ; la restitution sera enregistrée sans vérification d\'identité.'),
                selection,
                signataires.length ? null : h('div', { class: 'grid grid-cols-1 sm:grid-cols-2 gap-3' },
                    h('div', {}, h('label', { class: ETIQUETTE }, 'Nom du signataire'), champNom),
                    h('div', {}, h('label', { class: ETIQUETTE }, 'Qualité du signataire'), champQualite)),
                signataires.length ? h('div', {}, h('label', { class: ETIQUETTE, for: 'restitution-code' }, 'Code personnel (6 chiffres)'), code,
                    h('p', { class: 'text-xs text-slate-500 mt-1' }, 'Le code est chiffré à la validation puis effacé : il n\'est jamais conservé sur la tablette.')) : null,
                h('div', {}, h('p', { class: ETIQUETTE }, 'Signature (au doigt)'), etat.canvas,
                    h('button', { type: 'button', class: `${BOUTON_SECONDAIRE} mt-2`, onclick: () => etat.signature.effacer() }, 'Effacer la signature')),
                h('div', {}, h('label', { class: ETIQUETTE }, 'Photo du matériel rendu (facultative)'), entreePhoto, apercu),
                erreur,
                h('div', { class: 'flex flex-wrap justify-between gap-2' },
                    h('button', { type: 'button', class: BOUTON_SECONDAIRE, onclick: () => { code.value = ''; etat.etape = 'lignes'; dessiner(); } }, 'Retour'),
                    h('div', { class: 'flex flex-wrap justify-end gap-2' }, boutonRepli, bouton))));
    }

    /* Le bloc est chiffré une fois (code effacé aussitôt), puis les pièces et la déclaration partent dans la file. */
    async function valider({ code, repli, signataire }) {
        const cle = identifiantAleatoire();
        const horodatage_terminal = horodatage();
        const lignes = normaliserLignesRestitution(saisies.map((s) => ({ materiel_id: s.materiel_id, restitue: Number(s.restitue), observation: s.observation })));
        const signe = { user_id: signataire.user_id ?? null, nom: rogner(String(signataire.nom ?? '')), qualite: rogner(String(signataire.qualite ?? '')) };
        let bloc = null;
        if (!repli) {
            const cleServeur = await ctx.stockage.meta.lire('cle_preuve');
            if (!cleServeur?.pem) throw new Error('Clé de preuve non téléchargée : connectez la tablette au réseau puis synchronisez.');
            const clePublique = await importerCle(cleServeur.pem);
            const contenu = contenuPreuveRestitution({
                restitution_id: element.id, cle, signataire: signe, signataire_user_id: signe.user_id, lignes, horodatage_terminal,
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
            type: 'declaration_restitution',
            id: cle,
            cle,
            restitution_id: element.id,
            horodatage_terminal,
            bloc_preuve: bloc,
            ...(repli ? { repli: true } : {}),
            signataire: signe,
            lignes,
            pieces: pieces.map(({ empreinte, role }) => ({ empreinte, role })),
            utilisateur_id: utilisateurId,
            resume: { detenteur: element.detenteur?.nom ?? '', cycle: element.cycle ?? '' },
        });
        if (etat.photo) URL.revokeObjectURL(etat.photo.url);
        etat.signature.detruire();
        ctx.sync.cycle().then(() => ctx.rafraichirEntete()).catch(() => {});
        await ctx.rafraichirEntete();
    }

    function etapeEnregistree() {
        return h('div', { class: CARTE, id: 'restitution-enregistree' },
            h('div', { class: 'flex items-start justify-between gap-3' },
                h('h2', { class: 'font-semibold text-slate-800' }, etat.repli ? 'Restitution enregistrée — identité non vérifiée' : 'Restitution enregistrée — vérification d\'identité en attente'),
                h('div', { class: 'flex flex-col items-end gap-1' }, badge(STATUTS.EN_ATTENTE), etat.repli ? badgeIdentite() : null)),
            h('p', { class: 'text-sm text-slate-600' }, 'La déclaration est enregistrée sur la tablette et sera envoyée dès que le réseau le permet. Une fois retenue par le serveur, prenez le retour en charge depuis la liste des restitutions.'),
            h('div', { class: 'flex justify-end' }, lien('#/restitutions', 'Retour aux restitutions')));
    }

    function dessiner() {
        remplacer(racine, etat.etape === 'lignes' ? etapeLignes() : etat.etape === 'signature' ? etapeSignature() : etapeEnregistree());
    }
    dessiner();

    return racine;
}
