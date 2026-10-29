<?php

namespace App\DataMesh\Produits;

use App\DataMesh\Contrats\ProduitDonnees;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;

/**
 * Produit `catalogue_academique` v1.0 — domaine Référentiel.
 * Référence LMD active (facultés, programmes, niveaux) en lecture seule.
 * Données publiques de structure : aucune PII.
 */
final class CatalogueAcademique extends ProduitSocle implements ProduitDonnees
{
    public function code(): string
    {
        return 'catalogue_academique';
    }

    public function domaine(): string
    {
        return 'referentiel';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function decrire(): array
    {
        return [
            'nom' => 'AcademicCatalog',
            'description' => 'Référentiel académique LMD actif : facultés, programmes, niveaux.',
            'finalite' => 'Fournir un référentiel cohérent et traçable aux consommateurs autorisés.',
            'proprietaire_metier' => 'Direction de la scolarité (référentiel)',
            'source_verite' => 'Tables facultes, programmes, niveaux (module Referentiel)',
            'classification' => 'interne-non-sensible',
            'fraicheur_attendue' => 'à la demande (référentiel peu volatil)',
            'consommation' => 'Catalogue::produit(code)->donnees([utilisateur, faculte_id?])',
            'compatibilite' => 'Ajouts MINEURs compatibles ; retrait/renommage = version MAJEUR',
            'erreurs' => 'Enveloppe {ok, code, message} sans PII ; AuthorizationException si refusé',
        ];
    }

    public function schema(): array
    {
        return [
            ['champ' => 'facultes[].code', 'type' => 'string', 'signification' => 'Code stable de la faculté', 'sensible' => false],
            ['champ' => 'facultes[].nom', 'type' => 'string', 'signification' => 'Libellé officiel', 'sensible' => false],
            ['champ' => 'programmes[].code', 'type' => 'string', 'signification' => 'Code stable du programme', 'sensible' => false],
            ['champ' => 'programmes[].nom', 'type' => 'string', 'signification' => 'Libellé officiel', 'sensible' => false],
            ['champ' => 'programmes[].faculte_code', 'type' => 'string', 'signification' => 'Faculté de rattachement', 'sensible' => false],
            ['champ' => 'niveaux[].code', 'type' => 'string', 'signification' => 'Code stable du niveau (L1…D3)', 'sensible' => false],
            ['champ' => 'niveaux[].programme_code', 'type' => 'string|null', 'signification' => 'Programme parent, null si transversal', 'sensible' => false],
        ];
    }

    public function donnees(array $contexte = []): array
    {
        $utilisateur = $this->consommateur($contexte);
        $this->roleBackOfficeRequis($utilisateur);
        $faculteId = $this->perimetreFaculte($utilisateur, isset($contexte['faculte_id']) ? (int) $contexte['faculte_id'] : null);

        $facultes = Faculte::query()
            ->where('active', true)
            ->when($faculteId !== null, fn ($q) => $q->whereKey($faculteId))
            ->orderBy('code')->get(['code', 'nom']);

        $programmes = Programme::query()
            ->where('actif', true)
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->orderBy('code')->get(['code', 'nom', 'faculte_id']);

        $codesFacultes = Faculte::query()->where('active', true)->pluck('code', 'id');
        $programmesCodes = Programme::query()->where('actif', true)->pluck('code', 'id');

        $niveaux = Niveau::query()
            ->where('actif', true)
            ->orderBy('code')->get(['code', 'nom', 'programme_id']);

        return [
            'facultes' => $facultes->map(fn ($f) => ['code' => $f->code, 'nom' => $f->nom])->all(),
            'programmes' => $programmes->map(fn ($p) => [
                'code' => $p->code,
                'nom' => $p->nom,
                'faculte_code' => $codesFacultes[$p->faculte_id] ?? null,
            ])->all(),
            'niveaux' => $niveaux->map(fn ($n) => [
                'code' => $n->code,
                'nom' => $n->nom,
                'programme_code' => $n->programme_id !== null ? ($programmesCodes[$n->programme_id] ?? null) : null,
            ])->all(),
        ];
    }

    public function fraicheur(): ?string
    {
        return $this->derniereMaj([
            Faculte::max('updated_at'),
            Programme::max('updated_at'),
            Niveau::max('updated_at'),
        ]);
    }
}
