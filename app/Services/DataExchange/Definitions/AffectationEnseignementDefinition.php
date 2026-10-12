<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Schedule\Models\Teacher;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des affectations d'enseignants (Lot 14, §22).
 *
 * Dépendances : Enseignements, Enseignants.
 * Clé métier : `ecue` + `type` + `annee` + `enseignant_email`.
 */
class AffectationEnseignementDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'affectations-enseignants';
    }

    public function libelle(): string
    {
        return 'Affectations des enseignants';
    }

    public function colonnes(): array
    {
        return ['ecue', 'type', 'annee', 'enseignant_email', 'role', 'volume_heures', 'statut', 'enseignement', 'enseignant'];
    }

    public function colonnesRequises(): array
    {
        return [];
    }

    public function aliasesColonnes(): array
    {
        return [
            'ecue' => ['matiere', 'code_ecue', 'code_matiere'],
            'type' => ['type_enseignement', 'type_cours'],
            'annee' => ['annee_universitaire', 'session'],
            'enseignant_email' => ['email_enseignant', 'email'],
            'volume_heures' => ['heures', 'volume', 'volume_horaire'],
            'enseignement' => ['enseignement_code', 'code_enseignement'],
            'enseignant' => ['enseignant_matricule', 'matricule_enseignant', 'matricule'],
        ];
    }

    public function regles(): array
    {
        return [
            'ecue' => ['nullable', 'string', 'max:20'],
            'type' => ['nullable', 'string', 'max:20'],
            'annee' => ['nullable'],
            'enseignant_email' => ['nullable'],
            'enseignement' => ['nullable', 'string', 'max:50'],
            'enseignant' => ['nullable', 'string', 'max:50'],
            'role' => ['nullable'],
            'volume_heures' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'statut' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function resoudreEnseignement(array $ligne): ?Enseignement
    {
        if (! empty($ligne['enseignement'])) {
            $code = trim((string) $ligne['enseignement']);
            $direct = Enseignement::where('code', $code)->first();
            if ($direct !== null) {
                return $direct;
            }
        }

        $ecue = Matiere::where('code', trim((string) ($ligne['ecue'] ?? '')))->first();
        $type = TypeEnseignement::where('code', mb_strtoupper(trim((string) ($ligne['type'] ?? ''))))->first();
        $annee = AnneeUniversitaire::where('libelle', trim((string) ($ligne['annee'] ?? '')))->first();

        if ($ecue === null || $type === null || $annee === null) {
            return null;
        }

        return Enseignement::where('matiere_id', $ecue->id)
            ->where('type_id', $type->id)
            ->where('annee_universitaire_id', $annee->id)
            ->first();
    }

    public function resoudreTeacher(array|string $ligneOuEmail): ?Teacher
    {
        if (is_array($ligneOuEmail)) {
            if (! empty($ligneOuEmail['enseignant'])) {
                $mat = trim((string) $ligneOuEmail['enseignant']);
                $t = Teacher::where('matricule', $mat)->first();
                if ($t !== null) {
                    return $t;
                }
            }
            $email = $ligneOuEmail['enseignant_email'] ?? '';
        } else {
            $email = $ligneOuEmail;
        }

        return Teacher::where('email', mb_strtolower(trim($email)))->first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        $enseignement = $this->resoudreEnseignement($ligne);
        if ($enseignement === null) {
            $nom = $ligne['enseignement'] ?? ($ligne['ecue'] ?? 'inconnu');
            $erreurs[] = [
                'champ' => 'enseignement',
                'message' => "L'enseignement « {$nom} » est introuvable.",
                'code' => 'ENSEIGNEMENT_NOT_FOUND',
            ];
        }

        $teacher = $this->resoudreTeacher($ligne);
        if ($teacher === null) {
            $nomT = $ligne['enseignant'] ?? ($ligne['enseignant_email'] ?? 'inconnu');
            $erreurs[] = [
                'champ' => 'enseignant',
                'message' => "L'enseignant « {$nomT} » est introuvable.",
                'code' => 'TEACHER_NOT_FOUND',
            ];
        } elseif ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null && (int) $teacher->faculte_id !== $restreinteId) {
                $erreurs[] = [
                    'champ' => 'enseignant_email',
                    'message' => "L'enseignant « {$teacher->nom_complet} » est hors de votre périmètre faculté.",
                    'code' => 'FACULTY_SCOPE_VIOLATION',
                ];
            }
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        if (! empty($ligne['enseignement']) && ! empty($ligne['enseignant'])) {
            return trim((string) $ligne['enseignement']).'|'.trim((string) $ligne['enseignant']);
        }

        $ecue = trim((string) ($ligne['ecue'] ?? ''));
        $type = trim((string) ($ligne['type'] ?? ''));
        $annee = trim((string) ($ligne['annee'] ?? ''));
        $email = mb_strtolower(trim((string) ($ligne['enseignant_email'] ?? '')));

        return "{$ecue}|{$type}|{$annee}|{$email}";
    }

    public function cleDoublon(): array
    {
        return ['enseignement', 'enseignant', 'ecue', 'type', 'annee', 'enseignant_email'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $enseignement = $this->resoudreEnseignement($ligne);
        $teacher = $this->resoudreTeacher($ligne);

        if ($enseignement === null || $teacher === null) {
            return null;
        }

        return AffectationEnseignement::where('enseignement_id', $enseignement->id)
            ->where('teacher_id', $teacher->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $enseignement = $this->resoudreEnseignement($ligne);
        $teacher = $this->resoudreTeacher($ligne);

        if ($enseignement === null || $teacher === null) {
            throw IoException::malforme("Enseignement ou enseignant introuvable (ligne {$ligne['ligne']}).");
        }

        $roleBrut = mb_strtolower(trim((string) ($ligne['role'] ?? 'enseignant')));
        $role = match ($roleBrut) {
            'responsable' => 'responsable',
            'principal' => 'principal',
            'vacataire' => 'vacataire',
            'intervenant' => 'intervenant',
            default => 'enseignant',
        };

        $donnees = [
            'role' => $role,
            'volume_heures' => ! empty($ligne['volume_heures']) ? (float) $ligne['volume_heures'] : 0,
            'statut' => ! empty($ligne['statut']) ? mb_strtolower(trim((string) $ligne['statut'])) : 'active',
            'auteur_id' => $auteurId,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$teacher->nom_complet} ({$ligne['ecue']})";
        }

        AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id,
            'teacher_id' => $teacher->id,
        ] + $donnees);

        return "créée : {$teacher->nom_complet} ({$ligne['ecue']})";
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
        return AffectationEnseignement::with(['enseignement.matiere', 'enseignement.type', 'teacher'])->orderByDesc('id');
    }

    public function filtrer($query, Request $request)
    {
        if ($auteur = $request->user()) {
            if ($restreinteId = $auteur->faculteRestreinteId()) {
                $query->whereHas('teacher', fn ($q) => $q->where('faculte_id', $restreinteId));
            }
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('teacher', fn ($q) => $q->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif))
                ->orWhereHas('enseignement.matiere', fn ($q) => $q->where('code', 'like', $motif));
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
            'ecue' => $modele->enseignement?->matiere?->code ?? '',
            'type' => $modele->enseignement?->type?->code ?? '',
            'annee' => $modele->enseignement?->anneeUniversitaire?->libelle ?? '',
            'enseignant_email' => $modele->teacher?->email ?? '',
            'role' => $modele->role,
            'volume_heures' => (string) $modele->volume_heures,
            'statut' => $modele->statut ?? '',
        ];
    }
}
