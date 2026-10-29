<?php

namespace App\Modules\AiAssistant\Services;

use App\Modules\AiAssistant\Contracts\RechercheDocumentaire;
use App\Modules\AiAssistant\Data\ChunkDocumentaire;
use App\Modules\AiAssistant\Models\AiMorceauIndex;
use App\Modules\AiAssistant\Models\AiSourceDocumentaire;
use App\Modules\AiAssistant\Support\TraceurIa;

/**
 * Recherche documentaire (Lot 6) — lit la TABLE PARTAGÉE
 * `ai_documents_index` (index unique du projet). Aucune source n'est
 * découverte automatiquement : le réindexage passe par la liste
 * EXPLICITE de la configuration, protégée par :
 *   - exclusions de chemin (défense en profondeur : .env/.key/vendor/…) ;
 *   - empreintes de PII : un fichier positif est sauté ENTIER ;
 *   - anti-doublon sha256 : par fichier (registre local
 *     `ai_knowledge_sources`) et par morceau (empreinte partagée) ;
 *   - contrôle d'accès AVANT restitution : chunks sensibles ET
 *     fichiers restreints cachés du demandeur sans permission.
 */
final class RechercheIndexPartage implements RechercheDocumentaire
{
    public function rechercher(string $question, int $limite, bool $inclureRestreints): array
    {
        $mots = $this->motsCles($question);

        if ($mots === []) {
            return [];
        }

        $plafond = max(1, min($limite, (int) config('ai-assistant.limites.chunks_rag_max', 5)));
        $candidats = $this->candidats($mots, $plafond, $inclureRestreints);

        if ($candidats === []) {
            return [];
        }

        // Classement déterministe : occurrences pondérées (titre > corps).
        $questionMin = mb_strtolower(trim($question));
        $scores = [];

        foreach ($candidats as $index => $morceau) {
            $corps = mb_strtolower((string) $morceau->contenu_morceau);
            $titre = mb_strtolower((string) $morceau->titre);

            $score = str_contains($corps, $questionMin) ? 50.0 : 0.0;
            $score += str_contains($titre, $questionMin) ? 40.0 : 0.0;

            foreach ($mots as $mot) {
                $score += substr_count($corps, $mot) * 5.0;
                $score += substr_count($titre, $mot) * 15.0;
            }

            $scores[$index] = $score;
        }

        arsort($scores);

        $retenu = array_slice(
            array_keys(array_filter($scores, fn ($valeur) => $valeur > 0.0)),
            0,
            $plafond
        );

        $longueurExtrait = (int) config('ai-assistant.rag.extrait_max', 240);
        $suite = [];

        foreach ($retenu as $index) {
            $morceau = $candidats[$index];
            $suite[] = new ChunkDocumentaire(
                id: (int) $morceau->id,
                titre: (string) $morceau->titre,
                chemin: (string) $morceau->chemin_fichier,
                extrait: mb_substr((string) $morceau->contenu_morceau, 0, $longueurExtrait),
                ordre: (int) $morceau->index_morceau,
                score: (int) $scores[$index],
            );
        }

        return $suite;
    }

    public function reindexer(): int
    {
        $references = $this->fichiersAutorises(); // [relatif => contenu]
        $insertions = 0;

        foreach ($references as $relatif => $contenu) {
            if ($this->contientPii($contenu)) {
                TraceurIa::evenement('reindex.sauter', ['outil' => 'reindexer', 'etat' => 'saute']);

                continue;
            }

            $insertions += $this->indexerFichier($relatif, $contenu);
        }

        $insertions += $this->retirerSourcesAbsentes(array_keys($references));

        TraceurIa::evenement('reindex.termine', ['outil' => 'reindexer', 'etat' => 'ok']);

        return $insertions;
    }

    /**
     * État courant, pour la commande artisan (aucune PII).
     *
     * @return array{actives: int, chunks: int}
     */
    public function diagnostic(): array
    {
        return [
            'actives' => AiSourceDocumentaire::where('statut', AiSourceDocumentaire::STATUT_ACTIF)->count(),
            'chunks' => AiMorceauIndex::count(),
        ];
    }

    /* ── Mécanique privée ────────────────────────────────────────── */

    /**
     * @return array<int, string> mots-clés normalisés, au plus 8
     */
    private function motsCles(string $question): array
    {
        $mots = preg_split('/\s+/u', mb_strtolower(trim($question))) ?: [];
        $mots = array_values(array_unique(array_filter(
            $mots,
            fn ($mot) => mb_strlen($mot) >= 3 && mb_strlen($mot) <= 40
        )));

        return array_slice($mots, 0, 8);
    }

