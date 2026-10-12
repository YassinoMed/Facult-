<?php

namespace App\Services\Documents;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 16 §16.2/§16.8 : gestion des modèles (création, nouvelle
 * version immuable, publication, restauration, sandbox d'aperçu).
 */
final class DocumentTemplateService
{
    /**
     * Toute modification crée une version immuable (§16.2).
     */
    public function nouvelleVersion(DocumentTemplate $modele, string $contenu, ?array $schema, ?string $auteur): DocumentTemplateVersion
    {
        $this->validerContenu($contenu);

        return DB::transaction(function () use ($modele, $contenu, $schema, $auteur) {
            $version = ((int) $modele->versions()->max('version')) + 1;

            $objet = $modele->versions()->create([
                'version' => $version,
                'contenu' => $contenu,
                'schema_variables' => $schema,
                'gabarit' => $modele->versions()->latest('version')->first()?->gabarit,
                'cree_par' => $auteur,
            ]);

            $modele->update(['version_courante_id' => $objet->id]);

            return $objet;
        });
    }

    /**
     * Création + version initiale en une transaction (formulaire ou
     * upload : le contenu transite toujours par `nouvelleVersion`,
     * donc par `validerContenu`).
     *
     * @param  array{type: string, nom: string, locale?: string, format?: string, orientation?: string, faculte_id?: ?int, valide_du?: ?string, valide_au?: ?string}  $attributs
     */
    public function creer(array $attributs, string $contenu, ?array $schema, ?string $auteur): DocumentTemplate
    {
        return DB::transaction(function () use ($attributs, $contenu, $schema, $auteur) {
            $modele = DocumentTemplate::create([
                'type' => $attributs['type'],
                'nom' => $attributs['nom'],
                'locale' => $attributs['locale'] ?? 'fr',
                'format' => $attributs['format'] ?? 'A4',
                'orientation' => $attributs['orientation'] ?? 'portrait',
                'faculte_id' => $attributs['faculte_id'] ?? null,
                'valide_du' => $attributs['valide_du'] ?? null,
                'valide_au' => $attributs['valide_au'] ?? null,
            ]);

            $this->nouvelleVersion($modele, $contenu, $schema, $auteur);

            return $modele->fresh();
        });
    }

    /**
     * Duplication : nouveau modèle brouillon + copie du contenu
     * courant en v1 (jamais de partage de versions entre modèles).
     */
    public function dupliquer(DocumentTemplate $modele, ?string $auteur): DocumentTemplate
    {
        $courante = $modele->versionCourante();

        return $this->creer([
            'type' => $modele->type,
            'nom' => $modele->nom.' (copie)',
            'locale' => $modele->locale,
            'format' => $modele->format,
            'orientation' => $modele->orientation,
            'faculte_id' => $modele->faculte_id,
            'valide_du' => $modele->valide_du?->format('Y-m-d'),
            'valide_au' => $modele->valide_au?->format('Y-m-d'),
        ], (string) ($courante?->contenu ?? ''), $courante?->schema_variables, $auteur);
    }

    /**
     * Archivage : statut `archive` (consultable, non utilisable pour
     * de nouvelles générations). Idempotent.
     */
    public function archiver(DocumentTemplate $modele): DocumentTemplate
    {
        if ($modele->statut !== 'archive') {
            $modele->update(['statut' => 'archive']);
        }

        return $modele->fresh();
    }

    /**
     * Rendu direct du modèle avec des variables d'exemple (LOT 3) :
     * interpolation SÛRE — seules les `{{ cle }}` listées au schéma
     * (ou du jeu d'exemple sans schéma) sont remplacées, valeurs
     * échappées, inconnues laissées en texte. Jamais de compilation
     * Blade (aucun code du modèle n'est exécuté).
     */
    public function renduDirect(DocumentTemplateVersion $version, ?array $schema = null): string
    {
        $schema ??= $version->schema_variables;
        $exemple = $this->exempleVariables($schema);
        $autorisees = $schema === null || $schema === [] ? array_keys($exemple) : array_keys($schema);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_.]*)\s*\}\}/',
            fn ($m) => in_array($m[1], $autorisees, true) ? e((string) ($exemple[$m[1]] ?? '')) : $m[0],
            (string) $version->contenu
        );
    }

    /**
     * Variables d'exemple pour l'aperçu : une valeur neutre par clé
     * du schéma (ou un jeu de démonstration sans schéma).
     *
     * @return array<string, string>
     */
    public function exempleVariables(?array $schema): array
    {
        if ($schema === null || $schema === []) {
            return [
                'nom' => 'DUPONT', 'prenom' => 'Jean',
                'reference' => 'ATT-2026-000001', 'date' => '01/01/2026',
            ];
        }

        $exemple = [];

        foreach (array_keys($schema) as $cle) {
            $exemple[$cle] = 'Exemple '.str_replace(['.', '_'], ' ', (string) $cle);
        }

        return $exemple;
    }

    public function publier(DocumentTemplate $modele): void
    {
        if ($modele->version_courante_id === null) {
            throw new DomainException('Publication impossible : aucune version.');
        }

        $modele->update(['statut' => 'actif']);
    }

    /**
     * Rendu sandbox (§16.7) : variables échappées, liste blanche de
     * helpers, aucune écriture ni numérotation.
     *
     * @param  array<string, mixed>  $exemple
     */
    public function apercuSandbox(DocumentTemplateVersion $version, array $exemple): string
    {
        $variables = $this->filtrerVariables($version, $exemple);

        return view('admin.documents-center.sandbox', [
            'contenu' => $version->contenu,
            'variables' => $variables,
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $exemple
     * @return array<string, mixed>
     */
    private function filtrerVariables(DocumentTemplateVersion $version, array $exemple): array
    {
        $schema = $version->schema_variables ?? [];

        if ($schema === []) {
            return array_map(fn ($v) => is_scalar($v) ? e((string) $v) : '', $exemple);
        }

        $filtrees = [];

        foreach (array_keys($schema) as $cle) {
            $valeur = $exemple[$cle] ?? '';
            $filtrees[$cle] = is_scalar($valeur) ? e((string) $valeur) : '';
        }

        return $filtrees;
    }

    private function validerContenu(string $contenu): void
    {
        // Interdiction du code arbitraire : Blade/tags PHP refusés dans
        // le contenu stocké (§16.7). Seules les variables {{ nom }}
        // listées au schéma sont interpolées à l'aperçu.
        foreach (['<?php', '<?=', '@php', '{!!', '<script'] as $interdit) {
            if (str_contains(mb_strtolower($contenu), $interdit)) {
                throw new DomainException("Contenu de modèle refusé (motif interdit : {$interdit}).");
            }
        }
    }
}
