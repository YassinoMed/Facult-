<?php

namespace App\Services\Kpi;

use App\Services\Kpi\DTO\DefinitionKpi;
use InvalidArgumentException;

/**
 * Catalogue centralisé et versionné des KPI de scolarité UniRegistre.
 * Définit contractuellement les indicateurs clés, leurs formules,
 * leurs sources de données vérifiables, leurs seuils et SLA.
 */
final class CatalogueKpi
{
    public const VERSION = '1.0';

    /**
     * @var array<string, DefinitionKpi>|null
     */
    private static ?array $catalogue = null;

    /**
     * @return array<string, DefinitionKpi>
     */
    public static function tous(): array
    {
        if (self::$catalogue === null) {
            self::$catalogue = self::initialiser();
        }

        return self::$catalogue;
    }

    public static function obtenir(string $code): DefinitionKpi
    {
        $tous = self::tous();
        if (! isset($tous[$code])) {
            throw new InvalidArgumentException("Indicateur KPI inconnu : « {$code} ».");
        }

        return $tous[$code];
    }

    public static function existe(string $code): bool
    {
        return isset(self::tous()[$code]);
    }

    /**
     * @return array<string, DefinitionKpi>
     */
    public static function parCategorie(string $categorie): array
    {
        return array_filter(self::tous(), fn (DefinitionKpi $def) => $def->categorie === $categorie);
    }

