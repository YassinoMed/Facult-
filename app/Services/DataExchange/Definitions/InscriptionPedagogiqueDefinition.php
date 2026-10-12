<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\User;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\InscriptionService;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import + export des inscriptions pédagogiques (Phase 4 §10, §18) :
 * matricule + code ECUE + année, règles métier du service (maquette
 * ACTIVE, idempotence) + preview + transaction. Jamais d'écriture
 * directe sans confirmation.
 */
class InscriptionPedagogiqueDefinition implements ExportDefinitionInterface, ImportDefinitionInterface
{
    public function entite(): string
    {
        return 'inscriptions-pedagogiques';
    }

    public function libelle(): string
    {
        return 'Inscriptions pédagogiques (ECUE)';
    }

    public function colonnes(): array
    {
        return ['matricule', 'code_matiere', 'annee'];
    }

    public function colonnesRequises(): array
    {
        return ['matricule', 'code_matiere', 'annee'];
    }

    public function regles(): array
    {
        return [
            'matricule' => ['required', 'string', 'max:30'],
            'code_matiere' => ['required', 'string', 'max:30'],
            'annee' => ['required', 'regex:/^\d{4}-\d{4}$/'],
        ];
    }

    public function cleDoublon(): array
    {
        return ['matricule', 'code_matiere', 'annee'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        $resolution = $this->resoudre($ligne);

        if ($resolution === null) {
            return null;
        }

        return InscriptionPedagogique::where('inscription_id', $resolution['inscription']->id)
            ->where('matiere_id', $resolution['matiere']->id)
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        $resolution = $this->resoudre($ligne);

        if ($resolution === null) {
            throw new \DomainException('Étudiant, inscription, matière ou année introuvable.');
        }

        if ($this->trouverExistant($ligne) !== null) {
            return "déjà inscrit : {$resolution['matiere']->code}";
        }

        // Faculte de l'auteur : refus hors périmètre (anti-fuite import).
        $auteur = $auteurId !== null ? User::find($auteurId) : null;

        if ($auteur !== null && $auteur->faculteRestreinteId() !== null
            && ! $auteur->peutAccederFaculte((int) $resolution['etudiant']->faculte_id)) {
            throw new \DomainException('Étudiant hors périmètre.');
        }

        app(InscriptionService::class)->inscrireEcue(
            $resolution['inscription'],
            $resolution['matiere'],
            $auteurId,
        );

        return "inscrit : {$resolution['matiere']->code}";
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
        $query = InscriptionPedagogique::with(['inscription.etudiant', 'inscription.anneeUniversitaire', 'matiere'])
            ->orderByDesc('id');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->whereHas('inscription.etudiant', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('inscriptions_pedagogiques.id', array_map('intval', (array) $ids));
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w
                ->whereHas('matiere', fn ($q) => $q->where('code', 'like', $motif))
                ->orWhereHas('inscription.etudiant', fn ($q) => $q->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'matricule' => $modele->inscription?->etudiant?->dossierAcademique?->matricule
                ?? $modele->inscription?->etudiant?->num_inscription ?? '',
            'code_matiere' => $modele->matiere?->code ?? '',
            'annee' => $modele->inscription?->anneeUniversitaire?->libelle ?? '',
        ];
    }

    /** @return array{etudiant: Etudiant, inscription: InscriptionAcademique, matiere: Matiere}|null */
    private function resoudre(array $ligne): ?array
    {
        $matricule = mb_strtoupper(trim((string) ($ligne['matricule'] ?? '')));

        $etudiant = Etudiant::whereHas('dossierAcademique', fn ($q) => $q->where('matricule', $matricule))
            ->orWhere('num_inscription', $matricule)
            ->first();

        if ($etudiant === null) {
            return null;
        }

        $inscription = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->whereHas('anneeUniversitaire', fn ($q) => $q->where('libelle', $ligne['annee'] ?? ''))
            ->first();

        $matiere = Matiere::where('code', mb_strtoupper(trim((string) ($ligne['code_matiere'] ?? ''))))->first();

        if ($inscription === null || $matiere === null) {
            return null;
        }

        return ['etudiant' => $etudiant, 'inscription' => $inscription, 'matiere' => $matiere];
    }
}
