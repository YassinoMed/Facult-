<?php

namespace App\Modules\Attendance\Services;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceTemplate;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Support\QrCodeSvg;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Service de rendu des fiches de présence multi-modèles (§13-17).
 *
 * Responsabilités :
 * - Chargement optimisé des données sans duplication métier ni requêtes N+1 ;
 * - Détermination du contexte institutionnel (université, faculté, département, enseignant, salle, créneau) ;
 * - Fusion de la configuration du gabarit et des colonnes actives ;
 * - Génération des tokens et QR Codes SVG si requis ;
 * - Sélection dynamique des vues Blade spécialisées ;
 * - Compilation PDF via PdfGeneratorInterface et audit transverse.
 */
class AttendanceTemplateRenderer
{
    public function __construct(
        private readonly PdfGeneratorInterface $pdf,
        private readonly AttendanceQrSecurityService $qrSecurity,
    ) {}

    /**
     * Recommande automatiquement le modèle le plus adapté selon le type de séance.
     */
    public function suggererModele(?ScheduleSlot $slot, ?string $typeSeance = null): string
    {
        $type = $slot?->type ?? $typeSeance;

        return match ($type) {
            'td' => AttendanceTemplate::TYPE_TD,
            'tp' => AttendanceTemplate::TYPE_TP,
            'exam' => AttendanceTemplate::TYPE_EXAM,
            default => AttendanceTemplate::TYPE_CLASSIC,
        };
    }

    /**
     * Prépare toutes les données nécessaires au rendu d'un gabarit de présence.
     *
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    public function preparerContexte(
        AttendanceTemplate $template,
        array $parametres,
        ?User $utilisateur = null
    ): array {
        // 1. Résolution de la séance et du groupe
        /** @var ScheduleSlot|null $slot */
        $slot = null;
        if (! empty($parametres['schedule_slot_id'])) {
            $slot = ScheduleSlot::with([
                'schedule.groupe.faculte',
                'schedule.anneeUniversitaire',
                'matiere',
                'teacher',
                'room',
            ])->find((int) $parametres['schedule_slot_id']);
        }

        /** @var Group|null $groupe */
        $groupe = null;
        if ($slot !== null && $slot->schedule?->groupe !== null) {
            $groupe = $slot->schedule->groupe;
        } elseif (! empty($parametres['groupe_id'])) {
            $groupe = Group::with('faculte', 'programme', 'niveauRef')->find((int) $parametres['groupe_id']);
        }

        // Scope faculté strict (§26)
        $faculteId = $groupe?->faculte_id ?? $slot?->schedule?->groupe?->faculte_id;
        if ($faculteId !== null && $utilisateur !== null && ! $utilisateur->peutAccederFaculte((int) $faculteId)) {
            abort(404, 'Accès non autorisé à cette faculté.');
        }

        // Matière
        /** @var Matiere|null $matiere */
        $matiere = $slot?->matiere;
        if ($matiere === null && ! empty($parametres['matiere_id'])) {
            $matiere = Matiere::find((int) $parametres['matiere_id']);
        }

        // Année universitaire
        /** @var AnneeUniversitaire|null $annee */
        $annee = $slot?->schedule?->anneeUniversitaire;
        if ($annee === null && ! empty($parametres['annee_universitaire_id'])) {
            $annee = AnneeUniversitaire::find((int) $parametres['annee_universitaire_id']);
        }
        if ($annee === null) {
            $annee = AnneeUniversitaire::where('active', true)->first();
        }

        // Date et horaires
        $date = ! empty($parametres['date']) ? Carbon::parse($parametres['date']) : now();
        $dateFormatted = $date->format('d/m/Y');
        $dateIso = $date->format('Y-m-d');

        $horaire = $parametres['seance'] ?? null;
        if (empty($horaire) && $slot !== null) {
            $horaire = substr((string) $slot->start_time, 0, 5).' - '.substr((string) $slot->end_time, 0, 5);
        }
        if (empty($horaire)) {
            $horaire = 'Séance standard';
        }

        // Enseignant & Salle
        $enseignantNom = $slot?->teacher?->nomComplet() ?? $parametres['enseignant'] ?? 'Enseignant responsable';
        $salleNom = $slot?->room?->nom ?? $slot?->room?->code ?? $parametres['salle'] ?? 'Salle non assignée';

        $estApercu = ! empty($parametres['is_preview']) || (empty($parametres['schedule_slot_id']) && empty($parametres['groupe_id']));

