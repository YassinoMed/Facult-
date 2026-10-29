<?php

namespace App\DataMesh;

use App\DataMesh\Contrats\ProduitDonnees;
use App\DataMesh\Produits\AffectationsEnseignement;
use App\DataMesh\Produits\AffectationsGroupes;
use App\DataMesh\Produits\AnalytiqueFaculte;
use App\DataMesh\Produits\CatalogueAcademique;
use App\DataMesh\Produits\InscriptionsValidees;
use App\DataMesh\Produits\ResultatsExamens;
use InvalidArgumentException;

/**
 * Catalogue centralisé et versionné des produits de données du Data Mesh.
 * Permet la découverte sans plateforme externe lourde :
 * - Liste des produits disponibles et métadonnées.
 * - Schémas et versions.
 * - Filtrage par domaine propriétaire.
 * - Diagnostic de fraîcheur par rapport aux SLA.
 */
final class Catalogue
{
    public const VERSION = '1.0';

    /** @var array<string, class-string<ProduitDonnees>> */
    private const PRODUITS = [
        'catalogue_academique' => CatalogueAcademique::class,
        'inscriptions_validees' => InscriptionsValidees::class,
        'affectations_groupes' => AffectationsGroupes::class,
        'affectations_enseignement' => AffectationsEnseignement::class,
        'resultats_examens' => ResultatsExamens::class,
        'analytique_faculte' => AnalytiqueFaculte::class,
    ];

    /** @return array<int, string> */
    public static function codes(): array
    {
        return array_keys(self::PRODUITS);
    }

    public static function produit(string $code): ProduitDonnees
    {
        if (! isset(self::PRODUITS[$code])) {
            throw new InvalidArgumentException("Produit de données inconnu : {$code}.");
        }

        $classe = self::PRODUITS[$code];

        return new $classe;
    }

    /** @return array<int, array<string, mixed>> */
    public static function liste(): array
    {
        $lignes = [];

        foreach (self::PRODUITS as $code => $classe) {
            /** @var ProduitDonnees $produit */
            $produit = new $classe;
            $description = $produit->decrire();
            $lignes[] = [
                'code' => $produit->code(),
                'nom' => $description['nom'] ?? $produit->code(),
                'version' => $produit->version(),
                'domaine' => $produit->domaine(),
                'description' => $description['description'] ?? '',
                'classification' => $description['classification'] ?? 'interne',
                'fraicheur' => $produit->fraicheur(),
                'nb_champs_schema' => count($produit->schema()),
            ];
        }

        return $lignes;
    }

    /**
     * Retourne les produits appartenant à un domaine particulier.
     *
     * @return array<int, ProduitDonnees>
     */
    public static function parDomaine(string $domaine): array
    {
        $trouves = [];

        foreach (self::PRODUITS as $classe) {
            /** @var ProduitDonnees $produit */
            $produit = new $classe;
            if ($produit->domaine() === $domaine) {
                $trouves[] = $produit;
            }
        }

        return $trouves;
    }

    /**
     * Schéma versionné d'un produit donné.
     *
     * @return array<int, array{champ: string, type: string, signification: string, sensible: bool}>
     */
    public static function schema(string $code): array
    {
        return self::produit($code)->schema();
    }
}
