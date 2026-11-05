<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Appareil;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeLigne;
use App\Models\Logistique\Declaration;
use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Materiel;
use App\Models\Logistique\Remise;
use App\Models\Logistique\RemiseLigne;
use App\Models\Logistique\ReservationStock;
use App\Models\Logistique\ReserveAppareil;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/**
 * Remises d'une décharge : le chargement au magasin consomme les réservations (le stock sort) et crée la remise
 * de chargement (magasin vers l'agent ou le transporteur) et la remise de livraison (vers le destinataire).
 * Dans le circuit via le préfet, celui-ci (ou son adjoint habilité) redistribue au sous-préfet une part de ce qu'il
 * a reçu en bon état, en ligne ou, pour l'appareil désigné, hors ligne dans la limite de sa réserve.
 *
 * Ordre des verrous : cycle, décharge, remise, appareil et réserves, déclaration, article, réservation.
 */
class RemiseService
{
    private const HORS_PERIMETRE = 'Redistribution hors de votre périmètre.';

    private const AFFECTER = 'Affectez un agent (ou, à l\'étranger, un transporteur) avant le chargement.';

    private const REFUS_CLE_REUTILISEE = "Clé d'idempotence déjà utilisée pour un autre contenu.";

    public function __construct(
        private readonly StockService $stock,
        private readonly PerimetreTerrain $perimetre,
        private readonly DelegationService $delegations,
    ) {}

    /**
     * Charge une décharge validée qui n'est pas partie. Hors circuit étranger, un agent doit être affecté ;
     * à l'étranger, un transporteur peut le remplacer : nom, qualité, société (150 caractères au plus) et
     * numéro de bordereau (50 au plus), tous requis. Renvoie la remise de chargement.
     *
     * @param  array{nom?: string, qualite?: string, societe?: string, bordereau?: string}  $transporteur
     */
    public function charger(Decharge $decharge, array $transporteur = []): Remise
    {
        $this->exigerAuteur();
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut charger une décharge.');
        }

        $remise = DB::transaction(function () use ($decharge, $transporteur) {
            $cycle = Cycle::whereKey($decharge->cycle_id)->lockForUpdate()->first();
            if ($cycle === null || ! $cycle->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : chargement impossible.');
            }
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();
            if ($actuelle->statut_document !== 'validee' || $actuelle->statut_acheminement !== 'a_preparer') {
                throw new RegleLogistique('Seule une décharge validée non partie peut être chargée.');
            }

            $porteur = $this->porteur($actuelle, $transporteur);

            // Mêmes ordres que valider() et annuler() : articles par identifiant croissant.
            $lignes = DechargeLigne::where('decharge_id', $actuelle->id)->orderBy('materiel_id')->get();
            if ($lignes->isEmpty()) {
                throw new LogicException('Décharge validée sans ligne : invariant rompu.');
            }
            foreach ($lignes as $ligne) {
                $reservation = $ligne->reservation_id === null ? null : ReservationStock::find($ligne->reservation_id);
                if ($reservation === null || ! $reservation->estActive()
                    || (int) $reservation->quantite !== (int) $ligne->quantite
                    || (int) $reservation->materiel_id !== (int) $ligne->materiel_id) {
                    throw new LogicException("Ligne validée sans réservation active de même quantité (article {$ligne->materiel_id}) : invariant rompu.");
                }
            }

            $consommees = [];
            foreach ($lignes as $ligne) {
                $mouvement = $this->stock->consommer(ReservationStock::findOrFail($ligne->reservation_id));
                $consommees[] = [
                    'materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite,
                    'reservation_id' => $ligne->reservation_id, 'mouvement_id' => $mouvement->id,
                ];
            }

            $chargement = $this->creer($actuelle, 'chargement', 'magasin', null, $porteur['type'], $porteur['id'], 'recue', $porteur['transporteur'], $lignes);
            $this->creer($actuelle, 'livraison', $porteur['type'], $porteur['id'], 'destinataire', (int) $actuelle->remis_a_id, 'en_attente', $porteur['transporteur'], $lignes);

            $actuelle->forceFill(['statut_acheminement' => 'chargee'])->save();

            Journal::noter('decharge', $actuelle->id, 'chargement_decharge', ['statut_acheminement' => 'a_preparer'], [
                'statut_acheminement' => 'chargee', 'numero' => $actuelle->numero,
                'porteur' => $porteur['type'], 'agent_id' => $porteur['type'] === 'agent' ? $porteur['id'] : null,
                'transporteur' => $porteur['transporteur'], 'remise_chargement_id' => $chargement->id,
                'lignes' => $consommees,
            ]);

            return $chargement;
        }, attempts: 3);

