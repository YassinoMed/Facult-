<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Convocations et listes d'émargement (§13.12) : layout PDF partagé
 * `pdf/liste` (garde, sections, pagination — aucun HTML ad hoc),
 * sans stockage persistant, traçé via IntegrationLog.
 */
class ConvocationService
{
    public function __construct(private PdfGeneratorInterface $pdf) {}

    public function listeEmargement(Epreuve $epreuve): Response
    {
        $epreuve->load(['matiere', 'salle', 'groupe', 'session', 'candidats.etudiant.groupe', 'candidats.salle']);

        $candidats = $epreuve->candidats->sortBy(fn ($c) => $c->etudiant->nom.' '.$c->etudiant->prenom)->values();

        $lignes = [];

        foreach ($candidats as $index => $candidat) {
            $lignes[] = [
                'numero' => $index + 1,
                'matricule' => $candidat->etudiant->num_inscription ?? '',
                'nom' => $candidat->etudiant->nom ?? '',
                'prenom' => $candidat->etudiant->prenom ?? '',
                'groupe' => $candidat->etudiant->groupe?->code ?? '',
                'salle' => $candidat->salleEffective()?->code ?? $epreuve->salle?->code ?? '—',
                'place' => $candidat->place ?? '—',
                'statut' => $candidat->statut,
            ];
        }

        $reference = 'EMG-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        $contexte = implode(' · ', array_filter([
            $epreuve->matiere?->libelle_complet,
            'Session : '.($epreuve->session?->libelle ?? '—'),
            'Le '.$epreuve->date->format('d/m/Y').' '.substr((string) $epreuve->heure_debut, 0, 5).' → '.substr((string) $epreuve->heure_fin, 0, 5),
            'Salle : '.($epreuve->salle?->code ?? '—'),
            'Groupe : '.($epreuve->groupe?->code ?? '—'),
        ]));

        $html = view('pdf.liste', [
            'institution' => config('app.name'),
            'titreDocument' => "Liste d'émargement",
            'reference' => $reference,
            'dateGeneration' => now()->format('d/m/Y H:i'),
            'contexte' => $contexte,
            'metadonnees' => [
                'etablissement' => config('app.name'),
                'utilisateur' => auth()->user()?->name ?? auth()->user()?->email ?? 'système',
                'total' => count($lignes),
            ],
            'entetes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'salle', 'place', 'statut'],
            'lignes' => $lignes,
            'sections' => null,
            'signature' => true,
            'zoneSignature' => false,
            'orientation' => 'landscape',
        ])->render();

        $contenu = $this->pdf->generer($html, ['paper' => 'A4', 'orientation' => 'landscape']);

        IntegrationLog::tracer(
            'export', 'export', 'export.emargement', 'completed',
            null, 200, null, null, (string) Str::uuid(), $reference,
        );

        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="emargement-'.$epreuve->id.'.pdf"',
        ]);
    }

    /**
     * Convocation individuelle (Phase 5 §29) : un candidat, une
     * épreuve — moteur PDF central, aucune donnée sensible inutile
     * (ni CIN complète, ni notes). Traçée comme l'émargement.
     */
    public function convocation(Candidat $candidat): Response
    {
        $candidat->loadMissing(['etudiant.faculte', 'etudiant.groupe', 'epreuve.matiere', 'epreuve.salle', 'epreuve.groupe', 'epreuve.session', 'epreuve.examen', 'salle']);

        $epreuve = $candidat->epreuve;
        $etudiant = $candidat->etudiant;

        $reference = 'CNV-'.now()->format('Y').'-'.strtoupper(Str::random(6));

        $html = view('modules.evaluation.pdf.convocation', [
            'institution' => config('app.name'),
            'titreDocument' => 'Convocation aux épreuves',
            'reference' => $reference,
            'dateGeneration' => now()->format('d/m/Y H:i'),
            'nom' => (string) $etudiant->nom,
            'prenom' => (string) $etudiant->prenom,
            'inscription' => (string) $etudiant->num_inscription,
            'faculte' => (string) ($etudiant->faculte?->nom ?? '—'),
            'groupe' => (string) ($etudiant->groupe?->code ?? '—'),
            'matiere' => (string) ($epreuve->matiere?->libelle_complet ?? '—'),
            'session' => (string) ($epreuve->session?->libelle ?? '—'),
            'date' => $epreuve->date->format('d/m/Y'),
            'horaire' => substr((string) $epreuve->heure_debut, 0, 5).' → '.substr((string) $epreuve->heure_fin, 0, 5),
            'salle' => (string) ($candidat->salleEffective()?->code ?? $epreuve->salle?->code ?? '—'),
            'place' => (string) ($candidat->place ?? '—'),
            'type' => (string) ($epreuve->type_evaluation ?? $epreuve->examen?->type ?? '—'),
        ])->render();

        $contenu = $this->pdf->generer($html, ['paper' => 'A4', 'orientation' => 'portrait']);

        IntegrationLog::tracer(
            'export', 'export', 'export.convocation', 'completed',
            null, 200, null, null, (string) Str::uuid(), $reference,
        );

        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="convocation-'.$candidat->id.'.pdf"',
        ]);
    }
}
