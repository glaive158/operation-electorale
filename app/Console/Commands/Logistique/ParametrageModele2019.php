<?php

namespace App\Console\Commands\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\CleRepartition;
use App\Models\Logistique\CommissionAdministrative;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Models\User;
use App\Services\Logistique\CleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Paramètre un cycle de scrutin avec les articles et les clés imprimés sur les décharges de la présidentielle 2019. */
class ParametrageModele2019 extends Command
{
    protected $signature = 'logistique:modele-2019 {cycle : id du cycle} {--auteur= : id de l\'utilisateur responsable}';

    protected $description = 'Crée les articles et les clés de répartition de la présidentielle 2019 qui manquent dans un cycle de scrutin ouvert';

    private const MC = 'materiel_lourd';

    private const IM = 'imprime';

    private const BV = 'bureau_vote';

    private const REGROUPEMENT_CACHETS = 'Cachets';

    private const REGROUPEMENT_AFFICHES = 'Affiches';

    private const REGROUPEMENT_ENVELOPPES = 'Enveloppes de transmission des procès-verbaux';

    /**
     * Un article par ligne : libellé, catégorie, regroupement, base, ratio, réutilisable, particularités.
     * Particularités : « majoration » (en %), « par_entite » et « cle » (texte imprimé si différent de « NN par B.V »).
     *
     * @var list<array{0:string,1:string,2:?string,3:string,4:int,5:bool,6?:array<string,mixed>}>
     */
    private const MODELE = [
        ['Urnes', self::MC, null, self::BV, 1, true, ['majoration' => '0']],
        ['Bracelets de scellement de l\'urne', self::MC, null, self::BV, 4, false],
        ['Ciseaux', self::MC, null, self::BV, 1, true],
        ['Flacons d\'encre indélébile', self::MC, null, self::BV, 2, false],
        ['Isoloirs', self::MC, null, self::BV, 1, true, ['majoration' => '0']],
        ['Rideaux d\'isoloir', self::MC, null, self::BV, 1, false],
        ['Bâtonnets de cire', self::MC, null, self::BV, 1, false],
        ['Pelotes de ficelle', self::MC, null, self::BV, 1, false],
        ['Encreurs', self::MC, null, self::BV, 1, false],
        ['Lampes', self::MC, null, self::BV, 1, true],
        ['Piles pour lampe', self::MC, null, self::BV, 3, false],
        ['Dateurs', self::MC, null, self::BV, 1, true],
        ['Cachet « À cire »', self::MC, self::REGROUPEMENT_CACHETS, self::BV, 1, true],
        ['Cachet « A voté »', self::MC, self::REGROUPEMENT_CACHETS, self::BV, 1, true],
        ['Cachet « Original »', self::MC, self::REGROUPEMENT_CACHETS, self::BV, 1, true],
        ['Cachet « Copie »', self::MC, self::REGROUPEMENT_CACHETS, self::BV, 1, true],
        ['Cachet « Commune de »', self::MC, self::REGROUPEMENT_CACHETS, self::BV, 1, true],
        ['Caisses poubelles', self::MC, null, self::BV, 1, false],
        ['Flacons de colle', self::MC, null, self::BV, 1, false],
        ['Code électoral', self::IM, null, self::BV, 1, false],
        ['Procès-verbal des opérations électorales', self::IM, null, self::BV, 17, false],
        ['Feuille de dépouillement des votes', self::IM, null, self::BV, 6, false],
        ['Fiche de proclamation des résultats', self::IM, null, self::BV, 2, false],
        ['Fiche d\'indication du bureau de vote', self::IM, null, self::BV, 1, false],
        ['Fiche à coller sur l\'urne', self::IM, null, self::BV, 2, false],
        ['Fiche de pointage des votes', self::IM, null, self::BV, 1, false],
        ['Fiche de réception et de mise à disposition du matériel électoral', self::IM, null, self::BV, 1, false],
        ['Fiche de restitution du matériel électoral réutilisable', self::IM, null, self::BV, 1, false],
        ['Affiche « décret de convocation du corps électoral »', self::IM, self::REGROUPEMENT_AFFICHES, self::BV, 1, false],
        ['Affiche « liste des candidats en lice »', self::IM, self::REGROUPEMENT_AFFICHES, self::BV, 1, false],
        ['Affiche « quelques extraits du code électoral »', self::IM, self::REGROUPEMENT_AFFICHES, self::BV, 1, false],
        ['Carte de plénipotentiaire', self::IM, null, 'circonscription', 1, false, [
            'par_entite' => true, 'cle' => '01 par candidat et par circonscription administrative',
        ]],
        ['Carte de mandataire', self::IM, null, 'lieu_vote', 1, false, [
            'par_entite' => true, 'cle' => '01 par candidat et par lieu de vote',
        ]],
        ['Enveloppe de transmission des PV « C.D.R.V »', self::IM, self::REGROUPEMENT_ENVELOPPES, self::BV, 1, false],
        ['Enveloppe de transmission des PV « C.E.N.A »', self::IM, self::REGROUPEMENT_ENVELOPPES, self::BV, 1, false],
        ['Enveloppe de transmission des PV « ARCHIVES »', self::IM, self::REGROUPEMENT_ENVELOPPES, self::BV, 1, false],
        ['Enveloppes de vote', self::IM, null, 'electeur', 1, false, ['cle' => 'Nombre d\'inscrits du B.V']],
        ['Fiche récapitulative des résultats', self::IM, null, 'lieu_vote', 1, false, [
            'cle' => 'Selon le nombre de L.V de la circonscription',
        ]],
        ['Fiche de recueil et de transmission des résultats', self::IM, null, self::BV, 1, false, [
            'cle' => 'Selon le nombre de B.V de la circonscription',
        ]],
    ];

