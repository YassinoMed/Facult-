<?php

namespace App\Services;

use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\KpiDefinition;
use App\Models\Note;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Services\Kpi\CatalogueKpi;
use App\Services\Kpi\VersionKpi;
use Illuminate\Support\Facades\Cache;

/**
 * Business Intelligence (§24) : agrège les services existants
 * (population, évaluation, assiduité, demandes, qualité) en KPI
 * documentés (nom, définition, source, population, période).
 * Descriptive uniquement — jamais de décision automatique.
 * Cache versionné, clé scopée (jamais de fuite inter-périmètre).
 */
class BIService
{
    /**
     * @return array<string, array{valeur: int|float|string|null, definition: string, source: string}>
     */
    public function indicateurs(?int $anneeId, ?int $faculteId, ?int $programmeId = null, int $ttl = 600): array
    {
        $version = $this->version();
        $cle = "bi:{$version}:a{$anneeId}:f{$faculteId}:p{$programmeId}";

        return Cache::remember($cle, $ttl, fn () => $this->calculer($anneeId, $faculteId, $programmeId));
    }

    public function version(): string
    {
        $inscriptions = InscriptionAcademique::max('updated_at').InscriptionAcademique::count();
        $notes = Note::max('updated_at').Note::count();
        $etudiants = Etudiant::max('updated_at').Etudiant::count();
        $decisions = class_exists(DecisionDeliberation::class)
            ? DecisionDeliberation::max('updated_at').DecisionDeliberation::count() : '';
        $documents = class_exists(GeneratedDocument::class)
            ? GeneratedDocument::max('updated_at').GeneratedDocument::count() : '';
        $demandes = class_exists(OnlineRequest::class)
            ? OnlineRequest::max('updated_at').OnlineRequest::count() : '';
        $anomalies = class_exists(AnomalieQualite::class)
            ? AnomalieQualite::max('updated_at').AnomalieQualite::count() : '';
        $enseignements = class_exists(Enseignement::class)
            ? Enseignement::max('updated_at').Enseignement::count() : '';

        return md5(VersionKpi::courante()."|{$inscriptions}|{$notes}|{$etudiants}|{$decisions}|{$documents}|{$demandes}|{$anomalies}|{$enseignements}");
    }