    /**
     * Candidats filtrés par accès AVANT classement : chunks sensibles
     * et fichiers restreints exclus du demandeur sans permission.
     */
    private function candidats(array $mots, int $plafond, bool $inclureRestreints)
    {
        $query = AiMorceauIndex::query()->restituable($inclureRestreints);

        // Focalisation BASE DE CONNAISSANCE DE LA PLATEFORME : la liste
        // EXPLICITE `rag.sources` (celle qui gouverne l'indexation) borne
        // AUSSI la restitution. La table partagée pouvant contenir des
        // morceaux d'un autre indexeur (outillage, rapports d'ingénierie,
        // fixtures), tout chemin hors liste est ignoré : le modèle ne
        // reçoit que la connaissance gouvernée par la configuration.
        $focuse = [];
        foreach ((array) config('ai-assistant.rag.sources', []) as $groupe) {
            foreach ((array) $groupe as $chemin) {
                if (is_string($chemin) && $chemin !== '') {
                    $focuse[] = str_replace('\\', '/', $chemin);
                }
            }
        }
        $focuse = array_values(array_unique($focuse));

        if ($focuse !== []) {
            $query->whereIn('chemin_fichier', $focuse);
        }

        if (! $inclureRestreints) {
            $cheminsRestreints = AiSourceDocumentaire::query()
                ->where('restreinte', true)
                ->where('statut', AiSourceDocumentaire::STATUT_ACTIF)
                ->pluck('chemin')
                ->all();

            if ($cheminsRestreints !== []) {
                $query->whereNotIn('chemin_fichier', $cheminsRestreints);
            }
        }

        $query->where(function ($groupe) use ($mots) {
            foreach ($mots as $mot) {
                $joker = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $mot);
                $groupe->orWhere('contenu_morceau', 'LIKE', "%{$joker}%")
                    ->orWhere('titre', 'LIKE', "%{$joker}%");
            }
        });

