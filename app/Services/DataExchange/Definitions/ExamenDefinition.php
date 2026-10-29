<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des examens (Lot 19, §27).
 *
 * Dépendances : Sessions d'examen, Années universitaires, Programmes.
 * Clé métier : `session` + `libelle` ou `code` / `evaluation_code`.
 */
class ExamenDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'examens';
    }

    public function libelle(): string
    {
        return 'Examens';
    }

    public function colonnes(): array
    {
        return [
            'libelle', 'session', 'annee', 'programme', 'niveau', 'type', 'statut', 'description',
            'code', 'evaluation_code', 'date', 'heure_debut', 'heure_fin', 'salle_code', 'groupe_code', 'ecue',
        ];
    }

    public function colonnesRequises(): array
    {
        return [];
    }

    public function aliasesColonnes(): array
    {
        return [
            'libelle' => ['nom', 'titre', 'intitule'],
            'session' => ['code_session', 'session_examen'],
            'annee' => ['annee_universitaire', 'session_annee'],
            'programme' => ['parcours', 'filiere'],
            'evaluation_code' => ['evaluation', 'code_evaluation'],
            'salle_code' => ['salle', 'code_salle', 'room_code'],
            'groupe_code' => ['groupe', 'code_groupe', 'group_code'],
            'ecue' => ['ecue_code', 'matiere', 'code_matiere', 'code_ecue'],
        ];
    }

    public function regles(): array
    {
        return [
            'libelle' => ['nullable', 'string', 'max:150'],
            'session' => ['nullable', 'string', 'max:30'],
            'annee' => ['nullable'],
            'programme' => ['nullable', 'string', 'max:30'],
            'niveau' => ['nullable', 'string', 'max:10'],
            'type' => ['nullable'],
            'statut' => ['nullable'],
            'description' => ['nullable', 'string', 'max:1000'],
            'code' => ['nullable', 'string', 'max:50'],
            'evaluation_code' => ['nullable', 'string', 'max:50'],
            'date' => ['nullable', 'date'],
            'heure_debut' => ['nullable'],
            'heure_fin' => ['nullable'],
            'salle_code' => ['nullable', 'string', 'max:50'],
            'groupe_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function resoudreSession(string $code): ?SessionExamen
    {
        return SessionExamen::where('code', mb_strtoupper(trim($code)))->first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        if (! empty($ligne['evaluation_code'])) {
            $examen = Examen::where('code', trim((string) $ligne['evaluation_code']))->first();
            if ($examen === null) {
                $erreurs[] = [
                    'champ' => 'evaluation_code',
                    'message' => "L'évaluation « {$ligne['evaluation_code']} » est introuvable.",
                    'code' => 'EVALUATION_NOT_FOUND',
                ];
            }

            // Audit 08/10/2026 : fin du repli « Matiere::first() » (une
            // matière ALÉATOIRE était attachée à l'épreuve) — l'ECUE
            // doit être explicite et résoluble si l'épreuve est nouvelle.
            $codeEpreuve = trim((string) ($ligne['code'] ?? ''));
            $epreuveExistante = $codeEpreuve !== ''
                ? (Epreuve::where('code', $codeEpreuve)->first() ?? Epreuve::where('examen_id', $examen?->id)->first())
                : Epreuve::where('examen_id', $examen?->id)->first();

            if ($epreuveExistante === null && Matiere::resoudreParCode($ligne['ecue'] ?? null) === null) {
                $erreurs[] = [
                    'champ' => 'ecue',
                    'message' => 'ECUE requise (colonne « ecue », code matière) pour planifier une nouvelle épreuve.',
                    'code' => 'ECUE_INTROUVABLE',
                ];
            }

            return $erreurs;
        }

        $session = $this->resoudreSession($ligne['session'] ?? '');

        if ($session === null) {
            $erreurs[] = [
                'champ' => 'session',
                'message' => "La session d'examen « {$ligne['session']} » est introuvable.",
                'code' => 'SESSION_NOT_FOUND',
            ];
        }

        if (! empty($ligne['programme'])) {
            $programme = Programme::where('code', mb_strtoupper(trim((string) $ligne['programme'])))->first();
            if ($programme === null) {
                $erreurs[] = [
                    'champ' => 'programme',
                    'message' => "Le programme « {$ligne['programme']} » est introuvable.",
                    'code' => 'PROGRAMME_NOT_FOUND',
                ];
            } elseif ($auteurId !== null) {
                $auteur = User::find($auteurId);
                $restreinteId = $auteur?->faculteRestreinteId();
                if ($restreinteId !== null && (int) $programme->faculte_id !== $restreinteId) {
                    $erreurs[] = [
                        'champ' => 'programme',
                        'message' => "Le programme « {$ligne['programme']} » est hors de votre périmètre faculté.",
                        'code' => 'FACULTY_SCOPE_VIOLATION',
                    ];
                }
            }
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        if (! empty($ligne['code'])) {
            return mb_strtoupper(trim((string) $ligne['code']));
        }

        $session = mb_strtoupper(trim((string) ($ligne['session'] ?? '')));
        $libelle = mb_strtolower(trim((string) ($ligne['libelle'] ?? '')));

        return "{$session}|{$libelle}";
    }

    public function cleDoublon(): array
    {
        return ['code', 'session', 'libelle'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        if (! empty($ligne['code'])) {
            $exCode = Examen::where('code', trim((string) $ligne['code']))->first();
            if ($exCode !== null) {
                return $exCode;
            }
            $epCode = Epreuve::where('code', trim((string) $ligne['code']))->first();
            if ($epCode !== null) {
                return $epCode;
            }
        }

        $session = $this->resoudreSession($ligne['session'] ?? '');
        if ($session === null) {
            return null;
        }

        return Examen::where('session_id', $session->id)
            ->where('libelle', trim((string) ($ligne['libelle'] ?? '')))
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        if (! empty($ligne['evaluation_code'])) {
            $code = trim((string) ($ligne['code'] ?? ''));
            $examen = Examen::where('code', trim((string) $ligne['evaluation_code']))->first();

            if ($examen === null) {
                throw IoException::malforme("Évaluation {$ligne['evaluation_code']} introuvable.");
            }

            $salle = ! empty($ligne['salle_code'])
                ? Room::where('code', trim((string) $ligne['salle_code']))->first()
                : null;

            $groupe = ! empty($ligne['groupe_code'])
                ? Group::where('code', trim((string) $ligne['groupe_code']))->first()
                : null;

            $debut = ! empty($ligne['heure_debut']) ? substr(trim((string) $ligne['heure_debut']), 0, 5) : '08:00';
            $fin = ! empty($ligne['heure_fin']) ? substr(trim((string) $ligne['heure_fin']), 0, 5) : '10:00';

            $epreuveExistante = Epreuve::where('code', $code)->first() ?? Epreuve::where('examen_id', $examen->id)->first();

            // Audit 08/10/2026 : fin du repli « Matiere::first() » (une
            // matière ALÉATOIRE était attachée à toute nouvelle épreuve).
            $matiereId = $epreuveExistante?->matiere_id ?? Matiere::resoudreParCode($ligne['ecue'] ?? null)?->id;

            if ($matiereId === null) {
                throw IoException::malforme("ECUE requise (colonne « ecue », code matière) pour planifier l'épreuve de l'évaluation {$ligne['evaluation_code']} (ligne {$ligne['ligne']}).");
            }

            $epreuve = Epreuve::updateOrCreate([
                'code' => $code ?: "EXAM-{$examen->id}",
            ], [
                'examen_id' => $examen->id,
                'session_id' => $examen->session_id,
                'annee_universitaire_id' => $examen->annee_universitaire_id,
                'matiere_id' => $matiereId,
                'salle_id' => $salle?->id,
                'groupe_id' => $groupe?->id,
                'date' => $ligne['date'] ?? ($epreuveExistante?->date ?? now()->toDateString()),
                'heure_debut' => $debut.':00',
                'heure_fin' => $fin.':00',
                'statut' => 'scheduled',
            ]);

            return "examen/épreuve planifié : {$epreuve->code}";
        }

        $session = $this->resoudreSession($ligne['session'] ?? '');
        if ($session === null) {
            throw IoException::malforme("Session « {$ligne['session']} » introuvable (ligne {$ligne['ligne']}).");
        }

        $programmeId = null;
        if (! empty($ligne['programme'])) {
            $p = Programme::where('code', mb_strtoupper(trim((string) $ligne['programme'])))->first();
            $programmeId = $p?->id;
        }

        $niveauId = null;
        if (! empty($ligne['niveau'])) {
            $n = $programmeId !== null
                ? Niveau::resoudreDansProgramme($ligne['niveau'], $programmeId)
                : Niveau::resoudreCodeUnique($ligne['niveau']);

            if ($n === null) {
                throw IoException::malforme(
                    $programmeId !== null
                        ? "Niveau « {$ligne['niveau']} » inexistant pour ce programme (ligne {$ligne['ligne']})."
                        : "Niveau « {$ligne['niveau']} » ambigu : programme requis (ligne {$ligne['ligne']})."
                );
            }

            $niveauId = $n->id;
        }

        $anneeId = $session->annee_universitaire_id;
        if (! empty($ligne['annee'])) {
            $a = AnneeUniversitaire::where('libelle', trim((string) $ligne['annee']))->first();
            if ($a !== null) {
                $anneeId = $a->id;
            }
        }

        $libelle = trim((string) ($ligne['libelle'] ?? $ligne['code'] ?? 'Examen'));
        $donnees = [
            'annee_universitaire_id' => $anneeId,
            'programme_id' => $programmeId,
            'niveau_id' => $niveauId,
            'type' => $ligne['type'] ?? 'examen',
            'statut' => $ligne['statut'] ?? 'draft',
            'description' => $ligne['description'] ?? null,
            'responsable_id' => $auteurId,
        ];

        if (! empty($ligne['code'])) {
            $donnees['code'] = trim((string) $ligne['code']);
        }

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$libelle} ({$session->code})";
        }

        Examen::create([
            'session_id' => $session->id,
            'libelle' => $libelle,
        ] + $donnees);

        return "créé : {$libelle} ({$session->code})";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void {}

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'pdf'];
    }

    public function requete(Request $request)
    {
        return Examen::with(['session', 'anneeUniversitaire', 'programme'])->orderByDesc('id');
    }

    public function filtrer($query, Request $request)
    {
        if ($auteur = $request->user()) {
            if ($restreinteId = $auteur->faculteRestreinteId()) {
                $query->whereHas('programme', fn ($q) => $q->where('faculte_id', $restreinteId));
            }
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($q) => $q->where('libelle', 'like', $motif));
        }

        return $query;
    }

    public function colonnesAutorisees(array $demandees, bool $estAdmin = false): array
    {
        return array_values(array_intersect($this->colonnes(), $demandees));
    }

    public function ligne($modele): array
    {
        return [
            'libelle' => $modele->libelle,
            'session' => $modele->session?->code ?? '',
            'annee' => $modele->anneeUniversitaire?->libelle ?? '',
            'programme' => $modele->programme?->code ?? '',
            'niveau' => $modele->niveau?->code ?? '',
            'type' => $modele->type,
            'statut' => $modele->statut,
            'description' => $modele->description ?? '',
        ];
    }
}
