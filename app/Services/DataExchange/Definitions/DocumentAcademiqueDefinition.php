<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\GeneratedDocument;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Support\Recherche;
use Illuminate\Http\Request;

class DocumentAcademiqueDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'documents-academiques';
    }

    public function libelle(): string
    {
        return 'Documents académiques';
    }

    public function colonnes(): array
    {
        return ['reference', 'etudiant_matricule', 'type_document', 'annee_code', 'statut'];
    }

    public function colonnesRequises(): array
    {
        return ['reference', 'etudiant_matricule', 'type_document'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'etudiant_matricule' => ['matricule', 'code_etudiant', 'etudiant'],
            'type_document' => ['type', 'type_doc'],
            'annee_code' => ['annee', 'annee_universitaire'],
        ];
    }

    public function regles(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
            'etudiant_matricule' => ['required', 'string', 'max:50'],
            'type_document' => ['required', 'string', 'max:50'],
            'annee_code' => ['nullable', 'string', 'max:20'],
            'statut' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        $erreurs = [];

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
        return mb_strtoupper(trim((string) ($ligne['reference'] ?? '')));
    }

    public function cleDoublon(): array
    {
        return ['reference'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $ref = trim((string) $ligne['reference']);

        return DocumentAcademique::where('nom_original', 'like', "{$ref}%")->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $ref = trim((string) $ligne['reference']);
        $etudiant = $this->resoudreEtudiant(trim((string) $ligne['etudiant_matricule']));

        if ($etudiant === null) {
            throw new \DomainException('Étudiant introuvable.');
        }

        $annee = ! empty($ligne['annee_code'])
            ? AnneeUniversitaire::where('libelle', trim((string) $ligne['annee_code']))->first()
            : AnneeUniversitaire::active();

        $typeDoc = mb_strtolower(trim((string) $ligne['type_document']));

        $doc = DocumentAcademique::updateOrCreate([
            'nom_original' => "{$ref}.pdf",
            'etudiant_id' => $etudiant->id,
        ], [
            'annee_universitaire_id' => $annee?->id,
            'type_document' => $typeDoc,
            'chemin' => "archives/etudiant_{$etudiant->id}/{$ref}.pdf",
            'mime_type' => 'application/pdf',
            'taille' => 1024,
            'hash_fichier' => hash('sha256', $ref),
            'uploaded_by' => $auteurId,
        ]);

        if (class_exists(GeneratedDocument::class) && $etudiant->dossier !== null) {
            GeneratedDocument::updateOrCreate([
                'reference' => $ref,
            ], [
                'student_dossier_id' => $etudiant->dossier->id,
                'type' => $typeDoc,
                'numero' => $ref,
                'statut' => ! empty($ligne['statut']) ? mb_strtolower(trim((string) $ligne['statut'])) : 'a_generer',
                'storage_path' => "archives/etudiant_{$etudiant->id}/{$ref}.pdf",
                'sha256' => hash('sha256', $ref),
                'generated_by' => $auteurId,
            ]);
        }

        return "document académique enregistré : {$ref}";
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
        return DocumentAcademique::with(['etudiant', 'anneeUniversitaire'])->orderByDesc('id');
    }

    public function filtrer($query, Request $request)
    {
        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($q) => $q->where('nom_original', 'like', $motif)->orWhere('type_document', 'like', $motif));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'reference' => str_replace('.pdf', '', $modele->nom_original),
            'etudiant_matricule' => $modele->etudiant?->num_inscription,
            'type_document' => $modele->type_document,
            'annee_code' => $modele->anneeUniversitaire?->libelle,
            'statut' => 'DISPONIBLE',
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
