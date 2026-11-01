<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\PerimetreDestinataire;
use App\Models\Logistique\PosteEtranger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Destinataires du matériel (préfectures, sous-préfectures, postes à l'étranger) et découpage
 * par cycle : à quel destinataire revient chaque commune ou ville étrangère.
 */
class DecoupageService
{
    public function __construct(private readonly LectureRecensement $recensement)
    {
    }

    /** @return array{crees:int} */
    public function genererDestinataires(): array
    {
        $this->exigerAuteur();

        $departements = $this->recensement->departements();
        $arrondissements = $this->recensement->arrondissements();

        return DB::transaction(function () use ($departements, $arrondissements) {
            $crees = 0;
            $prefectures = [];

            foreach ($departements as $departement) {
                $prefecture = Destinataire::firstOrCreate(
                    ['departement_id' => $departement->id],
                    ['type' => 'prefecture', 'libelle' => "Préfecture de {$departement->nom}"],
                );
                $crees += $prefecture->wasRecentlyCreated ? 1 : 0;
                $prefectures[$departement->id] = $prefecture->id;
            }

            foreach ($arrondissements as $arrondissement) {
                $sousPrefecture = Destinataire::firstOrCreate(
                    ['arrondissement_id' => $arrondissement->id],
                    [
                        'type'          => 'sous_prefecture',
                        'libelle'       => "Sous-préfecture de {$arrondissement->nom}",
                        'prefecture_id' => $prefectures[$arrondissement->departement_id] ?? null,
                    ],
                );
                $crees += $sousPrefecture->wasRecentlyCreated ? 1 : 0;
            }

            foreach (PosteEtranger::actifs()->orderBy('id')->get() as $poste) {
                $destinataire = Destinataire::firstOrCreate(
                    ['poste_etranger_id' => $poste->id],
                    ['type' => 'poste', 'libelle' => $poste->nom],
                );
                $crees += $destinataire->wasRecentlyCreated ? 1 : 0;
            }

            return ['crees' => $crees];
        }, attempts: 3);
    }

    /**
     * N'écrase jamais une affectation existante : seules les localités absentes du cycle sont créées.
     *
     * @return array{affectees:int, sans_destinataire:int}
     */
    public function genererPerimetre(Cycle $cycle): array
    {
        $this->exigerAuteur();

        $communes = $this->recensement->communes();
        $villes = $this->recensement->villesEtranger();

        return DB::transaction(function () use ($cycle, $communes, $villes) {
            $this->verrouillerCycleOuvert($cycle);

            $parArrondissement = Destinataire::where('type', 'sous_prefecture')->pluck('id', 'arrondissement_id');
            $communesAffectees = PerimetreDestinataire::where('cycle_id', $cycle->id)
                ->whereNotNull('commune_id')->pluck('commune_id')->flip();
            $villesAffectees = PerimetreDestinataire::where('cycle_id', $cycle->id)
                ->whereNull('commune_id')->get()
                ->mapWithKeys(fn ($p) => [$p->localite() => true]);

            $affectees = 0;
            $sans = 0;

            foreach ($communes as $commune) {
                if ($communesAffectees->has($commune->id)) {
                    continue;
                }
                $destinataireId = $parArrondissement[$commune->arrondissement_id] ?? null;
                if ($destinataireId === null) {
                    $sans++;

                    continue;
                }
                PerimetreDestinataire::create([
                    'cycle_id' => $cycle->id, 'destinataire_id' => $destinataireId, 'commune_id' => $commune->id,
                ]);
                $affectees++;
            }

            foreach ($villes as $localite) {
                if ($villesAffectees->has("{$localite->pays}|{$localite->ville}")) {
                    continue;
                }
                $poste = PosteEtranger::posteParDefaut($localite->pays, $localite->ville);
                $destinataireId = $poste
                    ? Destinataire::where('poste_etranger_id', $poste->id)->value('id')
                    : null;
                if ($destinataireId === null) {
                    $sans++;

                    continue;
                }
                PerimetreDestinataire::create([
                    'cycle_id' => $cycle->id, 'destinataire_id' => $destinataireId,
                    'pays' => $localite->pays, 'ville' => $localite->ville,
                ]);
                $affectees++;
            }

            return ['affectees' => $affectees, 'sans_destinataire' => $sans];
        }, attempts: 3);
    }

    /** Crée ou réaffecte la localité (commune, ou pays + ville) du cycle au destinataire. */
    public function affecter(
        Cycle $cycle,
        Destinataire $destinataire,
        ?int $communeId,
        ?string $pays,
        ?string $ville,
        string $motif,
    ): PerimetreDestinataire {
        $this->exigerAuteur();

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Le motif est obligatoire.');
        }
        $pays = $pays === null ? null : trim($pays);
        $ville = $ville === null ? null : trim($ville);

        if ($communeId !== null) {
            if (($pays !== null && $pays !== '') || ($ville !== null && $ville !== '')) {
                throw new InvalidArgumentException('Une localité est soit une commune, soit un pays et une ville.');
            }
            if (! $this->recensement->communes()->contains('id', $communeId)) {
                throw new InvalidArgumentException("Commune inconnue : {$communeId}.");
            }
        } else {
            if ($pays === null || $pays === '' || $ville === null || $ville === '') {
                throw new InvalidArgumentException('Indiquer une commune, ou un pays et une ville.');
            }
            if (mb_strlen($pays) > 100 || mb_strlen($ville) > 100) {
                throw new InvalidArgumentException('Le pays et la ville ne dépassent pas 100 caractères.');
            }
        }

        return DB::transaction(function () use ($cycle, $destinataire, $communeId, $pays, $ville, $motif) {
            $this->verrouillerCycleOuvert($cycle);

            $cible = Destinataire::whereKey($destinataire->id)->sharedLock()->first();
            if ($cible === null || ! $cible->actif) {
                throw new RegleLogistique('Destinataire inactif : affectation impossible.');
            }

            $requete = PerimetreDestinataire::where('cycle_id', $cycle->id);
            if ($communeId !== null) {
                $requete->where('commune_id', $communeId);
            } else {
                $requete->whereNull('commune_id')->where('pays', $pays)->where('ville', $ville);
            }
            $perimetre = $requete->lockForUpdate()->first();

            if ($perimetre === null) {
                $perimetre = PerimetreDestinataire::create([
                    'cycle_id' => $cycle->id, 'destinataire_id' => $cible->id,
                    'commune_id' => $communeId, 'pays' => $pays, 'ville' => $ville,
                ]);
                Journal::noter('perimetre_destinataire', $perimetre->id, 'affectation', null, [
                    'cycle_id' => $cycle->id, 'localite' => $perimetre->localite(), 'destinataire_id' => $cible->id,
                ], $motif);

                return $perimetre;
            }

            $avant = ['destinataire_id' => $perimetre->destinataire_id];
            if ($perimetre->destinataire_id !== $cible->id) {
                $perimetre->update(['destinataire_id' => $cible->id]);
            }
            Journal::noter('perimetre_destinataire', $perimetre->id, 'affectation', $avant, [
                'cycle_id' => $cycle->id, 'localite' => $perimetre->localite(), 'destinataire_id' => $cible->id,
            ], $motif);

            return $perimetre;
        }, attempts: 3);
    }

    /**
     * Retire une localité du périmètre du cycle (commune sans électeur, par exemple) : le plan de dotation
     * ne la réclame plus. Réservé au responsable ; le motif et l'ancienne affectation sont journalisés.
     */
    public function retirer(Cycle $cycle, ?int $communeId, ?string $pays, ?string $ville, string $motif): void
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut retirer une localité du périmètre.');
        }

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Le motif est obligatoire.');
        }
        $pays = $pays === null ? null : trim($pays);
        $ville = $ville === null ? null : trim($ville);
        $etranger = ($pays !== null && $pays !== '') || ($ville !== null && $ville !== '');
        if ($communeId !== null && $etranger) {
            throw new InvalidArgumentException('Une localité est soit une commune, soit un pays et une ville.');
        }
        if ($communeId === null && ($pays === null || $pays === '' || $ville === null || $ville === '')) {
            throw new InvalidArgumentException('Indiquer une commune, ou un pays et une ville.');
        }

        DB::transaction(function () use ($cycle, $communeId, $pays, $ville, $motif) {
            $this->verrouillerCycleOuvert($cycle);

            $requete = PerimetreDestinataire::where('cycle_id', $cycle->id);
            if ($communeId !== null) {
                $requete->where('commune_id', $communeId);
            } else {
                $requete->whereNull('commune_id')->where('pays', $pays)->where('ville', $ville);
            }
            $perimetre = $requete->lockForUpdate()->first();
            if ($perimetre === null) {
                throw new RegleLogistique('Cette localité ne figure pas au périmètre du cycle.');
            }

            $perimetre->delete();
            Journal::noter('perimetre_destinataire', $perimetre->id, 'retrait_perimetre', [
                'cycle_id' => $cycle->id, 'localite' => $perimetre->localite(), 'destinataire_id' => $perimetre->destinataire_id,
            ], null, $motif);
        }, attempts: 3);
    }

    /**
     * Reprend le découpage d'un cycle (éventuellement clos) dans un cycle ouvert, sans toucher
     * aux localités déjà affectées dans la cible.
     */
    public function copierPerimetre(Cycle $source, Cycle $cible): int
    {
        $this->exigerAuteur();

        if ($source->id === $cible->id) {
            throw new InvalidArgumentException('Le cycle source et le cycle cible doivent différer.');
        }

        return DB::transaction(function () use ($source, $cible) {
            $this->verrouillerCycleOuvert($cible);

            $existantes = PerimetreDestinataire::where('cycle_id', $cible->id)->get()
                ->mapWithKeys(fn ($p) => [$p->localite() => true]);

            $copiees = 0;
            foreach (PerimetreDestinataire::where('cycle_id', $source->id)->orderBy('id')->get() as $ligne) {
                if ($existantes->has($ligne->localite())) {
                    continue;
                }
                PerimetreDestinataire::create([
                    'cycle_id' => $cible->id, 'destinataire_id' => $ligne->destinataire_id,
                    'commune_id' => $ligne->commune_id, 'pays' => $ligne->pays, 'ville' => $ligne->ville,
                ]);
                $copiees++;
            }

            if ($copiees > 0) {
                Journal::noter('cycle', $cible->id, 'copie_decoupage', null, [
                    'source_cycle_id' => $source->id, 'localites' => $copiees,
                ], null);
            }

            return $copiees;
        }, attempts: 3);
    }

    /**
     * Crée un poste à l'étranger (consulat ou ambassade). Son destinataire est créé par
     * genererDestinataires.
     *
     * @param  array{pays?:mixed, ville?:mixed, type?:mixed, nom?:mixed, actif?:mixed}  $donnees
     */
    public function creerPoste(array $donnees): PosteEtranger
    {
        $this->exigerAuteur();
        $this->exigerResponsable('Seul un responsable logistique peut gérer les postes à l\'étranger.');
        $retenues = $this->validerPoste($donnees + ['actif' => true]);

        return DB::transaction(function () use ($retenues) {
            $this->exigerPosteLibre($retenues['pays'], $retenues['nom'], null);

            $poste = PosteEtranger::create($retenues);
            Journal::noter('poste_etranger', $poste->id, 'creation_poste', null, $poste->only($poste->getFillable()));

            return $poste;
        }, attempts: 3);
    }

    /** @param  array{pays?:mixed, ville?:mixed, type?:mixed, nom?:mixed, actif?:mixed}  $donnees */
    public function modifierPoste(PosteEtranger $poste, array $donnees): void
    {
        $this->exigerAuteur();
        $this->exigerResponsable('Seul un responsable logistique peut gérer les postes à l\'étranger.');
        $retenues = $this->validerPoste($donnees + ['actif' => $poste->actif]);

        DB::transaction(function () use ($poste, $retenues) {
            $actuel = PosteEtranger::whereKey($poste->id)->lockForUpdate()->firstOrFail();
            $this->exigerPosteLibre($retenues['pays'], $retenues['nom'], $actuel->id);

            $avant = $actuel->only($actuel->getFillable());
            $actuel->update($retenues);
            Journal::noter('poste_etranger', $actuel->id, 'modification_poste', $avant, $actuel->only($actuel->getFillable()));
        }, attempts: 3);

        $poste->refresh();
    }

    /**
     * Met à jour le signataire (nom, qualité) et l'état d'un destinataire ; les champs absents restent inchangés.
     *
     * @param  array{signataire_nom?:mixed, signataire_qualite?:mixed, actif?:mixed}  $donnees
     */
    public function modifierDestinataire(Destinataire $destinataire, array $donnees): void
    {
        $this->exigerAuteur();
        $this->exigerResponsable('Seul un responsable logistique peut modifier un destinataire.');

        $retenues = [];
        foreach (['signataire_nom', 'signataire_qualite'] as $champ) {
            if (! array_key_exists($champ, $donnees)) {
                continue;
            }
            $valeur = $donnees[$champ] === null ? null : trim((string) $donnees[$champ]);
            if ($valeur !== null && mb_strlen($valeur) > 150) {
                throw new InvalidArgumentException('Le signataire ne dépasse pas 150 caractères.');
            }
            $retenues[$champ] = $valeur === '' ? null : $valeur;
        }
        if (array_key_exists('actif', $donnees)) {
            if (! is_bool($donnees['actif'])) {
                throw new InvalidArgumentException('L\'état actif est un booléen.');
            }
            $retenues['actif'] = $donnees['actif'];
        }

        DB::transaction(function () use ($destinataire, $retenues) {
            $actuel = Destinataire::whereKey($destinataire->id)->lockForUpdate()->firstOrFail();
            $avant = $actuel->only(array_keys($retenues));
            $actuel->update($retenues);

            if ($avant !== $actuel->only(array_keys($retenues))) {
                Journal::noter('destinataire', $actuel->id, 'modification_destinataire', $avant, $actuel->only(array_keys($retenues)));
            }
        }, attempts: 3);

        $destinataire->refresh();
    }

    /**
     * @param  array<string,mixed>  $d
     * @return array{pays:string, ville:string, type:string, nom:string, actif:bool}
     */
    private function validerPoste(array $d): array
    {
        $pays = trim((string) ($d['pays'] ?? ''));
        $ville = trim((string) ($d['ville'] ?? ''));
        $nom = trim((string) ($d['nom'] ?? ''));
        if ($pays === '' || $ville === '' || mb_strlen($pays) > 100 || mb_strlen($ville) > 100) {
            throw new InvalidArgumentException('Le pays et la ville comptent de 1 à 100 caractères.');
        }
        if ($nom === '' || mb_strlen($nom) > 200) {
            throw new InvalidArgumentException('Le nom du poste compte de 1 à 200 caractères.');
        }
        if (! in_array($d['type'] ?? null, ['consulat', 'ambassade'], true)) {
            throw new InvalidArgumentException('Type de poste inconnu (consulat ou ambassade).');
        }
        if (! is_bool($d['actif'])) {
            throw new InvalidArgumentException('L\'état actif est un booléen.');
        }

        return ['pays' => $pays, 'ville' => $ville, 'type' => $d['type'], 'nom' => $nom, 'actif' => $d['actif']];
    }

    private function exigerPosteLibre(string $pays, string $nom, ?int $sauf): void
    {
        $requete = PosteEtranger::where('pays', $pays)->where('nom', $nom);
        if ($sauf !== null) {
            $requete->where('id', '!=', $sauf);
        }
        if ($requete->exists()) {
            throw new InvalidArgumentException("Un poste « {$nom} » existe déjà pour ce pays.");
        }
    }

    private function exigerResponsable(string $message): void
    {
        if (! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique($message);
        }
    }

    private function verrouillerCycleOuvert(Cycle $cycle): void
    {
        $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
        if ($actuel === null || ! $actuel->estOuvert()) {
            throw new RegleLogistique('Cycle clôturé : découpage non modifiable.');
        }
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}
