<?php

namespace App\Modules\PreventionDecrochage\Services;

use App\Models\AnneeUniversitaire;
use App\Models\Note;
use App\Modules\Attendance\Models\Presence;
use App\Modules\PreventionDecrochage\Models\AlerteDecrochage;
use App\Modules\PreventionDecrochage\Models\RegleDecrochage;

/**
 * Lot 15 — Moteur de détection du décrochage (accompagnement humain).
 *
 * SOURCES LUES (jamais modifiées, conventions confirmées uniquement) :
 * - `presences` (module Attendance) : statuts `absent` / `retard` ;
 *   `excuse` = absence justifiée, NON comptée dans la mesure ; `present` sain.
 * - `notes` (racine) via la chaîne note → semestre → inscription :
 *   notes réelles sous un seuil, faculté/année confirmées.
 *
 * RÈGLE D'OR (cahier Lot 15) :
 * - une donnée ABSENTE ne produit JAMAIS un signal négatif : un étudiant
 *   sans présence enregistrée n'est pas « sans absence » ni « à risque »,
 *   il est SANS DONNÉES et ne génère rien ; le scan n'agit que sur les
 *   étudiants présents dans les sources (cohorte officielle non définie
 *   et jamais inventée) ;
 * - une alerte ne modifie ni note, ni décision, ni statut d'inscription ;
 * - une alerte ne se crée que pour une règle APPROUVÉE ;
 * - déduplication : une seule alerte OUVERTE par (règle, étudiant, année).
 *
 * Lecture seule sur les sources : uniquement des SELECT agrégés ; les
 * écritures restent confinées aux tables DU module.
 */
final class ServiceAlertes
{
    /** Échantillons de preuve retenus par alerte (preuve strictement bornée). */
    private const MAX_PREUVES = 10;

    /** Plafond d'étudiants analysés par scan de règle (bornage volumétrique). */
    private const PLAFOND_CANDIDATS = 5000;

    /**
     * Exécute le scan pour les règles APPROUVÉES sur le périmètre demandé.
     *
     * @param  ?int  $faculteId  null = toutes les facultés accordées
     * @param  ?int  $anneeId  présences : plage de dates de l'année ;
     *                         notes : année via l'inscription ; null = toutes
     * @return array<string, mixed> résumé daté et sourcé du scan
     */
    public function detecter(?int $faculteId, ?int $anneeId): array
    {
        $regles = RegleDecrochage::query()->where('statut', 'approuvee')->orderBy('id')->get();
        $annee = $anneeId !== null ? AnneeUniversitaire::find($anneeId) : null;

        $resume = [
            'alertes_creees' => 0,
            'doublons_ignores' => 0,
            'candidats_sous_seuil' => 0,
            'regles_executees' => 0,
            'regles_sans_donnees' => 0,
            'detail_regles' => [],
        ];

        foreach ($regles as $regle) {
            $candidats = $regle->type === 'presences'
                ? $this->scanPresences($regle, $faculteId, $annee)
                : $this->scanNotes($regle, $faculteId, $annee);

            $resume['regles_executees']++;
            if ($candidats === []) {
                $resume['regles_sans_donnees']++;
                $resume['detail_regles'][] = $this->ligneResume($regle, 0);

                continue;
            }

            $resume['detail_regles'][] = $this->ligneResume($regle, count($candidats));

            foreach ($candidats as $candidat) {
                if ($candidat['valeur'] >= (float) $regle->seuil) {
                    $creee = $this->creerAlerte($regle, $candidat, $annee);
                    $resume[$creee ? 'alertes_creees' : 'doublons_ignores']++;
                } else {
                    $resume['candidats_sous_seuil']++;
                }
            }
        }

        return $resume;
    }

