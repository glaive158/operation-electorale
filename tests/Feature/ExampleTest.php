<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_l_accueil_redirige_vers_la_connexion_si_non_connecte(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
