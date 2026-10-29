<?php

namespace App\Modules\GouvernanceConformite\Services;

use App\Models\Courriel;
use App\Models\HistoriqueAcademique;
use App\Models\IntegrationLog;
use App\Modules\GouvernanceConformite\Models\JournalAccesJournal;
use App\Modules\GouvernanceConformite\Models\JournalChangementPermission;
use App\Modules\GouvernanceConformite\Models\PolitiqueRetention;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lot 19 — Service du centre de gouvernance et conformité.
 *
 * RÈGLES DU CAHIER :
 * - VUE UNIFIÉE des accès sensibles : union des journaux RÉELS du dépôt
 *   (historique académique, échanges intégrés, courriels) — les sources
 *   inexistantes sont SIGNALÉES absentes, jamais masquées ni inventées ;
 * - RÉTENTIONS EXPLICITES ET APPROUVÉES : durées SAISIES ; le module ne
 *   SUPPRIME RIEN — il rapporte les lignes au-delà de la durée approuvée ;
 * - RÉSERVE ÉTHIQUE : proportionnalité stricte (le fait de consulter est
 *   tracé, jamais le contenu lu) ; AUCUNE promesse d'immuabilité — le
 *   rapport décrit l'état réel des journaux sous-jacents.
 */
final class ServiceGouvernance
{
    /** Journaux réels unifiables (tables confirmées du dépôt, lecture seule). */
    public const JOURNAUX = ['historique_academique', 'integration_logs', 'courriels'];

    /**
     * Vue unifiée des accès sensibles : union normalisée des journaux réels.
     *
     * @return array{entrees: array<int, array<string, mixed>>, disponibility: array<string, int>}
     */
    public function accesSensibles(?string $source, int $limite = 200): array
    {
        $entrees = collect();

        if ($source === null || $source === 'historique_academique') {
            HistoriqueAcademique::query()->orderByDesc('created_at')->limit($limite)->get()
                ->each(fn ($l) => $entrees->push([
                    'quand' => optional($l->created_at)->toIso8601String(),
                    'qui' => $l->utilisateur_id,
                    'quoi' => $l->action.' — '.$l->entite.' #'.$l->entite_id,
                    'source' => 'historique_academique',
                ]));
        }

        if ($source === null || $source === 'integration_logs') {
            IntegrationLog::query()->orderByDesc('created_at')->limit($limite)->get()
                ->each(fn ($l) => $entrees->push([
                    'quand' => optional($l->created_at)->toIso8601String(),
                    'qui' => $l->request_id, // système (pas d'usager humain)
                    'quoi' => $l->direction.' '.$l->operation.' — '.$l->integration.' ['.$l->status.']',
                    'source' => 'integration_logs',
                ]));
        }

        if ($source === null || $source === 'courriels') {
            Courriel::query()->orderByDesc('created_at')->limit($limite)->get()
                ->each(fn ($l) => $entrees->push([
                    'quand' => optional($l->created_at)->toIso8601String(),
                    'qui' => $l->destinataire_masque, // destinataire MASQUÉ par conception (PII-safe)
                    'quoi' => 'courriel '.$l->categorie.' ['.$l->statut.']',
                    'source' => 'courriels',
                ]));
        }

        $tries = $entrees->sortByDesc('quand')->values()->take($limite)->all();

        return [
            'entrees' => $tries,
            // Disponibilité RÉELLE par source : 0 ligne = dit explicitement.
            'disponibilite' => [
                'historique_academique' => HistoriqueAcademique::count(),
                'integration_logs' => IntegrationLog::count(),
                'courriels' => Courriel::count(),
            ],
        ];
    }

    /**
     * Journal des changements de permissions (rôles) — lecture seule.
     *
     * @return Collection<int, JournalChangementPermission>
     */
    public function changementsPermissions(int $limite = 200): Collection
    {
        return JournalChangementPermission::query()
            ->orderByDesc('id')
            ->limit($limite)
            ->get();
    }

    /**
     * Rapport de conformité des rétentions : pour chaque journal réel, sa
     * politique APPROUVÉE (ou l'absence explicite) et le nombre de lignes
     * au-delà de la durée — JAMAIS une suppression, jamais une conformité
     * par défaut.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rapportRetention(): array
    {
        $politiques = PolitiqueRetention::query()->get()->keyBy('source_journal');
        $effectifs = [
            'historique_academique' => fn () => HistoriqueAcademique::count(),
            'integration_logs' => fn () => IntegrationLog::count(),
            'courriels' => fn () => Courriel::count(),
        ];

        $lignes = [];
        foreach (self::JOURNAUX as $journal) {
            $politique = $politiques->get($journal);
            $total = $effectifs[$journal]();
            $auDela = null;
            $plusAncienne = null;

            if ($politique !== null && $politique->statut === 'approuvee') {
                $borne = Carbon::now()->subDays($politique->duree_jours);
                $auDela = $this->compterAuDela($journal, $borne);
                $plusAncienne = $this->plusAncienne($journal)?->toIso8601String();
            }

            $lignes[] = [
                'journal' => $journal,
                'total_lignes' => $total,
                'politique' => $politique?->only(['id', 'duree_jours', 'statut', 'approuve_le']),
                // Absence EXPLICITE : jamais de durée par défaut inventée.
                'etat_politique' => $politique === null
                    ? 'absente'
                    : ($politique->statut === 'approuvee' ? 'approuvee' : 'brouillon'),
                'lignes_au_dela' => $auDela, // null si politique absente/non approuvée
                'plus_ancienne' => $plusAncienne,
                'rappel' => 'Le module ne supprime AUCUNE ligne : ce rapport signale, la décision reste institutionnelle.',
            ];
        }

        return $lignes;
    }

    private function compterAuDela(string $journal, Carbon $borne): int
    {
        return match ($journal) {
            'historique_academique' => HistoriqueAcademique::where('created_at', '<', $borne)->count(),
            'integration_logs' => IntegrationLog::where('created_at', '<', $borne)->count(),
            'courriels' => Courriel::where('created_at', '<', $borne)->count(),
            default => 0,
        };
    }

    private function plusAncienne(string $journal): ?Carbon
    {
        $modele = match ($journal) {
            'historique_academique' => HistoriqueAcademique::class,
            'integration_logs' => IntegrationLog::class,
            'courriels' => Courriel::class,
            default => null,
        };

        if ($modele === null) {
            return null;
        }

        $premiere = $modele::query()->orderBy('created_at')->first(['created_at']);

        return $premiere?->created_at;
    }

    /**
     * Trace le FAIT de consulter une vue de gouvernance (proportionné :
     * vue + filtre générique, jamais le contenu lu).
     */
    public function tracerAcces(int $utilisateurId, string $vue, ?string $sourceJournal): void
    {
        JournalAccesJournal::create([
            'utilisateur_id' => $utilisateurId,
            'vue' => $vue,
            'source_journal' => $sourceJournal,
        ]);
    }
}
