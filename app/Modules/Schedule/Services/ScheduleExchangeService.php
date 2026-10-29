<?php

namespace App\Modules\Schedule\Services;

use App\Models\Matiere;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleChange;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Service d'import, export et interopérabilité pour les emplois du temps (Phase 9).
 * Prise en charge iCalendar (RFC 5545), CSV/JSON, et import sécurisé avec simulation (dry-run).
 */
class ScheduleExchangeService
{
    public const JOURS_EN_TO_NUM = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    public function __construct(
        protected ScheduleConflictService $conflictService
    ) {}

    /**
     * Génère un flux iCalendar standard (.ics conforme RFC 5545) pour un emploi du temps,
     * filtrable optionnellement par enseignant ou par groupe.
     *
     * @param array{
     *     teacher_id?: int,
     *     sous_groupe_id?: int,
     *     date_debut?: string,
     *     date_fin?: string
     * } $options
     */
    public function exporterIcs(Schedule $schedule, array $options = []): string
    {
        $query = $schedule->slots()->with(['matiere', 'teacher', 'room', 'teachersPivot']);

        if (! empty($options['teacher_id'])) {
            $tId = (int) $options['teacher_id'];
            $query->where(function ($q) use ($tId) {
                $q->where('teacher_id', $tId)
                    ->orWhereHas('teachersPivot', fn ($p) => $p->where('teacher_id', $tId));
            });
        }

        if (! empty($options['sous_groupe_id'])) {
            $query->where('sous_groupe_id', (int) $options['sous_groupe_id']);
        }

        $slots = $query->get();

        $dateDebutSemestre = Carbon::parse($options['date_debut'] ?? $schedule->starts_at ?? $schedule->anneeUniversitaire?->date_debut ?? now()->startOfWeek());
        $dateFinSemestre = Carbon::parse($options['date_fin'] ?? $schedule->ends_at ?? $schedule->anneeUniversitaire?->date_fin ?? $dateDebutSemestre->copy()->addWeeks(14));

        $ics = [];
        $ics[] = 'BEGIN:VCALENDAR';
        $ics[] = 'VERSION:2.0';
        $ics[] = 'PRODID:-//UniRegistre//Smart Timetables Engine//FR';
        $ics[] = 'CALSCALE:GREGORIAN';
        $ics[] = 'METHOD:PUBLISH';
        $ics[] = 'X-WR-CALNAME:'.self::echapperIcs($schedule->nom);
        $ics[] = 'X-WR-TIMEZONE:Africa/Tunis';

        foreach ($slots as $slot) {
            $jourNum = self::JOURS_EN_TO_NUM[$slot->day_of_week] ?? 1;
            $premierJour = $dateDebutSemestre->copy()->next($jourNum);
            if ($dateDebutSemestre->dayOfWeekIso === $jourNum) {
                $premierJour = $dateDebutSemestre->copy();
            }

            $hDeb = explode(':', $slot->start_time);
            $hFin = explode(':', $slot->end_time);

            $dtStart = $premierJour->copy()->setTime((int) $hDeb[0], (int) $hDeb[1], 0);
            $dtEnd = $premierJour->copy()->setTime((int) $hFin[0], (int) $hFin[1], 0);

            $uid = "slot-{$slot->id}-{$schedule->id}@uniregistre.tn";
            $matiereNom = $slot->matiere?->nom ?? 'Séance';
            $typeLabel = strtoupper($slot->type);
            $summary = "{$matiereNom} [{$typeLabel}]";

            $loc = 'Non assignée';
            if ($slot->room) {
                $loc = "Salle {$slot->room->nom}".($slot->room->batiment ? " ({$slot->room->batiment})" : '');
            }

            $profNom = $slot->teacher ? $slot->teacher->nomComplet() : 'Non assigné';
            $description = "Matière : {$matiereNom}\\nEnseignant : {$profNom}\\nType : {$typeLabel}\\nPlanning : {$schedule->nom}";

            $untilStr = $dateFinSemestre->copy()->setTime(23, 59, 59)->format('Ymd\THis\Z');

            $interval = 1;
            if ($slot->semaine_parite === 'paire' || $slot->semaine_parite === 'impaire') {
                $interval = 2;
            }

            $ics[] = 'BEGIN:VEVENT';
            $ics[] = 'UID:'.$uid;
            $ics[] = 'DTSTAMP:'.gmdate('Ymd\THis\Z');
            $ics[] = 'DTSTART:'.$dtStart->format('Ymd\THis');
            $ics[] = 'DTEND:'.$dtEnd->format('Ymd\THis');
            $ics[] = "RRULE:FREQ=WEEKLY;INTERVAL={$interval};UNTIL=".$untilStr;
            $ics[] = 'SUMMARY:'.self::echapperIcs($summary);
            $ics[] = 'LOCATION:'.self::echapperIcs($loc);
            $ics[] = 'DESCRIPTION:'.self::echapperIcs($description);
            $ics[] = 'STATUS:CONFIRMED';
            $ics[] = 'END:VEVENT';
        }

        $ics[] = 'END:VCALENDAR';

        return implode("\r\n", $ics);
    }

