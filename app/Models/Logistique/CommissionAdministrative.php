<?php

namespace App\Models\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Services\Logistique\Journal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Commission administrative d'une localité. Une localité peut en compter plusieurs
 * (une fixe et des itinérantes) : leur nombre sert au calcul du matériel nécessaire.
 */
class CommissionAdministrative extends Model
{
    public const TYPES = ['fixe', 'itinerante', 'etranger'];

    public const MESSAGE_CYCLE_CLOS = 'Cycle clôturé : commissions non modifiables.';

    protected $table = 'commissions_administratives';

    protected $fillable = ['cycle_id', 'commune_id', 'pays', 'ville', 'type', 'libelle'];

    protected static function booted(): void
    {
        static::saving(fn (self $commission) => $commission->verifierLocalite());
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /** Clé de la localité, identique à celle de PerimetreDestinataire : « commune:{id} » ou « {pays}|{ville} ». */
    public function localite(): string
    {
        return (new PerimetreDestinataire([
            'commune_id' => $this->commune_id, 'pays' => $this->pays, 'ville' => $this->ville,
        ]))->localite();
    }

    /**
     * Crée une commission fixe pour chaque commune du périmètre du cycle qui n'en a aucune.
     * Idempotent. Retourne le nombre de commissions créées.
     */
    public static function creerFixesParCommune(Cycle $cycle): int
    {
        self::exigerAuteur();

        return DB::transaction(function () use ($cycle) {
            self::verrouillerCycleOuvert($cycle);

            $couvertes = self::where('cycle_id', $cycle->id)
                ->whereNotNull('commune_id')->pluck('commune_id')->flip();

            $creees = 0;
            $communes = PerimetreDestinataire::where('cycle_id', $cycle->id)
                ->whereNotNull('commune_id')->orderBy('commune_id')->pluck('commune_id');

            foreach ($communes as $communeId) {
                if ($couvertes->has($communeId)) {
                    continue;
                }
                self::create(['cycle_id' => $cycle->id, 'commune_id' => $communeId, 'type' => 'fixe']);
                $creees++;
            }

            if ($creees > 0) {
                Journal::noter('cycle', $cycle->id, 'creation_commissions_fixes', null, ['commissions' => $creees]);
            }

            return $creees;
        }, attempts: 3);
    }

    /**
     * Nombre de commissions par localité du cycle.
     *
     * @return array<string,int> indexé par PerimetreDestinataire::localite()
     */
    public static function compterParLocalite(Cycle $cycle): array
    {
        $compte = [];
        foreach (self::where('cycle_id', $cycle->id)->get(['commune_id', 'pays', 'ville']) as $commission) {
            $cle = $commission->localite();
            $compte[$cle] = ($compte[$cle] ?? 0) + 1;
        }

        return $compte;
    }

    /** À appeler dans une transaction : verrouille le cycle et refuse s'il est clos. */
    public static function verrouillerCycleOuvert(Cycle $cycle): void
    {
        $actuel = Cycle::whereKey($cycle->id)->lockForUpdate()->first();
        if ($actuel === null || ! $actuel->estOuvert()) {
            throw new RegleLogistique(self::MESSAGE_CYCLE_CLOS);
        }
    }

    public static function exigerAuteur(): void
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
    }

    /** Une commission fixe ou itinérante porte une commune ; une commission à l'étranger, un pays et une ville. */
    private function verifierLocalite(): void
    {
        if (! in_array($this->type, self::TYPES, true)) {
            throw new InvalidArgumentException('Type de commission inconnu.');
        }

        $aPaysVille = $this->pays !== null && $this->pays !== '' && $this->ville !== null && $this->ville !== '';
        $aPaysOuVille = ($this->pays !== null && $this->pays !== '') || ($this->ville !== null && $this->ville !== '');

        if ($this->type === 'etranger') {
            if ($this->commune_id !== null || ! $aPaysVille) {
                throw new InvalidArgumentException('Une commission à l\'étranger exige un pays et une ville, sans commune.');
            }

            return;
        }

        if ($this->commune_id === null || $aPaysOuVille) {
            throw new InvalidArgumentException('Une commission fixe ou itinérante exige une commune, sans pays ni ville.');
        }
    }
}
