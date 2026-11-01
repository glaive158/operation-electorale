<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les tests ne doivent jamais atteindre la base « recensement » réelle :
     * la connexion est redirigée vers une base SQLite en mémoire.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $app['config']->set('database.connections.recensement', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        return $app;
    }
}