        return $query->orderBy('id')->take($plafond * 8)->get()->all();
    }

    /**
     * Fichiers EXPLICITES lisibles : config `rag.sources`, filtrée par
     * les exclusions de secours, bornée au périmètre du projet.
     * Clé = chemin relatif ; valeur = contenu du fichier.
     *
     * @return array<string, string>
     */
    private function fichiersAutorises(): array
    {
        $brutes = [];

        foreach ((array) config('ai-assistant.rag.sources', []) as $groupe) {
            foreach ((array) $groupe as $chemin) {
                if (is_string($chemin) && $chemin !== '') {
                    $brutes[] = $chemin;
                }
            }
        }

        $exclusions = (array) config('ai-assistant.rag.exclusions', ['.env', '.key', '.pem']);
        $contenus = [];

        foreach (array_unique($brutes) as $relatif) {
            $normalise = str_replace('\\', '/', (string) $relatif);

            if ($this->estExclu($normalise, $exclusions)) {
                continue;
            }

            $reel = realpath(base_path().'/'.$normalise);

            if ($reel === false || ! str_starts_with($reel, base_path().DIRECTORY_SEPARATOR) || ! is_file($reel)) {
                continue;
            }

            $contenu = file_get_contents($reel);

            if ($contenu !== false) {
                $contenus[$normalise] = $contenu;
            }
        }

        return $contenus;
    }

    /**
     * @param  array<int, string>  $exclusions
     */
    private function estExclu(string $chemin, array $exclusions): bool
    {
        $segments = explode('/', $chemin);
        $fichier = (string) end($segments);

        foreach ($exclusions as $motif) {
            $motif = str_replace('\\', '/', (string) $motif);

            if ($motif === '') {
                continue;
            }

            if (str_starts_with($motif, '.')) {
                // nom de fichier (ex. .env) ou suffixe (ex. .key -> x.key)
                if ($fichier === $motif || str_ends_with($fichier, $motif)) {
                    return true;
                }
            } elseif (str_contains($chemin, $motif)) {
                // segment de chemin (ex. vendor/, storage/)
                return true;
            }
        }

        return false;
    }

    /**
     * Garde PII : un fichier dont le contenu contient un motif
     * d'empreinte de données personnelles / secret est sauté ENTIER.
     */
    private function contientPii(string $contenu): bool
    {
        foreach ((array) config('ai-assistant.rag.motifs_pii', []) as $motif) {
            $motif = (string) $motif;

            if ($motif !== '' && @preg_match('#'.$motif.'#', $contenu) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indexe ou met à jour un fichier dans la table partagée :
     *   1. sha256 par fichier (registre local) : inchangé → zéro écriture ;
     *   2. morceaux préservés si leur empreinte partagée existe déjà ;
     *   3. morceaux disparus retirés en soft-delete (statut retire) ;
     *   4. registre local mis à jour (version montée, indexe_le, statut).
     *
     * @return int morceaux insérés
     */
    private function indexerFichier(string $relatif, string $contenu): int
    {
        $shaFichier = hash('sha256', $contenu);

        $registre = AiSourceDocumentaire::where('chemin', $relatif)->first();

        if ($registre !== null && $registre->sha256 === $shaFichier
            && $registre->statut === AiSourceDocumentaire::STATUT_ACTIF) {
            return 0; // inchangé : aucune écriture.
        }

        $echantillons = [];

        foreach ($this->decouper($contenu) as $position => $texte) {
            $echantillons[] = [
                'empreinte' => hash('sha256', $relatif.'|'.($position + 1).'|'.$texte),
                'position' => $position,
                'texte' => $texte,
            ];
        }

        $anciens = AiMorceauIndex::query()
            ->where('chemin_fichier', $relatif)
            ->orderBy('index_morceau')
            ->get();

        $empsConnus = $anciens->pluck('empreinte_sha256')->all();
        $version = ((int) $anciens->max('version_indexeur')) + 1;
        $insertions = 0;

        foreach ($echantillons as $echantillon) {
            $empreinte = $echantillon['empreinte'];

            if (in_array($empreinte, $empsConnus, true)) {
                continue; // morceau inchangé : garde anti-doublon.
            }

            AiMorceauIndex::create([
                'source' => 'docs',
                'titre' => basename($relatif),
                'chemin_fichier' => $relatif,
                'index_morceau' => $echantillon['position'] + 1,
                'contenu_morceau' => $echantillon['texte'],
                'empreinte_sha256' => $empreinte,
                'version_indexeur' => $version,
                'est_sensible' => false,
                'permission_requise' => null,
            ]);

            $insertions++;
            $empsConnus[] = $empreinte; // pas de double insertion dans la passe.
        }

        // Retrait : tout morceau actif du fichier dont le contenu a
        // disparu de la nouvelle lecture (statut retire, réversible).
        foreach ($anciens as $ancien) {
            $contenus = array_column($echantillons, 'texte');

            if (! in_array((string) $ancien->contenu_morceau, $contenus, true)) {
                $ancien->delete();
            }
        }

        // Registre local : empreinte du fichier, statut, version, date.
        if ($registre === null) {
            AiSourceDocumentaire::create([
                'titre' => basename($relatif),
                'chemin' => $relatif,
                'type' => 'doc',
                'restreinte' => false,
                'sha256' => $shaFichier,
                'statut' => AiSourceDocumentaire::STATUT_ACTIF,
                'version_indexeur' => (string) $version,
                'indexe_le' => now(),
            ]);
        } else {
            $registre->forceFill([
                'sha256' => $shaFichier,
                'statut' => AiSourceDocumentaire::STATUT_ACTIF,
                'version_indexeur' => (string) $version,
                'indexe_le' => now(),
            ])->save();
        }

        return $insertions;
    }

    /**
     * Fichiers du registre local absents de la liste explicite
     * courante : chunk partagés retirés (soft-delete), registres
     * marqués `retire`. Le rejet est TRAÇABLE, jamais destructif.
     *
     * @param  array<int, string>  $cheminsActifs
     */
    private function retirerSourcesAbsentes(array $cheminsActifs): int
    {
        $retires = 0;

        $horsListe = AiSourceDocumentaire::query()
            ->where('statut', AiSourceDocumentaire::STATUT_ACTIF)
            ->whereNotIn('chemin', $cheminsActifs ?: [''])
            ->get();

        foreach ($horsListe as $source) {
            $chunks = AiMorceauIndex::query()->where('chemin_fichier', $source->chemin)->get();

            foreach ($chunks as $chunk) {
                $chunk->delete();
                $retires++;
            }

            $source->forceFill(['statut' => AiSourceDocumentaire::STATUT_RETIREE])->save();
        }

        return $retires;
    }

    /**
     * Découpage borné : fenêtres glissantes de taille_morceau, cap
     * anti-flood par fichier.
     *
     * @return array<int, string>
     */
    private function decouper(string $contenu): array
    {
        $taille = max(100, (int) config('ai-assistant.rag.taille_morceau', 1000));
        $cap = max(1, (int) config('ai-assistant.rag.morceaux_max_par_fichier', 30));

        $texte = trim($contenu);

        if (mb_strlen($texte) <= $taille) {
            return [$texte];
        }

        $morceaux = [];

        for ($debut = 0; $debut < mb_strlen($texte) && count($morceaux) < $cap; $debut += $taille) {
            $morceaux[] = trim(mb_substr($texte, $debut, $taille));
        }

        return $morceaux;
    }
}
