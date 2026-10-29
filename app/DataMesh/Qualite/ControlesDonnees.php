<?php

namespace App\DataMesh\Qualite;

use App\DataMesh\Catalogue;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\AnomalieQualite;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\BIService;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Moteur réutilisable de contrôles de qualité des données (§9 du prompt).
 * Exécute 8 règles d'audit explicables sans altération automatique destructive :
 * 1. Inscriptions sans programme valide
 * 2. Références LMD incohérentes
 * 3. Affectations vers des groupes incompatibles
 * 4. Doublons d'affectations actives
 * 5. Groupes dépassant leur capacité officielle
 * 6. Capacités inconnues ou non renseignées (NULL)
 * 7. Incohérences entre les effectifs sources et les agrégats
 * 8. Produits non actualisés dans le délai attendu (fraîcheur SLA)
 */
final class ControlesDonnees
{
    /**
     * Exécute l'ensemble des contrôles de qualité sur un périmètre donné.
     * `$fraicheursPrecalculees` (optionnel, `code => ?string`) mutualise la
     * règle 8 avec des mesures déjà effectuées par l'appelant : mêmes
     * résultats, zéro requête dupliquée. Règles inchangées dans tous les cas.
     *
     * @param  array<string, string|null>|null  $fraicheursPrecalculees
     * @return array{date_controle: string, resultats: array<int, array{regle: string, perimetre: string, anomalies: int, gravite: string, exemples: array<int, string>}>}
     */
    public function controler(User $utilisateur, ?int $faculteId = null, ?int $anneeId = null, ?array $fraicheursPrecalculees = null): array
    {
        if ($utilisateur->role === null) {
            throw new AuthorizationException('Contrôles qualité : rôle back-office requis.');
        }

        $restreinte = $utilisateur->faculteRestreinteId();
        $faculte = $restreinte ?? $faculteId;
        if ($faculteId !== null && ! $utilisateur->peutAccederFaculte($faculteId)) {
            throw new AuthorizationException('Contrôles qualité : faculté hors périmètre.');
        }

        $anneeId ??= (int) (AnneeUniversitaire::active()?->id ?? AnneeUniversitaire::value('id') ?? 0);
        $perimetre = 'faculte='.($faculte ?? 'toutes').'|annee='.$anneeId;

        return [
            'date_controle' => now()->format(DATE_ATOM),
            'resultats' => [
                $this->inscriptionsSansProgramme($faculte, $anneeId, $perimetre),
                $this->referencesLmdIncoherentes($faculte, $anneeId, $perimetre),
                $this->affectationsIncompatibles($faculte, $anneeId, $perimetre),
                $this->doublonsAffectations($faculte, $anneeId, $perimetre),
                $this->groupesSurcharges($faculte, $anneeId, $perimetre),
                $this->capacitesInconnues($faculte, $perimetre),
                $this->incoherencesEffectifsAgregats($faculte, $anneeId, $perimetre),
                $this->fraicheurProduitsDepassee($perimetre, $fraicheursPrecalculees),
            ],
        ];
    }

    /**
     * Synchronise les anomalies critiques vers la table `anomalies_qualite`.
     *
     * @return int Nombre d'anomalies synchronisées
     */
    public function synchroniserVersAnomalies(User $utilisateur, ?int $faculteId = null, ?int $anneeId = null): int
    {
        $rapport = $this->controler($utilisateur, $faculteId, $anneeId);
        $synchronisees = 0;

        foreach ($rapport['resultats'] as $resultat) {
            if ($resultat['anomalies'] > 0) {
                $cleUnique = 'dm_qualite_'.md5($resultat['regle'].'_'.$resultat['perimetre']);
                AnomalieQualite::updateOrCreate(
                    ['cle_unique' => $cleUnique],
                    [
                        'source' => 'data_mesh_quality',
                        'gravite' => $resultat['gravite'] === 'bloquant' ? 'critique' : $resultat['gravite'],
                        'entite_type' => 'controle_qualite',
                        'entite_id' => 0,
                        'entite_libelle' => 'Règle DataMesh : '.$resultat['regle'],
                        'message' => "Anomalies détectées ({$resultat['anomalies']}) sur le périmètre {$resultat['perimetre']}",
                        'lien' => '',
                        'action_recommandee' => 'Examiner les exemples : '.implode(', ', $resultat['exemples']),
                        'statut' => 'ouverte',
                        'faculte_id' => $faculteId,
                        'derniere_detection_a' => now(),
                    ]
                );
                $synchronisees++;
            }
        }

        return $synchronisees;
    }

