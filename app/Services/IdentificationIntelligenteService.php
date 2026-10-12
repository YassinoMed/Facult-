<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\MensReference;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Support\Facades\Log;

/**
 * Identification intelligente (mission MENS) : CIN + date de naissance
 * → référentiel MENS (date exacte, fail-closed) + base locale →
 * statut + divergences + préremplissage autorisé.
 *
 * Statuts : INCONNU | MENS_SEUL | LOCAL_SEUL | MENS_ET_LOCAL.
 * Divergences MENS vs local : jamais d'écrasement, arbitrage
 * administratif explicite (À_VÉRIFIER, BLOQUANT si conflit d'identité).
 */
final class IdentificationIntelligenteService
{
    public function __construct(
        private readonly AcademicProgressionService $progressions,
    ) {}

    /**
     * @return array{statut: string, certitude: string, mens: ?array<string, mixed>, local: ?array<string, mixed>, divergences: array<int, array{champ: string, mens: ?string, local: ?string, gravite: string}>, prefill: array<string, mixed>, specialites: array<int, array{id: int, code: string, nom: string}>, progression: ?array<string, mixed>}
     */
    public function identifier(string $cin, string $dateNaissance): array
    {
        $cinNormalise = MensReference::normaliserCin($cin);

        // Simulation explicite ou gestion de panne de connexion au référentiel MENS
        if (config('inscriptions.mens_simuler_panne', false) === true) {
            Log::warning('mens.identification.indisponible', ['cin' => 'MASQUÉ', 'raison' => 'simulation_panne']);

            return [
                'statut' => 'INDISPONIBLE',
                'certitude' => 'indisponible',
                'mens' => null,
                'local' => null,
                'divergences' => [],
                'prefill' => $this->prefill(null, null),
                'specialites' => [],
                'progression' => null,
            ];
        }

        try {
            $mensRecords = MensReference::where('cin_normalise', $cinNormalise)
                ->whereDate('date_naissance', $dateNaissance)
                ->orderByDesc('id')
                ->get();
            $mens = $mensRecords->first();
        } catch (\Throwable $e) {
            Log::warning('mens.identification.erreur_connexion', ['message' => 'Erreur acces referentiel MENS']);

            return [
                'statut' => 'INDISPONIBLE',
                'certitude' => 'indisponible',
                'mens' => null,
                'local' => null,
                'divergences' => [],
                'prefill' => $this->prefill(null, null),
                'specialites' => [],
                'progression' => null,
            ];
        }

        $localRecords = Etudiant::where('cin', $cinNormalise)
            ->whereDate('date_naissance', $dateNaissance)
            ->get();
        $local = $localRecords->first();

        // Détection d'ambiguïté (doublons locaux conflictuels ou fiches MENS contradictoires)
        $ambigu = false;
        if ($localRecords->count() > 1) {
            $ambigu = true;
        }

        if ($mensRecords->count() > 1) {
            $nomsDistincts = $mensRecords->pluck('nom')->map(fn ($n) => mb_strtoupper(trim((string) $n)))->unique();
            if ($nomsDistincts->count() > 1) {
                $ambigu = true;
            }
        }

        $divergences = $this->divergences($mens, $local);
        $progression = $this->progression($local);

        foreach ($divergences as $div) {
            if (($div['gravite'] ?? '') === 'BLOQUANT') {
                $ambigu = true;
                break;
            }
        }

        $certitude = match (true) {
            $ambigu => 'ambigue',
            $mens === null && $local === null => 'aucune',
            count($divergences) > 0 => 'probable',
            default => 'certaine',
        };

        $statut = match (true) {
            $ambigu => 'AMBIGU',
            $mens !== null && $local !== null => 'MENS_ET_LOCAL',
            $mens !== null => 'MENS_SEUL',
            $local !== null => 'LOCAL_SEUL',
            default => 'INCONNU',
        };

        // Traçabilité (§28) : ids + statut + certitude + situation, jamais de PII.
        Log::debug('mens.identification', [
            'statut' => $statut,
            'certitude' => $certitude,
            'mens_id' => $mens?->id,
            'etudiant_id' => $local?->id,
            'situation' => $progression['situation'] ?? null,
            'divergences' => count($divergences),
        ]);

        return [
            'statut' => $statut,
            'certitude' => $certitude,
            'mens' => $mens !== null ? $this->dtoMens($mens) : null,
            'local' => $local !== null ? $this->dtoLocal($local) : null,
            'divergences' => $divergences,
            'prefill' => $this->prefill($mens, $local),
            'specialites' => $this->specialites($progression, $local),
            'progression' => $progression,
        ];
    }

    /** @return array<int, array{champ: string, mens: ?string, local: ?string, gravite: string}> */
    /**
     * B5 (LOT 1, 05/10/2026) : divergences MENS ↔ local pour un DOSSIER
     * existant (lecture seule, côté admin) — même comparateur que
     * `identifier`, jamais d'écrasement des données locales.
     *
     * @return array<int, array{champ: string, mens: ?string, local: ?string, gravite: string}>
     */
    public function divergencesDossier(Etudiant $etudiant): array
    {
        $mens = MensReference::where('cin_normalise', MensReference::normaliserCin((string) $etudiant->cin))
            ->orderByDesc('annee')
            ->orderByDesc('id')
            ->first();

        return $this->divergences($mens, $etudiant);
    }

