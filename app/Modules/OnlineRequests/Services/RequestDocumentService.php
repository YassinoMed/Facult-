<?php

namespace App\Modules\OnlineRequests\Services;

use App\Modules\OnlineRequests\Events\OnlineRequestDocumentGenerated;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\OnlineRequestDocument;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Documents PDF des demandes (§12) : réutilise le contrat
 * PdfGeneratorInterface (moteur dompdf branché au cœur) — AUCUN
 * nouveau moteur. Types supportés : voir config + `genererPour()`.
 * Données académiques lues depuis les relations officielles (§18),
 * jamais saisies par l'étudiant.
 */
class RequestDocumentService
{
    public function __construct(
        private readonly PdfGeneratorInterface $moteur,
    ) {}

    public function genererPour(OnlineRequest $demande, ?int $generePar): OnlineRequestDocument
    {
        $demande->loadMissing(['etudiant.faculte', 'type', 'anneeUniversitaire']);

        $code = $demande->type->code;

        if (! in_array($code, config('online-requests.types_pdf', []), true)) {
            throw new \RuntimeException("Aucun générateur pour le type « {$code} ».");
        }

        $html = view('modules.online-requests.pdf.attestation', $this->donnees($demande))->render();
        $pdf = $this->moteur->generer($html, ['paper' => 'A4', 'orientation' => 'portrait']);

        $chemin = implode('/', [
            'online-requests',
            date('Y'),
            (string) $demande->id,
            Str::uuid()->toString().'.pdf',
        ]);

        Storage::disk('private')->put($chemin, $pdf);

        $version = ((int) OnlineRequestDocument::where('online_request_id', $demande->id)->max('version')) + 1;

        $document = OnlineRequestDocument::create([
            'reference' => sprintf('PDF-%s-%02d', $demande->reference, $version),
            'online_request_id' => $demande->id,
            'type' => strtolower($code),
            'version' => $version,
            'storage_path' => $chemin,
            'sha256' => hash('sha256', $pdf),
            'generated_by' => $generePar,
        ]);

        OnlineRequestDocumentGenerated::dispatch($document->fresh('demande.etudiant'));

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(OnlineRequest $demande): array
    {
        $etudiant = $demande->etudiant;

        return [
            'institution' => 'Université',
            'titreDocument' => $demande->type->name,
            'reference' => $demande->reference,
            'dateGeneration' => now()->format('d/m/Y'),
            'nom' => (string) $etudiant->nom,
            'prenom' => (string) $etudiant->prenom,
            'cin' => (string) $etudiant->cin,
            'inscription' => (string) $etudiant->num_inscription,
            'naissance' => $etudiant->date_naissance?->format('d/m/Y') ?? '—',
            'faculte' => (string) ($etudiant->faculte?->nom ?? '—'),
            'niveau' => (string) $etudiant->niveau,
            'annee' => (string) ($demande->anneeUniversitaire?->libelle ?? $etudiant->annee_academique),
        ];
    }
}
