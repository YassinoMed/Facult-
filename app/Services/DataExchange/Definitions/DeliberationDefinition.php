<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\Io\IoException;
use App\Services\NormalisationService;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des délibérations et décisions de jury (Lot 21, §29-30).
 *
 * Dépendances : Sessions d'examen, Programmes, Étudiants.
 * Clé métier : `session` + `programme` + `niveau` + `matricule/cin`.
 */
class DeliberationDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'deliberations';
    }

    public function libelle(): string
    {
        return 'Délibérations et décisions de jury';
    }

    public function colonnes(): array
    {
        return [
            'code', 'annee_code', 'parcours_code', 'niveau_code', 'semestre_code', 'date_deliberation', 'statut',
            'session', 'programme', 'niveau', 'matricule', 'cin',
            'moyenne', 'credits_obtenus', 'decision', 'commentaire',
        ];
    }

    public function colonnesRequises(): array
    {
        return [];
    }

    public function aliasesColonnes(): array
    {
        return [
            'session' => ['code_session', 'session_examen'],
            'programme' => ['parcours', 'filiere', 'parcours_code'],
            'matricule' => ['code_etudiant', 'numero_etudiant', 'etudiant_matricule'],
            'cin' => ['c.i.n', 'n°cin'],
            'credits_obtenus' => ['credits', 'ects'],
            'decision' => ['verdict', 'resultat', 'avis_jury'],
            'annee_code' => ['annee', 'annee_universitaire'],
            'parcours_code' => ['parcours', 'programme'],
            'niveau_code' => ['niveau'],
            'semestre_code' => ['semestre'],
            'date_deliberation' => ['date'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50'],
            'annee_code' => ['nullable'],
            'parcours_code' => ['nullable', 'string', 'max:50'],
            'niveau_code' => ['nullable', 'string', 'max:50'],
            'semestre_code' => ['nullable', 'string', 'max:50'],
            'date_deliberation' => ['nullable', 'date'],
            'statut' => ['nullable', 'string', 'max:50'],
            'session' => ['nullable', 'string', 'max:30'],
            'programme' => ['nullable', 'string', 'max:30'],
            'niveau' => ['nullable', 'string', 'max:10'],
            'matricule' => ['nullable', 'string', 'max:30'],
            'cin' => ['nullable', 'string', NormalisationService::REGLE_CIN],
            'moyenne' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'credits_obtenus' => ['nullable', 'integer', 'min:0', 'max:180'],
            'decision' => ['nullable'],
            'commentaire' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function resoudreSession(string $code): ?SessionExamen
    {
        return SessionExamen::where('code', mb_strtoupper(trim($code)))->first();
    }

    public function resoudreProgramme(string $code): ?Programme
    {
        return Programme::where('code', mb_strtoupper(trim($code)))->first();
    }

    public function resoudreEtudiant(array $ligne): ?Etudiant
    {
        if (! empty($ligne['matricule'])) {
            $e = Etudiant::whereHas('dossierAcademique', fn ($q) => $q->where('matricule', trim((string) $ligne['matricule'])))->first();
            if ($e !== null) {
                return $e;
            }
        }

        if (! empty($ligne['cin'])) {
            $e = Etudiant::where('cin', mb_strtoupper(trim((string) $ligne['cin'])))->first();
            if ($e !== null) {
                return $e;
            }
        }

        return null;
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        if (! empty($ligne['code']) && empty($ligne['decision'])) {
            return [];
        }

        $session = $this->resoudreSession($ligne['session'] ?? '');

        if ($session === null) {
            $erreurs[] = [
                'champ' => 'session',
                'message' => "La session d'examen « {$ligne['session']} » est introuvable.",
                'code' => 'SESSION_NOT_FOUND',
            ];
        }

        $programme = $this->resoudreProgramme($ligne['programme'] ?? '');
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
                    'message' => "Le programme « {$programme->code} » est hors de votre périmètre faculté.",
                    'code' => 'FACULTY_SCOPE_VIOLATION',
                ];
            }
        }

        if (empty($ligne['matricule']) && empty($ligne['cin'])) {
            $erreurs[] = [
                'champ' => 'matricule',
                'message' => 'Le matricule ou le CIN de l’étudiant est obligatoire.',
                'code' => 'STUDENT_IDENTIFIER_MISSING',
            ];
        } else {
            $etudiant = $this->resoudreEtudiant($ligne);
            if ($etudiant === null) {
                $id = $ligne['matricule'] ?? $ligne['cin'];
                $erreurs[] = [
                    'champ' => 'matricule',
                    'message' => "L'étudiant « {$id} » est introuvable.",
                    'code' => 'STUDENT_NOT_FOUND',
                ];
            }
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        if (! empty($ligne['code']) && empty($ligne['decision'])) {
            return mb_strtoupper(trim((string) $ligne['code']));
        }

        $session = mb_strtoupper(trim((string) ($ligne['session'] ?? '')));
        $id = ! empty($ligne['matricule']) ? $ligne['matricule'] : ($ligne['cin'] ?? '');

        return mb_strtoupper(trim("{$session}|{$id}"));
    }

    public function cleDoublon(): array
    {
        return ['code', 'session', 'matricule', 'cin'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        if (! empty($ligne['code']) && empty($ligne['decision'])) {
            return Deliberation::where('code', trim((string) $ligne['code']))->first();
        }

        $session = $this->resoudreSession($ligne['session'] ?? '');
        $etudiant = $this->resoudreEtudiant($ligne);

        if ($session === null || $etudiant === null) {
            return null;
        }

        return DecisionDeliberation::whereHas('deliberation', fn ($q) => $q->where('session_id', $session->id))
            ->where('etudiant_id', $etudiant->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        if (! empty($ligne['code']) && empty($ligne['decision'])) {
            $code = trim((string) $ligne['code']);
            $annee = ! empty($ligne['annee_code']) ? AnneeUniversitaire::where('libelle', trim((string) $ligne['annee_code']))->first() : null;
            $programme = ! empty($ligne['parcours_code']) ? Programme::where('code', trim((string) $ligne['parcours_code']))->first() : null;
            $niveau = ! empty($ligne['niveau_code']) ? Niveau::where('code', trim((string) $ligne['niveau_code']))->first() : null;
            $semestre = ! empty($ligne['semestre_code']) ? SemestreLmd::where('code', trim((string) $ligne['semestre_code']))->first() : null;

            $statutBrut = mb_strtolower(trim((string) ($ligne['statut'] ?? 'prepared')));
            $statut = match (true) {
                str_contains($statutBrut, 'prep') => 'prepared',
                str_contains($statutBrut, 'val') => 'validated',
                str_contains($statutBrut, 'pub') => 'published',
                default => 'draft',
            };

            $delib = Deliberation::updateOrCreate([
                'code' => $code,
            ], [
                'annee_universitaire_id' => $annee?->id,
                'programme_id' => $programme?->id,
                'niveau_id' => $niveau?->id,
                'semestre_lmd_id' => $semestre?->id,
                'statut' => $statut,
                'created_by' => $auteurId,
            ]);

            return "délibération créée : {$delib->code}";
        }

        $session = $this->resoudreSession($ligne['session'] ?? '');
        $programme = $this->resoudreProgramme($ligne['programme'] ?? '');
        $etudiant = $this->resoudreEtudiant($ligne);

        if ($session === null || $programme === null || $etudiant === null) {
            throw IoException::malforme("Session, programme ou étudiant introuvable (ligne {$ligne['ligne']}).");
        }

        $niveau = Niveau::where('code', mb_strtoupper(trim((string) $ligne['niveau'])))
            ->where('programme_id', $programme->id)
            ->first();

        // Récupération ou création de la délibération parente
        $deliberation = Deliberation::firstOrCreate([
            'session_id' => $session->id,
            'programme_id' => $programme->id,
            'annee_universitaire_id' => $session->annee_universitaire_id,
        ], [
            'niveau_id' => $niveau?->id,
            'statut' => 'published',
            'created_by' => $auteurId,
            'published_at' => now(),
        ]);

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $session->annee_universitaire_id)
            ->first();

        $donnees = [
            'inscription_academique_id' => $inscription?->id,
            'moyenne' => ! empty($ligne['moyenne']) ? (float) $ligne['moyenne'] : null,
            'credits_obtenus' => ! empty($ligne['credits_obtenus']) ? (int) $ligne['credits_obtenus'] : null,
            'decision' => $ligne['decision'],
            'commentaire' => $ligne['commentaire'] ?? 'Import Délibération DataExchange',
            'publie' => true,
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour décision : {$etudiant->nom_complet} ({$ligne['decision']})";
        }

        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
        ] + $donnees);

        return "enregistrée : {$etudiant->nom_complet} ({$ligne['decision']})";
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
        return DecisionDeliberation::with(['deliberation.session', 'deliberation.programme', 'etudiant'])->orderByDesc('id');
    }

    public function filtrer($query, Request $request)
    {
        if ($auteur = $request->user()) {
            if ($restreinteId = $auteur->faculteRestreinteId()) {
                $query->whereHas('deliberation.programme', fn ($q) => $q->where('faculte_id', $restreinteId));
            }
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('etudiant', fn ($q) => $q->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif))
                ->orWhere('decision', 'like', $motif);
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
            'session' => $modele->deliberation?->session?->code ?? '',
            'programme' => $modele->deliberation?->programme?->code ?? '',
            'niveau' => $modele->deliberation?->niveau?->code ?? '',
            'matricule' => $modele->etudiant?->matricule ?? '',
            'cin' => $modele->etudiant?->cin ?? '',
            'moyenne' => (string) $modele->moyenne,
            'credits_obtenus' => (string) $modele->credits_obtenus,
            'decision' => $modele->decision,
            'commentaire' => $modele->commentaire ?? '',
        ];
    }
}