    /**
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return ['inscriptions', 'academique', 'operations', 'qualite', 'examens'];
    }

    /**
     * @return array<string, DefinitionKpi>
     */
    private static function initialiser(): array
    {
        return [
            // ── Inscriptions & Démographie ─────────────────────────────
            'effectif_total' => new DefinitionKpi(
                code: 'effectif_total',
                nom: 'Effectif total d’étudiants',
                categorie: 'inscriptions',
                definition: 'Nombre total d’étudiants enregistrés dans le périmètre actif.',
                formule: 'COUNT(etudiants WHERE faculte_id = :faculte)',
                sourceVerite: 'etudiants',
                unite: 'etudiants',
                seuilsAlerte: null,
                slaFraicheurMinutes: 60,
                sensiblePii: false,
            ),
            'inscriptions_validees' => new DefinitionKpi(
                code: 'inscriptions_validees',
                nom: 'Inscriptions validées',
                categorie: 'inscriptions',
                definition: 'Nombre d’inscriptions académiques ayant le statut validé sur l’année.',
                formule: 'COUNT(inscriptions_academiques WHERE statut = "valide")',
                sourceVerite: 'inscriptions_academiques',
                unite: 'inscriptions',
                seuilsAlerte: null,
                slaFraicheurMinutes: 60,
                sensiblePii: false,
            ),
            'inscriptions_en_attente' => new DefinitionKpi(
                code: 'inscriptions_en_attente',
                nom: 'Inscriptions en attente de validation',
                categorie: 'inscriptions',
                definition: 'Dossiers d’inscription soumis nécessitant une instruction ou validation.',
                formule: 'COUNT(inscriptions_academiques WHERE statut IN ("en_attente", "soumise", "en_cours"))',
                sourceVerite: 'inscriptions_academiques',
                unite: 'dossiers',
                seuilsAlerte: ['attention_si_sup' => 50, 'critique_si_sup' => 200],
                slaFraicheurMinutes: 30,
                sensiblePii: false,
            ),
            'taux_validation_inscriptions' => new DefinitionKpi(
                code: 'taux_validation_inscriptions',
                nom: 'Taux de validation des inscriptions',
                categorie: 'inscriptions',
                definition: 'Proportion des inscriptions validées par rapport au total des inscriptions enregistrées.',
                formule: '(Inscriptions validées / Total inscriptions soumises) * 100',
                sourceVerite: 'inscriptions_academiques',
                unite: '%',
                seuilsAlerte: ['critique_si_inf' => 50.0, 'attention_si_inf' => 75.0],
                slaFraicheurMinutes: 60,
                sensiblePii: false,
            ),

            // ── Performance Académique & LMD ───────────────────────────
            'taux_reussite_deliberations' => new DefinitionKpi(
                code: 'taux_reussite_deliberations',
                nom: 'Taux de réussite aux délibérations',
                categorie: 'academique',
                definition: 'Proportion de décisions admises (admis, compensation, conditionnel) parmi toutes les décisions de jury publiées.',
                formule: '(Décisions admises publiées / Total décisions publiées) * 100',
                sourceVerite: 'evaluation_decisions',
                unite: '%',
                seuilsAlerte: ['critique_si_inf' => 40.0, 'attention_si_inf' => 60.0],
                slaFraicheurMinutes: 120,
                sensiblePii: false,
            ),
            'taux_validation_credits' => new DefinitionKpi(
                code: 'taux_validation_credits',
                nom: 'Taux d’acquisition des crédits ECTS',
                categorie: 'academique',
                definition: 'Ratio des crédits obtenus sur l’ensemble des crédits inscrits sur l’année universitaire.',
                formule: '(SUM(credits_obtenus) / SUM(credits_total)) * 100',
                sourceVerite: 'inscriptions_academiques',
                unite: '%',
                seuilsAlerte: ['critique_si_inf' => 50.0, 'attention_si_inf' => 70.0],
                slaFraicheurMinutes: 120,
                sensiblePii: false,
            ),
            'moyenne_mediane' => new DefinitionKpi(
                code: 'moyenne_mediane',
                nom: 'Médiane des moyennes académiques',
                categorie: 'academique',
                definition: 'Valeur médiane des moyennes annuelles calculées pour les étudiants du périmètre (n >= 5).',
                formule: 'MEDIAN(moyenne_annuelle WHERE moyenne_annuelle IS NOT NULL)',
                sourceVerite: 'inscriptions_academiques.moyenne_annuelle',
                unite: 'points',
                seuilsAlerte: ['critique_si_inf' => 10.0, 'attention_si_inf' => 11.5],
                slaFraicheurMinutes: 120,
                sensiblePii: false,
            ),

            // ── Opérations & Charge Administrative ─────────────────────
            'taches_critiques_ouvertes' => new DefinitionKpi(
                code: 'taches_critiques_ouvertes',
                nom: 'Tâches administratives critiques actives',
                categorie: 'operations',
                definition: 'Tâches du centre de travail au statut ouvert/en cours avec priorité critique.',
                formule: 'COUNT(tasks WHERE priorite = "critical" AND statut IN ("pending", "in_progress", "waiting", "expired"))',
                sourceVerite: 'tasks',
                unite: 'taches',
                seuilsAlerte: ['attention_si_sup' => 5, 'critique_si_sup' => 15],
                slaFraicheurMinutes: 15,
                sensiblePii: false,
            ),
            'taches_en_retard_sla' => new DefinitionKpi(
                code: 'taches_en_retard_sla',
                nom: 'Tâches en retard SLA',
                categorie: 'operations',
                definition: 'Tâches administratives non finalisées dont l’échéance est dépassée.',
                formule: 'COUNT(tasks WHERE echeance_a < NOW() AND statut IN ("pending", "in_progress", "waiting"))',
                sourceVerite: 'tasks.echeance_a',
                unite: 'taches',
                seuilsAlerte: ['attention_si_sup' => 10, 'critique_si_sup' => 25],
                slaFraicheurMinutes: 15,
                sensiblePii: false,
            ),
            'demandes_en_retard' => new DefinitionKpi(
                code: 'demandes_en_retard',
                nom: 'Demandes en ligne hors délai SLA',
                categorie: 'operations',
                definition: 'Demandes étudiantes en cours de traitement dépassant le délai imparti.',
                formule: 'COUNT(online_requests WHERE status IN ("submitted", "received", "in_review") AND retard)',
                sourceVerite: 'online_requests',
                unite: 'demandes',
                seuilsAlerte: ['attention_si_sup' => 10, 'critique_si_sup' => 30],
                slaFraicheurMinutes: 30,
                sensiblePii: false,
            ),

            // ── Qualité & Conformité ───────────────────────────────────
            'anomalies_qualite_ouvertes' => new DefinitionKpi(
                code: 'anomalies_qualite_ouvertes',
                nom: 'Anomalies qualité actives',
                categorie: 'qualite',
                definition: 'Anomalies de données scolarité non résolues détectées par l’audit automatique.',
                formule: 'COUNT(anomalies_qualite WHERE statut IN ("ouverte", "acquittee", "en_cours"))',
                sourceVerite: 'anomalies_qualite',
                unite: 'anomalies',
                seuilsAlerte: ['attention_si_sup' => 15, 'critique_si_sup' => 50],
                slaFraicheurMinutes: 60,
                sensiblePii: false,
            ),
            'anomalies_critiques' => new DefinitionKpi(
                code: 'anomalies_critiques',
                nom: 'Anomalies qualité critiques',
                categorie: 'qualite',
                definition: 'Anomalies qualité de niveau critique menaçant la cohérence académique ou légale.',
                formule: 'COUNT(anomalies_qualite WHERE gravite = "critique" AND statut IN ("ouverte", "acquittee", "en_cours"))',
                sourceVerite: 'anomalies_qualite.gravite',
                unite: 'anomalies',
                seuilsAlerte: ['attention_si_sup' => 1, 'critique_si_sup' => 5],
                slaFraicheurMinutes: 30,
                sensiblePii: false,
            ),

            // ── Examens & Logistique ───────────────────────────────────
            'epreuves_sans_salle' => new DefinitionKpi(
                code: 'epreuves_sans_salle',
                nom: 'Épreuves d’examen sans salle affectée',
                categorie: 'examens',
                definition: 'Épreuves actives planifiées sans salle d’examen attribuée.',
                formule: 'COUNT(evaluation_epreuves WHERE salle_id IS NULL AND statut != "cancelled")',
                sourceVerite: 'evaluation_epreuves.salle_id',
                unite: 'epreuves',
                seuilsAlerte: ['attention_si_sup' => 1, 'critique_si_sup' => 5],
                slaFraicheurMinutes: 60,
                sensiblePii: false,
            ),
            'epreuves_sans_surveillant' => new DefinitionKpi(
                code: 'epreuves_sans_surveillant',
                nom: 'Épreuves sans surveillance complète',
                categorie: 'examens',
                definition: 'Épreuves d’examen actives n’ayant aucun surveillant affecté.',
                formule: 'COUNT(evaluation_epreuves WHERE statut != "cancelled" AND sans_surveillant)',
                sourceVerite: 'evaluation_epreuves.surveillances',
                unite: 'epreuves',
                seuilsAlerte: ['attention_si_sup' => 1, 'critique_si_sup' => 5],
                slaFraicheurMinutes: 60,
                sensiblePii: false,
            ),
            'notes_en_attente_validation' => new DefinitionKpi(
                code: 'notes_en_attente_validation',
                nom: 'Notes d’épreuves à valider',
                categorie: 'examens',
                definition: 'Notes soumises par les enseignants en attente de validation administrative.',
                formule: 'COUNT(evaluation_notes WHERE statut = "submitted")',
                sourceVerite: 'evaluation_notes.statut',
                unite: 'notes',
                seuilsAlerte: ['attention_si_sup' => 50, 'critique_si_sup' => 200],
                slaFraicheurMinutes: 60,
                sensiblePii: false,
            ),
        ];
    }
}