    /** @return array<string, array{valeur: int|float|string|null, definition: string, source: string}> */
    private function calculer(?int $anneeId, ?int $faculteId, ?int $programmeId): array
    {
        $etudiants = Etudiant::when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when($programmeId !== null, fn ($q) => $q->where('programme_id', $programmeId));
        $inscriptions = InscriptionAcademique::when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->when($programmeId !== null, fn ($q) => $q->where('programme_id', $programmeId));

        $parNiveau = (clone $etudiants)->selectRaw('niveau, COUNT(*) as total')->groupBy('niveau')->pluck('total', 'niveau')->all();
        $parStatut = (clone $inscriptions)->selectRaw('statut, COUNT(*) as total')->groupBy('statut')->pluck('total', 'statut')->all();

        $moyennes = (clone $inscriptions)->whereNotNull('moyenne_annuelle')->pluck('moyenne_annuelle')->map(fn ($m) => (float) $m);
        $distribution = ['<10' => 0, '10-12' => 0, '12-14' => 0, '14-16' => 0, '16+' => 0];
        foreach ($moyennes as $moyenne) {
            $distribution[$moyenne < 10 ? '<10' : ($moyenne < 12 ? '10-12' : ($moyenne < 14 ? '12-14' : ($moyenne < 16 ? '14-16' : '16+')))]++;
        }

        $mediane = null;
        if ($moyennes->isNotEmpty()) {
            $triees = $moyennes->sort()->values();
            $effectif = $triees->count();
            $milieu = intdiv($effectif, 2);
            $mediane = $effectif % 2 === 1
                ? (float) $triees->get($milieu)
                : ((float) $triees->get($milieu - 1) + (float) $triees->get($milieu)) / 2;
            $mediane = round($mediane, 2);
        }

        $credits = [
            'obtenus' => (int) (clone $inscriptions)->sum('credits_obtenus'),
            'total' => (int) (clone $inscriptions)->sum('credits_total'),
        ];

        return [
            'effectif' => [
                'valeur' => (clone $etudiants)->count(),
                'definition' => 'Étudiants du périmètre sélectionné.',
                'source' => 'etudiants',
            ],
            'effectif_par_niveau' => [
                'valeur' => json_encode($parNiveau, JSON_UNESCAPED_UNICODE),
                'definition' => 'Répartition de l’effectif par niveau.',
                'source' => 'etudiants',
            ],
            'inscriptions_par_statut' => [
                'valeur' => json_encode($parStatut, JSON_UNESCAPED_UNICODE),
                'definition' => 'Inscriptions par statut sur l’année.',
                'source' => 'inscriptions_academiques',
            ],
            'moyenne_mediane' => [
                'valeur' => $mediane,
                'definition' => 'Médiane des moyennes annuelles (n='.$moyennes->count().', insuffisant si n<5).',
                'source' => 'inscriptions_academiques.moyenne_annuelle',
            ],
            'distribution_moyennes' => [
                'valeur' => json_encode($distribution, JSON_UNESCAPED_UNICODE),
                'definition' => 'Distribution des moyennes annuelles par tranche (n='.$moyennes->count().').',
                'source' => 'inscriptions_academiques.moyenne_annuelle',
            ],
            'credits_validation' => [
                'valeur' => $credits['total'] > 0 ? round($credits['obtenus'] / $credits['total'] * 100, 1).' %' : null,
                'definition' => 'Crédits obtenus / crédits totaux sur l’année.',
                'source' => 'inscriptions_academiques',
            ],
            'demandes_retard' => [
                'valeur' => $this->demandesEnRetard($faculteId),
                'definition' => 'Demandes ouvertes dépassant leur délai (hors pause étudiant).',
                'source' => 'online_requests',
            ],
            'anomalies_ouvertes' => [
                'valeur' => AnomalieQualite::whereIn('statut', ['ouverte', 'acquittee', 'en_cours'])
                    ->when($faculteId !== null, fn ($q) => $q->where(
                        fn ($w) => $w->where('faculte_id', $faculteId)->orWhereNull('faculte_id')))
                    ->count(),
                'definition' => 'Anomalies qualité non résolues du périmètre (globales incluses, sans PII).',
                'source' => 'anomalies_qualite',
            ],
            'taux_passage' => [
                'valeur' => $this->tauxPassage($anneeId, $faculteId, $programmeId),
                'definition' => 'Décisions publiées admis* / décisions publiées (jury, même périmètre).',
                'source' => 'evaluation_decisions',
            ],
            'redoublements' => [
                'valeur' => (clone $inscriptions)->where('type', 'reinscription')
                    ->where('observation', 'like', 'Redoublement%')->count(),
                'definition' => 'Réinscriptions explicites sur décision de redoublement.',
                'source' => 'inscriptions_academiques',
            ],
            'volumes_enseignement' => [
                'valeur' => $this->volumesEnseignement($anneeId, $faculteId, $programmeId),
                'definition' => 'Enseignements ouverts + heures prévues (module enseignement).',
                'source' => 'enseignements',
            ],
            'documents_genere' => [
                'valeur' => $this->documentsGeneres($faculteId),
                'definition' => 'Documents officiels valides générés (moteur central, sans contenu).',
                'source' => 'generated_documents',
            ],
        ];
    }

    private function demandesEnRetard(?int $faculteId): int
    {
        if (! config('modules.online-requests.active')) {
            return 0;
        }

        return OnlineRequest::with('type')
            ->whereIn('status', ['submitted', 'received', 'in_review'])
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get()->filter(fn ($d) => $d->enRetard())->count();
    }

