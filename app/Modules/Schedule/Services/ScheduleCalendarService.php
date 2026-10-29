<?php

namespace App\Modules\Schedule\Services;

use App\Models\AnneeUniversitaire;
use App\Modules\Calendar\Models\EvenementCalendrier;
use App\Modules\Calendar\Services\JoursOuvrables;
use App\Modules\Schedule\Models\AcademicWeek;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\TimeSlot;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Service de gestion du calendrier pédagogique, de la grille horaire et des ressources temporelles.
 * Assure la cohérence des semaines pédagogiques, des créneaux types et des contraintes d'ouverture.
 */
class ScheduleCalendarService
{
    public function __construct(
        private readonly ?JoursOuvrables $joursOuvrables = null,
    ) {}

    /**
     * Fuseau horaire institutionnel.
     */
    public function fuseauHoraire(): string
    {
        return config('app.timezone', 'Africa/Tunis');
    }

    /**
     * Grille horaire standardisée pour une faculté donnée (ou commune).
     *
     * @return EloquentCollection<int, TimeSlot>
     */
    public function grilleHoraire(?int $faculteId = null, ?string $jour = null): EloquentCollection
    {
        return TimeSlot::query()
            ->actif()
            ->pourFaculte($faculteId)
            ->pourJour($jour)
            ->orderBy('ordre')
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Liste des pauses obligatoires pour un jour et une faculté.
     *
     * @return EloquentCollection<int, TimeSlot>
     */
    public function pausesObligatoires(?int $faculteId = null, ?string $jour = null): EloquentCollection
    {
        return TimeSlot::query()
            ->actif()
            ->pauses()
            ->pourFaculte($faculteId)
            ->pourJour($jour)
            ->orderBy('start_time')
            ->get();
    }

    /**
     * Vérifie si un créneau horaire chevauche une pause obligatoire.
     *
     * @return array<int, string> Messages d'erreur si violation
     */
    public function verifierPausesObligatoires(
        string $debut,
        string $fin,
        ?string $jour = null,
        ?int $faculteId = null
    ): array {
        if ($fin <= $debut) {
            return ['L\'heure de fin doit être strictement postérieure à l\'heure de début.'];
        }

        $pauses = $this->pausesObligatoires($faculteId, $jour);
        $violations = [];

        foreach ($pauses as $pause) {
            if ($pause->chevauche($debut, $fin)) {
                $libelle = $pause->nom ?: $pause->code;
                $violations[] = "Le créneau {$debut}–{$fin} chevauche une pause obligatoire : {$libelle} ({$pause->start_time}–{$pause->end_time}).";
            }
        }

        return $violations;
    }

    /**
     * Vérifie si un créneau respecte les heures d'ouverture de la salle ou du bâtiment.
     *
     * @return array<int, string>
     */
    public function verifierHorairesSalle(?int $roomId, string $debut, string $fin): array
    {
        if ($roomId === null) {
            return [];
        }

        /** @var Room|null $salle */
        $salle = Room::with('batimentRelation')->find($roomId);
        if ($salle === null) {
            return [];
        }

        $erreurs = [];

        if (! $salle->estOuvertePlage($debut, $fin)) {
            $erreurs[] = "La salle {$salle->code} n'est pas ouverte sur la plage demandée (horaires : {$salle->heure_ouverture}–{$salle->heure_fermeture}).";
        }

        return $erreurs;
    }

    /**
     * Retourne les semaines pédagogiques d'un semestre pour une année donnée.
     *
     * @return EloquentCollection<int, AcademicWeek>
     */
    public function semainesPour(int $anneeId, int $semestre): EloquentCollection
    {
        return AcademicWeek::query()
            ->pourAnnee($anneeId)
            ->pourSemestre($semestre)
            ->orderBy('numero_semaine')
            ->get();
    }

    /**
     * Retrouve la semaine académique correspondant à une date précise.
     */
    public function semainePourDate(string $date, ?int $anneeId = null): ?AcademicWeek
    {
        $d = Carbon::parse($date)->format('Y-m-d');

        $query = AcademicWeek::query()
            ->whereDate('date_debut', '<=', $d)
            ->whereDate('date_fin', '>=', $d);

        if ($anneeId !== null) {
            $query->where('annee_universitaire_id', $anneeId);
        }

        return $query->first();
    }

    /**
     * Détermine si une date est un jour ouvrable pédagogique (non-férié, hors vacances).
     */
    public function estJourOuvrable(string $date, ?int $faculteId = null): bool
    {
        $jour = Carbon::parse($date);

        // Week-end (samedi/dimanche) exclu
        if ($jour->isWeekend()) {
            return false;
        }

        // Vérification via le service JoursOuvrables si disponible
        if ($this->joursOuvrables !== null) {
            return $this->joursOuvrables->estOuvre($date);
        }

        // Vérification directe sur EvenementCalendrier si module Calendar présent
        $evenementFerie = EvenementCalendrier::where('statut', 'publie')
            ->whereIn('type', ['ferie', 'vacances'])
            ->whereDate('date_debut', '<=', $date)
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $date))
            ->when($faculteId !== null, fn ($q) => $q->where(function ($sub) use ($faculteId) {
                $sub->whereNull('faculte_id')->orWhere('faculte_id', $faculteId);
            }))
            ->exists();