        $groupeNomDemo = null;
        if ($estApercu) {
            $lignes = $this->lignesFictives($template);
            $enseignantNom = $parametres['enseignant'] ?? 'Dr. Khaled BEN SALEM';
            $salleNom = $parametres['salle'] ?? 'Amphi B — Campus Central';
            $groupeNomDemo = $parametres['groupe'] ?? 'L1-DISI-A — Licence Informatique';
        } else {
            // 2. Chargement des étudiants sans N+1 (§36)
            $etudiantsQuery = Etudiant::with(['faculte', 'groupe', 'programmeRef'])
                ->orderBy('nom')
                ->orderBy('prenom');

            if ($groupe !== null) {
                $etudiantsQuery->where('groupe_id', $groupe->id);
            }

            if ($faculteId !== null) {
                $etudiantsQuery->where('faculte_id', $faculteId);
            }

            /** @var Collection<int, Etudiant> $etudiants */
            $etudiants = $etudiantsQuery->get();

            // Récupérer les présences déjà enregistrées pour cette date et séance pour affichage éventuel
            $seanceRef = $slot?->id ? (string) $slot->id : $horaire;
            $presencesExistantes = Presence::whereDate('date', $dateIso)
                ->where('seance', $seanceRef)
                ->whereIn('etudiant_id', $etudiants->pluck('id')->all())
                ->get()
                ->keyBy('etudiant_id');

            // 3. Préparation des lignes d'étudiants
            $lignes = [];
            $index = 0;
            foreach ($etudiants as $etudiant) {
                $index++;
                $presence = $presencesExistantes->get($etudiant->id);

                // Génération QR Code si le gabarit est de type QR
                $qrSvg = null;
                if ($template->type === AttendanceTemplate::TYPE_QR) {
                    $token = $this->qrSecurity->genererToken($etudiant->id, $seanceRef, $dateIso);
                    $qrSvg = QrCodeSvg::generer($token, 60);
                }

                $lignes[] = [
                    'id' => $etudiant->id,
                    'numero' => $index,
                    'matricule' => $etudiant->num_inscription ?? '—',
                    'cin' => (string) $etudiant->cin,
                    'nom' => $etudiant->nom,
                    'prenom' => $etudiant->prenom,
                    'nom_complet' => $etudiant->nom_complet,
                    'poste' => 'Poste '.$index,
                    'piece_identite' => '☐',
                    'heure_arrivee' => $presence?->created_at?->format('H:i') ?? '—',
                    'heure_depart' => '—',
                    'retard' => $presence?->statut === 'retard' ? 'Oui' : 'Non',
                    'presence' => $presence ? ucfirst($presence->statut) : '☐',
                    'signature' => '',
                    'signature_enseignant' => '',
                    'observation' => $presence?->motif ?? '',
                    'qr_code_svg' => $qrSvg,
                ];
            }
        }

        $reference = 'ATT-'.$date->format('Ymd').'-'.strtoupper(Str::random(6));

        // 4. Cartouche d'en-tête institutionnel
        $metadonnees = [
            'institution' => config('app.name', 'Université Internationale'),
            'faculte' => $groupe?->faculte?->nom ?? $groupe?->faculte?->code ?? 'Faculté universitaire',
            'faculte_code' => $groupe?->faculte?->code ?? '',
            'departement' => $groupe?->programme?->departement?->nom ?? 'Département académique',
            'formation' => $groupe?->programme?->nom ?? $groupe?->filiere ?? 'Formation universitaire',
            'niveau' => $groupe?->niveauRef?->libelle ?? $groupe?->niveau ?? 'Niveau d\'études',
            'groupe' => $groupeNomDemo ?? (($groupe?->code ? $groupe->code.' — ' : '').($groupe?->nom ?? 'Groupe non spécifié')),
            'groupe_code' => $groupe?->code ?? '',
            'matiere' => ($matiere?->code ? $matiere->code.' — ' : '').($matiere?->nom ?? $matiere?->libelle ?? 'Matière / ECUE'),
            'matiere_code' => $matiere?->code ?? '',
            'enseignant' => $enseignantNom,
            'salle' => $salleNom,
            'date' => $dateFormatted,
            'date_iso' => $dateIso,
            'horaire' => $horaire,
            'annee_universitaire' => $annee?->libelle ?? $date->format('Y').'-'.($date->format('Y') + 1),
            'reference' => $reference,
            'date_generation' => now()->format('d/m/Y H:i'),
            'total' => count($lignes),
            'type_examen' => $parametres['type_examen'] ?? 'Examen Terminal',
            'session' => $parametres['session'] ?? 'Session Principale',
            'surveillant_1' => $parametres['surveillant_1'] ?? $enseignantNom,
            'surveillant_2' => $parametres['surveillant_2'] ?? 'Surveillant adjoint',
            'titre' => $parametres['titre'] ?? $template->name,
            'options' => $template->configuration['options'] ?? [],
        ];

