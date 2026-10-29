<?php

namespace App\DataMesh\Produits;

use App\DataMesh\Contrats\ProduitDonnees;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Services\BIService;
use App\Services\Kpi\CockpitKpiService;
use Illuminate\Support\Facades\Gate;

/**
 * Produit `analytique_faculte` v1.1 — domaine Pilotage (consommateur).
 * Enveloppe les indicateurs versionnés de BIService. Ne constitue
 * JAMAIS une seconde source de vérité (agrégats dérivés uniquement).
 *
 * v1.1 (mineur, compatible) : ajout du champ `snapshot_kpi` (snapshot
 * unifié des KPI scolarité) — documenté dans docs/data-mesh/CONTRACTS.md.
 */
final class AnalytiqueFaculte extends ProduitSocle implements ProduitDonnees
{
    public function code(): string
    {
        return 'analytique_faculte';
    }

    public function domaine(): string
    {
        return 'pilotage';
    }

    public function version(): string
    {
        return '1.1';
    }

    public function decrire(): array
    {
        return [
            'nom' => 'FacultyAnalytics',
            'description' => 'Effectifs, inscriptions validées, occupation des groupes : agrégats dérivés contrôlés.',
            'finalite' => 'Pilotage administratif sans exposer de données individuelles.',
            'proprietaire_metier' => 'Pilotage / scolarité centrale',
            'source_verite' => 'Dérivé de BIService::indicateurs (sources : inscriptions, étudiants, notes)',
            'classification' => 'interne-non-sensible (agrégats, aucune ligne individuelle)',
            'fraicheur_attendue' => 'cache versionné TTL 600 s (clé bi:{version}:a:f:p)',
            'consommation' => 'Catalogue::produit(code)->donnees([utilisateur, faculte_id?, annee_id?])',
            'compatibilite' => 'Ajouts MINEURs compatibles ; retrait/renommage = version MAJEUR',
            'erreurs' => 'Enveloppe {ok, code, message} sans PII ; AuthorizationException si refusé',
        ];
    }

    public function schema(): array
    {
        return [
            ['champ' => 'version_agregats', 'type' => 'string', 'signification' => 'Empreinte md5 des sources (invalidation cache)', 'sensible' => false],
            ['champ' => 'indicateurs', 'type' => 'object', 'signification' => 'Indicateurs {valeur, definition, source} de BIService', 'sensible' => false],
            ['champ' => 'snapshot_kpi', 'type' => 'object', 'signification' => 'Snapshot unifié des KPI scolarité (CockpitKpiService)', 'sensible' => false],
        ];
    }

    public function donnees(array $contexte = []): array
    {
        $utilisateur = $this->consommateur($contexte);
        $this->roleBackOfficeRequis($utilisateur);
        Gate::forUser($utilisateur)->authorize('viewAny', Etudiant::class);
        $faculteId = $this->perimetreFaculte($utilisateur, isset($contexte['faculte_id']) ? (int) $contexte['faculte_id'] : null);

        $anneeId = isset($contexte['annee_id'])
            ? (int) $contexte['annee_id']
            : (int) (AnneeUniversitaire::active()?->id ?? 0);

        $moteur = app(BIService::class);
        $cockpitKpi = app(CockpitKpiService::class);

        return [
            'version_agregats' => $moteur->version(),
            'indicateurs' => $moteur->indicateurs($anneeId ?: null, $faculteId),
            'snapshot_kpi' => $cockpitKpi->calculerSnapshot($anneeId ?: null, $faculteId, null, $utilisateur)->versDataMesh(),
        ];
    }

    public function fraicheur(): ?string
    {
        return $this->derniereMaj([
            InscriptionAcademique::max('updated_at'),
            Etudiant::max('updated_at'),
        ]);
    }
}
