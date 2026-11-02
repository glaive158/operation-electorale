<?php

namespace App\Console\Commands\Logistique;

use App\Services\Logistique\PreuveService;
use Illuminate\Console\Command;

/** Crée la paire de clés RSA qui protège le bloc de preuve des tablettes (la clé privée reste sur le serveur). */
class ClePreuve extends Command
{
    protected $signature = 'logistique:cle-preuve {--force : remplace une clé existante}';

    protected $description = 'Crée la clé RSA 2048 bits de la preuve de réception (refuse d\'écraser une clé existante sans --force)';

    public function handle(PreuveService $preuve): int
    {
        $chemin = (string) config('logistique.cle_preuve_privee');
        if ($chemin === '') {
            $this->error('Aucun chemin de clé configuré (logistique.cle_preuve_privee).');

            return self::FAILURE;
        }
        if (file_exists($chemin) && ! $this->option('force')) {
            $this->error("Une clé existe déjà ({$chemin}). Les déclarations en attente sur les tablettes ne seraient plus déchiffrables : relancez avec --force pour la remplacer.");

            return self::FAILURE;
        }

        $cle = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($cle === false || ! openssl_pkey_export($cle, $pem)) {
            $this->error('Création de la clé impossible (OpenSSL).');

            return self::FAILURE;
        }

        $dossier = dirname($chemin);
        if (! is_dir($dossier) && ! mkdir($dossier, 0700, true) && ! is_dir($dossier)) {
            $this->error("Dossier impossible à créer : {$dossier}");

            return self::FAILURE;
        }

        // Fichier créé en 0600 avant d'y écrire la clé : elle n'est jamais lisible par un autre compte.
        $ancien = umask(0o077);
        try {
            $ecrit = file_put_contents($chemin, $pem);
        } finally {
            umask($ancien);
        }
        if ($ecrit === false || ! chmod($chemin, 0600)) {
            $this->error("Écriture impossible : {$chemin}");

            return self::FAILURE;
        }

        $this->info("Clé privée écrite : {$chemin}");
        $this->line('Empreinte de la clé publique (SHA-256) : '.$preuve->empreinteClePublique());

        return self::SUCCESS;
    }
}