    /**
     * Exporte un planning au format CSV tabulaire standard.
     */
    public function exporterCsv(Schedule $schedule): string
    {
        $slots = $schedule->slots()->with(['matiere', 'teacher', 'room'])->get();

        $output = fopen('php://temp', 'r+');
        fputcsv($output, [
            'id',
            'jour',
            'heure_debut',
            'heure_fin',
            'matiere_code',
            'matiere_nom',
            'type',
            'enseignant_matricule',
            'enseignant_nom',
            'salle_code',
            'salle_nom',
            'parite',
            'notes',
        ], ';');

        foreach ($slots as $slot) {
            fputcsv($output, [
                $slot->id,
                $slot->day_of_week,
                substr($slot->start_time, 0, 5),
                substr($slot->end_time, 0, 5),
                $slot->matiere?->code ?? '',
                $slot->matiere?->nom ?? '',
                $slot->type,
                $slot->teacher?->matricule ?? '',
                $slot->teacher ? $slot->teacher->nomComplet() : '',
                $slot->room?->code ?? '',
                $slot->room?->nom ?? '',
                $slot->semaine_parite ?? 'toutes',
                $slot->notes ?? '',
            ], ';');
        }

        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent ?: '';
    }

    /**
     * Exporte un planning au format JSON complet avec métadonnées et créneaux.
     *
     * @return array<string, mixed>
     */
    public function exporterJson(Schedule $schedule): array
    {
        $schedule->load(['groupe', 'anneeUniversitaire', 'slots.matiere', 'slots.teacher', 'slots.room']);

        $creneaux = [];
        foreach ($schedule->slots as $slot) {
            $creneaux[] = [
                'id' => $slot->id,
                'jour' => $slot->day_of_week,
                'debut' => substr($slot->start_time, 0, 5),
                'fin' => substr($slot->end_time, 0, 5),
                'type' => $slot->type,
                'parite' => $slot->semaine_parite,
                'matiere' => [
                    'id' => $slot->matiere_id,
                    'code' => $slot->matiere?->code,
                    'nom' => $slot->matiere?->nom,
                ],
                'enseignant' => $slot->teacher ? [
                    'id' => $slot->teacher->id,
                    'matricule' => $slot->teacher->matricule,
                    'nom' => $slot->teacher->nomComplet(),
                ] : null,
                'salle' => $slot->room ? [
                    'id' => $slot->room->id,
                    'code' => $slot->room->code,
                    'nom' => $slot->room->nom,
                    'batiment' => $slot->room->batiment,
                ] : null,
                'notes' => $slot->notes,
            ];
        }

        return [
            'version_moteur' => 'SmartTimetables-1.0',
            'exporte_le' => now()->toIso8601String(),
            'planning' => [
                'id' => $schedule->id,
                'nom' => $schedule->nom,
                'statut' => $schedule->statut,
                'semestre' => $schedule->semestre,
                'groupe' => $schedule->groupe?->nom,
                'annee_universitaire' => $schedule->anneeUniversitaire?->libelle ?? $schedule->anneeUniversitaire?->code,
            ],
            'nb_creneaux' => count($creneaux),
            'creneaux' => $creneaux,
        ];
    }