        $decharge->refresh();

        return $remise->load('lignes');
    }

    /**
     * Qui prend le matériel : le transporteur (circuit étranger, s'il est renseigné) ou l'agent affecté.
     *
     * @return array{type: string, id: ?int, transporteur: array<string, string>}
     */
    private function porteur(Decharge $decharge, array $transporteur): array
    {
        // Un champ blanc compte comme absent.
        $transporteur = array_filter(
            array_map(fn ($valeur) => is_string($valeur) ? trim($valeur) : $valeur, $transporteur),
            fn ($valeur) => $valeur !== null && $valeur !== '',
        );

        if ($transporteur !== []) {
            if ($decharge->circuit !== 'etranger') {
                throw new InvalidArgumentException('Un transporteur ne se renseigne que pour une décharge du circuit étranger.');
            }

            $champs = ['nom' => 150, 'qualite' => 150, 'societe' => 150, 'bordereau' => 50];
            $retenu = [];
            foreach ($champs as $cle => $max) {
                $valeur = $transporteur[$cle] ?? null;
                $valeur = is_string($valeur) ? trim($valeur) : '';
                if ($valeur === '' || mb_strlen($valeur) > $max) {
                    throw new RegleLogistique(self::AFFECTER);
                }
                $retenu[$cle] = $valeur;
            }

            return ['type' => 'transporteur', 'id' => null, 'transporteur' => $retenu];
        }

        $agent = $decharge->agent_id === null ? null : User::find($decharge->agent_id);
        if ($agent === null || $agent->role !== 'agent_transport' || ! $agent->actif) {
            throw new RegleLogistique(self::AFFECTER);
        }

        return ['type' => 'agent', 'id' => (int) $agent->id, 'transporteur' => []];
    }

    /**
     * @param  array<string, string>  $transporteur
     * @param  iterable<DechargeLigne>  $lignes
     */
    private function creer(Decharge $decharge, string $etape, string $deType, ?int $deId, string $aType, ?int $aId, string $statut, array $transporteur, iterable $lignes): Remise
    {
        $remise = new Remise([
            'decharge_id' => $decharge->id, 'etape' => $etape,
            'de_type' => $deType, 'de_id' => $deId, 'a_type' => $aType, 'a_id' => $aId,
            'transporteur_nom' => $transporteur['nom'] ?? null,
            'transporteur_qualite' => $transporteur['qualite'] ?? null,
            'transporteur_societe' => $transporteur['societe'] ?? null,
            'bordereau_numero' => $transporteur['bordereau'] ?? null,
            'preparee_par' => auth()->id(),
        ]);
        $remise->forceFill(['statut' => $statut])->save();

        foreach ($lignes as $ligne) {
            RemiseLigne::create(['remise_id' => $remise->id, 'materiel_id' => $ligne->materiel_id, 'quantite' => $ligne->quantite]);
        }

        return $remise;
    }

    /**
     * Solde du préfet par article de la décharge : reçu utilisable (reçu moins défectueux de la déclaration
     * retenue de la livraison), transféré (redistributions non annulées, hors celles à arbitrer : aucune quantité
     * n'est transférée tant que le responsable n'a pas tranché), reliquat (reçu utilisable moins transféré, ce que
     * le préfet détient encore), réservé à l'appareil désigné (réserves actives) et mobilisable, ce que les
     * opérations en ligne peuvent redistribuer (reliquat moins réserve, jamais négatif). Quatre requêtes, quel que
     * soit le nombre d'articles. Pour un chiffre qui sert à une décision, l'appelant tient les verrous de la
     * décharge et de la livraison.
     *
     * @return Collection<int, array{recu_utilisable: int, transfere: int, reliquat: int, reserve_appareil: int, mobilisable: int}> par identifiant d'article
     */
    public function soldePrefet(Decharge $decharge): Collection
    {
        $articles = DechargeLigne::where('decharge_id', $decharge->id)->orderBy('materiel_id')->pluck('materiel_id');

        $recus = DB::table('declaration_lignes')
            ->join('declarations', 'declarations.id', '=', 'declaration_lignes.declaration_id')
            ->join('remises', 'remises.id', '=', 'declarations.remise_id')
            ->where('remises.decharge_id', $decharge->id)->where('remises.etape', 'livraison')
            ->where('remises.statut', '!=', 'annulee')->where('declarations.statut', 'retenue')
            ->selectRaw('declaration_lignes.materiel_id as materiel_id, SUM(declaration_lignes.recu - declaration_lignes.defectueux) as utilisable')
            ->groupBy('declaration_lignes.materiel_id')->pluck('utilisable', 'materiel_id');

        $transferts = DB::table('remise_lignes')
            ->join('remises', 'remises.id', '=', 'remise_lignes.remise_id')
            ->where('remises.decharge_id', $decharge->id)->where('remises.etape', 'redistribution')
            ->whereNotIn('remises.statut', ['annulee', 'a_arbitrer'])
            ->selectRaw('remise_lignes.materiel_id as materiel_id, SUM(remise_lignes.quantite) as transfere')
            ->groupBy('remise_lignes.materiel_id')->pluck('transfere', 'materiel_id');

        $reserves = DB::table('reserves_appareil')
            ->where('decharge_id', $decharge->id)->where('statut', 'active')
            ->selectRaw('materiel_id, SUM(quantite) as reservee')
            ->groupBy('materiel_id')->pluck('reservee', 'materiel_id');

        return $articles->mapWithKeys(function ($materielId) use ($recus, $transferts, $reserves) {
            $utilisable = (int) ($recus[$materielId] ?? 0);
            $transfere = (int) ($transferts[$materielId] ?? 0);
            $reserve = (int) ($reserves[$materielId] ?? 0);

            return [(int) $materielId => [
                'recu_utilisable' => $utilisable, 'transfere' => $transfere, 'reliquat' => $utilisable - $transfere,
                'reserve_appareil' => $reserve, 'mobilisable' => max(0, $utilisable - $transfere - $reserve),
            ]];
        });
    }

    /**
     * Le préfet (ou son adjoint habilité) remet au sous-préfet une part de ce qu'il a reçu en bon état (circuit via
     * le préfet). La quantité est plafonnée, par article, à ce qui est mobilisable : reçu utilisable de la déclaration
     * retenue, moins ce qui est déjà transféré, moins ce qui est réservé à l'appareil désigné. Aucun mouvement de
     * stock : le matériel est déjà sorti du magasin. Renvoie la remise.
     *
     * @param  array<int, int|string>  $lignes  quantité par identifiant d'article ; les zéros sont omis
     */
    public function preparerRedistribution(Decharge $decharge, array $lignes): Remise
    {
        [$remise] = $this->redistribuer($decharge, $lignes, null, null, null, true);

        return $remise->load('lignes');
    }

    /**
     * Redistribution envoyée par un terminal, idempotente par `$cle`.
     *
     * - Sans identifiant d'appareil (envoi en ligne) : la règle de preparerRedistribution, sur le mobilisable.
     * - Avec l'identifiant de l'appareil désigné de la préfecture, dont l'identité est établie (`$identiteVerifiee` :
     *   la requête porte le jeton épinglé à cet appareil à sa désignation) : la remise est créée dans la limite de sa réserve active,
     *   qu'elle consomme. Tout dépassement, un appareil non désigné, révoqué ou d'une autre préfecture, ou un
     *   état de la décharge qui n'autorise plus la redistribution (cycle clos, réception à vérifier…) n'est pas
     *   perdu : la remise est conservée au statut « à arbitrer », sans aucun transfert compté.
     *
     * Même clé et même contenu : la remise enregistrée est renvoyée ; autre contenu : refus.
     *
     * @param  array<int, int|string>  $lignes  quantité par identifiant d'article ; les zéros sont omis
     * @return array{0: Remise, 1: bool} la remise et vrai si elle vient d'être créée (faux : rejeu)
     */
    public function redistribuerSynchronisee(Decharge $decharge, array $lignes, string $cle, CarbonInterface $horodatageTerminal, ?string $identifiantAppareil = null, bool $identiteVerifiee = true): array
    {
        if (preg_match('/^[A-Za-z0-9-]{16,64}$/', $cle) !== 1) {
            throw new InvalidArgumentException("La clé d'idempotence doit compter de 16 à 64 caractères (lettres, chiffres, tirets).");
        }
        if ($identifiantAppareil !== null && preg_match('/^[A-Za-z0-9-]{16,64}$/', $identifiantAppareil) !== 1) {
            throw new InvalidArgumentException("L'identifiant de l'appareil est invalide.");
        }

        [$remise, $creee] = $this->redistribuer($decharge, $lignes, $cle, $horodatageTerminal, $identifiantAppareil, $identiteVerifiee);

        return [$remise->load('lignes'), $creee];
    }

    /**
     * @param  array<int, int|string>  $lignes
     * @return array{0: Remise, 1: bool}
     */
    private function redistribuer(Decharge $decharge, array $lignes, ?string $cle, ?CarbonInterface $horodatage, ?string $identifiant, bool $identiteVerifiee): array
    {
        $this->exigerAuteur();
        $identifiant = $identifiant === null ? null : mb_strtolower($identifiant);
        $auteur = auth()->user();
        $lignes = $this->quantitesRedistribuees($lignes);
        $demandes = array_filter($lignes, fn (int $quantite) => $quantite > 0);
        // L'instant est stocké tel quel dans une colonne sans fuseau : toujours dans le fuseau de l'application.
        $horodatage = $horodatage?->copy()->startOfSecond()->setTimezone((string) config('app.timezone'));

        try {
            [$remise, $creee, $conflit] = DB::transaction(function () use ($decharge, $demandes, $auteur, $cle, $horodatage, $identifiant, $identiteVerifiee) {
                $cycle = Cycle::whereKey($decharge->cycle_id)->lockForUpdate()->first();
                $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();

                // Rejeu : sous les verrous de la cycle et de la décharge, avant tout autre contrôle.
                $existante = $cle === null ? null : Remise::where('cle_idempotence', $cle)->first();
                if ($existante !== null) {
                    if ($this->memeEnvoi($existante, $actuelle, $demandes, $auteur, $identifiant, $horodatage)) {
                        return [$existante, false, false];
                    }
                    Journal::noter('remise', $existante->id, 'anomalie_idempotence', null, [
                        'cle' => $cle, 'decharge_id' => $actuelle->id, 'lignes' => $demandes,
                    ], "Clé d'idempotence réutilisée pour un autre contenu.");

                    return [$existante, false, true];
                }

                // Un seul instant pour le contrôle du droit et la délégation consignée.
                $maintenant = now();
                $prefecture = $this->prefectureQuiRedistribue($actuelle, $auteur, $maintenant);
                $depuisAppareil = $identifiant !== null;

                // Sur un appareil, un état qui n'autorise plus la redistribution n'efface pas ce qui s'est passé sur le terrain.
                $probleme = null;
                $livraison = null;
                if ($cycle === null || ! $cycle->estOuvert()) {
                    $probleme = 'Cycle clôturé : redistribution impossible.';
                } elseif ($actuelle->statut_document !== 'validee') {
                    $probleme = 'Seule une décharge validée peut être redistribuée.';
                } else {
                    $livraison = Remise::where('decharge_id', $actuelle->id)->where('etape', 'livraison')
                        ->where('statut', '!=', 'annulee')->lockForUpdate()->first();
                    if ($livraison?->statut === 'a_verifier') {
                        $probleme = 'Réception du préfet à vérifier : redistribution impossible.';
                    } elseif ($livraison === null || ! Declaration::where('remise_id', $livraison->id)->where('statut', 'retenue')->exists()) {
                        $probleme = "Le préfet n'a pas encore de réception retenue pour cette décharge.";
                    }
                }
                if ($probleme !== null && ! $depuisAppareil) {
                    throw new RegleLogistique($probleme);
                }

                $solde = $this->soldePrefet($actuelle);
                foreach (array_keys($demandes) as $materielId) {
                    if (! $solde->has($materielId)) {
                        throw new InvalidArgumentException("Article {$materielId} absent de la décharge.");
                    }
                }
                if ($demandes === []) {
                    throw new RegleLogistique('Rien à redistribuer.');
                }

                $raisons = $probleme === null ? [] : [$probleme];
                $appareil = null;
                $reserves = collect();
                if (! $depuisAppareil) {
                    foreach ($demandes as $materielId => $quantite) {
                        ['recu_utilisable' => $utilisable, 'transfere' => $transfere, 'reserve_appareil' => $reserve, 'mobilisable' => $mobilisable] = $solde[$materielId];
                        if ($quantite > $mobilisable) {
                            $libelle = Materiel::find($materielId)?->libelle ?? "article {$materielId}";
                            throw new RegleLogistique($reserve > 0
                                ? "Redistribution supérieure au mobilisable du préfet pour {$libelle} : mobilisable {$mobilisable}, réservé à l'appareil {$reserve}, demandé {$quantite}."
                                : "Redistribution supérieure au reçu utilisable du préfet pour {$libelle} : reçu utilisable {$utilisable}, déjà transféré {$transfere}, demandé {$quantite}.");
                        }
                    }
                } else {
                    [$appareil, $reserves, $raisonsAppareil] = $this->evaluerAppareil($identifiant, $identiteVerifiee, $prefecture, $actuelle, $demandes, $solde, $probleme);
                    $raisons = array_merge($raisons, $raisonsAppareil);
                }

                $aArbitrer = $raisons !== [];
                $redistribution = new Remise([
                    'decharge_id' => $actuelle->id, 'etape' => 'redistribution',
                    'de_type' => 'destinataire', 'de_id' => $prefecture->id,
                    'a_type' => 'destinataire', 'a_id' => (int) $actuelle->destinataire_id,
                    'preparee_par' => $auteur->id,
                ]);
                $redistribution->forceFill([
                    'statut' => $aArbitrer ? 'a_arbitrer' : 'en_attente',
                    'appareil_id' => $appareil?->id, 'appareil_declare' => $identifiant,
                    'cle_idempotence' => $cle, 'horodatage_terminal' => $horodatage,
                    'raison_arbitrage' => $aArbitrer ? mb_substr(implode(' ', $raisons), 0, 500) : null,
                ])->save();
                foreach ($demandes as $materielId => $quantite) {
                    RemiseLigne::create(['remise_id' => $redistribution->id, 'materiel_id' => $materielId, 'quantite' => $quantite]);
                }

                $delegation = $this->perimetre->estTitulaireDe($auteur, $prefecture) ? null : $this->delegations->activeA($prefecture, $auteur, $maintenant);
                $contexte = [
                    'decharge_id' => $actuelle->id, 'de' => $prefecture->id, 'a' => (int) $actuelle->destinataire_id,
                    'lignes' => $demandes, 'delegation_id' => $delegation?->id,
                    'appareil_id' => $appareil?->id, 'appareil' => $identifiant, 'cle' => $cle,
                    'horodatage_terminal' => $horodatage?->toIso8601String(),
                ];

                if ($aArbitrer) {
                    Journal::noter('remise', $redistribution->id, 'redistribution_a_arbitrer', null, $contexte + ['raisons' => $raisons]);
                } else {
                    $reserveAvant = [];
                    foreach ($demandes as $materielId => $quantite) {
                        $ligne = $reserves->get($materielId);
                        if ($ligne === null) {
                            continue;
                        }
                        $reserveAvant[$materielId] = $ligne->quantite;
                        $ligne->quantite -= $quantite;
                        if ($ligne->quantite === 0) {
                            $ligne->statut = 'consommee';
                        }
                        $ligne->save();
                    }
                    Journal::noter('remise', $redistribution->id, 'preparation_redistribution', null, $contexte + [
                        'soldes_avant' => $solde->only(array_keys($demandes))->all(),
                        'reserve_avant' => $reserveAvant,
                    ]);
                }

                return [$redistribution, true, false];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $e) {
            // Filet de concurrence : une écriture de la même clé a échappé aux verrous ; on retrouve celle qui a gagné,
            // d'après la base (le message de l'erreur cite toutes les colonnes insérées). Aucune ⇒ l'erreur.
            $existante = $cle === null ? null : Remise::where('cle_idempotence', $cle)->first();
            if ($existante === null) {
                throw $e;
            }
            [$remise, $creee, $conflit] = [$existante, false, ! $this->memeEnvoi($existante, Decharge::findOrFail($decharge->id), $demandes, $auteur, $identifiant, $horodatage)];
        }

        if ($conflit) {
            throw new RegleLogistique(self::REFUS_CLE_REUTILISEE);
        }

        return [$remise, $creee];
    }

    /**
     * Évalue une redistribution envoyée par un appareil : l'appareil connu (verrouillé) et ses réserves actives
     * (verrouillées) quand il est désigné, et les raisons pour lesquelles la remise est à arbitrer. Un appareil est
     * désigné s'il est actif, de cette préfecture, et si son identité est établie par le jeton ; ses quantités
     * doivent tenir dans sa réserve et dans ce que le préfet a reçu en bon état. Les raisons sont vides quand la
     * remise est acceptée. `$probleme` non nul : l'état de la décharge exclut déjà toute réserve utilisable.
     *
     * @param  array<int, int>  $demandes
     * @param  Collection<int, array{recu_utilisable: int, transfere: int, reliquat: int, reserve_appareil: int, mobilisable: int}>  $solde
     * @return array{0: ?Appareil, 1: Collection<int, ReserveAppareil>, 2: list<string>}
     */
    private function evaluerAppareil(string $identifiant, bool $identiteVerifiee, Destinataire $prefecture, Decharge $decharge, array $demandes, Collection $solde, ?string $probleme): array
    {
        $appareil = $identiteVerifiee ? Appareil::where('identifiant', $identifiant)->lockForUpdate()->first() : null;
        $designe = $appareil !== null && $appareil->actif && (int) $appareil->destinataire_id === (int) $prefecture->id;
        if (! $designe) {
            return [$appareil, collect(), [match (true) {
                $appareil !== null && ! $appareil->actif => 'Appareil révoqué.',
                $appareil !== null => 'Appareil désigné pour une autre préfecture.',
                default => 'Appareil non désigné pour cette préfecture.',
            }]];
        }
        if ($probleme !== null) {
            return [$appareil, collect(), []];
        }

        $raisons = [];
        $reserves = ReserveAppareil::where('appareil_id', $appareil->id)->where('decharge_id', $decharge->id)
            ->where('statut', 'active')->orderBy('materiel_id')->lockForUpdate()->get()->keyBy('materiel_id');
        foreach ($demandes as $materielId => $quantite) {
            $libelle = Materiel::find($materielId)?->libelle ?? "article {$materielId}";
            $disponible = (int) ($reserves->get($materielId)?->quantite ?? 0);
            ['recu_utilisable' => $utilisable, 'transfere' => $transfere] = $solde[$materielId];
            if ($quantite > $disponible) {
                $raisons[] = "Quantité supérieure à la réserve de l'appareil pour {$libelle} : réserve {$disponible}, demandée {$quantite}.";
            } elseif ($transfere + $quantite > $utilisable) {
                $raisons[] = "Redistribution supérieure au reçu utilisable du préfet pour {$libelle} : reçu utilisable {$utilisable}, déjà transféré {$transfere}, demandé {$quantite}.";
            }
        }

        return [$appareil, $reserves, $raisons];
    }

    /**
     * Même envoi : même décharge, même auteur, même appareil déclaré, mêmes quantités et même instant du terminal.
     *
     * @param  array<int, int>  $demandes
     */
    private function memeEnvoi(Remise $existante, Decharge $decharge, array $demandes, User $auteur, ?string $identifiant, ?CarbonInterface $horodatage): bool
    {
        if ($existante->etape !== 'redistribution' || (int) $existante->decharge_id !== (int) $decharge->id
            || (int) $existante->preparee_par !== (int) $auteur->id || $existante->appareil_declare !== $identifiant) {
            return false;
        }

        $enregistrees = RemiseLigne::where('remise_id', $existante->id)->orderBy('materiel_id')->pluck('quantite', 'materiel_id')
            ->map(fn ($quantite) => (int) $quantite)->all();
        if ($enregistrees !== $demandes) {
            return false;
        }

        return $existante->horodatage_terminal === null
            ? $horodatage === null
            : ($horodatage !== null && $existante->horodatage_terminal->equalTo($horodatage));
    }

    /**
     * Annule une redistribution que personne n'a encore déclarée : le reliquat du préfet est libéré.
     */
    public function annulerRedistribution(Remise $remise, string $motif): void
    {
        $this->exigerAuteur();
        $auteur = auth()->user();
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > 500) {
            throw new InvalidArgumentException("Le motif d'annulation est obligatoire (500 caractères au plus).");
        }

        $cycleId = Decharge::whereKey($remise->decharge_id)->value('cycle_id');
        if ($cycleId === null) {
            throw new LogicException('Remise sans décharge : invariant rompu.');
        }

        DB::transaction(function () use ($remise, $motif, $cycleId, $auteur) {
            $cycle = Cycle::whereKey($cycleId)->lockForUpdate()->first();
            $decharge = Decharge::whereKey($remise->decharge_id)->lockForUpdate()->firstOrFail();
            $actuelle = Remise::whereKey($remise->id)->lockForUpdate()->firstOrFail();

            $maintenant = now();
            $prefecture = $this->prefectureQuiRedistribue($decharge, $auteur, $maintenant);
            if ($actuelle->etape !== 'redistribution' || (int) $actuelle->de_id !== (int) $decharge->remis_a_id) {
                throw new RegleLogistique(self::HORS_PERIMETRE);
            }
            if ($cycle === null || ! $cycle->estOuvert()) {
                throw new RegleLogistique('Cycle clôturé : annulation impossible.');
            }
            if ($actuelle->statut !== 'en_attente' || Declaration::where('remise_id', $actuelle->id)->exists()) {
                throw new RegleLogistique('Seule une redistribution en attente, sans déclaration, peut être annulée.');
            }

            $actuelle->forceFill(['statut' => 'annulee', 'motif_annulation' => $motif])->save();
            $delegation = $this->perimetre->estTitulaireDe($auteur, $prefecture) ? null : $this->delegations->activeA($prefecture, $auteur, $maintenant);
            Journal::noter('remise', $actuelle->id, 'annulation_redistribution', ['statut' => 'en_attente'], [
                'statut' => 'annulee', 'decharge_id' => $decharge->id, 'delegation_id' => $delegation?->id,
            ], $motif);
        }, attempts: 3);

        $remise->refresh();
    }

    /**
     * La préfecture qui redistribue une décharge : celle à qui elle est remise, dont l'auteur est l'autorité (le
     * titulaire ou l'adjoint habilité par une délégation en vigueur), pour une sous-préfecture en circuit via le
     * préfet, à l'instant donné (maintenant par défaut). Sinon la redistribution est hors périmètre.
     */
    public function prefectureQuiRedistribue(Decharge $decharge, User $auteur, ?CarbonInterface $instant = null): Destinataire
    {
        $prefecture = $decharge->remis_a_id === null ? null : Destinataire::find($decharge->remis_a_id);
        $destinataire = Destinataire::find($decharge->destinataire_id);

        if (! Gate::forUser($auteur)->allows('logistique-terrain')
            || $prefecture === null || $destinataire === null
            || $decharge->circuit !== 'via_prefet'
            || $prefecture->type !== 'prefecture'
            || $destinataire->type !== 'sous_prefecture'
            || (int) $destinataire->prefecture_id !== (int) $prefecture->id
            || ! $this->perimetre->estAutoriteDe($auteur, $prefecture, $instant)) {
            throw new RegleLogistique(self::HORS_PERIMETRE);
        }

        return $prefecture;
    }

    /**
     * @param  array<int|string, mixed>  $lignes
     * @return array<int, int> quantité par identifiant d'article, triée
     */
    private function quantitesRedistribuees(array $lignes): array
    {
        $retenues = [];
        foreach ($lignes as $materielId => $quantite) {
            if (is_string($quantite) && preg_match('/^\d{1,12}$/', $quantite) === 1) {
                $quantite = (int) $quantite;
            }
            if (! is_int($materielId) || ! is_int($quantite) || $quantite < 0 || $quantite > 10_000_000) {
                throw new InvalidArgumentException('Les quantités à redistribuer doivent être des entiers de 0 à 10 000 000, par article.');
            }
            $retenues[$materielId] = $quantite;
        }
        ksort($retenues);

        return $retenues;
    }

    private function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }
}
