<?php

namespace App\Services\Logistique;

use App\Models\Logistique\Destinataire;
use App\Models\Logistique\Remise;
use App\Models\Logistique\Restitution;
use Carbon\CarbonInterface;

/**
 * Ce que signe un bloc de preuve, et qui peut le signer : l'objet signé (type et identifiant, sous lesquels une
 * anomalie de preuve est journalisée), le signataire attendu (le titulaire d'un destinataire ou son délégué actif,
 * ou un compte précis) et la borne basse de l'horodatage (la création de l'objet). Où lire, dans le contenu
 * attesté, le compte qui signe.
 *
 * PreuveService::verifier reconstruit celui de la remise du contenu (`remise_id`) : les déclarations de réception
 * gardent exactement leur comportement ; PreuveService::verifierDans reçoit un contexte explicite (une restitution).
 */
final class ContextePreuve
{
    private function __construct(
        public readonly string $objetType,
        public readonly int $objetId,
        public readonly ?Destinataire $destinataire,
        public readonly ?int $signataireUserId,
        public readonly ?CarbonInterface $creeLe,
        private readonly bool $signataireAPart,
    ) {}

    /**
     * La remise d'une déclaration de réception : son destinataire signe (titulaire ou délégué). Une remise
     * introuvable, ou qui ne va pas à un destinataire, n'a aucun signataire attendu.
     */
    public static function remise(int $remiseId): self
    {
        $remise = Remise::find($remiseId);
        $destinataire = $remise !== null && $remise->a_type === 'destinataire' && $remise->a_id !== null
            ? Destinataire::find($remise->a_id)
            : null;

        return new self('remise', $remiseId, $destinataire, null, $remise?->created_at, false);
    }

    /** Le contexte par défaut : la remise nommée par le contenu attesté. */
    public static function depuisContenu(array $contenu): self
    {
        return self::remise((int) ($contenu['remise_id'] ?? 0));
    }

    /**
     * Une restitution : son détenteur signe, le titulaire du destinataire ou son délégué actif, ou l'agent
     * détenteur lui-même. Le compte signataire est porté par le contenu à part (`signataire_user_id`).
     */
    public static function restitution(Restitution $restitution): self
    {
        $destinataire = $restitution->detenteur_type === 'destinataire' ? Destinataire::find($restitution->detenteur_id) : null;
        $agent = $restitution->detenteur_type === 'agent' ? (int) $restitution->detenteur_id : null;

        return new self('restitution', (int) $restitution->id, $destinataire, $agent, $restitution->created_at, true);
    }

    /** Le compte que le contenu attesté désigne comme signataire ; null s'il n'en désigne aucun. */
    public function signataireDuContenu(array $contenu): mixed
    {
        return $this->signataireAPart ? ($contenu['signataire_user_id'] ?? null) : ($contenu['signataire']['user_id'] ?? null);
    }
}
