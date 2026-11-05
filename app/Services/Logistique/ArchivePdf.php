<?php

namespace App\Services\Logistique;

use App\Exceptions\Logistique\RegleLogistique;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Rendu et archivage communs aux documents officiels en PDF (décharges, fiches de restitution) : PDF A4 sans
 * ressource distante, QR code, disque privé d'archivage et relecture d'un fichier archivé après contrôle de
 * son empreinte SHA-256.
 */
trait ArchivePdf
{
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

    /** QR code (data URI PNG) d'une URL absolue. */
    protected function qrDepuisUrl(string $url): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'scale' => 4,
            'quietzoneSize' => 1,
        ]);

        return (new QRCode($options))->render($url);
    }

    protected function disque(): Filesystem
    {
        return Storage::disk(config('logistique.disque_decharges'));
    }

    /** Octets du fichier archivé, refusés s'il est absent ou si son empreinte ne correspond plus. */
    protected function octetsArchives(string $chemin, string $empreinte): string
    {
        $disque = $this->disque();
        $octets = $disque->exists($chemin) ? $disque->get($chemin) : null;

        if (! is_string($octets) || ! hash_equals($empreinte, hash('sha256', $octets))) {
            throw new RegleLogistique('Fichier archivé altéré ou absent : réimpression refusée.');
        }

        return $octets;
    }
}
