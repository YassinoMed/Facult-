<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\MensReference;
use Illuminate\Support\Str;

/**
 * LOOP A (automatisation inscriptions, §6) — Façade d'éligibilité.
 *
 * Point d'évaluation UNIQUE et structuré pour un dossier d'inscription :
 * agrège les moteurs existants (périodes, blocages + vérification du
 * dossier, matrice des pièces) et retourne un verdict explicable.
 *
 * Invariants (§6, §16) :
 * - AUCUNE nouvelle règle métier : les décisions appartiennent aux
 *   moteurs agrégés, cette façade ne fait que les composer.
 * - Dry-run strict : aucune écriture, aucun évènement, aucune
 *   notification — utilisable pour l'affichage et la simulation.
 * - « Aucun problème détecté » ≠ validation administrative : la
 *   validation reste une action humaine (intervention_humaine vrai).
 * - L'OCR/MENS ne décident jamais : leurs incohérences sont signalées
 *   par les règles du moteur (R12, divergence 4-eyes), pas tranchées ici.
 */
final class InscriptionEligibilityService
{
    public function __construct(
        private readonly PeriodeInscriptionService $periodes,
        private readonly BlocageInscriptionService $blocages,
        private readonly DocumentsRequisService $documents,
    ) {}

    /**
     * Évaluation structurée d'un dossier d'inscription.
     *
     * @return array{
     *     eligible: bool,
     *     intervention_humaine: bool,
     *     verdict: 'ELIGIBLE'|'AVERTISSEMENT'|'BLOCAGE',
     *     controles: array<int, array{code: string, libelle: string, statut: 'pass'|'blocage'|'avertissement', message: string, source: string}>,
     *     blocages: array<int, array<string, mixed>>,
     *     avertissements: array<int, array<string, mixed>>,
     *     documents: array<string, mixed>,
     *     actions_proposees: array<int, string>,
     *     sources: array<int, class-string>,
     * }
     */
    public function evaluer(InscriptionAcademique $inscription): array
    {
        $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);
        $etudiant = $inscription->etudiant;
        $type = $inscription->type ?? 'premiere';

        // 1. Période applicable (priorité normale → exception, fuseau Tunis).
        $periode = $inscription->anneeUniversitaire !== null
            ? $this->periodes->evaluer($etudiant, $inscription->anneeUniversitaire, $type)
            : ['autorisee' => true, 'code' => 'SANS_PERIODE', 'message' => 'Année introuvable (mode transition).'];

        // 2. Anomalies + pièces via le moteur central (11 règles + bilan).
        $blocage = $this->blocages->blocages($inscription);

        // 3. Matrice des pièces obligatoires (états distingués).
        $pieces = $this->documents->verifier($etudiant, $type);

        $itemsBloquants = array_values(array_filter(
            $blocage['items'],
            fn (array $item) => ($item['gravite'] ?? '') === 'bloquant'
        ));
        $itemsAvertissement = array_values(array_filter(
            $blocage['items'],
            fn (array $item) => ($item['gravite'] ?? '') !== 'bloquant'
        ));

        // 2b. Identité MENS explicite (lecture seule : référence + divergences
        // du dossier, sans rejouer identifier()). Les divergences nom/prénom
        // et l'absence de rapprochement ne remontent pas toujours comme items
        // moteur : elles sont signalées ici, jamais tranchées.
        $mens = $this->controlerMens($etudiant);
        $itemsBloquants = array_values(array_merge(
            $itemsBloquants,
            array_filter($mens['items'], fn (array $item) => ($item['gravite'] ?? '') === 'bloquant')
        ));
        $itemsAvertissement = array_values(array_merge(
            $itemsAvertissement,
            array_filter($mens['items'], fn (array $item) => ($item['gravite'] ?? '') !== 'bloquant')
        ));

        $eligible = $periode['autorisee'] === true
            && $blocage['bloquant'] !== true
            && $pieces['complet'] === true
            && $mens['bloquant'] !== true;

