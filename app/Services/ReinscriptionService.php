<?php

namespace App\Services;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Réinscription simplifiée : contexte prérempli (identité existante,
 * dernière inscription, décision officielle, niveau proposé + source,
 * situation détectée, pièces réutilisables) puis création validée.
 *
 * Règles : jamais de niveau inventé (sans décision → blocage explicite),
 * jamais de doublon (unique [etudiant, annee]), niveau choisi ≠ proposé
 * exige une décision admis* couvrante (sauf réorientation explicite
 * avec motif, tracée). Réutilise `AcademicProgressionService`
 * (décisions) et `InscriptionService` (écriture) — aucune duplication.
 */
final class ReinscriptionService
{
    public function __construct(
        private readonly AcademicProgressionService $progressions,
        private readonly InscriptionService $inscriptions,
        private readonly ArchiveService $archives,
        private readonly AuditService $audit,
    ) {}

    /**
     * Contexte d'une (ré)inscription pour une année cible.
     *
     * @return array{etudiant: Etudiant, derniere: ?InscriptionAcademique, annee_cible: AnneeUniversitaire, existante: ?InscriptionAcademique, evaluation: ?array, situation: string, niveau_propose: ?array{id: int, code: string, nom: string}, source_decision: ?string, documents_reutilisables: Collection, documents_manquants: array<int, string>}
     */
    public function contexte(Etudiant $etudiant, AnneeUniversitaire $anneeCible): array
    {
        $etudiant->loadMissing(['programmeRef', 'niveauRef', 'faculte']);
        $derniere = $etudiant->inscriptionsAcademiques()->latest('annee_universitaire_id')->first();

        $existante = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $anneeCible->id)->first();

        $evaluation = $derniere !== null ? $this->progressions->evaluer($etudiant, $derniere) : null;
        $situation = $this->situer($derniere, $evaluation, $existante);

        $niveauPropose = null;
        $sourceDecision = null;

        if (($evaluation['niveau_cible'] ?? null) !== null) {
            $niveauPropose = [
                'id' => $evaluation['niveau_cible']->id,
                'code' => $evaluation['niveau_cible']->code,
                'nom' => $evaluation['niveau_cible']->nom,
            ];
            $sourceDecision = $evaluation['decision'];
        }

