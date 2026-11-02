<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use App\Models\Logistique\Cycle;
use App\Models\Logistique\Decharge;
use App\Models\Logistique\DechargeDocument;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use ZipArchive;

/**
 * Document officiel d'une décharge : rendu depuis les données figées à la validation, archivé avec
 * son empreinte SHA-256 et rendu par un QR vers la route de vérification. Un exemplaire émis n'est
 * jamais régénéré : la réimpression sert le fichier archivé, après contrôle de son empreinte.
 */
class DechargePdfService
{
    private const NON_EMIS = 'Ce document ne peut pas être émis pour cette décharge.';

    private const ALTERE = 'Fichier archivé altéré ou absent : réimpression refusée.';

    public function __construct(private readonly DechargeService $decharges) {}

    /** HTML du document. Un brouillon ne se rend qu'en aperçu filigrané ; les autres statuts, depuis donnees_gelees. */
    public function html(Decharge $decharge, string $exemplaire, bool $apercu = false): string
    {
        $this->exigerExemplaire($exemplaire);

        if ($decharge->statut_document === 'brouillon') {
            if (! $apercu) {
                throw new RegleLogistique(self::NON_EMIS);
            }
            $donnees = DonneesDecharge::construire($decharge);
            $filigrane = 'BROUILLON';
        } else {
            $donnees = $decharge->donnees_gelees;
            if (! is_array($donnees)) {
                throw new LogicException('Décharge sans données figées.');
            }
            $filigrane = $exemplaire === 'annulation' ? 'ANNULÉE' : null;
        }

        return view('logistique.decharges.pdf', [
            'd' => $donnees,
            'filigrane' => $filigrane,
            'identifiant' => $decharge->identifiant,
            'qr' => $this->qr($decharge),
        ])->render();
    }

