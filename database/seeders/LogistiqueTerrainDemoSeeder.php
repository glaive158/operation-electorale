<?php

namespace Database\Seeders;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\InstantaneEffectifs;
use App\Models\Logistique\InstantaneLigne;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\MouvementStock;
use App\Models\Logistique\PerimetreDestinataire;
use App\Models\Logistique\PlanDotation;
use App\Models\Logistique\PlanDotationLigne;
use App\Models\Logistique\Remise;
use App\Models\User;
use App\Services\Logistique\CodeReceptionService;
use App\Services\Logistique\DechargeService;
use App\Services\Logistique\RemiseService;
use App\Services\Logistique\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Scénario de démonstration de l'application de terrain, sur la base courante : un cycle ouvert, une
 * décharge validée dont la livraison est remise à un agent de transport et attend la réception d'un
 * sous-préfet qui a déjà choisi son code. À lancer à la main (jamais appelé par DatabaseSeeder) :
 *
 *   php artisan db:seed --class=LogistiqueTerrainDemoSeeder
 *
 * Idempotent : les comptes, articles et destinataires sont retrouvés par leur identifiant ; tant qu'une livraison
 * de démonstration attend, rien de plus n'est créé ; sinon un nouveau cycle de démonstration est ouvert.
 * Les mots de passe et le code de réception sont tirés au hasard à chaque exécution et affichés dans la console
 * seulement : ils ne sont écrits nulle part.
 */
class LogistiqueTerrainDemoSeeder extends Seeder
{
    private const COURRIEL_RESPONSABLE = 'demo.responsable@terrain.test';

    private const COURRIEL_AGENT = 'demo.agent@terrain.test';

    private const COURRIEL_AUTORITE = 'demo.souspref@terrain.test';

    private const LIBELLE_CYCLE = 'Démonstration terrain';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Scénario de démonstration refusé en production.');

