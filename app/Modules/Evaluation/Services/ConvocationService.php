<?php

namespace App\Modules\Evaluation\Services;

use App\Models\IntegrationLog;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Schedule\Models\Room;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Support\QrCodeSvg;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Convocations et listes d'émargement (§13.12) : layout PDF partagé
 * `pdf/liste` (garde, sections, pagination — aucun HTML ad hoc).
 * LOOP-EXAM-PREPARATION-01 : par épreuve ET par salle, aperçu inline,
 * visa surveillants, historique `evaluation_documents_generes`.
 */
class ConvocationService
{
    public function __construct(private PdfGeneratorInterface $pdf) {}

    public function listeEmargement(Epreuve $epreuve, ?Room $salleFiltre = null, bool $apercu = false): Response
    {
        $epreuve->load(['matiere', 'salle', 'groupe', 'session', 'candidats.etudiant.groupe', 'candidats.salle']);

        $salleEffective = fn ($candidat) => $candidat->salle_id ?? $epreuve->salle_id;

        $correspondanceService = app(CorrespondanceExamenService::class);
        $idsEligiblesRef = $correspondanceService->estModePedagogiqueStrict($epreuve)
            ? array_flip($correspondanceService->identifiantsEligiblesReference($epreuve))
            : null;

        $candidats = $epreuve->candidats
            ->when($idsEligiblesRef !== null, fn ($liste) => $liste->filter(fn ($c) => isset($idsEligiblesRef[(int) $c->etudiant_id])))
            ->sortBy(fn ($c) => $c->etudiant->nom.' '.$c->etudiant->prenom)
            ->when($salleFiltre !== null, fn ($liste) => $liste->filter(fn ($c) => $salleEffective($c) === $salleFiltre->id))
            ->values();

        $lignes = [];
        $versLigne = fn ($candidat, int $numero) => [
            'numero' => $numero,
            'anonyme' => $candidat->numero_anonyme ?? '—',
            'matricule' => $candidat->etudiant->num_inscription ?? '',
            'nom' => $candidat->etudiant->nom ?? '',
            'prenom' => $candidat->etudiant->prenom ?? '',
            'groupe' => $candidat->etudiant->groupe?->code ?? '',
            'salle' => $candidat->salleEffective()?->code ?? $epreuve->salle?->code ?? '—',
            'place' => $candidat->place ?? '—',
            'statut' => $candidat->statut,
        ];

        foreach ($candidats as $index => $candidat) {
            $lignes[] = $versLigne($candidat, $index + 1);
        }

        // G2 : sections par salle quand aucune salle n'est filtrée
        // (feuille distincte par salle au sein du même document).
        $sections = null;
        if ($salleFiltre === null && $candidats->isNotEmpty()) {
            $sections = $candidats->groupBy($salleEffective)
                ->map(function ($groupe) use ($versLigne) {
                    $lignesSection = [];
                    foreach ($groupe->values() as $index => $candidat) {
                        $lignesSection[] = $versLigne($candidat, $index + 1);
                    }

                    return [
                        'titre' => 'Salle : '.($groupe->first()->salleEffective()?->code ?? 'non affectée'),
                        'effectif' => $groupe->count(),
                        'lignes' => $lignesSection,
                    ];
                })
                ->values()
                ->all();
        }

        $reference = 'EMG-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        $contexte = implode(' · ', array_filter([
            $epreuve->matiere?->libelle_complet,
            'Session : '.($epreuve->session?->libelle ?? '—'),
            'Le '.$epreuve->date->format('d/m/Y').' '.substr((string) $epreuve->heure_debut, 0, 5).' → '.substr((string) $epreuve->heure_fin, 0, 5),
            'Salle : '.($salleFiltre?->code ?? $epreuve->salle?->code ?? 'toutes'),
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
            'entetes' => ['numero', 'anonyme', 'matricule', 'nom', 'prenom', 'groupe', 'salle', 'place', 'statut'],
            'lignes' => $lignes,
            'sections' => $sections,
            'signature' => true,
            'zoneSignature' => true,
            'orientation' => 'landscape',
        ])->render();

        $contenu = $this->pdf->generer($html, ['paper' => 'A4', 'orientation' => 'landscape']);

        IntegrationLog::tracer(
            'export', 'export', 'export.emargement', 'completed',
            null, 200, null, null, (string) Str::uuid(), $reference,
        );

        // G3 : historique de génération — auteur, épreuve, salle, effectif
        // (aucune donnée personnelle), base de l'aperçu et de la réimpression.
        DocumentExamenGenere::create([
            'epreuve_id' => $epreuve->id,
            'salle_id' => $salleFiltre?->id,
            'type' => 'emargement',
            'genere_par' => auth()->id(),
            'candidats_count' => count($lignes),
            'nom_fichier' => 'emargement-'.$epreuve->id.($salleFiltre !== null ? '-'.$salleFiltre->id : '').'.pdf',
        ]);

        $nomFichier = 'emargement-'.$epreuve->id.($salleFiltre !== null ? '-'.$salleFiltre->id : '').'.pdf';

        return new Response($contenu, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($apercu ? 'inline' : 'attachment').'; filename="'.$nomFichier.'"',
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

        app(CorrespondanceExamenService::class)->verifierCandidatPourConvocation($candidat);

        $epreuve = $candidat->epreuve;
        $etudiant = $candidat->etudiant;

        $reference = 'CNV-'.now()->format('Y').'-'.strtoupper(Str::random(6));

        $qrCodeSvg = null;
        try {
            $orchestrateur = app(ExamCandidatsConvocationsOrchestratorService::class);
            $payload = $orchestrateur->genererPayloadQrCode($candidat);
            $qrCodeSvg = QrCodeSvg::generer($payload, 84);
        } catch (\Throwable) {
            $qrCodeSvg = null;
        }

        $html = view('modules.evaluation.pdf.convocation', [
            'institution' => config('app.name'),
            'titreDocument' => 'Convocation aux épreuves',
            'reference' => $reference,
            'dateGeneration' => now()->format('d/m/Y H:i'),
            'nom' => (string) $etudiant->nom,
            'prenom' => (string) $etudiant->prenom,
            'inscription' => (string) $etudiant->num_inscription,
            'numeroAnonyme' => (string) ($candidat->numero_anonyme ?? '—'),
            'faculte' => (string) ($etudiant->faculte?->nom ?? '—'),
            'groupe' => (string) ($etudiant->groupe?->code ?? '—'),
            'matiere' => (string) ($epreuve->matiere?->libelle_complet ?? '—'),
            'session' => (string) ($epreuve->session?->libelle ?? '—'),
            'date' => $epreuve->date->format('d/m/Y'),
            'horaire' => substr((string) $epreuve->heure_debut, 0, 5).' → '.substr((string) $epreuve->heure_fin, 0, 5),
            'salle' => (string) ($candidat->salleEffective()?->code ?? $epreuve->salle?->code ?? '—'),
            'place' => (string) ($candidat->place ?? '—'),
            'type' => (string) ($epreuve->type_evaluation ?? $epreuve->examen?->type ?? '—'),
            'qrCodeSvg' => $qrCodeSvg,
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
