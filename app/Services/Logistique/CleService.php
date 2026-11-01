<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CleRepartition;
use App\Models\Logistique\CommissionAdministrative;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\EntiteBulletin;
use App\Models\Logistique\Materiel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Clés de répartition par (cycle, article) et entités de bulletin, chacune avec son article et sa clé. */
class CleService
{
    public const LIBELLE_BULLETIN = 'Bulletin de vote — ';

    public const REGROUPEMENT_BULLETINS = 'Bulletins de vote';

    public const CLE_AFFICHEE_BULLETIN = "Nombre d'inscrits du B.V";

    private const CHAMPS = [
        'base', 'par_entite', 'ratio', 'cle_affichee', 'majoration_distribuee', 'reserve_centrale',
        'minimum', 'minimum_par_commune', 'arrondi', 'arrondi_conditionnement',
    ];

    private const DEFAUTS = [
        'base' => null, 'par_entite' => false, 'ratio' => null, 'cle_affichee' => null,
        'majoration_distribuee' => null, 'reserve_centrale' => null, 'minimum' => 0,
        'minimum_par_commune' => false, 'arrondi' => 'unite', 'arrondi_conditionnement' => false,
    ];

    /** Longueur maximale du nom d'une entité : le libellé de l'article (200) porte le préfixe. */
    private const NOM_MAX = 180;

    /**
     * Crée ou met à jour la clé d'un article pour un cycle ouvert. Les champs absents de $valeurs gardent
     * leur valeur actuelle (ou le défaut à la création) ; une majoration ou réserve à null revient à la valeur du cycle.
     *
     * @param  array<string,mixed>  $valeurs
     */
    public function enregistrer(Cycle $cycle, Materiel $materiel, array $valeurs): CleRepartition
    {
        CommissionAdministrative::exigerAuteur();

        $inconnus = array_diff(array_keys($valeurs), self::CHAMPS);
        if ($inconnus !== []) {
            throw new InvalidArgumentException('Champ de clé inconnu : '.implode(', ', $inconnus).'.');
        }

        return DB::transaction(function () use ($cycle, $materiel, $valeurs) {
            CommissionAdministrative::verrouillerCycleOuvert($cycle);

            $materiel = Materiel::whereKey($materiel->id)->lockForUpdate()->firstOrFail();
            $cycleBulletin = EntiteBulletin::where('materiel_id', $materiel->id)->value('cycle_id');
            if ($cycleBulletin !== null && (int) $cycleBulletin !== (int) $cycle->id) {
                throw new RegleLogistique('Ce bulletin de vote appartient à un autre cycle.');
            }
            $existante = CleRepartition::where('cycle_id', $cycle->id)
                ->where('materiel_id', $materiel->id)->lockForUpdate()->first();

            $avant = $existante?->only(self::CHAMPS);
            $retenues = $this->valider(
                array_replace(self::DEFAUTS, $avant ?? [], $valeurs),
                $materiel,
            );

            if ($existante === null) {
                $cle = CleRepartition::create(['cycle_id' => $cycle->id, 'materiel_id' => $materiel->id] + $retenues);
                Journal::noter('cle_repartition', $cle->id, 'creation_cle', null, $cle->only(self::CHAMPS) + ['materiel_id' => $materiel->id]);

                return $cle;
            }

            $existante->update($retenues);
            Journal::noter('cle_repartition', $existante->id, 'modification_cle', $avant, $existante->only(self::CHAMPS));

            return $existante;
        }, attempts: 3);
    }

    /** Copie les clés des articles hors bulletins que la cible n'a pas encore. Retourne le nombre de clés copiées. */
    public function copier(Cycle $source, Cycle $cible): int
    {
        CommissionAdministrative::exigerAuteur();

        if ($source->id === $cible->id) {
            throw new InvalidArgumentException('Le cycle source et le cycle cible doivent être différents.');
        }

        return DB::transaction(function () use ($source, $cible) {
            CommissionAdministrative::verrouillerCycleOuvert($cible);

            $presents = CleRepartition::where('cycle_id', $cible->id)->pluck('materiel_id')->flip();

            $copiees = 0;
            $cles = CleRepartition::where('cycle_id', $source->id)
                ->whereDoesntHave('materiel.entiteBulletin')
                ->orderBy('materiel_id')->get();

            foreach ($cles as $cle) {
                if ($presents->has($cle->materiel_id)) {
                    continue;
                }
                CleRepartition::create(['cycle_id' => $cible->id, 'materiel_id' => $cle->materiel_id] + $cle->only(self::CHAMPS));
                $copiees++;
            }

            if ($copiees > 0) {
                Journal::noter('cycle', $cible->id, 'copie_cles', null, ['source_cycle_id' => $source->id, 'cles' => $copiees]);
            }

            return $copiees;
        }, attempts: 3);
    }

