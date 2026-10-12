<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use Illuminate\Support\Collection;

/**
 * Résolution d'audience (Phase 15 §15.2) : transforme une définition
 * déclarative en destinataires étudiants, TOUJOURS filtrée par le
 * périmètre de l'expéditeur (faculté restreinte prioritaire).
 * Règles combinables : inclusion ET, exclusion SAUF, doublons retirés.
 *
 * Définition : ['faculte_id'=>?, 'programme_id'=>?, 'niveau_id'=>?,
 *   'groupe_id'=>?, 'epreuve_id'=>? (candidats), 'etudiant_ids'=>[],
 *   'exclude_etudiant_ids'=>[]]
 *
 * @return array{etudiants: Collection<int, Etudiant>, total: int, echantillon: array<int, string>, sans_contact: int}
 */
class AudienceResolver
{
    public function resoudre(array $definition, ?User $expediteur, int $limiteEchantillon = 5): array
    {
        $query = Etudiant::query();

        if (! empty($definition['etudiant_ids'])) {
            $query->whereIn('id', array_map('intval', (array) $definition['etudiant_ids']));
        }

        foreach (['faculte_id', 'programme_id', 'niveau_id', 'groupe_id'] as $champ) {
            if (! empty($definition[$champ])) {
                $query->where($champ === 'groupe_id' ? 'groupe_id' : $champ, (int) $definition[$champ]);
            }
        }

        if (! empty($definition['epreuve_id'])) {
            $etudiantIds = Candidat::where('epreuve_id', (int) $definition['epreuve_id'])
                ->pluck('etudiant_id')->all();
            $query->whereIn('id', $etudiantIds);
        }

        if (! empty($definition['exclude_etudiant_ids'])) {
            $query->whereNotIn('id', array_map('intval', (array) $definition['exclude_etudiant_ids']));
        }

        // Périmètre de l'expéditeur : jamais de ciblage hors périmètre.
        $restreinte = $expediteur?->faculteRestreinteId();

        if ($restreinte !== null) {
            $query->where('faculte_id', $restreinte);
        }

        $etudiants = $query->orderBy('nom')->orderBy('prenom')->get();
        $uniques = $etudiants->unique('id')->values();

        return [
            'etudiants' => $uniques,
            'total' => $uniques->count(),
            'echantillon' => $uniques->take($limiteEchantillon)
                ->map(fn ($e) => $e->nom_complet)->values()->all(),
            'sans_contact' => $uniques->filter(fn ($e) => empty($e->email))->count(),
        ];
    }

    /**
     * Aperçu avant envoi (Phase 15 §15.2) : effectif, échantillon,
     * doublons retirés — sans écrire ni exposer de PII en masse.
     *
     * @return array{total: int, echantillon: array<int, string>, sans_contact: int}
     */
    public function previsualiser(array $definition, ?User $expediteur): array
    {
        $resolution = $this->resoudre($definition, $expediteur);

        return [
            'total' => $resolution['total'],
            'echantillon' => $resolution['echantillon'],
            'sans_contact' => $resolution['sans_contact'],
        ];
    }
}
