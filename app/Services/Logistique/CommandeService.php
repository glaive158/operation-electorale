<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Commande;
use App\Models\Logistique\CommandeLigne;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\ReceptionFournisseur;
use DateTimeImmutable;
use DateTimeInterface;
use App\Models\Logistique\AttributionStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Commandes fournisseur, livrables en plusieurs parties. Seules les quantités
 * acceptées entrent en stock ; le rejeté reste dû jusqu'à son abandon.
 *
 * Ordre des verrous d'une réception, compatible avec celui de StockService :
 * commande, lignes de la commande, cycles (partagés, par id croissant), puis matériels
 * (par id croissant).
 */
class CommandeService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * @param  array{reference:string, fournisseur:string, date_commande:mixed}  $entete
     * @param  array<int, array{materiel_id:int, quantite_commandee:int, cycle_id?:int|null}>  $lignes
     */
    public function creer(array $entete, array $lignes): Commande
    {
        $this->exigerAuteur();

        $reference = trim((string) ($entete['reference'] ?? ''));
        $fournisseur = trim((string) ($entete['fournisseur'] ?? ''));
        if ($reference === '' || $fournisseur === '' || empty($entete['date_commande'])) {
            throw new InvalidArgumentException('La référence, le fournisseur et la date de commande sont obligatoires.');
        }
        $this->exigerLongueurMax($reference, 50, 'référence');
        $this->exigerLongueurMax($fournisseur, 200, 'fournisseur');
        $dateCommande = $this->date($entete['date_commande'], 'date de commande');
        if ($lignes === []) {
            throw new InvalidArgumentException('Une commande comporte au moins une ligne.');
        }

        $normalisees = [];
        foreach ($lignes as $ligne) {
            $materielId = $this->entier($ligne['materiel_id'] ?? null, 'matériel');
            $quantite = $this->entier($ligne['quantite_commandee'] ?? null, 'quantité commandée');
            $cycleId = ($ligne['cycle_id'] ?? null) === null ? null : $this->entier($ligne['cycle_id'], 'cycle');

            if ($quantite <= 0) {
                throw new InvalidArgumentException('La quantité commandée doit être strictement positive.');
            }
            if (! Materiel::whereKey($materielId)->exists()) {
                throw new InvalidArgumentException("Matériel inconnu : {$materielId}.");
            }
            if ($cycleId !== null && ! Cycle::whereKey($cycleId)->exists()) {
                throw new InvalidArgumentException("Cycle inconnu : {$cycleId}.");
            }

            $normalisees[] = ['materiel_id' => $materielId, 'quantite_commandee' => $quantite, 'cycle_id' => $cycleId];
        }

        if (Commande::where('reference', $reference)->exists()) {
            throw new InvalidArgumentException("La référence de commande {$reference} existe déjà.");
        }

        // Affecter une commande à un cycle engage le stock à venir : droit du responsable.
        $cycleIds = collect($normalisees)->pluck('cycle_id')->filter()->unique()->sort()->values();
        if ($cycleIds->isNotEmpty() && ! Gate::allows('logistique-administrer')) {
            throw new RegleLogistique('Seul un responsable logistique peut affecter une commande à un cycle.');
        }
        $materielIds = collect($normalisees)->pluck('materiel_id')->unique()->sort()->values();

        return DB::transaction(function () use ($dateCommande, $reference, $fournisseur, $normalisees, $cycleIds, $materielIds) {
            // Mêmes verrous partagés que la réception (cycles puis matériels, par id croissant) :
            // une clôture ou une désactivation concurrente ne passe pas entre le contrôle et l'écriture.
            if ($cycleIds->isNotEmpty()) {
                foreach (Cycle::whereIn('id', $cycleIds)->orderBy('id')->sharedLock()->get() as $cycle) {
                    if (! $cycle->estOuvert()) {
                        throw new RegleLogistique('Cycle clôturé : commande impossible.');
                    }
                }
            }
            foreach (Materiel::whereIn('id', $materielIds)->orderBy('id')->sharedLock()->get() as $materiel) {
                if (! $materiel->actif) {
                    throw new InvalidArgumentException("L'article « {$materiel->libelle} » est inactif : commande impossible.");
                }
            }

            $commande = Commande::create([
                'reference'     => $reference,
                'fournisseur'   => $fournisseur,
                'date_commande' => $dateCommande,
                'statut'        => 'brouillon',
                'user_id'       => auth()->id(),
            ]);

            foreach ($normalisees as $ligne) {
                $commande->lignes()->create($ligne);
            }

            return $commande;
        }, attempts: 3);
    }

    public function confirmer(Commande $commande): void
    {
        $this->exigerAuteur();

        DB::transaction(function () use ($commande) {
            $actuelle = $this->commandeVerrouillee($commande);
            if ($actuelle->statut !== 'brouillon') {
                throw new RegleLogistique('Seule une commande en brouillon peut être confirmée.');
            }

            $actuelle->update(['statut' => 'confirmee']);

            Journal::noter('commande', $actuelle->id, 'confirmation', ['statut' => 'brouillon'], ['statut' => 'confirmee']);

            $this->synchroniser($commande, $actuelle);
        }, attempts: 3);
    }

    public function annuler(Commande $commande, string $motif): void
    {
        $this->exigerAuteur();
        $this->exigerMotif($motif);

        DB::transaction(function () use ($commande, $motif) {
            $actuelle = $this->commandeVerrouillee($commande);

            if ($actuelle->statut === 'annulee') {
                throw new RegleLogistique('Cette commande est déjà annulée.');
            }
            if (! in_array($actuelle->statut, ['brouillon', 'confirmee'], true) || $actuelle->receptions()->exists()) {
                throw new RegleLogistique('Commande déjà partiellement reçue : abandonner les reliquats ligne par ligne.');
            }

            $avant = $actuelle->statut;
            $actuelle->update(['statut' => 'annulee']);

            Journal::noter('commande', $actuelle->id, 'annulation', ['statut' => $avant], ['statut' => 'annulee'], $motif);

            $this->synchroniser($commande, $actuelle);
        }, attempts: 3);
    }

    /**
     * @param  array{date_reception:mixed, bon_livraison:string}  $entete
     * @param  array<int, array{acceptee:int, rejetee:int}>  $lignes  indexées par id de ligne de commande
     */
    public function recevoir(Commande $commande, array $entete, array $lignes): ReceptionFournisseur
    {
        $this->exigerAuteur();

        $bonLivraison = trim((string) ($entete['bon_livraison'] ?? ''));
        if ($bonLivraison === '' || empty($entete['date_reception'])) {
            throw new InvalidArgumentException('La date de réception et le bon de livraison sont obligatoires.');
        }
        $this->exigerLongueurMax($bonLivraison, 100, 'bon de livraison');
        $dateReception = $this->date($entete['date_reception'], 'date de réception');
        if ($lignes === []) {
            throw new InvalidArgumentException('Une réception comporte au moins une ligne.');
        }

        $saisies = [];
        foreach ($lignes as $ligneId => $quantites) {
            $saisies[(int) $ligneId] = [
                'acceptee' => $this->entier($quantites['acceptee'] ?? null, 'quantité acceptée'),
                'rejetee'  => $this->entier($quantites['rejetee'] ?? null, 'quantité rejetée'),
            ];
        }

        return DB::transaction(function () use ($commande, $dateReception, $bonLivraison, $saisies) {
            $actuelle = $this->commandeVerrouillee($commande);
            if (! in_array($actuelle->statut, ['confirmee', 'partiellement_recue'], true)) {
                throw new RegleLogistique('Seule une commande confirmée ou partiellement reçue peut être réceptionnée.');
            }

            // Relues sous verrou, après celui de la commande : deux réceptions de la même
            // commande se succèdent, la seconde voit les quantités de la première.
            $lignesBase = CommandeLigne::where('commande_id', $actuelle->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($saisies as $ligneId => $q) {
                $ligne = $lignesBase->get($ligneId);
                if ($ligne === null) {
                    throw new InvalidArgumentException("La ligne {$ligneId} n'appartient pas à la commande {$actuelle->reference}.");
                }
                if ($q['acceptee'] < 0 || $q['rejetee'] < 0) {
                    throw new RegleLogistique('Les quantités reçues ne peuvent pas être négatives.');
                }
                if ($q['acceptee'] + $q['rejetee'] === 0) {
                    throw new RegleLogistique('Une ligne de réception doit comporter une quantité acceptée ou rejetée.');
                }
                if ($q['acceptee'] + $q['rejetee'] > $ligne->resteARecevoir()) {
                    throw new RegleLogistique(sprintf(
                        'Réception supérieure au reste à recevoir pour « %s » : reçu %d, reste %d.',
                        Materiel::find($ligne->materiel_id)?->libelle ?? $ligne->materiel_id,
                        $q['acceptee'] + $q['rejetee'],
                        $ligne->resteARecevoir(),
                    ));
                }
            }

            // Ordre stable des verrous : cycles puis matériels, par id croissant.
            $cycleIds = collect($saisies)->keys()
                ->map(fn ($id) => $lignesBase[$id]->cycle_id)->filter()->unique()->sort()->values();
            $cycles = $cycleIds->isEmpty()
                ? collect()
                : Cycle::whereIn('id', $cycleIds)->orderBy('id')->sharedLock()->get()->keyBy('id');

            $materielIds = collect($saisies)->keys()
                ->map(fn ($id) => $lignesBase[$id]->materiel_id)->unique()->sort()->values();
            $materiels = Materiel::whereIn('id', $materielIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $reception = ReceptionFournisseur::create([
                'commande_id'    => $actuelle->id,
                'date_reception' => $dateReception,
                'bon_livraison'  => $bonLivraison,
                'user_id'        => auth()->id(),
            ]);

            $ordre = collect($saisies)->keys()
                ->sortBy(fn ($id) => sprintf('%012d-%012d', $lignesBase[$id]->materiel_id, $id))
                ->values();

            $acceptees = 0;
            $rejetees = 0;
            foreach ($ordre as $ligneId) {
                $q = $saisies[$ligneId];
                $ligne = $lignesBase[$ligneId];
                $materiel = $materiels[$ligne->materiel_id];

                $reception->lignes()->create([
                    'commande_ligne_id' => $ligne->id,
                    'acceptee'          => $q['acceptee'],
                    'rejetee'           => $q['rejetee'],
                ]);

                $ligne->update([
                    'quantite_acceptee' => $ligne->quantite_acceptee + $q['acceptee'],
                    'quantite_rejetee'  => $ligne->quantite_rejetee + $q['rejetee'],
                ]);

                if ($q['acceptee'] > 0) {
                    $this->stock->enregistrer($materiel, 'entree_fournisseur', $q['acceptee'], $reception, "Commande {$actuelle->reference}, BL {$bonLivraison}");

                    // Un cycle clos n'attribue plus : la marchandise reste en stock commun.
                    $cycle = $ligne->cycle_id ? $cycles[$ligne->cycle_id] : null;
                    if ($cycle?->estOuvert()) {
                        $this->ajouterALAttribution($cycle, $materiel, $q['acceptee'], "Réception commande {$actuelle->reference}");
                    }
                }

                $acceptees += $q['acceptee'];
                $rejetees += $q['rejetee'];
            }

            $avant = $actuelle->statut;
            $apres = $this->statutSelonReste($actuelle);
            $actuelle->update(['statut' => $apres]);

            Journal::noter(
                'commande', $actuelle->id, 'reception',
                ['statut' => $avant],
                ['statut' => $apres, 'reception_id' => $reception->id, 'acceptee' => $acceptees, 'rejetee' => $rejetees],
                "Bon de livraison {$bonLivraison}",
            );

            $this->synchroniser($commande, $actuelle);

            return $reception;
        }, attempts: 3);
    }

    /**
     * Renonce à une partie du reste dû (ex. rejeté non remplacé).
     */
    public function abandonner(CommandeLigne $ligne, int $quantite, string $motif): void
    {
        $this->exigerAuteur();
        $this->exigerMotif($motif);
        if ($quantite <= 0) {
            throw new InvalidArgumentException('La quantité abandonnée doit être strictement positive.');
        }

        DB::transaction(function () use ($ligne, $quantite, $motif) {
            // Même ordre que recevoir : commande, puis ligne.
            $actuelle = Commande::whereKey($ligne->commande_id)->lockForUpdate()->firstOrFail();
            if (! in_array($actuelle->statut, ['confirmee', 'partiellement_recue'], true)) {
                throw new RegleLogistique('Seule une commande confirmée ou partiellement reçue peut avoir des reliquats abandonnés.');
            }

            $courante = CommandeLigne::whereKey($ligne->id)->lockForUpdate()->firstOrFail();
            if ($quantite > $courante->resteARecevoir()) {
                throw new RegleLogistique(sprintf(
                    'Abandon supérieur au reste à recevoir : demandé %d, reste %d.',
                    $quantite, $courante->resteARecevoir(),
                ));
            }

            $avant = ['quantite_abandonnee' => $courante->quantite_abandonnee, 'statut_commande' => $actuelle->statut];
            $courante->update(['quantite_abandonnee' => $courante->quantite_abandonnee + $quantite]);

            $statut = $this->statutSelonReste($actuelle);
            $actuelle->update(['statut' => $statut]);

            Journal::noter(
                'commande_ligne', $courante->id, 'abandon',
                $avant,
                ['quantite_abandonnee' => $courante->quantite_abandonnee, 'statut_commande' => $statut],
                $motif,
            );

            $ligne->setRawAttributes($courante->getAttributes(), true);
        }, attempts: 3);
    }

    /**
     * Quand plus rien n'est dû : recue si une réception a eu lieu, annulee si tout a été
     * abandonné sans rien recevoir. Sinon partiellement_recue si une réception a eu lieu,
     * et statut inchangé dans le cas contraire.
     */
    private function statutSelonReste(Commande $commande): string
    {
        $reste = CommandeLigne::where('commande_id', $commande->id)->get()
            ->sum(fn (CommandeLigne $l) => $l->resteARecevoir());
        $recue = $commande->receptions()->exists();

        if ($reste === 0) {
            return $recue ? 'recue' : 'annulee';
        }

        // Rien n'a été reçu : un abandon partiel ne rend pas la commande « partiellement reçue ».
        return $recue ? 'partiellement_recue' : $commande->statut;
    }

    /**
     * Ajoute au cycle la quantité reçue : l'attribution courante est lue sous verrou (le matériel
     * est déjà verrouillé par l'appelant), puis fixée par StockService::attribuer, avec ses
     * contrôles et son journal.
     */
    private function ajouterALAttribution(Cycle $cycle, Materiel $materiel, int $delta, string $motif): void
    {
        $courante = (int) (AttributionStock::where('cycle_id', $cycle->id)
            ->where('materiel_id', $materiel->id)
            ->lockForUpdate()
            ->value('quantite') ?? 0);

        $this->stock->attribuer($cycle, $materiel, $courante + $delta, $motif);
    }

    private function commandeVerrouillee(Commande $commande): Commande
    {
        return Commande::whereKey($commande->id)->lockForUpdate()->firstOrFail();
    }

    private function synchroniser(Commande $commande, Commande $actuelle): void
    {
        $commande->setRawAttributes($actuelle->getAttributes(), true);
        $commande->unsetRelation('lignes');
    }

    private function entier(mixed $valeur, string $nom): int
    {
        if (is_int($valeur)) {
            return $valeur;
        }
        if (is_string($valeur) && preg_match('/^-?\d+$/', trim($valeur)) === 1) {
            return (int) $valeur;
        }

        throw new InvalidArgumentException("La valeur « {$nom} » doit être un entier.");
    }

    private function date(mixed $valeur, string $nom): string
    {
        if ($valeur instanceof DateTimeInterface) {
            return $valeur->format('Y-m-d');
        }
        if (is_string($valeur)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);
            if ($date !== false && $date->format('Y-m-d') === $valeur) {
                return $valeur;
            }
        }

        throw new InvalidArgumentException("La {$nom} doit être une date au format AAAA-MM-JJ.");
    }

    private function exigerLongueurMax(string $valeur, int $max, string $nom): void
    {
        if (mb_strlen($valeur) > $max) {
            throw new InvalidArgumentException("Le champ « {$nom} » ne peut pas dépasser {$max} caractères.");
        }
    }

    private function exigerMotif(string $motif): void
    {
        if (trim($motif) === '') {
            throw new InvalidArgumentException('Un motif est obligatoire.');
        }
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération de stock sans auteur.');
        }
    }
}