        return ! $evenementFerie;
    }

    /**
     * Génère un découpage automatique de semaines académiques pour un semestre.
     * Gère les semestres débutant en milieu de semaine (semaines partielles).
     *
     * @param  array<int, int>  $semainesVacances  Numéros de semaines déclarées comme vacances
     * @param  array<int, int>  $semainesExamens  Numéros de semaines déclarées comme examens
     * @return Collection<int, AcademicWeek>
     */
    public function genererSemainesAutomatiques(
        AnneeUniversitaire $annee,
        int $semestre,
        string $dateDebut,
        int $nbSemaines = 14,
        ?int $semestreLmdId = null,
        array $semainesVacances = [],
        array $semainesExamens = []
    ): Collection {
        if ($nbSemaines < 1 || $nbSemaines > 30) {
            throw new InvalidArgumentException('Le nombre de semaines doit être compris entre 1 et 30.');
        }

        $dateCourante = Carbon::parse($dateDebut);
        $semainesCrees = collect();

        for ($i = 1; $i <= $nbSemaines; $i++) {
            $debutSemaine = $dateCourante->copy();
            // Fin de la semaine = dimanche de la même semaine
            $finSemaine = $dateCourante->copy()->endOfWeek(Carbon::SUNDAY);

            $type = 'cours';
            if (in_array($i, $semainesVacances, true)) {
                $type = 'vacances';
            } elseif (in_array($i, $semainesExamens, true)) {
                $type = 'examens';
            }

            $parite = ($i % 2 === 0) ? 'paire' : 'impaire';

            /** @var AcademicWeek $semaine */
            $semaine = AcademicWeek::updateOrCreate(
                [
                    'annee_universitaire_id' => $annee->id,
                    'semestre' => $semestre,
                    'numero_semaine' => $i,
                ],
                [
                    'semestre_lmd_id' => $semestreLmdId,
                    'code' => sprintf('S%02d', $i),
                    'nom' => "Semaine {$i}".($type !== 'cours' ? " ({$type})" : ''),
                    'date_debut' => $debutSemaine->format('Y-m-d'),
                    'date_fin' => $finSemaine->format('Y-m-d'),
                    'type' => $type,
                    'parite' => $parite,
                    'actif' => true,
                ]
            );

            $semainesCrees->push($semaine);

            // Passer au lundi suivant pour la prochaine semaine
            $dateCourante = $finSemaine->copy()->addDay()->startOfDay();
        }

        return $semainesCrees;
    }
}