    /**
     * @return string|null pourcentage ou null si aucune décision publiée.
     */
    private function tauxPassage(?int $anneeId, ?int $faculteId, ?int $programmeId): ?string
    {
        if (! config('modules.evaluation.active')) {
            return null;
        }

        $publiees = DecisionDeliberation::where('publie', true)
            ->when($anneeId !== null, fn ($q) => $q->whereHas('inscriptionAcademique',
                fn ($i) => $i->where('annee_universitaire_id', $anneeId)))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant',
                fn ($e) => $e->where('faculte_id', $faculteId)))
            ->when($programmeId !== null, fn ($q) => $q->whereHas('inscriptionAcademique',
                fn ($i) => $i->where('programme_id', $programmeId)));

        $total = (clone $publiees)->count();

        if ($total === 0) {
            return null;
        }

        $admis = (clone $publiees)->whereIn('decision', ['admis', 'admis_compensation', 'admis_conditionnel'])->count();

        return round($admis / $total * 100, 1).' %';
    }

    /**
     * @return string|null "ouverts + heures" ou null si module inactif.
     */
    private function volumesEnseignement(?int $anneeId, ?int $faculteId, ?int $programmeId): ?string
    {
        if (! config('modules.enseignement.active')) {
            return null;
        }

        $query = Enseignement::when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($programmeId !== null, fn ($q) => $q->where('programme_id', $programmeId))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('matiere.ue.programme',
                fn ($p) => $p->where('faculte_id', $faculteId)));

        $ouverts = (clone $query)->count();

        $heures = (int) (clone $query)->selectRaw(
            'COALESCE(SUM(volume_cm),0)+COALESCE(SUM(volume_td),0)+COALESCE(SUM(volume_tp),0)+COALESCE(SUM(volume_projet),0)+COALESCE(SUM(volume_autre),0) as total'
        )->value('total');

        return "{$ouverts} ens. / {$heures} h";
    }

    private function documentsGeneres(?int $faculteId): ?string
    {
        if (! config('modules.student-dossiers.active')) {
            return null;
        }

        $query = GeneratedDocument::where('statut', 'valide')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('dossier.etudiant',
                fn ($e) => $e->where('faculte_id', $faculteId)));

        $total = (clone $query)->count();

        $revoques = GeneratedDocument::where('statut', 'revoque')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('dossier.etudiant',
                fn ($e) => $e->where('faculte_id', $faculteId)))
            ->count();

        return "{$total} valides / {$revoques} révoqués";
    }

    /**
     * LOT 03 — Registre persistant des définitions KPI (table
     * `kpi_definitions`, migration additive 2026_10_24_110000) : reflète
     * les catalogues de code (BI centrale ci-dessus + catalogue du
     * cockpit `App\Services\Kpi\CatalogueKpi`), versionne la dérive
     * documentaire (bump de version dès que définition ou source change)
     * et fige le dernier snapshot de valeur du périmètre BI. Descriptive
     * uniquement — jamais d'écriture métier.
     *
     * Fail-soft : table absente (migration non appliquée) = registre
     * ignoré, renvoyé `actif: false` — la couche KPI de code reste la
     * source de vérité fonctionnelle et rien ne casse au rendu.
     *
     * @return array{actif: bool, raison?: string, creees: int, mises_a_jour: int, figurees: int, cockpit: int, bi_service: int}
     */
    public function synchroniserDefinitions(
        ?int $anneeId = null,
        ?int $faculteId = null,
        ?int $programmeId = null,
        int $ttl = 600,
    ): array {
        if (! KpiDefinition::tableDisponible(true)) {
            return [
                'actif' => false,
                'raison' => 'Table kpi_definitions absente (migration 2026_10_24_110000 non appliquée) : registre ignoré, couche KPI de code active.',
            ];
        }

        $creees = 0;
        $misesAJour = 0;
        $figurees = 0;
        $compteurCockpit = 0;
        $compteurBi = 0;

        $contexte = array_filter([
            'annee_id' => $anneeId,
            'faculte_id' => $faculteId,
            'programme_id' => $programmeId,
        ], fn ($v) => $v !== null);

        // 1) BI centrale — les indicateurs() sont déjà calculés et cache
        // versionné : le registre reflète le texte documentaire en place.
        foreach ($this->indicateurs($anneeId, $faculteId, $programmeId, $ttl) as $cle => $donnees) {
            $definition = (string) $donnees['definition'];
            $source = (string) $donnees['source'];
            $hash = md5("{$definition}|{$source}");

            $def = KpiDefinition::where('cle', $cle)->where('provenance', 'bi_service')->first();

            if ($def === null) {
                KpiDefinition::create([
                    'cle' => $cle,
                    'provenance' => 'bi_service',
                    'definition' => $definition,
                    'source' => $source,
                    'definition_hash' => $hash,
                    'version' => 1,
                    'actif' => true,
                    'dernier_snapshot' => ['valeur' => $donnees['valeur']] + $contexte,
                    'dernier_snapshot_a' => now(),
                ]);
                $creees++;
            } elseif ($def->definition_hash !== $hash || $def->definition !== $definition || $def->source !== $source) {
                $def->fill([
                    'definition' => $definition,
                    'source' => $source,
                    'definition_hash' => $hash,
                    'version' => $def->version + 1,
                    'actif' => true,
                ])->save();
                $misesAJour++;
            }

            if ($def !== null) {
                $def->fill([
                    'dernier_snapshot' => ['valeur' => $donnees['valeur']] + $contexte,
                    'dernier_snapshot_a' => now(),
                ])->save();
            }

            $figurees++;
            $compteurBi++;
        }

        // 2) Catalogue du cockpit (définitions uniquement) : la valeur
        // vit et se calcule côté CockpitKpiService — aucune duplication
        // de calcul ici, seule la documentation est versionnée.
        if (class_exists(CatalogueKpi::class)) {
            foreach (CatalogueKpi::tous() as $code => $definition) {
                $hash = md5("{$definition->definition}|{$definition->sourceVerite}");

                $defRegistre = KpiDefinition::where('cle', (string) $code)
                    ->where('provenance', 'catalogue_cockpit')
                    ->first();

                if ($defRegistre === null) {
                    KpiDefinition::create([
                        'cle' => (string) $code,
                        'provenance' => 'catalogue_cockpit',
                        'nom' => $definition->nom,
                        'definition' => $definition->definition,
                        'source' => $definition->sourceVerite,
                        'unite' => $definition->unite,
                        'categorie' => $definition->categorie,
                        'definition_hash' => $hash,
                        'version' => 1,
                        'actif' => true,
                        'dernier_snapshot' => null,
                        'dernier_snapshot_a' => null,
                    ]);
                    $creees++;
                } elseif ($defRegistre->definition_hash !== $hash) {
                    $defRegistre->fill([
                        'nom' => $definition->nom,
                        'definition' => $definition->definition,
                        'source' => $definition->sourceVerite,
                        'unite' => $definition->unite,
                        'categorie' => $definition->categorie,
                        'definition_hash' => $hash,
                        'version' => $defRegistre->version + 1,
                        'actif' => true,
                    ])->save();
                    $misesAJour++;
                } else {
                    $defRegistre->fill([
                        'nom' => $definition->nom,
                        'unite' => $definition->unite,
                        'categorie' => $definition->categorie,
                        'actif' => true,
                    ])->save();
                }

                $compteurCockpit++;
            }
        }

        return [
            'actif' => true,
            'creees' => $creees,
            'mises_a_jour' => $misesAJour,
            'figurees' => $figurees,
            'cockpit' => $compteurCockpit,
            'bi_service' => $compteurBi,
        ];
    }

    /**
     * Cohorte (descriptif, §24.12) : année d'entrée (année du bac)
     * × niveau actuel, jamais de prédiction.
     *
     * @return array<int, array{annee_bac: string, niveau: string, total: int}>
     */
    public function cohorte(?int $faculteId): array
    {
        return Etudiant::when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->selectRaw('annee_bac, niveau, COUNT(*) as total')
            ->groupBy('annee_bac', 'niveau')
            ->orderBy('annee_bac')->orderBy('niveau')
            ->limit(200)->get()
            ->map(fn ($ligne) => ['annee_bac' => $ligne->annee_bac, 'niveau' => $ligne->niveau, 'total' => (int) $ligne->total])
            ->all();
    }
}