        $controles = [
            $this->controlePeriode($periode),
            $this->controlePieces($pieces),
            $this->controleAnomalies($blocage, $itemsAvertissement),
            $mens['controle'],
        ];

        $verdict = ! $eligible
            ? 'BLOCAGE'
            : ($itemsAvertissement === [] ? 'ELIGIBLE' : 'AVERTISSEMENT');

        return [
            'eligible' => $eligible,
            // Toujours vrai : lever un blocage OU valider restent des
            // actions humaines autorisées — jamais une décision automatique.
            'intervention_humaine' => true,
            'verdict' => $verdict,
            'controles' => $controles,
            'blocages' => $itemsBloquants,
            'avertissements' => $itemsAvertissement,
            'documents' => $pieces,
            'actions_proposees' => array_values(array_unique(array_merge(
                $this->actionsProposees($periode, $pieces, $eligible, $itemsBloquants, $itemsAvertissement),
                $mens['actions']
            ))),
            'sources' => [
                PeriodeInscriptionService::class,
                BlocageInscriptionService::class,
                DocumentsRequisService::class,
                MensReference::class,
            ],
        ];
    }

    /**
     * Contrôle MENS explicite : existence d'une référence rapprochée.
     * Lecture seule, sans rejouer identifier() (pas de log, pas de recalcul).
     * Les divergences nom/prénom/doublon sont déjà couvertes par la règle
     * moteur R12 (`mens.divergences`) — ce contrôle ne signale QUE
     * l'absence de rapprochement, angle mort du moteur (référence absente
     * = zéro divergence détectable).
     *
     * @return array{controle: array{code: string, libelle: string, statut: string, message: string, source: string}, items: array<int, array<string, mixed>>, actions: array<int, string>, bloquant: bool}
     */
    private function controlerMens(Etudiant $etudiant): array
    {
        $rapproche = MensReference::where(
            'cin_normalise', MensReference::normaliserCin((string) $etudiant->cin)
        )->exists();

        if ($rapproche) {
            return [
                'controle' => [
                    'code' => 'IDENTITE_MENS',
                    'libelle' => 'Identité MENS',
                    'statut' => 'pass',
                    'message' => 'Référence MENS rapprochée (divergences éventuelles couvertes par le moteur).',
                    'source' => IdentificationIntelligenteService::class,
                ],
                'items' => [],
                'actions' => [],
                'bloquant' => false,
            ];
        }

        $item = [
            'code' => 'MENS_NON_RAPPROCHE',
            'gravite' => 'avertissement',
            'titre' => 'Rapprochement MENS non effectué.',
            'constat' => 'Aucune référence MENS rapprochée pour ce CIN.',
            'recommandation' => 'Vérifier le rapprochement MENS du dossier.',
            'source' => 'identite:mens',
        ];

        return [
            'controle' => [
                'code' => 'IDENTITE_MENS',
                'libelle' => 'Identité MENS',
                'statut' => 'avertissement',
                'message' => 'Aucune référence MENS rapprochée — à vérifier.',
                'source' => IdentificationIntelligenteService::class,
            ],
            'items' => [$item],
            'actions' => ['Vérifier le rapprochement MENS du dossier (référence absente).'],
            'bloquant' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $periode
     * @return array{code: string, libelle: string, statut: string, message: string, source: string}
     */
    private function controlePeriode(array $periode): array
    {
        $statut = ($periode['autorisee'] ?? false) === true ? 'pass' : 'blocage';

        return [
            'code' => 'PERIODE_INSCRIPTION',
            'libelle' => 'Période d\'inscription',
            'statut' => $statut,
            'message' => (string) ($periode['message'] ?? ''),
            'source' => PeriodeInscriptionService::class,
        ];
    }

    /**
     * @param  array<string, mixed>  $pieces
     * @return array{code: string, libelle: string, statut: string, message: string, source: string}
     */
    private function controlePieces(array $pieces): array
    {
        $complet = ($pieces['complet'] ?? false) === true;
        $message = $complet
            ? (string) ($pieces['progression'] ?? 'Pièces conformes.')
            : $this->messagePieces($pieces);

        return [
            'code' => 'PIECES_OBLIGATOIRES',
            'libelle' => 'Pièces obligatoires',
            'statut' => $complet ? 'pass' : 'blocage',
            'message' => $message,
            'source' => DocumentsRequisService::class,
        ];
    }

    /**
     * @param  array<string, mixed>  $blocage
     * @param  array<int, array<string, mixed>>  $itemsAvertissement
     * @return array{code: string, libelle: string, statut: string, message: string, source: string}
     */
    private function controleAnomalies(array $blocage, array $itemsAvertissement): array
    {
        $statut = ($blocage['bloquant'] ?? false) === true
            ? 'blocage'
            : ($itemsAvertissement === [] ? 'pass' : 'avertissement');

        $message = match ($statut) {
            'pass' => 'Aucune anomalie détectée par le moteur de vérification.',
            'blocage' => 'Vérification du dossier bloquante (verdict '.($blocage['verdict'] ?? 'BLOQUANT').').',
            default => count($itemsAvertissement).' point(s) à examiner (aucun blocage).',
        };

        return [
            'code' => 'ANOMALIES_DOSSIER',
            'libelle' => 'Vérification du dossier',
            'statut' => $statut,
            'message' => $message,
            'source' => BlocageInscriptionService::class,
        ];
    }

    /**
     * Actions déterministes, dérivées exclusivement des contrôles exécutés.
     *
     * @param  array<string, mixed>  $periode
     * @param  array<string, mixed>  $pieces
     * @param  array<int, array<string, mixed>>  $itemsBloquants
     * @param  array<int, array<string, mixed>>  $itemsAvertissement
     * @return array<int, string>
     */
    private function actionsProposees(
        array $periode,
        array $pieces,
        bool $eligible,
        array $itemsBloquants,
        array $itemsAvertissement
    ): array {
        $actions = [];

        if (($periode['autorisee'] ?? false) !== true) {
            $actions[] = 'Ouvrir une période normale ou exceptionnelle applicable ('
                .($periode['code'] ?? 'REGISTRATION_PERIOD_CLOSED').') ou faire basculer le dossier vers le workflow de reprise autorisé.';
        }

        if (($pieces['complet'] ?? true) !== true) {
            $actions[] = 'Compléter les pièces obligatoires : '.$this->messagePieces($pieces);
        }

        if ($itemsBloquants !== []) {
            $codes = implode(', ', array_column($itemsBloquants, 'code'));
            $actions[] = "Traiter les blocages au centre de résolution (codes : {$codes}).";
        }

        if ($itemsAvertissement !== []) {
            $codes = implode(', ', array_column($itemsAvertissement, 'code'));
            $actions[] = "Examiner les points signalés avant validation (codes : {$codes}).";
        }

        if ($eligible) {
            $actions[] = 'Dossier prêt : validation administrative (transition vers « valide ») par un agent autorisé — décision humaine.';
        }

        return $actions;
    }

    /**
     * Message documenté : distingue absent / en attente / rejeté / expiré.
     *
     * @param  array<string, mixed>  $pieces
     */
    private function messagePieces(array $pieces): string
    {
        $segments = [];

        foreach (['manquants' => 'manquantes', 'en_attente' => 'en attente', 'rejetes' => 'rejetées', 'expires' => 'expirées'] as $cle => $libelle) {
            $liste = $pieces[$cle] ?? [];
            if ($liste !== []) {
                $segments[] = Str::plural($libelle, count($liste)).' : '.implode(', ', $liste);
            }
        }

        return $segments === []
            ? (string) ($pieces['progression'] ?? 'Pièces conformes.')
            : ucfirst(implode(' ; ', $segments)).'.';
    }
}
