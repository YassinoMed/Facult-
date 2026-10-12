<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Export des épreuves (§31) : lecture seule, périmètre faculté appliqué
 * par le contrôleur DataExchange (scopes existants).
 *
 * Import (Phase 5 §28) : examen (libellé + session) + ECUE + créneau,
 * création via EpreuveService (conflits bloquants, session verrouillée
 * refusés en rapport d'erreurs, jamais en 500).
 */
class EpreuveDefinition implements ExportDefinitionInterface, ImportDefinitionInterface
{
    public function entite(): string
    {
        return 'epreuves';
    }

    public function libelle(): string
    {
        return 'Épreuves';
    }

    public function colonnes(): array
    {
        return ['examen', 'session', 'ecue', 'date', 'horaire', 'salle', 'groupe', 'statut'];
    }

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Epreuve::with(['examen', 'session', 'matiere', 'salle', 'groupe'])->orderBy('date');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('matiere', fn ($q) => $q->where('code', 'like', $motif)->orWhere('nom', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'examen' => $modele->examen?->libelle,
            'session' => $modele->session?->nom,
            'ecue' => $modele->matiere?->code,
            'date' => $modele->date?->format('Y-m-d'),
            'horaire' => substr((string) $modele->heure_debut, 0, 5).'-'.substr((string) $modele->heure_fin, 0, 5),
            'salle' => $modele->salle?->code,
            'groupe' => $modele->groupe?->code,
            'statut' => $modele->statut,
        ];
    }

    public function colonnesRequises(): array
    {
        // Import aligné sur les colonnes d'export (un seul contrat) :
        // `horaire` au format d'export « HH:MM-HH:MM », `session`
        // acceptée en code puis en nom.
        return ['examen', 'session', 'ecue', 'date', 'horaire'];
    }

    public function regles(): array
    {
        return [
            'examen' => ['required', 'string', 'max:150'],
            'session' => ['required', 'string', 'max:30'],
            'ecue' => ['required', 'string', 'max:30'],
            'date' => ['required', 'date'],
            'horaire' => ['required', 'regex:/^\d{2}:\d{2}-\d{2}:\d{2}$/'],
            'salle' => ['nullable', 'string', 'max:30'],
            'groupe' => ['nullable', 'string', 'max:20'],
            'type_evaluation' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function cleDoublon(): array
    {
        return ['examen', 'session', 'ecue', 'date', 'horaire'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $resolution = $this->resoudre($ligne);

        if ($resolution === null) {
            return null;
        }

        return Epreuve::where('examen_id', $resolution['examen']->id)
            ->where('matiere_id', $resolution['matiere']->id)
            ->whereDate('date', $ligne['date'])
            ->where('heure_debut', $resolution['debut'])
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $resolution = $this->resoudre($ligne);

        if ($resolution === null) {
            throw new \DomainException('Session, examen, matière, salle ou groupe introuvable.');
        }

        if ($this->trouverExistant($ligne) !== null) {
            return "déjà planifiée : {$ligne['ecue']} {$ligne['date']}";
        }

        // Faculte de l'auteur : refus hors périmètre (miroir
        // EvaluationPolicy : programme puis groupe de l'examen).
        $auteur = $auteurId !== null ? User::find($auteurId) : null;
        $faculteId = $resolution['examen']->programme?->faculte_id
            ?? $resolution['examen']->groupe?->faculte_id;

        if ($auteur !== null && $faculteId !== null && $auteur->faculteRestreinteId() !== null
            && ! $auteur->peutAccederFaculte((int) $faculteId)) {
            throw new \DomainException('Examen hors périmètre.');
        }

        app(EpreuveService::class)->creer($resolution['examen'], [
            'session_id' => $resolution['session']->id,
            'matiere_id' => $resolution['matiere']->id,
            'groupe_id' => $resolution['groupe']?->id,
            'salle_id' => $resolution['salle']?->id,
            'date' => $ligne['date'],
            'heure_debut' => $resolution['debut'],
            'heure_fin' => $resolution['fin'],
            'type_evaluation' => $ligne['type_evaluation'] ?? null,
        ]);

        return "planifiée : {$ligne['ecue']} {$ligne['date']}";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void {}

    /** @return array{session: SessionExamen, examen: Examen, matiere: Matiere, salle: ?Room, groupe: ?Group, debut: string, fin: string}|null */
    private function resoudre(array $ligne): ?array
    {
        $cleSession = mb_strtoupper(trim((string) ($ligne['session'] ?? '')));
        $session = SessionExamen::where('code', $cleSession)
            ->orWhere('nom', trim((string) ($ligne['session'] ?? '')))
            ->first();

        if ($session === null) {
            return null;
        }

        $examen = Examen::where('session_id', $session->id)
            ->where('libelle', trim((string) ($ligne['examen'] ?? '')))
            ->first();

        $matiere = Matiere::where('code', mb_strtoupper(trim((string) ($ligne['ecue'] ?? ''))))->first();

        if ($examen === null || $matiere === null) {
            return null;
        }

        [$debut, $fin] = explode('-', (string) ($ligne['horaire'] ?? ''), 2) + [null, null];

        if ($debut === null || $fin === null || $fin <= $debut) {
            return null;
        }

        $salle = ! empty($ligne['salle'])
            ? Room::where('code', mb_strtoupper(trim((string) $ligne['salle'])))->first()
            : null;

        $groupe = ! empty($ligne['groupe'])
            ? Group::where('code', mb_strtoupper(trim((string) $ligne['groupe'])))->first()
            : null;

        if (! empty($ligne['salle']) && $salle === null) {
            return null;
        }

        if (! empty($ligne['groupe']) && $groupe === null) {
            return null;
        }

        return ['session' => $session, 'examen' => $examen, 'matiere' => $matiere, 'salle' => $salle, 'groupe' => $groupe, 'debut' => $debut, 'fin' => $fin];
    }
}
