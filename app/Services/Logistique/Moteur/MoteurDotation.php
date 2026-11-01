<?php

namespace App\Services\Logistique\Moteur;

/**
 * Calcul pur d'une dotation : aucune base de données, aucun flottant.
 * Toutes les opérations intermédiaires utilisent bcmath à l'échelle 6.
 */
class MoteurDotation
{
    private const ECHELLE = 6;

    private const MULTIPLES = ['unite' => 1, 'dizaine' => 10, 'cinquantaine' => 50, 'centaine' => 100];

    /**
     * @param  list<LocaliteCalcul>  $localites
     * @param  int  $entitesCouvrantD  entités couvrant le destinataire (forfait / circonscription par entité)
     */
    public function doter(ParametresCle $p, array $localites, int $entitesCouvrantD): ResultatDotation
    {
        $forfaitaire = in_array($p->base, ['forfait', 'circonscription'], true);
        $facteur = bcadd('1', bcdiv($p->majoration, '100', self::ECHELLE), self::ECHELLE);

        if ($forfaitaire) {
            $unites = $p->parEntite ? $entitesCouvrantD : 1;
        } else {
            $unites = array_sum(array_map(fn (LocaliteCalcul $l) => $this->unitesLocalite($p, $l), $localites));
        }

        if ($unites <= 0) {
            return new ResultatDotation(0, '0.0000', '0.0000', 0);
        }

        if ($p->minimumParCommune && ! $forfaitaire) {
            $exact = '0';
            $majore = '0';
            $brut = '0';
            foreach ($localites as $l) {
                $u = $this->unitesLocalite($p, $l);
                if ($u <= 0) {
                    continue;
                }
                $exactC = bcmul((string) $u, $p->ratio, self::ECHELLE);
                $majoreC = bcmul($exactC, $facteur, self::ECHELLE);
                $exact = bcadd($exact, $exactC, self::ECHELLE);
                $majore = bcadd($majore, $majoreC, self::ECHELLE);
                $brut = bcadd($brut, $this->maximum((string) $p->minimum, $majoreC), self::ECHELLE);
            }
        } else {
            $exact = bcmul((string) $unites, $p->ratio, self::ECHELLE);
            $majore = bcmul($exact, $facteur, self::ECHELLE);
            $brut = $this->maximum((string) $p->minimum, $majore);
        }

        $dotation = $this->plafond($brut);
        $dotation = $this->multipleSuperieur($dotation, self::MULTIPLES[$p->arrondi]);
        if ($p->conditionnement !== null) {
            $dotation = $this->multipleSuperieur($dotation, $p->conditionnement);
        }

        return new ResultatDotation(
            $unites,
            bcadd($exact, '0', 4),
            bcadd($majore, '0', 4),
            $dotation,
        );
    }

    private function unitesLocalite(ParametresCle $p, LocaliteCalcul $l): int
    {
        if ($p->bulletin) {
            return $l->couverte ? $l->effectif : 0;
        }

        return $l->effectif * ($p->parEntite ? $l->entites : 1);
    }

    private function maximum(string $a, string $b): string
    {
        return bccomp($a, $b, self::ECHELLE) >= 0 ? $a : $b;
    }

    /** Partie entière, + 1 si le reste est strictement positif. */
    private function plafond(string $valeur): int
    {
        $entier = bcadd($valeur, '0', 0);
        $reste = bcsub($valeur, $entier, self::ECHELLE);

        return (int) (bccomp($reste, '0', self::ECHELLE) > 0 ? bcadd($entier, '1', 0) : $entier);
    }

    private function multipleSuperieur(int $n, int $multiple): int
    {
        $reste = $n % $multiple;

        return $reste === 0 ? $n : $n + ($multiple - $reste);
    }
}