        return [
            'template' => $template,
            'colonnes' => $template->colonnesActives(),
            'blocs' => $template->blocsOrdonnes(),
            'lignes' => $lignes,
            'metadonnees' => $metadonnees,
            'orientation' => $template->orientation(),
            'paper' => $template->formatPapier(),
            'slot' => $slot,
            'groupe' => $groupe,
        ];
    }

    /**
     * Fournit des données fictives contrôlées sans aucune PII réelle pour l'aperçu (§8).
     *
     * @return array<int, array<string, mixed>>
     */
    public function lignesFictives(AttendanceTemplate $template): array
    {
        $hasQr = $template->type === AttendanceTemplate::TYPE_QR || in_array('qr_code', array_column($template->colonnesActives(), 'key'), true);
        $qrSvg = $hasQr ? QrCodeSvg::generer('DEMO-SAMPLE-ATTENDANCE-QR-TOKEN', 60) : null;

        return [
            [
                'id' => 99901,
                'numero' => 1,
                'matricule' => 'INS-2026-00001',
                'cin' => '11223344',
                'nom' => 'BEN ALI',
                'prenom' => 'Ahmed',
                'nom_complet' => 'Ahmed BEN ALI',
                'poste' => 'Poste 1',
                'piece_identite' => '☑',
                'heure_arrivee' => '08:30',
                'heure_depart' => '10:30',
                'retard' => 'Non',
                'presence' => 'Présent',
                'signature' => 'A. Ben Ali',
                'signature_enseignant' => '',
                'observation' => 'Ponctuel',
                'qr_code_svg' => $qrSvg,
            ],
            [
                'id' => 99902,
                'numero' => 2,
                'matricule' => 'INS-2026-00002',
                'cin' => '11223345',
                'nom' => 'MANAI',
                'prenom' => 'Sonia',
                'nom_complet' => 'Sonia MANAI',
                'poste' => 'Poste 2',
                'piece_identite' => '☑',
                'heure_arrivee' => '08:32',
                'heure_depart' => '10:30',
                'retard' => 'Non',
                'presence' => 'Présent',
                'signature' => 'S. Manai',
                'signature_enseignant' => '',
                'observation' => '',
                'qr_code_svg' => $qrSvg,
            ],
            [
                'id' => 99903,
                'numero' => 3,
                'matricule' => 'INS-2026-00003',
                'cin' => '11223346',
                'nom' => 'TRABELSI',
                'prenom' => 'Mohamed',
                'nom_complet' => 'Mohamed TRABELSI',
                'poste' => 'Poste 3',
                'piece_identite' => '☑',
                'heure_arrivee' => '08:45',
                'heure_depart' => '10:30',
                'retard' => 'Oui (15m)',
                'presence' => 'Retard',
                'signature' => 'M. Trabelsi',
                'signature_enseignant' => '',
                'observation' => 'Retard justifié',
                'qr_code_svg' => $qrSvg,
            ],
            [
                'id' => 99904,
                'numero' => 4,
                'matricule' => 'INS-2026-00004',
                'cin' => '11223347',
                'nom' => 'CHERIF',
                'prenom' => 'Yasmine',
                'nom_complet' => 'Yasmine CHERIF',
                'poste' => 'Poste 4',
                'piece_identite' => '☐',
                'heure_arrivee' => '—',
                'heure_depart' => '—',
                'retard' => 'Non',
                'presence' => 'Absent',
                'signature' => '',
                'signature_enseignant' => '',
                'observation' => 'Absence non justifiée',
                'qr_code_svg' => $qrSvg,
            ],
            [
                'id' => 99905,
                'numero' => 5,
                'matricule' => 'INS-2026-00005',
                'cin' => '11223348',
                'nom' => 'JELASSI',
                'prenom' => 'Karim',
                'nom_complet' => 'Karim JELASSI',
                'poste' => 'Poste 5',
                'piece_identite' => '☑',
                'heure_arrivee' => '08:28',
                'heure_depart' => '10:30',
                'retard' => 'Non',
                'presence' => 'Présent',
                'signature' => 'K. Jelassi',
                'signature_enseignant' => '',
                'observation' => '',
                'qr_code_svg' => $qrSvg,
            ],
        ];
    }

    /**
     * Résout le nom de vue Blade pour le type de gabarit.
     */
    public function resoudreVueBlade(AttendanceTemplate $template): string
    {
        if (! empty($template->configuration['blocks']) || $template->type === AttendanceTemplate::TYPE_CUSTOM) {
            return 'modules.attendance.templates.modular';
        }

        $type = $template->type;
        $vueSpecifique = "modules.attendance.templates.{$type}";

        if (view()->exists($vueSpecifique)) {
            return $vueSpecifique;
        }

        return 'modules.attendance.templates.classic';
    }

    /**
     * Génère le code HTML complet de la fiche de présence.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function rendreHtml(
        AttendanceTemplate $template,
        array $parametres,
        ?User $utilisateur = null
    ): string {
        $contexte = $this->preparerContexte($template, $parametres, $utilisateur);
        $vue = $this->resoudreVueBlade($template);

        return view($vue, $contexte)->render();
    }

    /**
     * Génère le document PDF binaire de la fiche de présence.
     *
     * @param  array<string, mixed>  $parametres
     */
    public function genererPdf(
        AttendanceTemplate $template,
        array $parametres,
        ?User $utilisateur = null
    ): string {
        $html = $this->rendreHtml($template, $parametres, $utilisateur);

        $contenuPdf = $this->pdf->generer($html, [
            'paper' => $template->formatPapier(),
            'orientation' => $template->orientation(),
        ]);

        // Audit transverse §24
        IntegrationLog::tracer(
            'attendance',
            'pdf_generation',
            'ATTENDANCE_SHEET_GENERATED',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            'TMPL-'.$template->code
        );

        return $contenuPdf;
    }
}
