<?php

namespace App\DataMesh\Produits;

use App\DataMesh\Contrats\ProduitDonnees;
use App\Models\AffectationGroupe;
use App\Models\Etudiant;
use Illuminate\Support\Facades\Gate;

/**
 * Produit `affectations_groupes` v1.0 — domaine Affectations.
 * État des affectations actives + occupation des groupes, en lecture
 * seule et selon les règles métier existantes. Les DÉCISIONS restent
 * dans AffectationService / MassAssignmentService (jamais ici).
 */
final class AffectationsGroupes extends ProduitSocle implements ProduitDonnees
{
    public function code(): string
    {
        return 'affectations_groupes';
    }

    public function domaine(): string
    {
        return 'affectations';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function decrire(): array
    {
        return [
            'nom' => 'StudentGroupAssignment',
            'description' => 'Affectations actives étudiant→groupe + occupation vs capacité officielle.',
            'finalite' => 'Publier l’état des affectations conformément aux règles métier existantes.',
            'proprietaire_metier' => 'Scolarité (groupes)',
            'source_verite' => 'Table affectations_groupe (statut active) + groups.capacite',
            'classification' => 'interne-non-sensible',
            'fraicheur_attendue' => 'temps réel (lecture directe, sans cache)',
            'consommation' => 'Catalogue::produit(code)->donnees([utilisateur, faculte_id?, annee?])',
            'compatibilite' => 'Ajouts MINEURs compatibles ; retrait/renommage = version MAJEUR',
            'erreurs' => 'Enveloppe {ok, code, message} sans PII ; AuthorizationException si refusé',
        ];
    }

    public function schema(): array
    {
        return [
            ['champ' => 'total', 'type' => 'int', 'signification' => 'Nombre d’affectations actives', 'sensible' => false],
            ['champ' => 'affectations[].reference_etudiant', 'type' => 'string|null', 'signification' => 'Numéro d’inscription', 'sensible' => false],
            ['champ' => 'affectations[].groupe_code', 'type' => 'string|null', 'signification' => 'Code du groupe attribué', 'sensible' => false],
            ['champ' => 'affectations[].verrouille', 'type' => 'bool', 'signification' => 'Affectation intouchable en auto', 'sensible' => false],
            ['champ' => 'occupation[].groupe_code', 'type' => 'string', 'signification' => 'Code du groupe', 'sensible' => false],
            ['champ' => 'occupation[].effectif', 'type' => 'int', 'signification' => 'Affectations actives', 'sensible' => false],
            ['champ' => 'occupation[].capacite', 'type' => 'int|null', 'signification' => 'Capacité officielle ; null = INCONNUE sauf illimitation explicite (cf. gouvernance)', 'sensible' => false],
            ['champ' => 'occupation[].depasse', 'type' => 'bool', 'signification' => 'Effectif > capacité officielle', 'sensible' => false],
        ];
    }

    public function donnees(array $contexte = []): array
    {
        $utilisateur = $this->consommateur($contexte);
        $this->roleBackOfficeRequis($utilisateur);
        Gate::forUser($utilisateur)->authorize('viewAny', Etudiant::class);
        $faculteId = $this->perimetreFaculte($utilisateur, isset($contexte['faculte_id']) ? (int) $contexte['faculte_id'] : null);

        $affectations = AffectationGroupe::query()
            ->where('statut', 'active')
            ->when(! empty($contexte['annee']), fn ($q) => $q->whereHas('anneeUniversitaire', fn ($a) => $a->where('libelle', $contexte['annee'])))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->with(['etudiant:id,num_inscription,faculte_id', 'groupe:id,code,capacite,faculte_id'])
            ->orderBy('id')->limit(5000)->get();

        $occupation = [];
        foreach ($affectations as $affectation) {
            $code = $affectation->groupe?->code ?? '—';
            $occupation[$code] ??= ['groupe_code' => $code, 'effectif' => 0, 'capacite' => $affectation->groupe?->capacite];
            $occupation[$code]['effectif']++;
        }
        foreach ($occupation as &$ligne) {
            $ligne['depasse'] = $ligne['capacite'] !== null && $ligne['effectif'] > $ligne['capacite'];
        }
        unset($ligne);

        return [
            'total' => $affectations->count(),
            'affectations' => $affectations->map(fn ($a) => [
                'reference_etudiant' => $a->etudiant?->num_inscription,
                'groupe_code' => $a->groupe?->code,
                'verrouille' => (bool) $a->verrouille,
            ])->all(),
            'occupation' => array_values($occupation),
        ];
    }

    public function fraicheur(): ?string
    {
        return $this->derniereMaj([
            AffectationGroupe::where('statut', 'active')->max('updated_at'),
        ]);
    }
}