    /** PDF A4 portrait, sans ressource distante, en octets. */
    public function pdf(string $html): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }

    /** QR code (data URI PNG) de l'URL absolue de vérification de la décharge. */
    public function qr(Decharge $decharge): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'scale' => 4,
            'quietzoneSize' => 1,
        ]);

        return (new QRCode($options))->render(route('logistique.decharges.qr', $decharge->identifiant));
    }

    /**
     * Émet l'exemplaire : le PDF est rendu une seule fois, archivé et empreint. Idempotent — un exemplaire
     * déjà émis est renvoyé tel quel, y compris pour deux appels simultanés (contrainte d'unicité).
     */
    public function emettre(Decharge $decharge, string $exemplaire): DechargeDocument
    {
        $this->exigerExemplaire($exemplaire);
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut émettre les décharges.');
        }

        return DB::transaction(function () use ($decharge, $exemplaire) {
            // Ordre des verrous : cycle, puis décharge. L'archivage d'un exemplaire reste possible après la clôture.
            Cycle::whereKey($decharge->cycle_id)->lockForUpdate()->first();
            $actuelle = Decharge::whereKey($decharge->id)->lockForUpdate()->firstOrFail();

            $existant = DechargeDocument::where('decharge_id', $actuelle->id)->where('exemplaire', $exemplaire)->first();
            if ($existant !== null) {
                return $existant;
            }
            $this->exigerEmissible($actuelle, $exemplaire);

            $octets = $this->pdf($this->html($actuelle, $exemplaire));
            $chemin = "logistique/decharges/{$actuelle->cycle_id}/{$actuelle->numero}-{$exemplaire}.pdf";
            if (! $this->disque()->put($chemin, $octets)) {
                throw new RegleLogistique("Archivage du document impossible : {$chemin}.");
            }

            $document = DechargeDocument::create([
                'decharge_id' => $actuelle->id,
                'exemplaire' => $exemplaire,
                'chemin' => $chemin,
                'empreinte' => hash('sha256', $octets),
                'taille' => strlen($octets),
                'emis_par' => auth()->id(),
                'emis_le' => now(),
            ]);

            Journal::noter('decharge', $actuelle->id, 'emission_pdf', null, [
                'exemplaire' => $exemplaire, 'chemin' => $chemin, 'empreinte' => $document->empreinte, 'taille' => $document->taille,
            ]);

            return $document;
        }, attempts: 3);
    }

    /** Octets du fichier archivé, après contrôle de son empreinte. */
    public function contenu(DechargeDocument $document): string
    {
        $disque = $this->disque();
        $octets = $disque->exists($document->chemin) ? $disque->get($document->chemin) : null;

        if (! is_string($octets) || ! hash_equals($document->empreinte, hash('sha256', $octets))) {
            throw new RegleLogistique(self::ALTERE);
        }

        return $octets;
    }

    /**
     * ZIP temporaire des documents archivés des décharges validées ou annulées du périmètre (filtre de
     * preparerLot : un seul critère parmi departement_id, region_id, etranger ; vide = tout le cycle).
     * Entrées par numéro : {numero}.pdf, puis {numero}-ANNULEE.pdf pour une décharge annulée. Tout
     * destinataire du périmètre compte, actif ou non : les documents existent déjà. Les exemplaires
     * manquants sont émis d'abord ; les octets viennent toujours de contenu(). Renvoie le chemin du ZIP,
     * à supprimer par l'appelant.
     */
    public function lot(Cycle $cycle, array $filtre): string
    {
        if (auth()->id() === null) {
            throw new LogicException('Opération sans auteur.');
        }
        if (! Gate::allows('logistique-gerer')) {
            throw new RegleLogistique('Seul le personnel logistique peut télécharger les décharges.');
        }

        $destinataires = $this->decharges->destinatairesDuPerimetre($this->decharges->filtreValide($filtre), true)->pluck('id');
        $decharges = Decharge::where('cycle_id', $cycle->id)
            ->whereIn('destinataire_id', $destinataires)
            ->whereIn('statut_document', ['validee', 'annulee'])
            ->orderBy('numero')
            ->get();
        if ($decharges->isEmpty()) {
            throw new RegleLogistique('Aucune décharge validée dans ce périmètre.');
        }

        $entrees = [];
        foreach ($decharges as $decharge) {
            $exemplaires = ['initial' => "{$decharge->numero}.pdf"];
            if ($decharge->statut_document === 'annulee') {
                $exemplaires['annulation'] = "{$decharge->numero}-ANNULEE.pdf";
            }
            foreach ($exemplaires as $exemplaire => $nom) {
                $entrees[$nom] = $this->contenu($this->emettre($decharge, $exemplaire));
            }
        }

        $dossier = storage_path('app/tmp');
        if (! is_dir($dossier) && ! mkdir($dossier, 0775, true) && ! is_dir($dossier)) {
            throw new RegleLogistique("Création du dossier temporaire impossible : {$dossier}.");
        }
        $chemin = $dossier.'/decharges-'.bin2hex(random_bytes(8)).'.zip';

        $zip = new ZipArchive;
        if ($zip->open($chemin, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RegleLogistique('Création de l\'archive ZIP impossible.');
        }
        foreach ($entrees as $nom => $octets) {
            $zip->addFromString($nom, $octets);
        }
        if (! $zip->close()) {
            @unlink($chemin);
            throw new RegleLogistique('Écriture de l\'archive ZIP impossible.');
        }

        return $chemin;
    }

    private function exigerEmissible(Decharge $decharge, string $exemplaire): void
    {
        $permis = match ($exemplaire) {
            'initial' => in_array($decharge->statut_document, ['validee', 'annulee'], true),
            'annulation' => $decharge->statut_document === 'annulee',
            default => false,
        };

        if (! $permis) {
            throw new RegleLogistique(self::NON_EMIS);
        }
    }

    private function exigerExemplaire(string $exemplaire): void
    {
        if (! in_array($exemplaire, DechargeDocument::EXEMPLAIRES, true)) {
            throw new InvalidArgumentException("Exemplaire inconnu : {$exemplaire}.");
        }
    }

    private function disque(): Filesystem
    {
        return Storage::disk(config('logistique.disque_decharges'));
    }
}