    /**
     * Ajoute un candidat, une liste ou une option : son article bulletin, son périmètre et sa clé par électeur.
     *
     * @param  array{type?:string, nom?:string, ordre?:int, national?:bool, perimetre?:array{departements?:array<int,int>, communes?:array<int,int>, pays?:array<int,string>}}  $valeurs
     */
    public function ajouterEntite(Cycle $cycle, array $valeurs): EntiteBulletin
    {
        CommissionAdministrative::exigerAuteur();

        $type = $valeurs['type'] ?? null;
        if (! in_array($type, EntiteBulletin::TYPES, true)) {
            throw new InvalidArgumentException('Type d\'entité inconnu (candidat, liste ou option).');
        }
        $nom = $this->nomValide($valeurs['nom'] ?? null);
        $ordre = $valeurs['ordre'] ?? 0;
        if (! is_int($ordre) || $ordre < 0 || $ordre > 65535) {
            throw new InvalidArgumentException('L\'ordre est un entier entre 0 et 65535.');
        }
        $national = $valeurs['national'] ?? true;
        if (! is_bool($national)) {
            throw new InvalidArgumentException('Le caractère national est un booléen.');
        }
        $lignes = $this->lignesPerimetre($valeurs['perimetre'] ?? [], $national);

        return DB::transaction(function () use ($cycle, $type, $nom, $ordre, $national, $lignes) {
            CommissionAdministrative::verrouillerCycleOuvert($cycle);
            $this->exigerNomLibre($cycle, $nom, null);

            $materiel = Materiel::create([
                'libelle'      => self::LIBELLE_BULLETIN.$nom,
                'categorie'    => 'imprime',
                'regroupement' => self::REGROUPEMENT_BULLETINS,
                'ordre'        => $ordre,
                'indivisible'  => true,
                'reutilisable' => false,
            ]);

            $entite = EntiteBulletin::create([
                'cycle_id' => $cycle->id, 'type' => $type, 'nom' => $nom, 'ordre' => $ordre,
                'national' => $national, 'materiel_id' => $materiel->id,
            ]);
            foreach ($lignes as $ligne) {
                $entite->perimetres()->create($ligne);
            }

            $this->enregistrer($cycle, $materiel, [
                'base' => 'electeur', 'par_entite' => false, 'ratio' => '1',
                'cle_affichee' => self::CLE_AFFICHEE_BULLETIN,
            ]);

            Journal::noter('entite_bulletin', $entite->id, 'creation_entite', null, [
                'cycle_id' => $cycle->id, 'type' => $type, 'nom' => $nom, 'ordre' => $ordre,
                'national' => $national, 'materiel_id' => $materiel->id, 'perimetre' => $lignes,
            ]);

            return $entite->load('materiel', 'perimetres');
        }, attempts: 3);
    }

    /** Renomme l'entité et le libellé de son article bulletin. */
    public function renommerEntite(EntiteBulletin $entite, string $nom): void
    {
        CommissionAdministrative::exigerAuteur();
        $nom = $this->nomValide($nom);

        DB::transaction(function () use ($entite, $nom) {
            CommissionAdministrative::verrouillerCycleOuvert($entite->cycle()->firstOrFail());

            $actuelle = EntiteBulletin::whereKey($entite->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->nom === $nom) {
                return;
            }
            $this->exigerNomLibre($actuelle->cycle()->firstOrFail(), $nom, $actuelle->id);

            $ancien = $actuelle->nom;
            $actuelle->update(['nom' => $nom]);
            Materiel::whereKey($actuelle->materiel_id)->update(['libelle' => self::LIBELLE_BULLETIN.$nom]);

            Journal::noter('entite_bulletin', $actuelle->id, 'renommage_entite', ['nom' => $ancien], ['nom' => $nom]);
        }, attempts: 3);

        $entite->refresh();
    }

