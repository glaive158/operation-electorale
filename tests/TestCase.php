<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Les tests n'atteignent jamais une base réelle, quel que soit l'environnement du shell
     * (DB_CONNECTION, DB_DATABASE) ou une configuration en cache : la connexion par défaut et
     * la connexion « recensement » sont redirigées vers des bases SQLite en mémoire.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $memoire = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', $memoire);
        $app['config']->set('database.connections.recensement', $memoire);

        return $app;
    }
}
