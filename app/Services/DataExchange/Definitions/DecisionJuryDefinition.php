<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

class DecisionJuryDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'decisions-jury';
    }

    public function libelle(): string
    {
        return 'Décisions de jury';
    }

    public function colonnes(): array
    {
        return ['code', 'deliberation_code', 'etudiant_matricule', 'decision', 'moyenne', 'credits_obtenus'];
    }

    public function colonnesRequises(): array
    {
        return ['deliberation_code', 'etudiant_matricule', 'decision'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'deliberation_code' => ['deliberation', 'code_deliberation'],
            'etudiant_matricule' => ['matricule', 'code_etudiant', 'etudiant'],
            'credits_obtenus' => ['credits', 'ects'],
            'decision' => ['verdict', 'resultat', 'avis_jury'],
        ];
    }

    public function regles(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50'],
            'deliberation_code' => ['required', 'string', 'max:50'],
            'etudiant_matricule' => ['required', 'string', 'max:50'],
            'decision' => ['required', 'string', 'max:50'],
            'moyenne' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'credits_obtenus' => ['nullable', 'integer', 'min:0', 'max:180'],
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

        $delib = Deliberation::where('code', trim((string) $ligne['deliberation_code']))->first();
        if ($delib === null) {
            $erreurs[] = [
                'champ' => 'deliberation_code',
                'message' => "La délibération « {$ligne['deliberation_code']} » est introuvable.",
                'code' => 'DELIBERATION_NOT_FOUND',
            ];
        }

        $etudiant = $this->resoudreEtudiant(trim((string) $ligne['etudiant_matricule']));
        if ($etudiant === null) {
            $erreurs[] = [
                'champ' => 'etudiant_matricule',
                'message' => "L'étudiant « {$ligne['etudiant_matricule']} » est introuvable.",
                'code' => 'STUDENT_NOT_FOUND',
            ];
        }

        return $erreurs;
    }

    public function cleMetier(array $ligne): ?string
    {
        return trim((string) ($ligne['deliberation_code'] ?? '')).'|'.trim((string) ($ligne['etudiant_matricule'] ?? ''));
    }

    public function cleDoublon(): array
    {
        return ['deliberation_code', 'etudiant_matricule'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $delib = Deliberation::where('code', trim((string) $ligne['deliberation_code']))->first();
        $etudiant = $this->resoudreEtudiant(trim((string) $ligne['etudiant_matricule']));

        if ($delib === null || $etudiant === null) {
            return null;
        }

        return DecisionDeliberation::where('deliberation_id', $delib->id)
            ->where('etudiant_id', $etudiant->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $delib = Deliberation::where('code', trim((string) $ligne['deliberation_code']))->first();
        $etudiant = $this->resoudreEtudiant(trim((string) $ligne['etudiant_matricule']));

        if ($delib === null || $etudiant === null) {
            throw new \DomainException('Délibération ou étudiant introuvable.');
        }

        $decisionBrute = mb_strtolower(trim((string) $ligne['decision']));
        $decision = match (true) {
            str_contains($decisionBrute, 'admis_comp') => 'admis_compensation',
            str_contains($decisionBrute, 'admis_cond') => 'admis_conditionnel',
            str_contains($decisionBrute, 'admis') => 'admis',
            str_contains($decisionBrute, 'ajourn') => 'ajourne',
            str_contains($decisionBrute, 'ratt') => 'rattrapage',
            str_contains($decisionBrute, 'redoub') => 'redouble',
            str_contains($decisionBrute, 'excl') => 'exclu',
            default => 'a_examiner',
        };

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $delib->annee_universitaire_id)
            ->first();

        if ($inscription === null) {
            $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)->latest('id')->first();
        }

        $existant = $this->trouverExistant($ligne);

        $donnees = [
            'inscription_academique_id' => $inscription?->id,
            'moyenne' => ! empty($ligne['moyenne']) ? (float) $ligne['moyenne'] : null,
            'credits_obtenus' => ! empty($ligne['credits_obtenus']) ? (int) $ligne['credits_obtenus'] : null,
            'decision' => $decision,
            // LOOP saisies §10 : PAS de `'publie' => true` via l'import —
            // la publication officielle d'une décision passe par le
            // workflow délibération (quorum + verrous + contrôle
            // pré-publication), jamais par une valeur de fichier.
        ];

        if ($existant !== null) {
            $existant->update($donnees);

            return "mise à jour décision : {$etudiant->nom_complet}";
        }

        DecisionDeliberation::create([
            'deliberation_id' => $delib->id,
            'etudiant_id' => $etudiant->id,
        ] + $donnees);

        return "décision enregistrée : {$etudiant->nom_complet}";
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
        return DecisionDeliberation::with(['deliberation', 'etudiant'])->orderBy('id');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->whereHas('etudiant', fn ($q) => $q->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'code' => 'DEC-'.$modele->id,
            'deliberation_code' => $modele->deliberation?->code,
            'etudiant_matricule' => $modele->etudiant?->num_inscription,
            'decision' => $modele->decision,
            'moyenne' => $modele->moyenne,
            'credits_obtenus' => $modele->credits_obtenus,
        ];
    }

    private function resoudreEtudiant(string $matricule): ?Etudiant
    {
        if ($matricule === '') {
            return null;
        }

        $e = Etudiant::where('num_inscription', $matricule)->first();
        if ($e !== null) {
            return $e;
        }

        $e = Etudiant::whereHas('dossierAcademique', fn ($q) => $q->where('matricule', $matricule))->first();
        if ($e !== null) {
            return $e;
        }

        return Etudiant::where('cin', $matricule)->first();
    }
}