        return [
            'etudiant' => $etudiant,
            'derniere' => $derniere,
            'annee_cible' => $anneeCible,
            'existante' => $existante,
            'evaluation' => $evaluation,
            'situation' => $situation,
            'niveau_propose' => $niveauPropose,
            'source_decision' => $sourceDecision,
            'documents_reutilisables' => $this->documentsReutilisables($etudiant),
            'documents_manquants' => $this->documentsManquants($etudiant, $derniere === null ? 'premiere' : 'reinscription'),
        ];
    }

    /**
     * Création validée depuis le formulaire simplifié.
     *
     * @param  array{programme_id?: ?int, niveau_id?: ?int, regime?: string, confirmer: bool}  $choix
     *
     * @throws DomainException
     */
    public function confirmer(Etudiant $etudiant, AnneeUniversitaire $anneeCible, array $choix, ?int $auteurId): InscriptionAcademique
    {
        if (! ($choix['confirmer'] ?? false)) {
            throw new DomainException('Confirmation explicite requise.');
        }

        $contexte = $this->contexte($etudiant, $anneeCible);

        if ($contexte['existante'] !== null) {
            return $contexte['existante'];
        }

        $this->verifierChoix($contexte, $choix);

        // Première inscription : délégation au créateur existant
        // (choix explicites, jamais devinés).
        if ($contexte['derniere'] === null) {
            $niveau = Niveau::find((int) ($choix['niveau_id'] ?? 0));

            abort_unless($niveau !== null && isset($choix['programme_id']), 422, 'Première inscription : programme et niveau explicites requis.');

            return $this->archives->creerInscription($etudiant, [
                'annee_universitaire_id' => $anneeCible->id,
                'programme_id' => (int) $choix['programme_id'],
                'niveau_id' => $niveau->id,
                'niveau' => $niveau->code,
                'type' => 'premiere',
                'regime' => $choix['regime'] ?? 'initiale',
            ]);
        }

        $inscription = isset($choix['programme_id']) || isset($choix['niveau_id'])
            ? $this->progressions->appliquer($etudiant, $contexte['derniere'], $auteurId, [
                'programme_id' => $choix['programme_id'] ?? null,
                'niveau_id' => $choix['niveau_id'] ?? null,
            ])
            : $this->inscriptions->reinscrire($etudiant, $anneeCible, $auteurId, [
                'programme_id' => $contexte['derniere']?->programme_id,
                'niveau_id' => $contexte['niveau_propose']['id'] ?? null,
                'regime' => $choix['regime'] ?? 'initiale',
            ]);

        return $inscription;
    }

    /**
     * @param  array{derniere: ?InscriptionAcademique, existante: ?InscriptionAcademique, evaluation: ?array}  $partiel
     */
    private function situer(?InscriptionAcademique $derniere, ?array $evaluation, ?InscriptionAcademique $existante): string
    {
        if ($existante !== null) {
            return 'existante';
        }

        if ($derniere === null) {
            return 'premiere';
        }

        return match ($evaluation['action'] ?? 'bloque') {
            'progresser' => 'passage',
            'redoubler' => 'redoublement',
            'fin_cycle' => 'fin_cycle',
            default => 'bloque',
        };
    }

    /**
     * @param  array{derniere: ?InscriptionAcademique, programme_id?: ?int}  $contexte
     */
    private function niveauDansProgramme(int $niveauId, ?int $programmeId, array $contexte): bool
    {
        $niveau = Niveau::find($niveauId);

        if ($niveau === null) {
            return false;
        }

        $programmeCible = $programmeId
            ?? $contexte['derniere']?->programme_id
            ?? $contexte['derniere']?->etudiant?->programme_id;

        if ($programmeCible === null) {
            return true;
        }

        return (int) $niveau->programme_id === (int) $programmeCible;
    }

    /**
     * @param  array{derniere: ?InscriptionAcademique, existante: ?InscriptionAcademique, evaluation: ?array, niveau_propose: ?array{id: int}}  $contexte
     * @param  array{programme_id?: ?int, niveau_id?: ?int}  $choix
     *
     * @throws DomainException
     */
    private function verifierChoix(array $contexte, array $choix): void
    {
        if ($contexte['derniere'] === null) {
            return;
        }

        $niveauChoisi = isset($choix['niveau_id']) ? (int) $choix['niveau_id'] : null;
        $niveauPropose = $contexte['niveau_propose']['id'] ?? null;

        if ($niveauChoisi === null) {
            return;
        }

        // Cohérence minimale systématique : le niveau doit appartenir
        // au programme (choisi ou courant).
        $programmeId = isset($choix['programme_id']) ? (int) $choix['programme_id'] : null;

        if (! $this->niveauDansProgramme($niveauChoisi, $programmeId, $contexte)) {
            throw new DomainException('Niveau incompatible avec le programme visé.');
        }

        if ($niveauPropose === null || $niveauChoisi === $niveauPropose) {
            return;
        }

        // Écart au proposé : seule une décision admis* l'autorise
        // (réorientation/passage anticipé explicite, jamais deviné).
        $decision = $contexte['evaluation']['decision'] ?? null;

        if (! in_array($decision, ['admis', 'admis_compensation', 'admis_conditionnel'], true)) {
            throw new DomainException(
                "Niveau choisi incompatible avec la proposition ({$decision} : {$contexte['evaluation']['blocages'][0]}) — vérifiez la décision académique."
            );
        }
    }

    /** @return Collection<int, StudentDocument> */
    private function documentsReutilisables(Etudiant $etudiant): Collection
    {
        if (! class_exists(StudentDocument::class)) {
            return collect();
        }

        $dossierIds = StudentDossier::where('etudiant_id', $etudiant->id)->pluck('id');

        return StudentDocument::whereIn('student_dossier_id', $dossierIds)
            ->whereIn('status', ['validated', 'pending'])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /** @return array<int, string> */
    private function documentsManquants(Etudiant $etudiant, ?string $type = null): array
    {
        if (! class_exists(StudentDocument::class)) {
            return [];
        }

        return app(DocumentsRequisService::class)->verifier($etudiant, $type)['manquants'];
    }
}