    /**
     * Parse et valide un ensemble de données de créneaux (CSV ou Array) avec mode simulation (dry-run).
     *
     * @param  string|array<int, mixed>  $donnees
     * @return array{
     *     succes: bool,
     *     nb_lignes: int,
     *     nb_valides: int,
     *     nb_erreurs: int,
     *     erreurs: array<int, string>,
     *     avertissements: array<int, string>,
     *     lignes_preparees: array<int, array<string, mixed>>
     * }
     */
    public function validerOuSimulerImport(Schedule $schedule, string|array $donnees): array
    {
        $lignes = is_string($donnees) ? $this->parserCsvVersArray($donnees) : $donnees;

        $erreurs = [];
        $avertissements = [];
        $lignesPreparees = [];

        foreach ($lignes as $idx => $raw) {
            $numLigne = $idx + 1;

            // 1. Validation du jour
            $jour = strtolower(trim((string) ($raw['jour'] ?? $raw['day_of_week'] ?? '')));
            if (! in_array($jour, ScheduleSlot::JOURS, true)) {
                $erreurs[] = "Ligne #{$numLigne} : Jour invalide '{$jour}' (valeurs attendues: ".implode(', ', ScheduleSlot::JOURS).').';

                continue;
            }

            // 2. Validation des heures
            $debut = trim((string) ($raw['heure_debut'] ?? $raw['start_time'] ?? ''));
            $fin = trim((string) ($raw['heure_fin'] ?? $raw['end_time'] ?? ''));

            if (strlen($debut) === 5) {
                $debut .= ':00';
            }
            if (strlen($fin) === 5) {
                $fin .= ':00';
            }

            if ($debut >= $fin) {
                $erreurs[] = "Ligne #{$numLigne} : L'heure de début ({$debut}) doit précéder l'heure de fin ({$fin}).";

                continue;
            }

            // 3. Résolution de la matière
            $codeMatiere = trim((string) ($raw['matiere_code'] ?? $raw['code_matiere'] ?? ''));
            $idMatiere = $raw['matiere_id'] ?? null;
            $matiere = null;

            if ($idMatiere) {
                $matiere = Matiere::find($idMatiere);
            } elseif ($codeMatiere !== '') {
                $matiere = Matiere::where('code', $codeMatiere)->first();
            }

            if (! $matiere) {
                $erreurs[] = "Ligne #{$numLigne} : Matière introuvable (Code: '{$codeMatiere}', ID: '{$idMatiere}').";

                continue;
            }

            // 4. Résolution de l'enseignant (optionnel)
            $matProf = trim((string) ($raw['enseignant_matricule'] ?? $raw['prof_matricule'] ?? ''));
            $idProf = $raw['teacher_id'] ?? null;
            $prof = null;

            if ($idProf) {
                $prof = Teacher::find($idProf);
            } elseif ($matProf !== '') {
                $prof = Teacher::where('matricule', $matProf)->first();
            }

            if (($idProf || $matProf !== '') && ! $prof) {
                $avertissements[] = "Ligne #{$numLigne} : Enseignant non trouvé ('{$matProf}'), le cours sera importé sans enseignant assigné.";
            }

            // 5. Résolution de la salle (optionnel)
            $codeSalle = trim((string) ($raw['salle_code'] ?? $raw['code_salle'] ?? ''));
            $idSalle = $raw['room_id'] ?? null;
            $salle = null;

            if ($idSalle) {
                $salle = Room::find($idSalle);
            } elseif ($codeSalle !== '') {
                $salle = Room::where('code', $codeSalle)->first();
            }

            if (($idSalle || $codeSalle !== '') && ! $salle) {
                $avertissements[] = "Ligne #{$numLigne} : Salle non trouvée ('{$codeSalle}'), le cours sera importé sans salle assignée.";
            }

            $type = strtolower(trim((string) ($raw['type'] ?? 'course')));
            if (! in_array($type, ScheduleSlot::TYPES, true)) {
                $type = 'course';
            }

            $parite = strtolower(trim((string) ($raw['parite'] ?? $raw['semaine_parite'] ?? 'toutes')));
            if (! in_array($parite, ScheduleSlot::PARITES, true)) {
                $parite = 'toutes';
            }

            $lignesPreparees[] = [
                'schedule_id' => $schedule->id,
                'matiere_id' => $matiere->id,
                'teacher_id' => $prof?->id,
                'room_id' => $salle?->id,
                'day_of_week' => $jour,
                'start_time' => $debut,
                'end_time' => $fin,
                'type' => $type,
                'semaine_parite' => $parite,
                'status' => 'scheduled',
                'notes' => $raw['notes'] ?? null,
            ];
        }

        $nbTotal = count($lignes);
        $nbValides = count($lignesPreparees);
        $nbErreurs = count($erreurs);

        return [
            'succes' => $nbErreurs === 0,
            'nb_lignes' => $nbTotal,
            'nb_valides' => $nbValides,
            'nb_erreurs' => $nbErreurs,
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'lignes_preparees' => $lignesPreparees,
        ];
    }

