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
        if ($this->danslePublic($chemin)) {
            $this->error('La clé privée ne doit pas être dans le dossier public.');

            return self::FAILURE;
        }
        $force = (bool) $this->option('force');
        if (file_exists($chemin) && ! $force) {
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

        // Écrite dans un fichier temporaire 0600 du même dossier puis publiée d'un coup : la clé n'est jamais
        // lisible par un autre compte, et un échec en cours d'écriture laisse l'ancienne clé intacte.
        $temporaire = tempnam($dossier, '.preuve-');
        if ($temporaire === false) {
            $this->error("Écriture impossible dans {$dossier}");

            return self::FAILURE;
        }
        $publie = false;
        try {
            if (chmod($temporaire, 0600) && file_put_contents($temporaire, $pem) !== false) {
                // Sans --force, link() échoue si une clé est apparue entre-temps ; avec, rename() remplace.
                $publie = $force ? rename($temporaire, $chemin) : link($temporaire, $chemin);
            }
        } finally {
            if (file_exists($temporaire)) {
                unlink($temporaire);
            }
        }
        if (! $publie) {
            $this->error("Écriture impossible : {$chemin}");

            return self::FAILURE;
        }

        $this->info("Clé privée écrite : {$chemin}");
        $this->line('Empreinte de la clé publique (SHA-256) : '.$preuve->empreinteClePublique());

        return self::SUCCESS;
    }

    private function danslePublic(string $chemin): bool
    {
        $public = realpath(public_path()) ?: public_path();
        $dossier = realpath(dirname($chemin)) ?: dirname($chemin);

        return $dossier === $public || str_starts_with($dossier.'/', rtrim($public, '/').'/');
    }
}
