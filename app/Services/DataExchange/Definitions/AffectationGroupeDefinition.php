<?php

namespace App\Services\DataExchange\Definitions;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use App\Services\DataExchange\Contracts\ExportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportDefinitionInterface;
use App\Services\DataExchange\Contracts\ImportValidationAvancee;
use App\Services\NormalisationService;
use App\Support\Recherche;
use Illuminate\Http\Request;

/**
 * Import + export des affectations groupe (§25-26, §33) : matricule/CIN + code
 * groupe + année, ou enseignement_code + groupe_code.
 */
class AffectationGroupeDefinition implements ExportDefinitionInterface, ImportDefinitionInterface, ImportValidationAvancee
{
    public function entite(): string
    {
        return 'affectations-groupes';
    }

    public function libelle(): string
    {
        return 'Affectations aux groupes';
    }

    public function colonnes(): array
    {
        return ['matricule', 'cin', 'code_groupe', 'annee', 'enseignement_code', 'groupe_code'];
    }

    public function colonnesRequises(): array
    {
        return [];
    }

    public function aliasesColonnes(): array
    {
        return [
            'enseignement_code' => ['code_enseignement', 'enseignement'],
            'groupe_code' => ['code_groupe', 'groupe'],
            'annee' => ['annee_universitaire', 'session'],
            'matricule' => ['code_etudiant', 'etudiant_matricule'],
        ];
    }

