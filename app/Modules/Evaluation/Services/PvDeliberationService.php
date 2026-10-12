<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use DomainException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Procès-verbal de délibération (Phase 14 §14.6) : composition du
 * jury, résultats, décisions — même moteur PDF central, aucune donnée
 * sensible au-delà du relevé (ni CIN, ni médical). Émis uniquement
 * sur délibération validée ou publiée (immuable par construction).
 */
class PvDeliberationService
{
    public function __construct(private PdfGeneratorInterface $pdf) {}

    public function generer(Deliberation $deliberation): Response
    {
        if (! in_array($deliberation->statut, ['validated', 'published', 'locked', 'archived'], true)) {
            throw new DomainException('PV indisponible : délibération non validée.');
        }

        $deliberation->loadMissing([
            'anneeUniversitaire', 'session', 'programme', 'niveau', 'groupe',
            'membres.utilisateur', 'membres.enseignant', 'decisions.etudiant',
        ]);

        $decisions = $deliberation->decisions
            ->sortBy(fn ($d) => ($d->etudiant->nom ?? '').' '.($d->etudiant->prenom ?? ''))
            ->values();

        $lignes = [];

        foreach ($decisions as $index => $decision) {
            $lignes[] = [
                'numero' => $index + 1,
                'matricule' => $decision->etudiant->num_inscription ?? '',
                'nom' => $decision->etudiant->nom ?? '',
                'prenom' => $decision->etudiant->prenom ?? '',
                'moyenne' => $decision->moyenne !== null ? number_format((float) $decision->moyenne, 2) : '—',
                'credits' => ((int) $decision->credits_obtenus).'/'.((int) $decision->credits_total),
                'decision' => $decision->decision,
            ];
        }

        $reference = 'PV-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        $contexte = implode(' · ', array_filter([
            $deliberation->anneeUniversitaire?->libelle,
            $deliberation->programme?->nom,
            $deliberation->niveau?->code,
            'Session : '.($deliberation->session?->libelle ?? '—'),
            'Jury : '.$deliberation->membres->count().' membre(s)',
            'Règles v'.(is_array($deliberation->regles_appliquees)
                ? ($deliberation->regles_appliquees['version'] ?? '?') : '?'),
        ]));

        $html = view('modules.evaluation.pdf.pv-deliberation', [
            'institution' => config('app.name'),
            'titreDocument' => 'Procès-verbal de délibération',
            'reference' => $reference,
            'dateGeneration' => now()->format('d/m/Y H:i'),
            'contexte' => $contexte,
            'membres' => $deliberation->membres->map(fn ($m) => [
                'nom' => $m->utilisateur?->name ?? $m->enseignant?->nomComplet() ?? '—',
                'fonction' => $m->fonction,
                'present' => (bool) $m->present,
            ])->all(),
            'entetes' => ['numero', 'matricule', 'nom', 'prenom', 'moyenne', 'credits', 'decision'],
            'lignes' => $lignes,
            'statistiques' => [
                'candidats' => count($lignes),
                'admis' => $decisions->filter(fn ($d) => str_starts_with((string) $d->decision, 'admis'))->count(),
            ],
        ])->render();

        $contenu = $this->pdf->generer($html, ['paper' => 'A4', 'orientation' => 'landscape']);

        IntegrationLog::tracer(
            'export', 'export', 'export.pv-deliberation', 'completed',
            null, 200, null, null, (string) Str::uuid(), $reference,
        );

        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="pv-deliberation-'.$deliberation->id.'.pdf"',
        ]);
    }
}
