<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@elections.sn'], [
            'name'     => 'ADMINISTRATEUR',
            'prenom'   => 'Super',
            'email'    => 'admin@elections.sn',
            'password' => \Illuminate\Support\Facades\Hash::make('Elections2026!'),
            'role'     => 'admin',
            'actif'    => true,
        ]);

        User::updateOrCreate(['email' => 'commission@elections.sn'], [
            'name'          => 'SALL',
            'prenom'        => 'Ibrahima',
            'email'         => 'commission@elections.sn',
            'password'      => \Illuminate\Support\Facades\Hash::make('Elections2026!'),
            'role'          => 'commission',
            'commune_nom'   => 'Dakar-Plateau',
            'actif'         => true,
        ]);
    }
}
