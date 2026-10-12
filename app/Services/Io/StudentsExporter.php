<?php

namespace App\Services\Io;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use Illuminate\Support\Facades\Config;

/**
 * Export des étudiants — trois profils STRICTEMENT listés en blanc (§43) :
 * - administratif : identité + contact ;
 * - academique     : parcours + résultats (CIN exclue du profil) ;
 * - statistique   : agrégats anonymes (jamais de données personnelles).
 * Filtres multicritères (§70) : tous passent par la whitelist ExportFilters.
 */
class StudentsExporter
{
    /**
     * @param  array<string, mixed>  $filtres  clés épurées (ExportFilters::epurer)
     * @return array{entetes: array<int, string>, lignes: array<int, array<string, mixed>>}
     */
    public function exporter(string $profil, array $filtres, ?int $limite = null): array
    {
        $profil = in_array($profil, ['administratif', 'academique', 'statistique'], true) ? $profil : 'administratif';

        return match ($profil) {
            'statistique' => $this->statistiques($filtres),
            'academique' => $this->parcours($filtres, $limite),
            default => $this->administratif($filtres, $limite),
        };
    }

    /**
     * Profil administratif : CIN, identité, contact, affectation, statut.
     */
    private function administratif(array $filtres, ?int $limite = null): array
    {
        $entetes = Config::get('integrations.profiles.administratif.etudiants');

        $query = Etudiant::query()->with('faculte')->orderBy('nom');
        $this->appliquerFiltres($query, $filtres);
        $this->appliquerPerimetre($query, $filtres);

        $lignes = [];
        foreach ($limite !== null ? $query->limit($limite)->get() : $query->get() as $etudiant) {
            $lignes[] = [
                'cin' => $etudiant->cin,
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'email' => $etudiant->email,
                'telephone' => $etudiant->telephone ?? '',
                'faculte' => $etudiant->faculte->code,
                'niveau' => $etudiant->niveau,
                'statut' => $etudiant->statut,
            ];
        }

        return compact('entetes', 'lignes');
    }

    /**
     * Profil académique : matricule, année, niveau, moyennes, crédits, décision.
     * La CIN et les emails ne font PAS partie de ce profil (minimisation §21/§43).
     */
    private function parcours(array $filtres, ?int $limite = null): array
    {
        $entetes = Config::get('integrations.profiles.academique.etudiants');

        $query = InscriptionAcademique::query()
            ->with(['etudiant.dossierAcademique', 'etudiant.faculte', 'anneeUniversitaire'])
            ->orderBy('annee_universitaire_id');

        $this->appliquerFiltresInscription($query, $filtres);

        $lignes = [];
        foreach ($limite !== null ? $query->limit($limite)->get() : $query->get() as $inscription) {
            $lignes[] = [
                'matricule' => $inscription->etudiant->dossierAcademique?->matricule ?? '',
                'nom' => $inscription->etudiant->nom,
                'prenom' => $inscription->etudiant->prenom,
                'faculte' => $inscription->etudiant->faculte->code,
                'annee' => $inscription->anneeUniversitaire->libelle,
                'niveau' => $inscription->niveau,
                'filiere' => $inscription->filiere ?? '',
                'moyenne_annuelle' => $inscription->moyenne_annuelle ?? '',
                'credits_obtenus' => $inscription->credits_obtenus,
                'credits_total' => $inscription->credits_total,
                'decision' => $inscription->decision ?? '',
            ];
        }

        return compact('entetes', 'lignes');
    }

    /**
     * Profil statistique : effectifs, moyennes et taux de réussite agrégés —
     * aucune donnée individuelle.
     */
    private function statistiques(array $filtres): array
    {
        $entetes = Config::get('integrations.profiles.statistique.etudiants');

        $query = InscriptionAcademique::query()
            ->with(['etudiant.faculte', 'anneeUniversitaire']);

        $this->appliquerFiltresInscription($query, $filtres);

        $agregats = $query->get()
            ->groupBy(fn ($i) => $i->etudiant->faculte->code.'|'.$i->niveau.'|'.$i->anneeUniversitaire->libelle)
            ->map(function ($groupe, $cle) {
                [$faculte, $niveau, $annee] = explode('|', $cle);
                $notees = $groupe->filter(fn ($i) => $i->moyenne_annuelle !== null);

                return [
                    'faculte' => $faculte,
                    'niveau' => $niveau,
                    'annee' => $annee,
                    'effectif' => $groupe->count(),
                    'moyenne_promotion' => $notees->isNotEmpty() ? round((float) $notees->avg('moyenne_annuelle'), 2) : '',
                    'taux_reussite' => $groupe->count() > 0
                        ? round($groupe->where('statut', 'valide')->count() * 100 / $groupe->count(), 1).'%'
                        : '',
                ];
            })
            ->values();

        return ['entetes' => $entetes, 'lignes' => $agregats->all()];
    }

