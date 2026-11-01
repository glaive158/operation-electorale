<?php

namespace Tests\Unit\Logistique;

use App\Services\Logistique\NomGeo;
use PHPUnit\Framework\TestCase;

class NomGeoTest extends TestCase
{
    public function test_normalise_accents_tirets_apostrophes(): void
    {
        $this->assertSame('THIES NONES', NomGeo::normaliser('Thiès-Nones'));
        $this->assertSame('SEDHIOU', NomGeo::normaliser('Sédhiou'));
        $this->assertSame('N DINDY', NomGeo::normaliser("N'Dindy"));
        $this->assertSame('N DINDY', NomGeo::normaliser('N’Dindy'));
        $this->assertSame('KAOLACK', NomGeo::normaliser('  Kaolack  '));
        $this->assertSame('SAINT LOUIS', NomGeo::normaliser('Saint_Louis.'));
        $this->assertSame('GUEDIAWAYE', NomGeo::normaliser("GUÉDIAWAYE"));
    }
}