    public function handle(CleService $cles): int
    {
        $auteur = User::find($this->option('auteur'));
        if ($auteur === null || ! Gate::forUser($auteur)->allows('logistique-administrer')) {
            $this->error('L\'auteur doit être un utilisateur responsable de la logistique ou un administrateur (option --auteur).');

            return self::FAILURE;
        }

        $cycle = Cycle::find($this->argument('cycle'));
        if ($cycle === null) {
            $this->error('Cycle introuvable.');

            return self::FAILURE;
        }
        if ($cycle->type === 'revision') {
            $this->error('Le modèle de la présidentielle 2019 ne s\'applique pas à un cycle de révision des listes.');

            return self::FAILURE;
        }

        Auth::onceUsingId($auteur->id);

        try {
            $bilan = DB::transaction(function () use ($cles, $cycle) {
                CommissionAdministrative::verrouillerCycleOuvert($cycle);

                return $this->semer($cles, $cycle);
            }, attempts: 3);
        } catch (RegleLogistique|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Articles créés : {$bilan['articles']}.");
        $this->info("Clés créées : {$bilan['cles']}.");
        $this->info("Clés existantes conservées : {$bilan['conservees']}.");

        return self::SUCCESS;
    }

    /** @return array{articles:int, cles:int, conservees:int} */
    private function semer(CleService $cles, Cycle $cycle): array
    {
        $bilan = ['articles' => 0, 'cles' => 0, 'conservees' => 0];

        foreach (self::MODELE as $ordre => $ligne) {
            [$libelle, $categorie, $regroupement, $base, $ratio, $reutilisable] = $ligne;
            $particularites = $ligne[6] ?? [];

            $materiel = Materiel::where('libelle', $libelle)->first();
            if ($materiel === null) {
                $materiel = Materiel::create([
                    'libelle'      => $libelle,
                    'categorie'    => $categorie,
                    'regroupement' => $regroupement,
                    'ordre'        => $ordre,
                    'indivisible'  => true,
                    'reutilisable' => $reutilisable,
                ]);
                $bilan['articles']++;
            }

            if (CleRepartition::where('cycle_id', $cycle->id)->where('materiel_id', $materiel->id)->exists()) {
                $bilan['conservees']++;

                continue;
            }

            $valeurs = [
                'base'         => $base,
                'par_entite'   => $particularites['par_entite'] ?? false,
                'ratio'        => (string) $ratio,
                'cle_affichee' => $particularites['cle'] ?? sprintf('%02d par B.V', $ratio),
            ];
            if (isset($particularites['majoration'])) {
                $valeurs['majoration_distribuee'] = $particularites['majoration'];
            }

            $cles->enregistrer($cycle, $materiel, $valeurs);
            $bilan['cles']++;
        }

        return $bilan;
    }
}