    /**
     * Filtres d'identité en liste blanche (§59/§70) — toute autre valeur
     * est ignorée : jamais de colonne SQL issue directement de l'entrée.
     */
    private function appliquerFiltres($query, array $filtres): void
    {
        if (! empty($filtres['faculte'])) {
            $query->whereHas('faculte', fn ($q) => $q->where('code', mb_strtoupper($filtres['faculte'])));
        }
        if (! empty($filtres['statut'])) {
            $query->where('statut', $filtres['statut']);
        }
        if (! empty($filtres['niveau'])) {
            $query->where('niveau', $filtres['niveau']);
        }
        if (! empty($filtres['cin'])) {
            $query->where('cin', mb_strtoupper($filtres['cin']));
        }
        if (! empty($filtres['nom'])) {
            $query->where('nom', 'like', '%'.trim($filtres['nom']).'%');
        }
        if (! empty($filtres['prenom'])) {
            $query->where('prenom', 'like', '%'.trim($filtres['prenom']).'%');
        }
        if (! empty($filtres['num_inscription'])) {
            $query->where('num_inscription', $filtres['num_inscription']);
        }
        if (! empty($filtres['matricule'])) {
            $query->whereHas('dossierAcademique', fn ($q) => $q->where('matricule', $filtres['matricule']));
        }
        if (! empty($filtres['search'])) {
            $terme = trim((string) $filtres['search']);
            $query->where(function ($q) use ($terme) {
                $q->where('nom', 'like', "%{$terme}%")
                    ->orWhere('prenom', 'like', "%{$terme}%")
                    ->orWhere('cin', 'like', "%{$terme}%");
            });
        }
    }

    /**
     * Périmètre académique (§70) : l'étudiant doit avoir une inscription
     * correspondant aux critères (filière, groupe, année, semestre, matière,
     * statut académique, décision, intervalles de moyenne/crédits).
     */
    private function appliquerPerimetre($query, array $filtres): void
    {
        $criteresInscription = array_filter([
            'filiere', 'groupe', 'annee', 'semestre', 'matiere', 'session',
            'statut_academique', 'decision', 'moyenne_min', 'moyenne_max', 'credits_min', 'credits_max',
        ], fn ($cle) => ! empty($filtres[$cle]));

        if ($criteresInscription === []) {
            return;
        }

        $query->whereHas('inscriptionsAcademiques', function ($q) use ($filtres) {
            $this->appliquerFiltresInscription($q, $filtres);

            if (! empty($filtres['semestre']) || ! empty($filtres['matiere']) || ! empty($filtres['session'])
                || ! empty($filtres['note_min']) || ! empty($filtres['note_max'])) {
                $q->whereHas('notes', function ($qn) use ($filtres) {
                    if (! empty($filtres['semestre'])) {
                        $qn->whereHas('semestre', fn ($qs) => $qs->where('numero', (int) $filtres['semestre']));
                    }
                    if (! empty($filtres['matiere'])) {
                        $qn->whereHas('matiere', fn ($qm) => $qm->where('code', mb_strtoupper($filtres['matiere'])));
                    }
                    if (! empty($filtres['session']) && $filtres['session'] !== 'toutes') {
                        $qn->where('session', $filtres['session']);
                    }
                    if (! empty($filtres['note_min'])) {
                        $qn->where('note', '>=', $filtres['note_min']);
                    }
                    if (! empty($filtres['note_max'])) {
                        $qn->where('note', '<=', $filtres['note_max']);
                    }
                });
            }
        });
    }

    /**
     * Filtres applicables à une requête d'inscriptions académiques.
     */
    private function appliquerFiltresInscription($query, array $filtres): void
    {
        if (! empty($filtres['faculte'])) {
            $query->whereHas('etudiant.faculte', fn ($q) => $q->where('code', mb_strtoupper($filtres['faculte'])));
        }
        if (! empty($filtres['annee'])) {
            $query->whereHas('anneeUniversitaire', fn ($q) => $q->where('libelle', $filtres['annee']));
        }
        if (! empty($filtres['niveau'])) {
            $query->where('niveau', $filtres['niveau']);
        }
        if (! empty($filtres['statut'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('statut', $filtres['statut']));
        }
        if (! empty($filtres['filiere'])) {
            $query->where('filiere', 'like', '%'.trim($filtres['filiere']).'%');
        }
        if (! empty($filtres['groupe'])) {
            $query->where('groupe', $filtres['groupe']);
        }
        if (! empty($filtres['statut_academique'])) {
            $query->where('statut', $filtres['statut_academique']);
        }
        if (! empty($filtres['nom'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('nom', 'like', '%'.trim($filtres['nom']).'%'));
        }
        if (! empty($filtres['prenom'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('prenom', 'like', '%'.trim($filtres['prenom']).'%'));
        }
        if (! empty($filtres['cin'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('cin', mb_strtoupper($filtres['cin'])));
        }
        if (! empty($filtres['num_inscription'])) {
            $query->whereHas('etudiant', fn ($q) => $q->where('num_inscription', $filtres['num_inscription']));
        }
        if (! empty($filtres['matricule'])) {
            $query->whereHas('etudiant.dossierAcademique', fn ($q) => $q->where('matricule', $filtres['matricule']));
        }
        if (! empty($filtres['decision'])) {
            $query->where('decision', 'like', match ($filtres['decision']) {
                'admis' => 'Admis%',
                'ajourne' => 'Ajourné%',
                'rattrapage' => '%rattrapage%',
                default => '%',
            });
        }
        if (! empty($filtres['moyenne_min'])) {
            $query->where('moyenne_annuelle', '>=', $filtres['moyenne_min']);
        }
        if (! empty($filtres['moyenne_max'])) {
            $query->where('moyenne_annuelle', '<=', $filtres['moyenne_max']);
        }
        if (! empty($filtres['credits_min'])) {
            $query->where('credits_obtenus', '>=', $filtres['credits_min']);
        }
        if (! empty($filtres['credits_max'])) {
            $query->where('credits_obtenus', '<=', $filtres['credits_max']);
        }
    }
}
