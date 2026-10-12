<?php

namespace App\Services\Documents;

use App\Models\DocumentEvent;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Services\AuditService;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Circuit de traitement d'un document officiel (généré → approuvé
 * → émis → envoyé). Fonctionne sur le registre `GeneratedDocument`
 * quel que soit le flux de génération, sans toucher aux statuts
 * existants (valide/remplace/revoque) : l'avancement est horodaté
 * (`approuve_*`, `emis_*`), jamais d'écrasement.
 *
 * Chaque transition : `lockForUpdate` + garde d'état + événement
 * `DocumentEvent` + audit (sans PII) + notification étudiant.
 */
final class DocumentWorkflowService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Approbation : document valide non encore approuvé.
     *
     * @throws DomainException
     */
    public function approuver(GeneratedDocument $document, int $auteurId): GeneratedDocument
    {
        return DB::transaction(function () use ($document, $auteurId) {
            $verrouille = GeneratedDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== 'valide') {
                throw new DomainException("Approbation impossible : document « {$verrouille->statut} » (seul un document valide peut être approuvé).");
            }

            if ($verrouille->approuve_le !== null) {
                return $verrouille;
            }

            $verrouille->update(['approuve_le' => now(), 'approuve_par' => $auteurId]);

            $this->tracer($verrouille, 'approuve', $auteurId, 'Document approuvé', "Votre document {$verrouille->reference} a été approuvé.");

            return $verrouille->fresh();
        });
    }

    /**
     * Émission : document valide ET approuvé (circuit strict : pas
     * d'émission sans approbation explicite).
     *
     * @throws DomainException
     */
    public function emettre(GeneratedDocument $document, int $auteurId): GeneratedDocument
    {
        return DB::transaction(function () use ($document, $auteurId) {
            $verrouille = GeneratedDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== 'valide') {
                throw new DomainException("Émission impossible : document « {$verrouille->statut} ».");
            }

            if ($verrouille->approuve_le === null) {
                throw new DomainException('Émission impossible : document non approuvé (approbation requise).');
            }

            if ($verrouille->emis_le !== null) {
                return $verrouille;
            }

            $verrouille->update(['emis_le' => now(), 'emis_par' => $auteurId]);

            $this->tracer($verrouille, 'emis', $auteurId, 'Document émis', "Votre document {$verrouille->reference} a été émis et est disponible.");

            return $verrouille->fresh();
        });
    }

    /**
     * Envoi : notifie l'étudiant avec le lien de son dossier. Acte
     * de transmission pur (aucun changement d'état), réservé aux
     * documents émis.
     *
     * @throws DomainException
     */
    public function envoyer(GeneratedDocument $document, int $auteurId): GeneratedDocument
    {
        return DB::transaction(function () use ($document, $auteurId) {
            $verrouille = GeneratedDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->statut !== 'valide') {
                throw new DomainException("Envoi impossible : document « {$verrouille->statut} ».");
            }

            if ($verrouille->emis_le === null) {
                throw new DomainException('Envoi impossible : document non émis.');
            }

            $this->tracer($verrouille, 'envoye', $auteurId, 'Document envoyé', "Votre document {$verrouille->reference} vous a été transmis.");

            return $verrouille->fresh();
        });
    }

    private function tracer(GeneratedDocument $document, string $evenement, int $auteurId, string $titre, string $message): void
    {
        DocumentEvent::create([
            'document_id' => $document->id,
            'evenement' => $evenement,
            'auteur_id' => $auteurId,
        ]);

        $document->loadMissing('dossier.etudiant');

        $this->audit->tracer(
            $document->dossier->etudiant, "document.{$evenement}", 'document', $document->id,
            null, ['reference' => $document->reference],
        );

        $this->notifications->notifierEtudiant(
            $document->dossier->etudiant, "document.{$evenement}", $titre, $message,
            route('etudiant.dossier.show'),
        );
    }
}