    /**
     * @param  array<string,mixed>  $v
     * @return array<string,mixed>
     */
    private function valider(array $v, Materiel $materiel): array
    {
        if (! in_array($v['base'], CleRepartition::BASES, true)) {
            throw new InvalidArgumentException('Base de répartition inconnue.');
        }
        if (! in_array($v['arrondi'], CleRepartition::ARRONDIS, true)) {
            throw new InvalidArgumentException('Arrondi inconnu.');
        }

        $v['par_entite'] = $this->booleen($v['par_entite'], 'Par entité');
        $v['minimum_par_commune'] = $this->booleen($v['minimum_par_commune'], 'Minimum par commune');
        $v['arrondi_conditionnement'] = $this->booleen($v['arrondi_conditionnement'], 'Arrondi au conditionnement');

        $v['ratio'] = $this->decimal($v['ratio'], 4, 'Le ratio');
        if (bccomp($v['ratio'], '0', 4) <= 0 || bccomp($v['ratio'], '100000', 4) > 0) {
            throw new InvalidArgumentException('Le ratio est supérieur à 0 et au plus 100 000.');
        }

        foreach (['majoration_distribuee' => 'La majoration distribuée', 'reserve_centrale' => 'La réserve centrale'] as $champ => $nom) {
            if ($v[$champ] === null) {
                continue;
            }
            $v[$champ] = $this->decimal($v[$champ], 2, $nom);
            if (bccomp($v[$champ], '100', 2) > 0) {
                throw new InvalidArgumentException("{$nom} est comprise entre 0 et 100 %.");
            }
        }

        if (! is_int($v['minimum']) && ! (is_string($v['minimum']) && ctype_digit($v['minimum']))) {
            throw new InvalidArgumentException('Le minimum est un entier positif ou nul.');
        }
        $v['minimum'] = (int) $v['minimum'];
        if ($v['minimum'] < 0 || $v['minimum'] > 4294967295) {
            throw new InvalidArgumentException('Le minimum est un entier positif ou nul.');
        }

        $v['cle_affichee'] = $v['cle_affichee'] === null ? null : trim((string) $v['cle_affichee']);
        if ($v['cle_affichee'] === '') {
            $v['cle_affichee'] = null;
        }
        if ($v['cle_affichee'] !== null && mb_strlen($v['cle_affichee']) > 150) {
            throw new InvalidArgumentException('La clé affichée ne dépasse pas 150 caractères.');
        }

        if ($v['minimum_par_commune'] && in_array($v['base'], ['circonscription', 'forfait'], true)) {
            throw new InvalidArgumentException('Le minimum par commune est impossible avec la base circonscription ou forfait.');
        }
        if ($v['arrondi_conditionnement'] && (int) $materiel->facteur_conditionnement <= 0) {
            throw new InvalidArgumentException('L\'arrondi au conditionnement exige un facteur de conditionnement sur l\'article.');
        }
        if ($materiel->entiteBulletin()->exists() && ($v['base'] !== 'electeur' || $v['par_entite'])) {
            throw new InvalidArgumentException('La clé d\'un bulletin de vote se calcule par électeur, sans répartition par entité.');
        }

        return array_intersect_key($v, array_flip(self::CHAMPS));
    }

    /** Chaîne décimale stricte (chiffres, point, au plus $echelle décimales) ; jamais de conversion en flottant. */
    private function decimal(mixed $valeur, int $echelle, string $nom): string
    {
        if (is_int($valeur) || is_string($valeur)) {
            $texte = trim((string) $valeur);
            if (preg_match('/^\d+(\.\d{1,'.$echelle.'})?$/', $texte) === 1) {
                return $texte;
            }
        }

        throw new InvalidArgumentException("{$nom} est un nombre décimal positif d'au plus {$echelle} décimales.");
    }

    private function booleen(mixed $valeur, string $nom): bool
    {
        $b = filter_var($valeur, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($b === null || $valeur === null) {
            throw new InvalidArgumentException("« {$nom} » est un booléen.");
        }

        return $b;
    }

    private function nomValide(mixed $nom): string
    {
        $nom = is_string($nom) ? trim($nom) : '';
        if ($nom === '') {
            throw new InvalidArgumentException('Le nom de l\'entité est obligatoire.');
        }
        if (mb_strlen($nom) > self::NOM_MAX) {
            throw new InvalidArgumentException('Le nom de l\'entité ne dépasse pas '.self::NOM_MAX.' caractères.');
        }

        return $nom;
    }

    private function exigerNomLibre(Cycle $cycle, string $nom, ?int $sauf): void
    {
        $requete = EntiteBulletin::where('cycle_id', $cycle->id)->where('nom', $nom);
        if ($sauf !== null) {
            $requete->where('id', '!=', $sauf);
        }
        if ($requete->exists()) {
            throw new InvalidArgumentException("Une entité nommée « {$nom} » existe déjà dans ce cycle.");
        }
    }

    /**
     * @param  array<string,mixed>  $perimetre
     * @return list<array{departement_id:?int, commune_id:?int, pays:?string}>
     */
    private function lignesPerimetre(array $perimetre, bool $national): array
    {
        $departements = array_values(array_unique($perimetre['departements'] ?? []));
        $communes = array_values(array_unique($perimetre['communes'] ?? []));
        $pays = array_values(array_unique(array_map(
            fn ($p) => is_string($p) ? trim($p) : '',
            $perimetre['pays'] ?? [],
        )));

        foreach ([...$departements, ...$communes] as $id) {
            if (! is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Les départements et communes du périmètre sont des identifiants entiers positifs.');
            }
        }
        foreach ($pays as $p) {
            if ($p === '' || mb_strlen($p) > 100) {
                throw new InvalidArgumentException('Chaque pays du périmètre compte de 1 à 100 caractères.');
            }
        }

        $total = count($departements) + count($communes) + count($pays);
        if ($national && $total > 0) {
            throw new InvalidArgumentException('Une entité nationale n\'a pas de périmètre.');
        }
        if (! $national && $total === 0) {
            throw new InvalidArgumentException('Une entité non nationale exige au moins un département, une commune ou un pays.');
        }

        return [
            ...array_map(fn ($id) => ['departement_id' => $id, 'commune_id' => null, 'pays' => null], $departements),
            ...array_map(fn ($id) => ['departement_id' => null, 'commune_id' => $id, 'pays' => null], $communes),
            ...array_map(fn ($p) => ['departement_id' => null, 'commune_id' => null, 'pays' => $p], $pays),
        ];
    }
}
