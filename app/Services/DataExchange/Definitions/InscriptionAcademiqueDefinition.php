<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Semestre;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\InscriptionService;
use App\Services\Io\IoException;
use App\Services\NormalisationService;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import / Export des inscriptions académiques (Lot 12, §20).
 *
 * Dépendances : Étudiants, Années universitaires.
 * Clé métier : `matricule` (ou `cin`) + `annee_universitaire`.
 * Crée l'inscription ET ses semestres S1/S2 (cohérence calculs & relevés).
 */
class InscriptionAcademiqueDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'inscriptions-academiques';
    }

    public function libelle(): string
    {
        return 'Inscriptions académiques';
    }

    public function colonnes(): array
    {
        return ['matricule', 'cin', 'annee_universitaire', 'filiere', 'niveau', 'groupe', 'statut', 'observation'];
    }

    public function colonnesRequises(): array
    {
        return ['annee_universitaire', 'niveau'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'matricule' => ['etudiant_matricule', 'code_etudiant', 'numero_etudiant', 'num_etudiant', 'etudiant_id'],
            'cin' => ['c.i.n', 'n°cin', 'num_cin'],
            'annee_universitaire' => ['annee_code', 'code_annee', 'annee', 'annee_academique', 'session'],
            'filiere' => ['parcours_code', 'code_parcours', 'formation', 'programme', 'parcours'],
            'niveau' => ['niveau_code', 'code_niveau'],
            'groupe' => ['groupe_code', 'code_groupe', 'classe'],
        ];
    }

    public function regles(): array
    {
        return [
            'matricule' => ['nullable', 'string', 'max:30'],
            'cin' => ['nullable', 'string', NormalisationService::REGLE_CIN],
            'annee_universitaire' => ['required', 'regex:/^\d{4}-\d{4}$/'],
            'filiere' => ['nullable', 'string', 'max:150'],
            'niveau' => ['required', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
            'groupe' => ['nullable', 'string', 'max:20'],
            'statut' => ['nullable', 'string', 'max:50'],
            'observation' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function resoudreEtudiant(array $ligne): ?Etudiant
    {
        $mat = trim((string) ($ligne['matricule'] ?? ''));
        if ($mat !== '') {
            $e = Etudiant::where('num_inscription', $mat)->first();
            if ($e !== null) {
                return $e;
            }

            $e = Etudiant::whereHas('dossierAcademique', fn ($q) => $q->where('matricule', $mat))->first();
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

    public function resoudreAnnee(string $valeur): ?AnneeUniversitaire
    {
        return AnneeUniversitaire::where('libelle', trim($valeur))->first();
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        if (empty($ligne['matricule']) && empty($ligne['cin'])) {
            $erreurs[] = [
                'champ' => 'matricule',
                'message' => 'Le matricule ou le CIN de l’étudiant est obligatoire.',
                'code' => 'STUDENT_IDENTIFIER_MISSING',
            ];

            return $erreurs;
        }

        $etudiant = $this->resoudreEtudiant($ligne);
        if ($etudiant === null) {
            $id = $ligne['matricule'] ?? $ligne['cin'];
            $erreurs[] = [
                'champ' => 'matricule',
                'message' => "L'étudiant « {$id} » est introuvable.",
                'code' => 'STUDENT_NOT_FOUND',
            ];
        } elseif ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null && (int) $etudiant->faculte_id !== $restreinteId) {
                $erreurs[] = [
                    'champ' => 'matricule',
                    'message' => "L'étudiant « {$etudiant->matricule} » est hors de votre faculté.",
                    'code' => 'FACULTY_SCOPE_VIOLATION',
                ];
            }
        }

        $annee = $this->resoudreAnnee($ligne['annee_universitaire'] ?? '');
        if ($annee === null) {
            $erreurs[] = [
                'champ' => 'annee_universitaire',
                'message' => "L'année universitaire « {$ligne['annee_universitaire']} » est introuvable.",
                'code' => 'YEAR_NOT_FOUND',
            ];
        }

        $programme = null;
        if (! empty($ligne['filiere'])) {
            $programme = Programme::where('code', trim((string) $ligne['filiere']))->first();

            if ($programme !== null && $programme->statut === Programme::STATUT_ARCHIVE) {
                $erreurs[] = [
                    'champ' => 'filiere',
                    'message' => "Le parcours « {$ligne['filiere']} » est archivé.",
                    'code' => 'PROGRAMME_ARCHIVE',
                ];
            }
        }

        // Cohérence niveau ↔ programme : `niveaux.code` n'est unique que
        // par programme (plusieurs « L1 » coexistent). Sans ce contrôle,
        // le niveau d'un autre programme peut être rattaché silencieusement.
        $programme = $programme ?? $etudiant?->programme;
        $codeNiveau = trim((string) ($ligne['niveau'] ?? ''));

        if ($programme !== null && $codeNiveau !== ''
            && Niveau::resoudreDansProgramme($codeNiveau, $programme->id) === null) {
            $erreurs[] = [
                'champ' => 'niveau',
                'message' => "Le niveau « {$codeNiveau} » n'appartient pas au parcours « {$programme->code} ».",
                'code' => 'NIVEAU_HORS_PROGRAMME',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        $id = ! empty($ligne['matricule']) ? $ligne['matricule'] : ($ligne['cin'] ?? '');
        $annee = $ligne['annee_universitaire'] ?? '';

        return mb_strtoupper(trim("{$id}|{$annee}"));
    }

    public function cleDoublon(): array
    {
        return ['matricule', 'cin', 'annee_universitaire'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $etudiant = $this->resoudreEtudiant($ligne);
        $annee = $this->resoudreAnnee($ligne['annee_universitaire'] ?? '');

        if ($etudiant === null || $annee === null) {
            return null;
        }

        return InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $annee->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $etudiant = $this->resoudreEtudiant($ligne);
        $annee = $this->resoudreAnnee($ligne['annee_universitaire'] ?? '');

        if ($etudiant === null || $annee === null) {
            throw IoException::malforme("Étudiant ou année introuvable (ligne {$ligne['ligne']}).");
        }

        if ($auteurId !== null) {
            $auteur = User::find($auteurId);
            $restreinteId = $auteur?->faculteRestreinteId();
            if ($restreinteId !== null && (int) $etudiant->faculte_id !== $restreinteId) {
                throw IoException::malforme("Étudiant hors périmètre faculté (ligne {$ligne['ligne']}).");
            }
        }

        $statutBrut = mb_strtolower(trim((string) ($ligne['statut'] ?? 'en_cours')));
        $statut = match (true) {
            str_contains($statutBrut, 'val') => 'valide',
            str_contains($statutBrut, 'ajourn') => 'ajourne',
            str_contains($statutBrut, 'aband') => 'abandon',
            default => 'en_cours',
        };

        $programme = ! empty($ligne['filiere']) ? Programme::where('code', trim((string) $ligne['filiere']))->first() : $etudiant->programme;
        $niveauObj = $this->resoudreNiveau($ligne, $programme);

        if ($programme !== null && $programme->statut === Programme::STATUT_ARCHIVE) {
            throw IoException::malforme("Parcours « {$programme->code} » archivé (ligne {$ligne['ligne']}).");
        }

        $donnees = [
            'filiere' => ! empty($ligne['filiere']) ? $ligne['filiere'] : ($programme?->nom ?? $etudiant->niveau),
            'programme_id' => $programme?->id,
            'niveau_id' => $niveauObj?->id,
            'niveau' => $ligne['niveau'],
            'groupe' => $ligne['groupe'] ?? null,
            'statut' => $statut,
            'observation' => $ligne['observation'] ?? 'Import DataExchange',
        ];

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour : {$etudiant->nom} {$etudiant->prenom} ({$annee->libelle})";
        }

        try {
            // Création sous la règle d'unicité annuelle CENTRALISÉE.
            $inscription = app(InscriptionService::class)->creerInscriptionAnnuelle(
                (int) $etudiant->id,
                (int) $annee->id,
                $donnees,
            );
        } catch (\DomainException) {
            // Course concurrence entre la lecture ci-dessus et l'écriture :
            // la paire (étudiant, année) a été créée entre-temps. Refus
            // métier contrôlé (jamais d'écrasement silencieux) — relancer
            // l'import produira la mise à jour attendue.
            throw IoException::malforme(
                "Inscription déjà existante pour cet étudiant et cette année (conflit d'unicité, ligne {$ligne['ligne']}) — relancez l'import pour une mise à jour."
            );
        }

        // Création des deux semestres S1 et S2 si absents (§117)
        Semestre::firstOrCreate([
            'inscription_academique_id' => $inscription->id,
            'numero' => 1,
        ]);
        Semestre::firstOrCreate([
            'inscription_academique_id' => $inscription->id,
            'numero' => 2,
        ]);

        return "créée : {$etudiant->nom_complet} ({$annee->libelle})";
    }

    /**
     * Résout le niveau DANS le programme concerné.
     *
     * `niveaux.code` n'étant unique que par programme, une résolution par
     * le seul code retournerait le premier « L1 » venu. Sans programme
     * résolu, on ne devine jamais : le rattachement n'est posé que si le
     * code est globalement unique.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws IoException
     */
    private function resoudreNiveau(array $ligne, ?Programme $programme): ?Niveau
    {
        $code = trim((string) ($ligne['niveau'] ?? ''));

        if ($code === '') {
            return null;
        }

        if ($programme !== null) {
            $niveau = Niveau::resoudreDansProgramme($code, $programme->id);

            if ($niveau === null) {
                throw IoException::malforme(
                    "Niveau « {$code} » inexistant pour le parcours « {$programme->code} » (ligne {$ligne['ligne']})."
                );
            }

            return $niveau;
        }

        return Niveau::resoudreCodeUnique($code);
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
        return InscriptionAcademique::with(['etudiant.faculte', 'anneeUniversitaire'])->orderByDesc('id');
    }

    public function filtrer($query, Request $request)
    {
        if ($auteur = $request->user()) {
            if ($restreinteId = $auteur->faculteRestreinteId()) {
                $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $restreinteId));
            }
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('etudiant', fn ($q) => $q->where('nom', 'like', $motif)
                ->orWhere('prenom', 'like', $motif)
                ->orWhere('cin', 'like', $motif)
            );
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
            'matricule' => $modele->etudiant?->matricule ?? '',
            'cin' => $modele->etudiant?->cin ?? '',
            'annee_universitaire' => $modele->anneeUniversitaire?->libelle ?? '',
            'filiere' => $modele->filiere,
            'niveau' => $modele->niveau,
            'groupe' => $modele->groupe ?? '',
            'statut' => $modele->statut,
            'observation' => $modele->observation ?? '',
        ];
    }
}
