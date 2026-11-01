<?php

namespace Tests\Support;

use App\Models\Logistique\Cycle;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\PerimetreDestinataire;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\Logistique\PosteEtranger;
use App\Models\User;
use App\Services\Logistique\StockService;

/**
 * Décor des tests de décharges : un cycle ouvert, une préfecture (département 10), deux
 * sous-préfectures, un poste, un article actif pour chaque catégorie, et des plans de dotation
 * approuvés écrits directement, dans la forme exacte de PlanDotationService (paramètres complets
 * figés, instantané figé et périmètres correspondants).
 *
 * À utiliser dans une classe de test qui hérite de Tests\TestCase.
 */
trait PlanApprouveFictif
{
    protected User $responsable;

    protected Cycle $cycle;

    protected Destinataire $prefecture;

    protected Destinataire $sousPrefecture1;

    protected Destinataire $sousPrefecture2;

    protected Destinataire $poste;

    protected Materiel $urnes;

    protected Materiel $isoloirs;

    protected Materiel $enveloppes;

    protected Materiel $registres;

    protected function creerDecorFictif(): void
    {
        $this->responsable = User::factory()->create(['role' => 'logistique_responsable']);
        $this->actingAs($this->responsable);

        $this->cycle = Cycle::create(['libelle' => 'Cycle A', 'type' => 'scrutin']);

        $this->prefecture = Destinataire::create(['type' => 'prefecture', 'libelle' => 'Préfecture de Thiès', 'departement_id' => 10]);
        $this->sousPrefecture1 = Destinataire::create([
            'type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture de Sindia',
            'arrondissement_id' => 100, 'prefecture_id' => $this->prefecture->id,
        ]);
        $this->sousPrefecture2 = Destinataire::create([
            'type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture de Thiénaba',
            'arrondissement_id' => 101, 'prefecture_id' => $this->prefecture->id,
        ]);
        $posteEtranger = PosteEtranger::create(['pays' => 'France', 'ville' => 'Paris', 'type' => 'consulat', 'nom' => 'Consulat général de Paris']);
        $this->poste = Destinataire::create([
            'type' => 'poste', 'libelle' => 'Consulat général de Paris', 'poste_etranger_id' => $posteEtranger->id,
        ]);

        $this->urnes = Materiel::create(['libelle' => 'Urnes', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'ordre' => 1]);
        $this->isoloirs = Materiel::create(['libelle' => 'Isoloirs', 'categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'ordre' => 2]);
        $this->enveloppes = Materiel::create(['libelle' => 'Enveloppes', 'categorie' => 'imprime', 'unite_stock' => 'pièce', 'ordre' => 3]);
        $this->registres = Materiel::create(['libelle' => 'Registres de révision', 'categorie' => 'revision', 'unite_stock' => 'pièce', 'ordre' => 4]);
    }

    /**
     * Approuve une nouvelle version du plan du cycle ; la version précédente passe à « remplace ».
     *
     * @param  list<array{0: Destinataire, 1: Materiel, 2: int}>  $dotations  [destinataire, article, dotation approuvée]
     * @param  array<int, string>  $clesAffichees  libellé de clé par article ; « Clé {libellé} » par défaut
     */
    protected function approuverPlanFictif(array $dotations, array $clesAffichees = []): PlanDotation
    {
        PlanDotation::where('cycle_id', $this->cycle->id)->where('statut', 'approuve')->update(['statut' => 'remplace']);
        $version = (int) PlanDotation::where('cycle_id', $this->cycle->id)->max('version') + 1;

        $instantane = $this->figerInstantaneFictif($version);

        $parametres = [];
        foreach ([$this->urnes, $this->isoloirs, $this->enveloppes, $this->registres] as $article) {
            $parametres[$article->id] = [
                'base' => $article->categorie === 'materiel_lourd' ? 'bureau_vote' : 'electeur',
                'ratio' => '1', 'majoration' => '0.00', 'reserve' => '0.00', 'minimum' => 0,
                'minimum_par_commune' => false, 'arrondi' => 'unite', 'conditionnement' => null,
                'par_entite' => false, 'bulletin' => false,
                'cle_affichee' => $clesAffichees[$article->id] ?? 'Clé '.$article->libelle,
            ];
        }

        $plan = PlanDotation::create([
            'cycle_id' => $this->cycle->id, 'version' => $version, 'instantane_id' => $instantane->id,
            'parametres' => $parametres, 'calcule_par' => $this->responsable->id, 'anomalies' => [],
        ]);
        $plan->forceFill(['statut' => 'approuve', 'approuve_par' => $this->responsable->id, 'approuve_le' => now()])->save();

        foreach ($dotations as [$destinataire, $article, $dotation]) {
            PlanDotationLigne::create([
                'plan_dotation_id' => $plan->id, 'destinataire_id' => $destinataire->id, 'materiel_id' => $article->id,
                'effectif' => $dotation, 'exact' => (string) $dotation, 'majoration' => '0.00', 'minimum' => 0,
                'arrondi' => 'unite', 'dotation_calculee' => $dotation,
            ])->forceFill(['dotation_approuvee' => $dotation])->save();
        }

        return $plan;
    }

    /** Inventaire initial puis attribution au cycle (0 = pas d'attribution). */
    protected function stockerFictif(Materiel $article, int $inventaire, int $attribue = 0): void
    {
        $stock = app(StockService::class);
        $stock->inventaireInitial($article, $inventaire, 'Stock de test');
        if ($attribue > 0) {
            $stock->attribuer($this->cycle, $article, $attribue, 'Attribution de test');
        }
    }

    /**
     * Instantané figé : une localité par destinataire (commune fictive 1000 + id, Paris pour le
     * poste), avec les périmètres correspondants. Les périmètres sont créés une seule fois par cycle.
     */
    private function figerInstantaneFictif(int $version): InstantaneEffectifs
    {
        $instantane = InstantaneEffectifs::create(['cycle_id' => $this->cycle->id, 'version' => $version, 'user_id' => $this->responsable->id]);
        $instantane->forceFill([
            'statut' => 'fige', 'fige_par' => $this->responsable->id, 'fige_le' => now(),
            'bilan' => ['electeurs_non_rapproches' => 0],
        ])->save();

        foreach ([$this->prefecture, $this->sousPrefecture1, $this->sousPrefecture2, $this->poste] as $destinataire) {
            $etranger = $destinataire->type === 'poste';
            $localite = $etranger
                ? ['commune_id' => null, 'pays' => 'France', 'ville' => 'Paris']
                : ['commune_id' => 1000 + $destinataire->id, 'pays' => null, 'ville' => null];

            PerimetreDestinataire::firstOrCreate(
                ['cycle_id' => $this->cycle->id, 'destinataire_id' => $destinataire->id] + $localite,
            );
            InstantaneLigne::create($localite + [
                'instantane_id' => $instantane->id, 'departement_id' => $etranger ? null : 10,
                'libelle_commune' => $etranger ? null : $destinataire->libelle,
                'electeurs' => 1000 + $destinataire->id, 'bureaux_vote' => 4, 'lieux_vote' => 2, 'commissions' => 1,
                'rapprochee' => true, 'destinataire_id' => $destinataire->id,
            ]);
        }

        return $instantane;
    }
}
