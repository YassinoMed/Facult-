<?php

namespace App\Modules\AssistantIa\Tools\Metier;

use App\DataMesh\Catalogue;
use App\Models\User;
use App\Modules\AssistantIa\Contracts\OutilIaInterface;
use App\Modules\AssistantIa\DTO\ResultatOutil;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\JournalisationIa;

/**
 * Outil métier : Consultation des métadonnées, schémas de contrats et état des produits du Data Mesh.
 * Interdit toute extraction massive ou non autorisée. Masque systématiquement les champs sensibles.
 */
class ConsulterProduitDataMeshTool implements OutilIaInterface
{
    public function nom(): string
    {
        return 'consulter_produit_data_mesh';
    }

    public function description(): string
    {
        return 'Consulte le catalogue, le schéma versionné, la fraîcheur SLA et la description fonctionnelle des produits de données du Data Mesh UniRegistre.';
    }

    public function mode(): string
    {
        return 'metier';
    }

    public function permissionRequise(): string
    {
        return 'assistant_ia.mode_metier';
    }

    public function schemaParametres(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'code_produit' => [
                    'type' => 'string',
                    'description' => 'Code stable du produit (ex: "catalogue_academique", "inscriptions_validees", "resultats_examens", "affectations_groupes")',
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'Action souhaitée : "decrire" (métadonnées & schéma) ou "echantillon" (données d\'aperçu limitées)',
                    'enum' => ['decrire', 'echantillon'],
                ],
            ],
            'required' => ['code_produit'],
        ];
    }

    public function estSensible(): bool
    {
        return false;
    }

    public function executer(array $parametres, User $utilisateur): ResultatOutil
    {
        if (! $utilisateur->hasPermission('assistant_ia.mode_metier') && ! $utilisateur->estSuperAdmin()) {
            throw new SecuriteIaException("Vous ne disposez pas de l'habilitation requise pour consulter les produits de données du Data Mesh.");
        }

        $codeProduit = trim((string) ($parametres['code_produit'] ?? ''));
        $action = trim((string) ($parametres['action'] ?? 'decrire'));

        $codesDisponibles = Catalogue::codes();

        if ($codeProduit === '' || ! in_array($codeProduit, $codesDisponibles, true)) {
            return ResultatOutil::echec(
                "Produit de données '{$codeProduit}' introuvable ou non référencé. Produits disponibles : ".implode(', ', $codesDisponibles).'.'
            );
        }

        $produit = Catalogue::produit($codeProduit);
        $description = $produit->decrire();
        $schema = $produit->schema();
        $fraicheur = $produit->fraicheur();

        $champsSensibles = [];
        foreach ($schema as $c) {
            if (! empty($c['sensible'])) {
                $champsSensibles[] = (string) $c['champ'];
            }
        }

        // 1. Mode Description & Métadonnées (Zéro lecture de données réelles)
        if ($action !== 'echantillon') {
            $donnees = [
                'code' => $produit->code(),
                'domaine' => $produit->domaine(),
                'version' => $produit->version(),
                'nom' => $description['nom'] ?? $produit->code(),
                'description' => $description['description'] ?? '',
                'finalite' => $description['finalite'] ?? '',
                'proprietaire_metier' => $description['proprietaire_metier'] ?? '',
                'source_verite' => $description['source_verite'] ?? '',
                'classification' => $description['classification'] ?? 'interne',
                'fraicheur_sla' => $fraicheur ?? ($description['fraicheur_attendue'] ?? 'N/A'),
                'nombre_champs' => count($schema),
                'schema_champs' => $schema,
                'champs_sensibles_identifies' => $champsSensibles,
            ];

            $resume = "Produit Data Mesh '{$produit->code()}' (Domaine : {$produit->domaine()}, v{$produit->version()}) : {$donnees['nom']}. Fraîcheur : {$donnees['fraicheur_sla']}.";

            return ResultatOutil::succes($donnees, $resume, [
                [
                    'titre' => "Contrat de Produit Data Mesh {$produit->code()}",
                    'url_ou_chemin' => 'app/DataMesh/Catalogue.php',
                    'source' => 'technical_documentation',
                ],
            ]);
        }

        // 2. Mode Échantillon sous contrôle strict (Périmètre faculté + anonymisation + max 5 lignes)
        try {
            $contexte = [
                'utilisateur' => $utilisateur,
                'faculte_id' => $utilisateur->faculteRestreinteId(),
            ];

            $donneesBrutes = $produit->donnees($contexte);
        } catch (\Throwable $e) {
            JournalisationIa::avertissement("Échec d'accès aux données du produit Data Mesh '{$codeProduit}' : ".$e->getMessage());

            return ResultatOutil::echec("Accès refusé ou indisponible pour les données réelles du produit '{$codeProduit}' : ".$e->getMessage());
        }

        // Limitation stricte de volume (max 5 lignes par tableau pour prévenir les fuites de masse)
        $echantillon = [];
        foreach ($donneesBrutes as $cle => $valeur) {
            if (is_array($valeur)) {
                $lignes = array_slice($valeur, 0, 5);
                // Masquage systématique des données sensibles
                $echantillon[$cle] = array_map(function ($item) use ($champsSensibles) {
                    if (! is_array($item)) {
                        return $item;
                    }
                    foreach ($champsSensibles as $champSensible) {
                        $nomChamp = str_replace('[]', '', $champSensible);
                        if (data_get($item, $nomChamp) !== null) {
                            data_set($item, $nomChamp, '[MASQUÉ]');
                        }
                        $parts = explode('.', $nomChamp);
                        $dernier = end($parts);
                        if (array_key_exists($dernier, $item)) {
                            $item[$dernier] = '[MASQUÉ]';
                        }
                    }

                    return $item;
                }, $lignes);
            } else {
                $echantillon[$cle] = $valeur;
            }
        }

        $donnees = [
            'code' => $produit->code(),
            'domaine' => $produit->domaine(),
            'version' => $produit->version(),
            'classification' => $description['classification'] ?? 'interne',
            'echantillon_donnees' => $echantillon,
            'limite_appliquee' => 5,
            'champs_sensibles_masques' => $champsSensibles,
        ];

        $resume = "Échantillon restreint (max 5) du produit '{$codeProduit}' transmis avec masquage des champs sensibles.";

        return ResultatOutil::succes($donnees, $resume, [
            [
                'titre' => "Données Contrôlées Data Mesh {$produit->code()}",
                'url_ou_chemin' => 'app/DataMesh/Catalogue.php',
                'source' => 'business_procedure',
            ],
        ]);
    }
}