    private function divergences(?MensReference $mens, ?Etudiant $local): array
    {
        if ($mens === null || $local === null) {
            return [];
        }

        $trouvees = [];

        foreach (['nom' => 'nom', 'prenom' => 'prénom'] as $champ => $libelle) {
            $valeurMens = mb_strtoupper(trim((string) $mens->{$champ}));
            $valeurLocale = mb_strtoupper(trim((string) $local->{$champ}));

            if ($valeurMens !== '' && $valeurLocale !== '' && $valeurMens !== $valeurLocale) {
                $trouvees[] = [
                    'champ' => $libelle,
                    'mens' => (string) $mens->{$champ},
                    'local' => (string) $local->{$champ},
                    'gravite' => 'A_VERIFIER',
                ];
            }
        }

        // Deux dossiers locaux, même CIN : BLOQUANT (jamais fusionné).
        $doublons = Etudiant::where('cin', $local->cin)->whereKeyNot($local->id)->exists();

        if ($doublons) {
            $trouvees[] = [
                'champ' => 'CIN', 'mens' => null, 'local' => (string) $local->cin, 'gravite' => 'BLOQUANT',
            ];
        }

        return $trouvees;
    }

    /** @return array<string, mixed> */
    private function dtoMens(MensReference $mens): array
    {
        return [
            'nom' => $mens->nom, 'prenom' => $mens->prenom,
            'nom_ar' => $mens->nom_ar, 'prenom_ar' => $mens->prenom_ar,
            'sexe' => $mens->sexe, 'nationalite' => $mens->nationalite,
            'matricule_national' => $mens->matricule_national,
            'etablissement' => $mens->etablissement,
            'annee' => $mens->annee, 'programme' => $mens->programme_code, 'niveau' => $mens->niveau,
        ];
    }

    /** @return array<string, mixed> */
    private function dtoLocal(Etudiant $etudiant): array
    {
        $etudiant->loadMissing(['programmeRef', 'niveauRef']);

        return [
            'num_inscription' => $etudiant->num_inscription,
            'nom' => $etudiant->nom, 'prenom' => $etudiant->prenom,
            'programme' => $etudiant->programmeRef?->nom,
            'niveau' => $etudiant->niveauRef?->code ?? $etudiant->niveau,
            'derniere_inscription' => $etudiant->inscriptionsAcademiques()->latest('annee_universitaire_id')->first()?->anneeUniversitaire?->libelle,
        ];
    }

    /** @return array<string, mixed> */
    private function prefill(?MensReference $mens, ?Etudiant $local): array
    {
        // Priorité locale (dossier validé) > MENS (référence) ; jamais
        // de mot de passe, hash ou statut interne exposé.
        return [
            'cin' => (string) ($local?->cin ?? $mens?->cin_normalise ?? ''),
            'nom' => (string) ($local?->nom ?? $mens?->nom ?? ''),
            'prenom' => (string) ($local?->prenom ?? $mens?->prenom ?? ''),
            'nom_ar' => (string) ($mens?->nom_ar ?? ''),
            'prenom_ar' => (string) ($mens?->prenom_ar ?? ''),
            'date_naissance' => $local?->date_naissance?->format('Y-m-d') ?? $mens?->date_naissance?->format('Y-m-d'),
            'sexe' => $local?->sexe ?? $mens?->sexe,
            'nationalite' => $mens?->nationalite,
            'matricule_national' => $mens?->matricule_national,
            'email' => (string) ($local?->email ?? ''),
            'telephone' => (string) ($local?->telephone ?? ''),
            'adresse' => (string) ($local?->adresse ?? ''),
            'ville' => (string) ($local?->ville ?? ''),
            'code_postal' => (string) ($local?->code_postal ?? ''),
            'faculte_id' => $local?->faculte_id,
        ];
    }

    /** @return array<string, mixed>|null */
    private function progression(?Etudiant $local): ?array
    {
        if ($local === null) {
            return null;
        }

        $derniere = $local->inscriptionsAcademiques()->latest('annee_universitaire_id')->first();

        if ($derniere === null) {
            return ['situation' => 'SANS_HISTORIQUE', 'niveau_propose' => null, 'decision' => null];
        }

        $evaluation = $this->progressions->evaluer($local, $derniere);

        return [
            'situation' => $evaluation['action'],
            'niveau_propose' => $evaluation['niveau_cible']?->code,
            'programme_propose' => $evaluation['programme_cible']?->nom,
            'decision' => $evaluation['decision'],
            'blocages' => $evaluation['blocages'],
        ];
    }

    /**
     * Spécialités candidates pour le niveau proposé : programmes actifs
     * de la même faculté couvrant ce niveau, spécialités distinctes
     * (référentiel, jamais codé en dur ; sans capacité au modèle —
     * la capacité s'applique aux groupes, à l'affectation).
     *
     * @param  array<string, mixed>|null  $progression
     * @return array<int, array{id: int, code: string, nom: string}>
     */
    private function specialites(?array $progression, ?Etudiant $local): array
    {
        $niveau = $progression['niveau_propose'] ?? null;

        if ($local === null || $niveau === null) {
            return [];
        }

        $programmes = Programme::where('faculte_id', $local->faculte_id)
            ->where('actif', true)
            ->whereHas('niveaux', fn ($q) => $q->where('code', $niveau)->where('actif', true))
            ->with('specialite')
            ->get();

        return $programmes->map(fn (Programme $p) => $p->specialite)
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'nom' => $s->nom])
            ->all();
    }
}
