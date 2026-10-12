<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Services\ArchiveService;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\NormalisationService;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import/export des notes via le centre unifié (§14.6 + mission import
 * notes) : mêmes règles que GradesImporter, persistance via
 * ArchiveService (cohérence, recalcul, audit) — jamais d'INSERT direct.
 *
 * Résolution stricte (jamais de nom seul) : CIN exact → matricule →
 * num_inscription → CIN + date de naissance. Matière par code +
 * cohérence (niveau, semestre). Notes verrouillées refusées. Scope
 * faculté de l'auteur appliqué.
 */
class NoteImportDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'notes';
    }

    public function libelle(): string
    {
        return 'Notes';
    }

    public function colonnes(): array
    {
        return ['matricule', 'cin', 'date_naissance', 'code_matiere', 'annee', 'semestre', 'note', 'session', 'evaluation_code', 'statut'];
    }

    public function colonnesRequises(): array
    {
        return ['note'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'cin' => ['c.i.n', 'n°cin', 'n cin', 'numero cin', 'num cin'],
            'matricule' => ['code etudiant', 'code étudiant', 'id etudiant', 'id étudiant', 'numero etudiant', 'numéro étudiant', 'num etudiant', 'etudiant_matricule'],
            'code_matiere' => ['matiere', 'matière', 'code matiere', 'code matière', 'ecue', 'code ecue', 'code_ecue'],
            'annee' => ['annee_universitaire', 'année', 'annee universitaire', 'année universitaire', 'annee_code'],
            'date_naissance' => ['dob', 'naissance', 'date naissance'],
            'session' => ['periode', 'période'],
            'evaluation_code' => ['evaluation', 'code_evaluation'],
        ];
    }

    public function regles(): array
    {
        return [
            'matricule' => ['nullable', 'string', 'max:30'],
            'cin' => ['nullable', 'string', NormalisationService::REGLE_CIN],
            'date_naissance' => ['nullable', 'date'],
            'code_matiere' => ['nullable', 'string', 'max:20'],
            'evaluation_code' => ['nullable', 'string', 'max:50'],
            'annee' => ['nullable'],
            'semestre' => ['nullable'],
            'note' => ['required', 'numeric', 'min:0', 'max:20'],
            'session' => ['nullable'],
            'statut' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function cleDoublon(): array
    {
        return ['matricule', 'cin', 'code_matiere', 'annee', 'semestre', 'session'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $resolution = $this->resoudre($ligne);

        if ($resolution === null) {
            return null;
        }

        return Note::where('etudiant_id', $resolution['etudiant']->id)
            ->where('semestre_id', $resolution['semestre']->id)
            ->where('matiere_id', $resolution['matiere']->id)
            ->where('session', $ligne['session'] ?? 'normale')
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $resolution = $this->resoudre($ligne);

        if ($resolution === null) {
            throw new \DomainException($this->diagnosticResolution($ligne));
        }

        $numLigne = (int) ($ligne['ligne'] ?? $ligne['_ligne'] ?? 1);
        $this->verifierScope($resolution['etudiant'], $auteurId, $numLigne);

        $archives = app(ArchiveService::class);
        $donnees = [
            'semestre_numero' => $this->numeroSemestre($ligne, $resolution['matiere']),
            'matiere_id' => $resolution['matiere']->id,
            'note' => (float) $ligne['note'],
            'session' => $ligne['session'] ?? 'normale',
            'observation' => 'Import DataExchange',
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            if ($mode === 'creer') {
                return "doublon ignoré : {$ligne['code_matiere']} ({$donnees['session']})";
            }

            if ($existant->estVerrouillee()) {
                throw new \DomainException("Note verrouillée (ligne {$numLigne}) : modification interdite par import, utilisez la correction autorisée.");
            }

            $archives->modifierNote($existant, $donnees + ['raison' => 'Import DataExchange']);

            return "mise à jour : {$ligne['code_matiere']} ({$donnees['session']})";
        }

        $archives->saisirNote($resolution['inscription'], $donnees);

        return "créée : {$ligne['code_matiere']} ({$donnees['session']})";
    }

    public function modes(): array
    {
        return ['creer', 'upsert'];
    }

    public function vider(?int $auteurId): void {}

    public function formats(): array
    {
        return ['csv', 'xlsx', 'json', 'xml'];
    }

    /**
     * @param  array<string, mixed>  $ligne
     * @return array<int, array{champ: string, message: string, code: string}>
     */
    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $etudiant = $this->resoudreEtudiant($ligne);

        if ($etudiant === null) {
            return [$this->erreur($ligne, 'etudiant', $this->diagnosticEtudiant($ligne))];
        }

        $portee = $this->verifierScope($etudiant, $auteurId, (int) $ligne['ligne'], false);

        if ($portee !== null) {
            return [$portee];
        }

        $matiere = ! empty($ligne['code_matiere']) ? Matiere::where('code', mb_strtoupper((string) $ligne['code_matiere']))->first() : null;
        $annee = $this->resoudreAnnee($ligne);

        if (! empty($ligne['evaluation_code'])) {
            $evalCode = trim((string) $ligne['evaluation_code']);
            $epreuve = Epreuve::where('code', $evalCode)->first();
            if ($epreuve !== null) {
                $matiere = $matiere ?? $epreuve->matiere;
                $annee = $annee ?? $epreuve->anneeUniversitaire;
            } else {
                $examen = Examen::where('code', $evalCode)->first();
                if ($examen !== null) {
                    $ep = $examen->epreuves()->first();
                    $matiere = $matiere ?? $ep?->matiere;
                    $annee = $annee ?? $examen->anneeUniversitaire;
                }
            }
        }

        if ($annee === null) {
            $annee = AnneeUniversitaire::where('active', true)->first() ?? AnneeUniversitaire::first();
        }

        if ($annee === null) {
            return [$this->erreur($ligne, 'annee', empty($ligne['annee']) ? 'Aucune année active pour résoudre la ligne '.$ligne['ligne'].'.' : "Année « {$ligne['annee']} » introuvable (ligne {$ligne['ligne']}).", 'ANNEE_INTROUVABLE')];
        }

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $annee->id)->first();

        if ($inscription === null) {
            $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->latest('id')->first();
        }

        if ($inscription === null) {
            return [$this->erreur($ligne, 'annee', "Aucune inscription de l'étudiant pour {$annee->libelle} (ligne {$ligne['ligne']}).", 'INSCRIPTION_ABSENTE')];
        }

        // LOOP saisies : PAS de repli `Matiere::first()` — une matière
        // inconnue est rejetée avec le code précis (fail-closed,
        // pattern ExamenDefinition), jamais rattachée silencieusement
        // à la première matière du référentiel.
        if ($matiere === null) {
            return [$this->erreur($ligne, 'code_matiere', "Matière introuvable au référentiel (ligne {$ligne['ligne']}).", 'MATIERE_INTROUVABLE')];
        }

        if ($matiere->niveau !== null && $inscription->niveau !== null && $matiere->niveau !== $inscription->niveau) {
            return [$this->erreur($ligne, 'code_matiere', "Matière {$matiere->code} (niveau {$matiere->niveau}) incompatible avec l'inscription (niveau {$inscription->niveau}) — ligne {$ligne['ligne']}.", 'NIVEAU_INCOHERENT')];
        }

        $semestre = $inscription->semestres()->where('numero', $this->numeroSemestre($ligne, $matiere))->first();

        if ($semestre === null) {
            return [$this->erreur($ligne, 'semestre', "Semestre introuvable pour cette inscription (ligne {$ligne['ligne']}).", 'SEMESTRE_INTROUVABLE')];
        }

        if ($matiere->semestre !== null && (int) $matiere->semestre !== (int) $semestre->numero) {
            return [$this->erreur($ligne, 'semestre', "Matière {$matiere->code} (semestre {$matiere->semestre}) hors semestre {$semestre->numero} — ligne {$ligne['ligne']}.", 'SEMESTRE_INCOHERENT')];
        }

        $existante = Note::where('etudiant_id', $etudiant->id)
            ->where('semestre_id', $semestre->id)
            ->where('matiere_id', $matiere->id)
            ->where('session', $ligne['session'] ?? 'normale')
            ->first();

        if ($existante !== null && $existante->estVerrouillee()) {
            return [$this->erreur($ligne, 'note', "Note verrouillée (ligne {$ligne['ligne']}) : modification interdite par import.", 'NOTE_VERROUILLEE')];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $ligne
     */
    public function cleMetier(array $ligne): ?string
    {
        $etudiant = $this->resoudreEtudiant($ligne);
        $matiere = Matiere::where('code', mb_strtoupper((string) ($ligne['code_matiere'] ?? '')))->first();
        $annee = $this->resoudreAnnee($ligne);

        if ($etudiant === null || $matiere === null || $annee === null) {
            return null;
        }

        return implode('|', [
            'etu:'.$etudiant->id, 'mat:'.$matiere->id,
            'sem:'.$this->numeroSemestre($ligne, $matiere),
            $annee->libelle, (string) ($ligne['session'] ?? 'normale'),
        ]);
    }

    public function requete(Request $request)
    {
        $query = Note::with(['etudiant', 'matiere'])->orderByDesc('id');

        if ($request->user()?->faculteRestreinteId() !== null) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('matiere', fn ($q) => $q->where('code', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'matricule' => $modele->etudiant?->dossierAcademique?->matricule,
            'cin' => $modele->etudiant?->cin,
            'code_matiere' => $modele->matiere?->code,
            'annee' => $modele->inscriptionAcademique?->anneeUniversitaire?->libelle,
            'semestre' => $modele->semestre?->numero,
            'note' => $modele->note,
            'session' => $modele->session,
        ];
    }

    /** Année explicite (libellé) ou année active par défaut. */
    private function resoudreAnnee(array $ligne): ?AnneeUniversitaire
    {
        if (! empty($ligne['annee'])) {
            return AnneeUniversitaire::where('libelle', $ligne['annee'])->first();
        }

        return AnneeUniversitaire::active();
    }

    /** Semestre explicite ou semestre de la matière au référentiel. */
    private function numeroSemestre(array $ligne, Matiere $matiere): int
    {
        return $ligne['semestre'] !== null ? (int) $ligne['semestre'] : (int) $matiere->semestre;
    }

    /** @param  array<string, mixed>  $ligne */
    private function erreur(array $ligne, string $champ, string $message, string $code = 'ETUDIANT_INTROUVABLE'): array
    {
        return ['champ' => $champ, 'message' => $message.' (ligne '.$ligne['ligne'].').', 'code' => $code];
    }

    /**
     * Chaîne d'identification : CIN exact → matricule → num_inscription →
     * CIN + date de naissance. Jamais de nom seul (pas d'association
     * silencieuse approximative).
     *
     * @param  array<string, mixed>  $ligne
     */
    private function resoudreEtudiant(array $ligne): ?Etudiant
    {
        $cin = mb_strtoupper(trim((string) ($ligne['cin'] ?? '')));

        if ($cin !== '') {
            $etudiant = Etudiant::where('cin', $cin)->first();

            if ($etudiant !== null) {
                return $etudiant;
            }
        }

        if (! empty($ligne['matricule'])) {
            $etudiant = Etudiant::whereHas('dossierAcademique', fn ($q) => $q->where('matricule', $ligne['matricule']))->first();

            if ($etudiant !== null) {
                return $etudiant;
            }

            $etudiant = Etudiant::where('num_inscription', $ligne['matricule'])->first();

            if ($etudiant !== null) {
                return $etudiant;
            }
        }

        if ($cin !== '' && ! empty($ligne['date_naissance'])) {
            return Etudiant::where('cin', $cin)->whereDate('date_naissance', $ligne['date_naissance'])->first();
        }

        return null;
    }

    /** @param  array<string, mixed>  $ligne */
    private function diagnosticEtudiant(array $ligne): string
    {
        if (empty($ligne['cin']) && empty($ligne['matricule'])) {
            return 'Aucun identifiant étudiant (CIN ou matricule requis).';
        }

        return 'Étudiant introuvable (CIN/matricule sans correspondance locale).';
    }

    /** @param  array<string, mixed>  $ligne */
    private function diagnosticResolution(array $ligne): string
    {
        $numLigne = $ligne['ligne'] ?? $ligne['_ligne'] ?? 1;

        return $this->diagnosticEtudiant($ligne)." (ligne {$numLigne})";
    }

    /**
     * Scope faculté de l'auteur : une ligne hors périmètre est refusée
     * (jamais importée silencieusement). Retourne null si autorisé, ou
     * l'erreur structurée (mode non-exception pour la preview).
     *
     * @return array{champ: string, message: string, code: string}|null
     */
    private function verifierScope(Etudiant $etudiant, ?int $auteurId, int $numeroLigne, bool $lever = true): ?array
    {
        if ($auteurId === null) {
            return null;
        }

        $auteur = User::find($auteurId);

        if ($auteur !== null && ! $auteur->peutAccederFaculte((int) $etudiant->faculte_id)) {
            $erreur = [
                'champ' => 'etudiant',
                'message' => "Étudiant hors de votre périmètre faculté (ligne {$numeroLigne}).",
                'code' => 'HORS_PERIMETRE',
            ];

            if ($lever) {
                throw new \DomainException($erreur['message']);
            }

            return $erreur;
        }

        return null;
    }

    /** @return array{etudiant: Etudiant, matiere: Matiere, inscription: InscriptionAcademique, semestre: Semestre}|null */
    private function resoudre(array $ligne): ?array
    {
        $etudiant = $this->resoudreEtudiant($ligne);
        $matiere = ! empty($ligne['code_matiere']) ? Matiere::where('code', mb_strtoupper((string) $ligne['code_matiere']))->first() : null;
        $annee = $this->resoudreAnnee($ligne);

        if (! empty($ligne['evaluation_code'])) {
            $evalCode = trim((string) $ligne['evaluation_code']);
            $epreuve = Epreuve::where('code', $evalCode)->first();
            if ($epreuve !== null) {
                $matiere = $matiere ?? $epreuve->matiere;
                $annee = $annee ?? $epreuve->anneeUniversitaire;
            } else {
                $examen = Examen::where('code', $evalCode)->first();
                if ($examen !== null) {
                    $ep = $examen->epreuves()->first();
                    $matiere = $matiere ?? $ep?->matiere;
                    $annee = $annee ?? $examen->anneeUniversitaire;
                }
            }
        }

        // LOOP saisies : PAS de repli `Matiere::first()` — une matière
        // inconnue est rejetée en amont (fail-closed, pattern
        // ExamenDefinition), jamais rattachée silencieusement à la
        // première matière du référentiel.
        if ($etudiant === null || $matiere === null || $annee === null) {
            return null;
        }

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $annee->id)->first();

        if ($inscription === null) {
            $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->latest('id')->first();
        }

        if ($inscription === null) {
            return null;
        }

        $semestre = $inscription->semestres()->where('numero', $this->numeroSemestre($ligne, $matiere))->first();

        if ($semestre === null) {
            $semestre = $inscription->semestres()->first() ?? Semestre::firstOrCreate([
                'inscription_academique_id' => $inscription->id,
                'numero' => 1,
            ], ['statut' => 'en_cours']);
        }

        return ['etudiant' => $etudiant, 'matiere' => $matiere, 'inscription' => $inscription, 'semestre' => $semestre];
    }
}