    public function regles(): array
    {
        return [
            'matricule' => ['nullable'],
            'cin' => ['nullable', 'string', NormalisationService::REGLE_CIN],
            'code_groupe' => ['nullable', 'string', 'max:50'],
            'annee' => ['nullable'],
            'enseignement_code' => ['nullable', 'string', 'max:50'],
            'groupe_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function validerLigneMetier(array $ligne, ?int $auteurId): array
    {
        if (! empty($ligne['enseignement_code'])) {
            $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();
            if ($ens === null) {
                return [[
                    'champ' => 'enseignement_code',
                    'message' => "L'enseignement « {$ligne['enseignement_code']} » est introuvable.",
                    'code' => 'ENSEIGNEMENT_NOT_FOUND',
                ]];
            }
            $groupeCode = $ligne['groupe_code'] ?? ($ligne['code_groupe'] ?? '');
            $grp = Group::where('code', trim((string) $groupeCode))->first();
            if ($grp === null) {
                return [[
                    'champ' => 'groupe_code',
                    'message' => "Le groupe « {$groupeCode} » est introuvable.",
                    'code' => 'GROUP_NOT_FOUND',
                ]];
            }

            return [];
        }

        return [];
    }

    public function cleMetier(array $ligne): ?string
    {
        if (! empty($ligne['enseignement_code'])) {
            $groupeCode = $ligne['groupe_code'] ?? ($ligne['code_groupe'] ?? '');

            return trim((string) $ligne['enseignement_code']).'|'.trim((string) $groupeCode);
        }

        return null;
    }

    public function cleDoublon(): array
    {
        return ['enseignement_code', 'groupe_code', 'matricule', 'code_groupe'];
    }

    public function trouverExistant(array $ligne): ?object
    {
        if (! empty($ligne['enseignement_code'])) {
            $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();
            $groupeCode = $ligne['groupe_code'] ?? ($ligne['code_groupe'] ?? '');
            $grp = Group::where('code', trim((string) $groupeCode))->first();
            if ($ens !== null && $grp !== null) {
                return $ens->groupes()->where('groups.id', $grp->id)->first();
            }

            return null;
        }

        $resolution = $this->resoudre($ligne);

        if ($resolution === null) {
            return null;
        }

        return AffectationGroupe::where('etudiant_id', $resolution['etudiant']->id)
            ->where('groupe_id', $resolution['groupe']->id)
            ->where('annee_universitaire_id', $resolution['annee']->id)
            ->where('statut', 'active')
            ->first();
    }

    public function persister(array $ligne, string $mode, ?int $auteurId): string
    {
        if (! empty($ligne['enseignement_code'])) {
            $ens = Enseignement::where('code', trim((string) $ligne['enseignement_code']))->first();
            $groupeCode = $ligne['groupe_code'] ?? ($ligne['code_groupe'] ?? '');
            $grp = Group::where('code', trim((string) $groupeCode))->first();

            if ($ens === null || $grp === null) {
                throw new \DomainException('Enseignement ou groupe introuvable.');
            }

            $ens->groupes()->syncWithoutDetaching([$grp->id]);

            return "affectation enseignement-groupe : {$grp->code}";
        }

        $resolution = $this->resoudre($ligne);

        if ($resolution === null) {
            throw new \DomainException('Étudiant, groupe ou année introuvable.');
        }

        $existant = $this->trouverExistant($ligne);

        if ($existant !== null) {
            return "déjà affecté : {$ligne['code_groupe']}";
        }

        if ($mode === 'creer' && $this->trouverExistant($ligne) !== null) {
            return "doublon ignoré : {$ligne['code_groupe']}";
        }

        // Faculte de l'auteur : refus hors périmètre (anti-fuite import).
        $auteur = $auteurId !== null ? User::find($auteurId) : null;

        if ($auteur !== null && $auteur->faculteRestreinteId() !== null
            && ! $auteur->peutAccederFaculte((int) $resolution['etudiant']->faculte_id)) {
            throw new \DomainException('Étudiant hors périmètre.');
        }

        app(AffectationService::class)->affecter(
            $resolution['etudiant'],
            $resolution['groupe'],
            $resolution['annee'],
            $auteurId,
            'Import DataExchange',
        );

        return "affecté : {$ligne['code_groupe']}";
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
        $query = AffectationGroupe::with(['etudiant', 'groupe', 'anneeUniversitaire'])->orderByDesc('date_debut');

        if ($request->user()->faculteRestreinteId() !== null) {
            $query->whereHas('etudiant', fn ($q) => $q->where('faculte_id', $request->user()->faculteRestreinteId()));
        }

        return $query;
    }

    public function filtrer($query, Request $request)
    {
        if ($ids = $request->input('ids')) {
            $query->whereIn('affectations_groupe.id', array_map('intval', (array) $ids));
        }

        if ($search = trim($request->input('search', ''))) {
            $motif = Recherche::contient($search);
            $query->where(fn ($w) => $w
                ->whereHas('groupe', fn ($q) => $q->where('code', 'like', $motif))
                ->orWhereHas('etudiant', fn ($q) => $q->where('nom', 'like', $motif)->orWhere('prenom', 'like', $motif)));
        }

        return $query;
    }

    public function ligne($modele): array
    {
        return [
            'matricule' => $modele->etudiant?->dossierAcademique?->matricule ?? $modele->etudiant?->num_inscription ?? '',
            'cin' => $modele->etudiant?->cin ?? '',
            'code_groupe' => $modele->groupe?->code ?? '',
            'annee' => $modele->anneeUniversitaire?->libelle ?? '',
        ];
    }

    /** @return array{etudiant: Etudiant, groupe: Group, annee: AnneeUniversitaire}|null */
    private function resoudre(array $ligne): ?array
    {
        $etudiant = null;

        if (! empty($ligne['matricule'])) {
            $etudiant = Etudiant::whereHas('dossierAcademique', fn ($q) => $q->where('matricule', $ligne['matricule']))->first();
        }

        if ($etudiant === null && ! empty($ligne['cin'])) {
            $etudiant = Etudiant::where('cin', $ligne['cin'])->first();
        }

        $groupe = Group::where('code', mb_strtoupper((string) ($ligne['code_groupe'] ?? '')))->first();
        $annee = AnneeUniversitaire::where('libelle', $ligne['annee'] ?? '')->first();

        if ($etudiant === null || $groupe === null || $annee === null) {
            return null;
        }

        return ['etudiant' => $etudiant, 'groupe' => $groupe, 'annee' => $annee];
    }
}
