<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\InscriptionService;
use App\Support\Recherche;
use Illuminate\Http\Request;

class ProgressionDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'progressions';
    }

    public function libelle(): string
    {
        return 'Progressions académiques';
    }

    public function colonnes(): array
    {
        return ['etudiant_matricule', 'annee_source', 'niveau_source', 'decision', 'niveau_cible', 'annee_cible'];
    }

    public function colonnesRequises(): array
    {
        return ['etudiant_matricule', 'decision', 'niveau_cible', 'annee_cible'];
    }

    public function aliasesColonnes(): array
    {
        return [
            'etudiant_matricule' => ['matricule', 'code_etudiant', 'etudiant'],
            'annee_source' => ['annee_precedente', 'annee_origine'],
            'niveau_source' => ['niveau_precedent', 'niveau_origine'],
            'decision' => ['verdict', 'resultat'],
            'niveau_cible' => ['niveau_suivant', 'niveau_futur'],
            'annee_cible' => ['annee_suivante', 'annee_future'],
        ];
    }

    public function regles(): array
    {
        return [
            'etudiant_matricule' => ['required', 'string', 'max:50'],
            'annee_source' => ['nullable', 'string', 'max:20'],
            'niveau_source' => ['nullable', 'string', 'max:20'],
            'decision' => ['required', 'string', 'max:50'],
            'niveau_cible' => ['required', 'string', 'max:20'],
            'annee_cible' => ['required', 'string', 'max:20'],
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

        $niveau = $this->resoudreNiveauCible($ligne, $etudiant);
        if ($niveau === null) {
            $erreurs[] = [
                'champ' => 'niveau_cible',
                'message' => "Le niveau cible « {$ligne['niveau_cible']} » est introuvable ou ambigu (programme de l'étudiant inconnu).",
                'code' => 'NIVEAU_NOT_FOUND',
            ];
        }

        return $erreurs;
    }

    /**
     * Le format « progressions » ne porte pas de programme : on le déduit
     * de la dernière inscription de l'étudiant. Sans programme connu,
     * seul un code globalement unique est accepté — jamais le premier
     * niveau venu.
     */
    private function resoudreNiveauCible(array $ligne, ?Etudiant $etudiant): ?Niveau
    {
        $code = $ligne['niveau_cible'] ?? null;

        if ($etudiant === null) {
            return Niveau::resoudreCodeUnique($code);
        }

        $programmeId = $etudiant->inscriptionsAcademiques()
            ->latest('annee_universitaire_id')
            ->value('programme_id');

        return $programmeId !== null
            ? Niveau::resoudreDansProgramme($code, (int) $programmeId)
            : Niveau::resoudreCodeUnique($code);
    }

    public function cleMetier(array $ligne): ?string
    {
        return trim((string) ($ligne['etudiant_matricule'] ?? '')).'|'.trim((string) ($ligne['annee_cible'] ?? ''));
    }

    public function cleDoublon(): array
    {
        return ['etudiant_matricule', 'annee_cible'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $etudiant = $this->resoudreEtudiant(trim((string) $ligne['etudiant_matricule']));
        $annee = AnneeUniversitaire::where('libelle', trim((string) $ligne['annee_cible']))->first();

        if ($etudiant === null || $annee === null) {
            return null;
        }

        return InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $annee->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $etudiant = $this->resoudreEtudiant(trim((string) $ligne['etudiant_matricule']));
        $niveauCible = $this->resoudreNiveauCible($ligne, $etudiant);

        if ($etudiant === null || $niveauCible === null) {
            throw new \DomainException('Étudiant ou niveau cible introuvable.');
        }

        $anneeLibelle = trim((string) $ligne['annee_cible']);
        $anneeCible = AnneeUniversitaire::firstOrCreate([
            'libelle' => $anneeLibelle,
        ], [
            'active' => false,
            'date_debut' => substr($anneeLibelle, 0, 4).'-09-01',
            'date_fin' => substr($anneeLibelle, 5, 4).'-08-31',
        ]);

        $inscriptions = app(InscriptionService::class);

        $attributs = [
            'programme_id' => $niveauCible->programme_id,
            'niveau_id' => $niveauCible->id,
            'niveau' => $niveauCible->code,
            'statut' => 'valide',
            'observation' => 'Progression académique importée',
        ];

        // Upsert sous la règle d'unicité annuelle CENTRALISÉE : lecture,
        // puis création sous garde ; si une création concurrente gagne la
        // paire (étudiant, année), on retombe sur sa mise à jour — jamais
        // de doublon ni d'erreur technique (idempotence d'import).
        $inscription = $inscriptions->inscriptionAnnuelle($etudiant->id, $anneeCible->id);

        if ($inscription === null) {
            try {
                $inscription = $inscriptions->creerInscriptionAnnuelle(
                    (int) $etudiant->id,
                    (int) $anneeCible->id,
                    $attributs,
                );
            } catch (\DomainException) {
                $inscription = $inscriptions->inscriptionAnnuelle($etudiant->id, $anneeCible->id);

                if ($inscription === null) {
                    throw new \DomainException('Progression refusée : inscription annuelle en conflit introuvable.');
                }

                $inscription->update($attributs);
            }
        } else {
            $inscription->update($attributs);
        }

        return "progression validée : {$etudiant->nom} {$etudiant->prenom} vers {$niveauCible->code} ({$anneeCible->libelle})";
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
        return InscriptionAcademique::with(['etudiant', 'anneeUniversitaire', 'programme'])
            ->where(function ($q) {
                $q->where('type', 'reinscription')
                    ->orWhere('observation', 'like', '%progression%');
            })
            ->orderByDesc('id');
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
            'etudiant_matricule' => $modele->etudiant?->num_inscription,
            'annee_source' => '',
            'niveau_source' => '',
            'decision' => 'PASSAGE',
            'niveau_cible' => $modele->programme?->nom,
            'annee_cible' => $modele->anneeUniversitaire?->libelle,
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