    /**
     * Scan des présences : comptage des statuts visés par la mesure.
     *
     * @param  RegleDecrochage  $regle  mesure ∈ absents | retards
     * @return array<int, array<string, mixed>>
     */
    private function scanPresences(RegleDecrochage $regle, ?int $faculteId, ?AnneeUniversitaire $annee): array
    {
        $statutVise = $regle->mesure === 'retards' ? 'retard' : 'absent';

        $lignes = Presence::query()
            ->selectRaw('presences.etudiant_id, COUNT(*) as total')
            ->where('presences.statut', $statutVise)
            ->groupBy('presences.etudiant_id')
            ->limit(self::PLAFOND_CANDIDATS);

        if ($faculteId !== null) {
            $lignes->join('etudiants', 'etudiants.id', '=', 'presences.etudiant_id')
                ->where('etudiants.faculte_id', $faculteId);
        }

        if ($annee !== null) {
            $lignes->whereBetween('presences.date', [$annee->date_debut, $annee->date_fin]);
        }

        $candidats = [];
        foreach ($lignes->get() as $ligne) {
            $candidats[] = [
                'etudiant_id' => (int) $ligne->etudiant_id,
                'valeur' => (float) $ligne->total,
                'preuve' => [
                    'source' => 'presences',
                    'statut_compte' => $statutVise,
                    'fenetre' => $annee === null
                        ? 'toutes-dates-enregistrees'
                        : $annee->date_debut.' -> '.$annee->date_fin,
                    'elements' => $this->preuvPresences((int) $ligne->etudiant_id, $statutVise, $annee),
                ],
            ];
        }

        return $candidats;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function preuvPresences(int $etudiantId, string $statutVise, ?AnneeUniversitaire $annee): array
    {
        $elements = Presence::query()
            ->where('etudiant_id', $etudiantId)
            ->where('statut', $statutVise)
            ->when($annee !== null, fn ($q) => $q->whereBetween('date', [$annee->date_debut, $annee->date_fin]))
            ->orderByDesc('date')
            ->limit(self::MAX_PREUVES)
            ->get(['id', 'date', 'seance']);

        return $elements->map(fn ($e) => [
            'id' => $e->id,
            'date' => $e->date->format('Y-m-d'),
            'seance' => $e->seance,
        ])->all();
    }

    /**
     * Scan des notes réelles : notes sous le seuil de la mesure via la
     * chaîne note → semestre → inscription (faculté/année confirmées).
     * Une règle notes SANS seuil_mesure est incohérente : elle est
     * explicitement ignorée (aucune invention de valeur par défaut).
     *
     * @return array<int, array<string, mixed>>
     */
    private function scanNotes(RegleDecrochage $regle, ?int $faculteId, ?AnneeUniversitaire $annee): array
    {
        if ($regle->seuil_mesure === null) {
            return [];
        }

        $seuilMesure = (float) $regle->seuil_mesure;

        $lignes = Note::query()
            ->selectRaw('notes.etudiant_id, COUNT(*) as total')
            ->where('notes.note', '<', $seuilMesure)
            ->groupBy('notes.etudiant_id')
            ->limit(self::PLAFOND_CANDIDATS);

        if ($faculteId !== null || $annee !== null) {
            $lignes->join('semestres', 'semestres.id', '=', 'notes.semestre_id')
                ->join('inscriptions_academiques', 'inscriptions_academiques.id', '=', 'semestres.inscription_academique_id')
                ->join('programmes', 'programmes.id', '=', 'inscriptions_academiques.programme_id');
        }

        if ($faculteId !== null) {
            $lignes->where('programmes.faculte_id', $faculteId);
        }

        if ($annee !== null) {
            $lignes->where('inscriptions_academiques.annee_universitaire_id', $annee->id);
        }

        $candidats = [];
        foreach ($lignes->get() as $ligne) {
            $candidats[] = [
                'etudiant_id' => (int) $ligne->etudiant_id,
                'valeur' => (float) $ligne->total,
                'preuve' => [
                    'source' => 'notes',
                    'regle' => 'note < '.$seuilMesure,
                    'seuil_generation' => 'comptage >= '.$regle->seuil.' notes sous seuil',
                    'elements' => $this->preuvesNotes((int) $ligne->etudiant_id, $seuilMesure, $faculteId, $annee),
                ],
            ];
        }

        return $candidats;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function preuvesNotes(int $etudiantId, float $seuilMesure, ?int $faculteId, ?AnneeUniversitaire $annee): array
    {
        $elements = Note::query()
            ->where('notes.etudiant_id', $etudiantId)
            ->where('notes.note', '<', $seuilMesure)
            ->when($faculteId !== null || $annee !== null, function ($q) use ($faculteId, $annee) {
                $q->join('semestres', 'semestres.id', '=', 'notes.semestre_id')
                    ->join('inscriptions_academiques', 'inscriptions_academiques.id', '=', 'semestres.inscription_academique_id')
                    ->join('programmes', 'programmes.id', '=', 'inscriptions_academiques.programme_id');
                if ($faculteId !== null) {
                    $q->where('programmes.faculte_id', $faculteId);
                }
                if ($annee !== null) {
                    $q->where('inscriptions_academiques.annee_universitaire_id', $annee->id);
                }
            })
            ->limit(self::MAX_PREUVES)
            ->get(['notes.id', 'notes.semestre_id', 'notes.matiere_id', 'notes.note']);

        return $elements->map(fn ($e) => [
            'note_id' => $e->id,
            'note' => (float) $e->note,
            'semestre_id' => $e->semestre_id,
            'matiere_id' => $e->matiere_id,
        ])->all();
    }

    /**
     * Crée l'alerte sauf si une alerte OUVERTE existe déjà pour ce triplet
     * (règle, étudiant, année) : une alerte FERMÉE peut réapparaître lors
     * d'un scan suivant — le flux humain garde sa trace complète.
     */
    private function creerAlerte(RegleDecrochage $regle, array $candidat, ?AnneeUniversitaire $annee): bool
    {
        $ouvertes = AlerteDecrochage::query()
            ->where('regle_id', $regle->id)
            ->where('etudiant_id', $candidat['etudiant_id'])
            ->whereIn('statut', ['generee', 'examinee'])
            ->where(function ($q) use ($annee) {
                if ($annee === null) {
                    $q->whereNull('annee_universitaire_id');
                } else {
                    $q->where('annee_universitaire_id', $annee->id);
                }
            });

        if ($ouvertes->exists()) {
            return false;
        }

        AlerteDecrochage::create([
            'regle_id' => $regle->id,
            'etudiant_id' => $candidat['etudiant_id'],
            'annee_universitaire_id' => $annee?->id,
            'valeur' => $candidat['valeur'],
            'seuil' => (float) $regle->seuil,
            'preuve' => $candidat['preuve'],
            'statut' => 'generee',
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneResume(RegleDecrochage $regle, int $nombreCandidats): array
    {
        return [
            'code' => $regle->code,
            'type' => $regle->type,
            'mesure' => $regle->mesure,
            'seuil' => (float) $regle->seuil,
            'candidats' => $nombreCandidats,
        ];
    }
}
