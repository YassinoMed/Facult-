<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;
use App\Modules\AssistantIa\Contracts\RechercheDocumentaireInterface;
use App\Modules\AssistantIa\Models\AiDocumentIndex;

/**
 * Moteur de recherche textuelle RAG pour UniRegistre AI avec filtrage de sécurité et attribution vérifiable.
 */
class MoteurRechercheDocumentaire implements RechercheDocumentaireInterface
{
    /**
     * Recherche des extraits documentaires pertinents sous contrôle d'accès strict.
     *
     * @param  array<string, mixed>  $filtres
     * @return array<int, array{source: string, titre: string, chemin: string, extrait: string, score: float, date_indexation: string}>
     */
    public function rechercher(string $requete, ?User $utilisateur = null, int $limite = 5, array $filtres = []): array
    {
        // 1. Contrôle de sécurité sur les termes de recherche
        FiltreSecuriteIa::validerTexteEntrant($requete, 'recherche_rag');

        $motsVides = [
            'les', 'des', 'ces', 'ses', 'mes', 'tes', 'nos', 'vos', 'aux',
            'une', 'par', 'sur', 'dans', 'pour', 'avec', 'sans', 'sous',
            'est', 'sont', 'ete', 'etre', 'avoir', 'qui', 'que', 'quoi', 'dont',
            'quel', 'quels', 'quelle', 'quelles', 'cette', 'ceux',
        ];

        // Extraction robuste des mots-clés (mots individuels + termes composés avec ponctuation)
        $termesBruts = preg_split('/[\s]+/', mb_strtolower(trim($requete)));
        $termesFiltres = [];
        $tousMots = [];

        foreach ($termesBruts as $terme) {
            $termeNettoye = trim($terme, " \t\n\r\0\x0B\"'()[]{}?,;.:!«»");
            if (mb_strlen($termeNettoye) >= 3) {
                $tousMots[] = $termeNettoye;
                if (! in_array($termeNettoye, $motsVides, true)) {
                    $termesFiltres[] = $termeNettoye;
                }
            }
            $sousTermes = preg_split('/[._:\/-]+/', $termeNettoye);
            foreach ($sousTermes as $st) {
                $st = trim($st);
                if (mb_strlen($st) >= 3) {
                    $tousMots[] = $st;
                    if (! in_array($st, $motsVides, true)) {
                        $termesFiltres[] = $st;
                    }
                }
            }
        }

        // Si la requête ne contenait que des mots vides, conserver les termes bruts
        $candidatsMots = ! empty($termesFiltres) ? $termesFiltres : $tousMots;
        $motsCles = [];
        foreach ($candidatsMots as $m) {
            if (! in_array($m, $motsCles, true)) {
                $motsCles[] = $m;
            }
            // Racinisation pluriel -> singulier (ex: "étudiants" -> "étudiant", "statuts" -> "statut")
            if (str_ends_with($m, 's') && mb_strlen($m) > 4) {
                $singulier = mb_substr($m, 0, -1);
                if (! in_array($singulier, $motsCles, true)) {
                    $motsCles[] = $singulier;
                }
            }
        }

        if (empty($motsCles)) {
            $motsCles = ['uniregistre', 'inscription', 'scolarite', 'regles'];
        }

        // 2. Requête scopée aux documents autorisés pour l'utilisateur
        $query = AiDocumentIndex::accessiblePar($utilisateur);

        // Filtre par source (avec inclusion permanente du socle de connaissances canonique)
        if (! empty($filtres['source'])) {
            $sourceFiltre = $filtres['source'];
            $query->where(function ($sub) use ($sourceFiltre) {
                $sub->where('source', $sourceFiltre)
                    ->orWhere('source', 'LIKE', "%{$sourceFiltre}%")
                    ->orWhere('chemin_fichier', 'LIKE', "%{$sourceFiltre}%")
                    ->orWhere('chemin_fichier', 'LIKE', '%KNOWLEDGE-BASE%');
            });
        }

        // Filtre par domaine métier
        if (! empty($filtres['domaine'])) {
            $query->where('domaine_metier', $filtres['domaine']);
        }

        // Exclusion des documents obsolètes par défaut
        $inclureObsoletes = (bool) ($filtres['inclure_obsoletes'] ?? false);
        if (! $inclureObsoletes) {
            $query->where(function ($sub) {
                $sub->whereNull('statut_document')
                    ->orWhere('statut_document', '!=', 'obsolete');
            });
        }

        // 3. Filtrage en base de données par les mots-clés
        $query->where(function ($sub) use ($motsCles) {
            foreach ($motsCles as $mot) {
                $motEchappe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $mot);
                $sub->orWhere('contenu_morceau', 'LIKE', "%{$motEchappe}%")
                    ->orWhere('titre', 'LIKE', "%{$motEchappe}%")
                    ->orWhere('chemin_fichier', 'LIKE', "%{$motEchappe}%");
            }
        });