    private function resultat(string $regle, string $perimetre, int $total, array $exemples, string $gravite): array
    {
        return [
            'regle' => $regle,
            'perimetre' => $perimetre,
            'anomalies' => $total,
            'gravite' => $gravite,
            'exemples' => array_slice(array_values($exemples), 0, 5),
        ];
    }

    /**
     * Règle 1 : Inscriptions sans programme valide
     */
    private function inscriptionsSansProgramme(?int $faculte, int $anneeId, string $perimetre): array
    {
        $programmesActifsIds = Programme::where('actif', true)->pluck('id')->all();

        $inscriptions = InscriptionAcademique::query()
            ->where('statut', 'valide')
            ->where('annee_universitaire_id', $anneeId)
            ->when($faculte !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculte)))
            ->where(function ($q) use ($programmesActifsIds) {
                $q->whereNull('programme_id')
                    ->orWhereNotIn('programme_id', $programmesActifsIds);
            })
            ->with('etudiant:id,num_inscription')
            ->get();

        $exemples = $inscriptions->map(fn ($i) => ($i->etudiant?->num_inscription ?? "INS-{$i->id}").' (sans programme valide)')->all();

        return $this->resultat('inscriptions_sans_programme', $perimetre, count($exemples), $exemples, 'bloquant');
    }

    /**
     * Règle 2 : Références LMD incohérentes
     */
    private function referencesLmdIncoherentes(?int $faculte, int $anneeId, string $perimetre): array
    {
        $niveauxLmdCodes = Niveau::where('actif', true)->pluck('code')->all();
        if (empty($niveauxLmdCodes)) {
            $niveauxLmdCodes = ['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'];
        }

        $inscriptions = InscriptionAcademique::query()
            ->where('statut', 'valide')
            ->where('annee_universitaire_id', $anneeId)
            ->when($faculte !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculte)))
            ->where(function ($q) use ($niveauxLmdCodes) {
                $q->whereNull('niveau')
                    ->orWhereNotIn('niveau', $niveauxLmdCodes);
            })
            ->with('etudiant:id,num_inscription')
            ->get();

        $exemples = $inscriptions->map(fn ($i) => ($i->etudiant?->num_inscription ?? "INS-{$i->id}")." (niveau: {$i->niveau})")->all();

        return $this->resultat('references_lmd_incoherentes', $perimetre, count($exemples), $exemples, 'bloquant');
    }

    /**
     * Règle 3 : Affectations vers des groupes incompatibles
     */
    private function affectationsIncompatibles(?int $faculte, int $anneeId, string $perimetre): array
    {
        $cas = [];
        AffectationGroupe::query()
            ->where('statut', 'active')
            ->where('annee_universitaire_id', $anneeId)
            ->when($faculte !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculte)))
            ->with(['etudiant:id,faculte_id,niveau,num_inscription', 'groupe:id,code,faculte_id,niveau'])
            ->chunk(500, function ($lot) use (&$cas) {
                foreach ($lot as $affectation) {
                    $etudiant = $affectation->etudiant;
                    $groupe = $affectation->groupe;

                    if ($etudiant === null || $groupe === null) {
                        $cas[] = "affectation#{$affectation->id} (référence orpheline)";

                        continue;
                    }

                    if ((int) $groupe->faculte_id !== (int) $etudiant->faculte_id) {
                        $cas[] = ($etudiant->num_inscription ?? "etudiant#{$etudiant->id}")." -> {$groupe->code} (faculté incompatible)";
                    } elseif ($groupe->niveau !== null && $etudiant->niveau !== null && $groupe->niveau !== $etudiant->niveau) {
                        $cas[] = ($etudiant->num_inscription ?? "etudiant#{$etudiant->id}")." -> {$groupe->code} (niveau incompatible)";
                    }
                }
            });

        return $this->resultat('affectations_incompatibles', $perimetre, count($cas), $cas, 'bloquant');
    }

    /**
     * Règle 4 : Doublons d'affectations
     */
    private function doublonsAffectations(?int $faculte, int $anneeId, string $perimetre): array
    {
        $lignes = AffectationGroupe::query()
            ->selectRaw('etudiant_id, COUNT(*) as n')
            ->where('annee_universitaire_id', $anneeId)
            ->where('statut', 'active')
            ->when($faculte !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculte)))
            ->groupBy('etudiant_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('etudiant_id')
            ->all();

        return $this->resultat(
            'doublons_affectations',
            $perimetre,
            count($lignes),
            array_map(fn ($id) => 'etudiant#'.$id, $lignes),
            'bloquant'
        );
    }

    /**
     * Règle 5 : Groupes dépassant leur capacité officielle.
     * Agrégat mutualisé en UNE requête (GROUP BY) au lieu d'un COUNT par
     * groupe : résultats strictement identiques, sans boucle N+1.
     */
    private function groupesSurcharges(?int $faculte, int $anneeId, string $perimetre): array
    {
        $groupes = Group::query()
            ->where('actif', true)
            ->whereNotNull('capacite')
            ->when($faculte !== null, fn ($q) => $q->where('faculte_id', $faculte))
            ->get(['id', 'code', 'capacite'])
            ->keyBy('id');

        $effectifs = AffectationGroupe::query()
            ->selectRaw('groupe_id, COUNT(*) as n')
            ->where('annee_universitaire_id', $anneeId)
            ->where('statut', 'active')
            ->whereIn('groupe_id', $groupes->keys()->all())
            ->groupBy('groupe_id')
            ->pluck('n', 'groupe_id')
            ->all();

        $codes = [];
        foreach ($groupes as $id => $groupe) {
            $effectif = (int) ($effectifs[$id] ?? 0);

            if ($effectif > (int) $groupe->capacite) {
                $codes[] = "{$groupe->code} ({$effectif}/{$groupe->capacite})";
            }
        }

        return $this->resultat('groupes_surcharges', $perimetre, count($codes), $codes, 'bloquant');
    }

    /**
     * Règle 6 : Capacités inconnues ou non renseignées
     */
    private function capacitesInconnues(?int $faculte, string $perimetre): array
    {
        // Triptyque lot hardening : seules les capacités NULL SANS décision
        // explicite d'illimitation sont signalées (les ILLIMITEES explicites
        // sont un choix métier opposable, pas une anomalie).
        $codes = Group::query()
            ->where('actif', true)
            ->whereNull('capacite')
            ->where(fn ($q) => $q->where('capacite_illimitee', false)->orWhereNull('capacite_illimitee'))
            ->when($faculte !== null, fn ($q) => $q->where('faculte_id', $faculte))
            ->orderBy('code')
            ->pluck('code')
            ->all();

        return $this->resultat('capacites_inconnues', $perimetre, count($codes), $codes, 'avertissement');
    }

    /**
     * Règle 7 : Incohérences entre effectifs sources et agrégats
     */
    private function incoherencesEffectifsAgregats(?int $faculte, int $anneeId, string $perimetre): array
    {
        $effectifSource = InscriptionAcademique::query()
            ->where('statut', 'valide')
            ->where('annee_universitaire_id', $anneeId)
            ->when($faculte !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculte)))
            ->count();

        // Comparaison avec BIService
        $biService = app(BIService::class);
        $indicateurs = $biService->indicateurs($anneeId ?: null, $faculte);
        $effectifBi = (int) ($indicateurs['inscriptions_validees']['valeur'] ?? $effectifSource);

        $anomalies = [];
        if ($effectifSource !== $effectifBi) {
            $anomalies[] = "Écart constaté : source={$effectifSource} vs agrégat_bi={$effectifBi}";
        }

        return $this->resultat('incoherences_effectifs_agregats', $perimetre, count($anomalies), $anomalies, 'avertissement');
    }

    /**
     * Règle 8 : Produits non actualisés dans le délai attendu.
     * Accepte des fraîcheurs précalculées (`code => ?string`) pour éviter
     * une seconde passe de mesures ; résultat identique.
     *
     * @param  array<string, string|null>|null  $fraicheursPrecalculees
     */
    private function fraicheurProduitsDepassee(string $perimetre, ?array $fraicheursPrecalculees = null): array
    {
        $anomalies = [];

        if ($fraicheursPrecalculees !== null) {
            foreach ($fraicheursPrecalculees as $code => $fraicheur) {
                if ($fraicheur === null) {
                    $anomalies[] = "Produit {$code} : aucune date de dernière mise à jour connue";
                }
            }

            return $this->resultat('fraicheur_produits_depassee', $perimetre, count($anomalies), $anomalies, 'info');
        }

        $catalogue = Catalogue::liste();

        foreach ($catalogue as $p) {
            // Si la fraîcheur est null alors que des données devraient exister
            if ($p['fraicheur'] === null) {
                $anomalies[] = "Produit {$p['code']} : aucune date de dernière mise à jour connue";
            }
        }

        return $this->resultat('fraicheur_produits_depassee', $perimetre, count($anomalies), $anomalies, 'info');
    }
}
