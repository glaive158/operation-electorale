<?php

namespace App\Services\Logistique\Moteur;

final readonly class ResultatDotation
{
    /**
     * @param  string  $exact  quantité avant majoration, échelle 4
     * @param  string  $majore  quantité après majoration, échelle 4
     */
    public function __construct(
        public int $unites,
        public string $exact,
        public string $majore,
        public int $dotation,
    ) {}
}