        $premierMot = $motsCles[0] ?? '';
        $premierMotEchappe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $premierMot);

        // Terme utilisateur : TOUJOURS lié (binding), jamais interpolé dans le SQL brut.
        $query->orderByRaw(
            "CASE 
                WHEN chemin_fichier LIKE '%KNOWLEDGE-BASE%' THEN 0
                WHEN titre LIKE ? THEN 1
                WHEN chemin_fichier LIKE ? THEN 2
                ELSE 3 
            END",
            ["%{$premierMotEchappe}%", "%{$premierMotEchappe}%"]
        );

        $candidats = $query->take(250)->get();
        if ($candidats->isEmpty()) {
            return [];
        }

        // 4. Calcul de pertinence hybride et classement
        $resultats = [];
        $requeteMin = mb_strtolower($requete);
        $fichiersVus = [];

        foreach ($candidats as $doc) {
            $score = 0.0;
            $texteMin = mb_strtolower($doc->contenu_morceau);
            $titreMin = mb_strtolower($doc->titre);
            $cheminMin = mb_strtolower($doc->chemin_fichier);

            // Correspondance exacte de phrase
            if (str_contains($texteMin, $requeteMin)) {
                $score += 60.0;
            }
            if (str_contains($titreMin, $requeteMin)) {
                $score += 50.0;
            }
            if (str_contains($cheminMin, $requeteMin)) {
                $score += 40.0;
            }

            // Bonus pour symbole exact (Nom de classe, fonction, route)
            foreach ($motsCles as $mot) {
                if (preg_match('/\\b'.preg_quote($mot, '/').'\\b/i', $doc->titre)) {
                    $score += 40.0;
                }
                if (str_contains($cheminMin, $mot)) {
                    $score += 25.0;
                }

                $occurrencesContenu = substr_count($texteMin, $mot);
                $occurrencesTitre = substr_count($titreMin, $mot);

                $score += ($occurrencesContenu * 4.0) + ($occurrencesTitre * 12.0);
            }

            // Bonus majeur pour la base de connaissances canonique (KB-RAG et KNOWLEDGE-BASE)
            if (str_contains($doc->chemin_fichier, 'KNOWLEDGE-BASE')) {
                $score += 75.0;
            } elseif (str_starts_with($doc->chemin_fichier, 'docs/')) {
                $score += 25.0;
            } elseif (str_starts_with($doc->chemin_fichier, '.agents/') || str_starts_with($doc->chemin_fichier, '.opencode/')) {
                // Documents techniques d'outillage agent (secondaires par rapport à la base de connaissances officielle)
                $score -= 35.0;
            }

            // Bonus document validé vs pénalité brouillon
            if ($doc->statut_document === 'valide') {
                $score += 15.0;
            } elseif ($doc->statut_document === 'brouillon') {
                $score -= 10.0;
            } elseif ($doc->statut_document === 'obsolete') {
                $score -= 30.0;
            }

            if ($score > 0) {
                // Déduplication : si on a déjà un morceau de ce fichier avec un très bon score, pénaliser les doublons
                $cleFichier = $doc->chemin_fichier;
                if (isset($fichiersVus[$cleFichier])) {
                    $score *= 0.7; // Léger recul pour favoriser la diversité des sources
                }
                $fichiersVus[$cleFichier] = true;

                $extrait = $this->extraireExtraitPertinent($doc->contenu_morceau, $motsCles);

                $resultats[] = [
                    'source_id' => 'SRC_'.($doc->id ?? substr(md5($doc->chemin_fichier.'_'.$doc->index_morceau), 0, 8)),
                    'source' => $doc->source,
                    'titre' => $doc->titre,
                    'chemin' => $doc->chemin_fichier,
                    'statut' => $doc->statut_document ?? 'valide',
                    'version' => $doc->version_document ?? '1.0',
                    'domaine' => $doc->domaine_metier ?? 'general',
                    'extrait' => $extrait,
                    'contenu' => $doc->contenu_morceau,
                    'score' => round($score, 2),
                    'date_indexation' => $doc->created_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            }
        }

        // 5. Tri par score décroissant et limitation
        usort($resultats, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($resultats, 0, $limite);
    }

    /**
     * Génère un extrait court centré sur les termes recherchés.
     *
     * @param  array<int, string>  $motsCles
     */
    protected function extraireExtraitPertinent(string $texte, array $motsCles, int $longueurMax = 250): string
    {
        $texteMin = mb_strtolower($texte);
        $premierePos = false;

        foreach ($motsCles as $mot) {
            $pos = mb_strpos($texteMin, $mot);
            if ($pos !== false && ($premierePos === false || $pos < $premierePos)) {
                $premierePos = $pos;
            }
        }

        if ($premierePos === false || mb_strlen($texte) <= $longueurMax) {
            return mb_substr($texte, 0, $longueurMax).(mb_strlen($texte) > $longueurMax ? '...' : '');
        }

        $debut = max(0, $premierePos - 60);
        $extrait = mb_substr($texte, $debut, $longueurMax);

        return ($debut > 0 ? '...' : '').trim($extrait).'...';
    }
}