            return;
        }

        $secrets = ['responsable' => $this->secret(), 'agent' => $this->secret(), 'autorite' => $this->secret()];
        $responsable = $this->compte(self::COURRIEL_RESPONSABLE, 'logistique_responsable', 'DEMO', 'Responsable', $secrets['responsable']);
        $agent = $this->compte(self::COURRIEL_AGENT, 'agent_transport', 'DEMO', 'Agent', $secrets['agent']);
        $autorite = $this->compte(self::COURRIEL_AUTORITE, 'sous_prefet', 'DEMO', 'Sous-préfet', $secrets['autorite']);

        Auth::setUser($responsable);

        $prefecture = $this->destinataire(['type' => 'prefecture', 'libelle' => 'Préfecture de démonstration'], ['departement_id' => 9901]);
        $sousPrefecture = $this->destinataire(
            ['type' => 'sous_prefecture', 'libelle' => 'Sous-préfecture de démonstration'],
            ['arrondissement_id' => 9901, 'prefecture_id' => $prefecture->id],
        );
        $sousPrefecture->forceFill([
            'autorite_user_id' => $autorite->id, 'signataire_nom' => $autorite->nom_complet, 'signataire_qualite' => 'Sous-préfet',
        ])->save();

        $urnes = $this->materiel('Urnes (démonstration terrain)', 9001);
        $isoloirs = $this->materiel('Isoloirs (démonstration terrain)', 9002);

        $decharge = $this->livraisonEnAttente($agent) ?? $this->nouvelleLivraison($responsable, $agent, $prefecture, $sousPrefecture, $urnes, $isoloirs);

        $code = $this->choisirCode($responsable, $autorite, $secrets['autorite']);
        Auth::forgetUser();

        if (! is_file((string) config('logistique.cle_preuve_privee'))) {
            Artisan::call('logistique:cle-preuve');
            $this->command?->info('Clé de preuve créée : '.config('logistique.cle_preuve_privee'));
        }

        $this->afficher($decharge, $secrets, $code);
    }

    private function secret(): string
    {
        return Str::password(16, symbols: false);
    }

    private function compte(string $courriel, string $role, string $nom, string $prenom, string $motDePasse): User
    {
        return User::updateOrCreate(['email' => $courriel], [
            'name' => $nom, 'prenom' => $prenom, 'role' => $role, 'actif' => true, 'password' => $motDePasse,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $cle
     * @param  array<string, mixed>  $autres
     */
    private function destinataire(array $cle, array $autres): Destinataire
    {
        return Destinataire::firstOrCreate($cle, $autres + ['actif' => true]);
    }

    private function materiel(string $libelle, int $ordre): Materiel
    {
        return Materiel::firstOrCreate(['libelle' => $libelle], ['categorie' => 'materiel_lourd', 'unite_stock' => 'pièce', 'ordre' => $ordre]);
    }

    /** La décharge de démonstration dont la livraison attend encore une réception, si elle existe. */
    private function livraisonEnAttente(User $agent): ?Decharge
    {
        $remise = Remise::query()->where('etape', 'livraison')->whereIn('statut', ['en_attente', 'a_verifier'])
            ->whereIn('decharge_id', Decharge::where('agent_id', $agent->id)->where('statut_document', 'validee')
                ->whereIn('cycle_id', Cycle::ouverts()->where('libelle', 'like', self::LIBELLE_CYCLE.'%')->pluck('id'))->pluck('id'))
            ->orderByDesc('id')->first();

        return $remise === null ? null : Decharge::find($remise->decharge_id);
    }

    private function nouvelleLivraison(User $responsable, User $agent, Destinataire $prefecture, Destinataire $sousPrefecture, Materiel $urnes, Materiel $isoloirs): Decharge
    {
        $numero = Cycle::where('libelle', 'like', self::LIBELLE_CYCLE.'%')->count() + 1;
        $cycle = Cycle::create([
            'libelle' => $numero === 1 ? self::LIBELLE_CYCLE : self::LIBELLE_CYCLE.' '.$numero,
            'type' => 'scrutin', 'circuit_defaut' => 'direct',
        ]);

        $this->approuverPlan($cycle, $responsable, [$prefecture, $sousPrefecture], [[$sousPrefecture, $urnes, 20], [$sousPrefecture, $isoloirs, 10]], [$urnes, $isoloirs]);

        $stock = app(StockService::class);
        foreach ([$urnes, $isoloirs] as $article) {
            if (! MouvementStock::where('materiel_id', $article->id)->exists()) {
                $stock->inventaireInitial($article, 500, 'Stock de démonstration terrain');
            }
            $stock->attribuer($cycle, $article, 100, 'Attribution de démonstration terrain');
        }

        $service = app(DechargeService::class);
        $decharge = $service->preparer($cycle, $sousPrefecture, 'materiel_lourd');
        foreach ($decharge->lignes as $ligne) {
            $service->modifierLigne($ligne, $ligne->materiel_id === $urnes->id ? 20 : 10, null);
        }
        $service->valider($decharge);
        $service->affecterAgent($decharge, $agent, 'Agent de démonstration');
        app(RemiseService::class)->charger($decharge);

        return $decharge->refresh();
    }

    /**
     * Plan approuvé écrit directement, dans la forme de PlanDotationService, sans lecture du recensement :
     * une localité fictive par destinataire.
     *
     * @param  list<Destinataire>  $destinataires
     * @param  list<array{0: Destinataire, 1: Materiel, 2: int}>  $dotations
     * @param  list<Materiel>  $articles
     */
    private function approuverPlan(Cycle $cycle, User $responsable, array $destinataires, array $dotations, array $articles): void
    {
        $instantane = InstantaneEffectifs::create(['cycle_id' => $cycle->id, 'version' => 1, 'user_id' => $responsable->id]);
        $instantane->forceFill([
            'statut' => 'fige', 'fige_par' => $responsable->id, 'fige_le' => now(), 'bilan' => ['electeurs_non_rapproches' => 0],
        ])->save();
        foreach ($destinataires as $destinataire) {
            $localite = ['commune_id' => 9000 + $destinataire->id, 'pays' => null, 'ville' => null];
            PerimetreDestinataire::firstOrCreate(['cycle_id' => $cycle->id, 'destinataire_id' => $destinataire->id] + $localite);
            InstantaneLigne::create($localite + [
                'instantane_id' => $instantane->id, 'departement_id' => 9901, 'libelle_commune' => $destinataire->libelle,
                'electeurs' => 1000, 'bureaux_vote' => 4, 'lieux_vote' => 2, 'commissions' => 1, 'rapprochee' => true,
                'destinataire_id' => $destinataire->id,
            ]);
        }

        $parametres = [];
        foreach ($articles as $article) {
            $parametres[$article->id] = [
                'base' => 'bureau_vote', 'ratio' => '1', 'majoration' => '0.00', 'reserve' => '0.00', 'minimum' => 0,
                'minimum_par_commune' => false, 'arrondi' => 'unite', 'conditionnement' => null, 'par_entite' => false,
                'bulletin' => false, 'cle_affichee' => 'Clé '.$article->libelle,
            ];
        }
        $plan = PlanDotation::create([
            'cycle_id' => $cycle->id, 'version' => 1, 'instantane_id' => $instantane->id,
            'parametres' => $parametres, 'calcule_par' => $responsable->id, 'anomalies' => [],
        ]);
        $plan->forceFill(['statut' => 'approuve', 'approuve_par' => $responsable->id, 'approuve_le' => now()])->save();
        foreach ($dotations as [$destinataire, $article, $dotation]) {
            PlanDotationLigne::create([
                'plan_dotation_id' => $plan->id, 'destinataire_id' => $destinataire->id, 'materiel_id' => $article->id,
                'effectif' => $dotation, 'exact' => (string) $dotation, 'majoration' => '0.00', 'minimum' => 0,
                'arrondi' => 'unite', 'dotation_calculee' => $dotation,
            ])->forceFill(['dotation_approuvee' => $dotation])->save();
        }
    }

    /** Code à six chiffres tiré au hasard (jamais l'un des codes refusés comme trop simples). */
    private function choisirCode(User $responsable, User $autorite, string $motDePasse): string
    {
        $codes = app(CodeReceptionService::class);

        if ($autorite->refresh()->code_verrouille_le !== null) {
            Auth::setUser($responsable);
            $codes->reinitialiser($autorite, 'Démonstration terrain');
        }

        Auth::setUser($autorite);
        for ($essai = 0; $essai < 20; $essai++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            try {
                $codes->choisir($autorite, $code, $motDePasse);

                return $code;
            } catch (InvalidArgumentException) {
                continue; // code trop simple : on en tire un autre
            } catch (RegleLogistique $e) {
                throw $e;
            }
        }

        throw new RegleLogistique('Aucun code de démonstration utilisable.');
    }

    /** @param  array{responsable: string, agent: string, autorite: string}  $secrets */
    private function afficher(Decharge $decharge, array $secrets, string $code): void
    {
        if ($this->command === null) {
            return;
        }
        $this->command->newLine();
        $this->command->info("Scénario de démonstration prêt : décharge n° {$decharge->numero} ({$decharge->identifiant}), livraison remise à l'agent, en attente de réception.");
        $this->command->table(['Compte', 'Courriel', 'Mot de passe', 'Code de réception'], [
            ['Responsable logistique', self::COURRIEL_RESPONSABLE, $secrets['responsable'], '-'],
            ['Agent de transport', self::COURRIEL_AGENT, $secrets['agent'], '-'],
            ['Sous-préfet (signataire)', self::COURRIEL_AUTORITE, $secrets['autorite'], $code],
        ]);
        $this->command->line('Ces valeurs sont tirées au hasard à chaque exécution et ne sont écrites nulle part.');
    }
}
