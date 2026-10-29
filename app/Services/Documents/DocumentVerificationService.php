<?php

namespace App\Services\Documents;

use App\Models\DocumentVerification;
use App\Modules\StudentDossiers\Models\GeneratedDocument;

/**
 * Phase 16 §16.3 : vérification publique d'authenticité (QR + code).
 * Réponses distinctes (valide/révoqué/remplacé/expiré/inexistant) mais
 * sans fuite : nom partiel masqué, jamais de PII complète, IP hachée.
 */
final class DocumentVerificationService
{
    public function __construct(
        private readonly DocumentSignatureService $signature,
    ) {}

    /**
     * @return array{code: string, trouve: bool, statut: string, type: ?string, date: ?string, nom_masque: ?string, signature_valide: ?bool, valide: bool}
     */
    public function verifier(string $code, string $ip): array
    {
        $document = GeneratedDocument::where('code_verification', $code)->first()
            ?? GeneratedDocument::where('reference', $code)->first()
            ?? GeneratedDocument::where('numero', $code)->first();

        if ($document === null) {
            DocumentVerification::create([
                'document_id' => null,
                'code' => mb_substr($code, 0, 64),
                'ip_hachee' => hash('sha256', $ip.config('app.key')),
                'resultat' => 'inexistant',
            ]);

            return [
                'code' => $code, 'trouve' => false, 'statut' => 'inexistant',
                'type' => null, 'date' => null, 'nom_masque' => null,
                'signature_valide' => null, 'valide' => false,
            ];
        }

        $statut = $document->statut;

        if ($statut === 'valide' && (($document->valide_jusquau !== null && $document->valide_jusquau->isPast()) || ($document->expires_at !== null && $document->expires_at->isPast()))) {
            $statut = 'expire';
        }

        $signatureValide = $this->signature->verifier($document);

        DocumentVerification::create([
            'document_id' => $document->id,
            'code' => mb_substr($code, 0, 64),
            'ip_hachee' => hash('sha256', $ip.config('app.key')),
            'resultat' => $statut,
        ]);

        $snapshot = $document->snapshot_donnees ?? [];

        return [
            'code' => $code,
            'trouve' => true,
            'statut' => $statut,
            'type' => $document->type,
            'date' => $document->emis_le?->format('d/m/Y') ?? $document->created_at?->format('d/m/Y'),
            'nom_masque' => $this->masquerNom((string) ($snapshot['nom'] ?? '')),
            'signature_valide' => $signatureValide,
            'valide' => $statut === 'valide' && $signatureValide !== false,
        ];
    }

    private function masquerNom(string $nom): ?string
    {
        if ($nom === '') {
            return null;
        }

        $premier = mb_substr($nom, 0, 1);

        return $premier.str_repeat('•', max(0, mb_strlen($nom) - 1));
    }
}