    /**
     * Importe définitivement les créneaux validés dans le planning.
     * Rejette l'opération si le planning est verrouillé ou s'il y a des erreurs bloquantes.
     *
     * @param  string|array<int, mixed>  $donnees
     * @return array{nb_importes: int, schedule: Schedule}
     *
     * @throws DomainException
     */
    public function importerCreneaux(
        Schedule $schedule,
        string|array $donnees,
        bool $ecraserExistant = false,
        ?int $userId = null,
        ?string $motif = null
    ): array {
        if ($schedule->estVerrouille()) {
            throw new DomainException("Impossible d'importer des créneaux dans un planning verrouillé ou archivé.");
        }

        $analyse = $this->validerOuSimulerImport($schedule, $donnees);

        if (! $analyse['succes']) {
            throw new DomainException('Importation annulée : '.count($analyse['erreurs']).' erreur(s) détectée(s) : '.implode(' | ', $analyse['erreurs']));
        }

        return DB::transaction(function () use ($schedule, $analyse, $ecraserExistant, $userId, $motif) {
            if ($ecraserExistant) {
                $schedule->slots()->delete();
            }

            $crees = [];
            foreach ($analyse['lignes_preparees'] as $data) {
                $slot = ScheduleSlot::create($data);
                $crees[] = $slot;

                ScheduleChange::create([
                    'schedule_slot_id' => $slot->id,
                    'changed_by' => $userId,
                    'change_type' => 'created',
                    'old_values' => null,
                    'new_values' => $data,
                    'reason' => $motif ?? 'Importation de créneaux (ScheduleExchangeService)',
                ]);
            }

            return [
                'nb_importes' => count($crees),
                'schedule' => $schedule->fresh(['slots.matiere', 'slots.room', 'slots.teacher']),
            ];
        });
    }

    /**
     * Helper pour parser une chaîne CSV en tableau associatif.
     *
     * @return array<int, array<string, string>>
     */
    protected function parserCsvVersArray(string $csvContent): array
    {
        $lignes = [];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        $headers = null;
        while (($row = fgetcsv($stream, 0, ';')) !== false) {
            if ($headers === null) {
                $headers = array_map('trim', $row);

                continue;
            }

            if (empty(array_filter($row))) {
                continue;
            }

            $item = [];
            foreach ($headers as $i => $h) {
                $item[$h] = $row[$i] ?? '';
            }
            $lignes[] = $item;
        }

        fclose($stream);

        return $lignes;
    }

    protected static function echapperIcs(string $texte): string
    {
        $texte = str_replace('\\', '\\\\', $texte);
        $texte = str_replace(';', '\;', $texte);
        $texte = str_replace(',', '\,', $texte);
        $texte = str_replace("\n", '\n', $texte);

        return $texte;
    }
}
