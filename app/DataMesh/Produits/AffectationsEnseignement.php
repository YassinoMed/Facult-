<?php

namespace App\DataMesh\Produits;

use App\DataMesh\Contrats\ProduitDonnees;
use App\Modules\Enseignement\Models\Enseignement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Produit `affectations_enseignement` v1.0 — domaine Enseignement.
 * Expose les affectations pédagogiques des enseignants et des groupes
 * en lecture seule, avec contrôle d'autorisation et périmètre facultaire.
 */
final class AffectationsEnseignement extends ProduitSocle implements ProduitDonnees
{
    public function code(): string
    {
        return 'affectations_enseignement';
    }

    public function domaine(): string
    {
        return 'enseignement';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function decrire(): array
    {
        return [
            'nom' => 'TeachingAssignments',
            'description' => 'Affectations pédagogiques : matières, enseignants responsables, groupes, semestres et volumes.',
            'finalite' => 'Fournir la visibilité sur les charges d’enseignement pour les emplois du temps et le pilotage.',
            'proprietaire_metier' => 'Direction des études et départements',
            'source_verite' => 'Module Enseignement (table enseignements + affectations_enseignement)',
            'classification' => 'interne-restreint',
            'fraicheur_attendue' => 'quotidien / à la demande',
            'consommation' => 'Catalogue::produit(code)->donnees([utilisateur, faculte_id?, annee?])',
            'compatibilite' => 'Ajouts MINEURs compatibles ; retrait/renommage = version MAJEUR',
            'erreurs' => 'Enveloppe {ok, code, message} sans PII ; AuthorizationException si refusé',
        ];
    }

    public function schema(): array
    {
        return [
            ['champ' => 'total', 'type' => 'int', 'signification' => 'Nombre d’enseignements', 'sensible' => false],
            ['champ' => 'enseignements[].code', 'type' => 'string', 'signification' => 'Code de l’enseignement', 'sensible' => false],
            ['champ' => 'enseignements[].matiere_code', 'type' => 'string|null', 'signification' => 'Code de la matière / ECUE', 'sensible' => false],
            ['champ' => 'enseignements[].matiere_nom', 'type' => 'string|null', 'signification' => 'Intitulé de la matière', 'sensible' => false],
            ['champ' => 'enseignements[].enseignant_nom', 'type' => 'string|null', 'signification' => 'Nom de l’enseignant principal', 'sensible' => true],
            ['champ' => 'enseignements[].groupe_code', 'type' => 'string|null', 'signification' => 'Code du premier groupe rattaché', 'sensible' => false],
            ['champ' => 'enseignements[].semestre', 'type' => 'string|null', 'signification' => 'Semestre d’enseignement', 'sensible' => false],
            ['champ' => 'enseignements[].volume_horaire', 'type' => 'int', 'signification' => 'Volume horaire total', 'sensible' => false],
            ['champ' => 'enseignements[].statut', 'type' => 'string', 'signification' => 'Statut du cycle de vie', 'sensible' => false],
        ];
    }

    public function donnees(array $contexte = []): array
    {
        $utilisateur = $this->consommateur($contexte);
        $this->roleBackOfficeRequis($utilisateur);

        // Permission spécifique ou rôle d'administration
        if (! in_array($utilisateur->role, ['super_admin', 'admin_scolarite', 'admin_faculte'], true) && ! Gate::forUser($utilisateur)->allows('enseignements.view')) {
            throw new AuthorizationException('Produit de données : permission enseignements.view requise.');
        }

        $faculteId = $this->perimetreFaculte($utilisateur, isset($contexte['faculte_id']) ? (int) $contexte['faculte_id'] : null);

        $requete = Enseignement::query()
            ->when($faculteId !== null, fn ($q) => $q->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId)))
            ->when(! empty($contexte['annee']), fn ($q) => $q->whereHas('anneeUniversitaire', fn ($a) => $a->where('libelle', $contexte['annee'])))
            ->with(['matiere:id,code,nom', 'enseignants:id,nom,prenom', 'groupes:id,code', 'semestre:id,code'])
            ->orderBy('id')
            ->limit(1000);

        $enseignements = $requete->get();

        return [
            'total' => $enseignements->count(),
            'enseignements' => $enseignements->map(fn ($e) => [
                'code' => $e->code ?? "ENS-{$e->id}",
                'matiere_code' => $e->matiere?->code,
                'matiere_nom' => $e->matiere?->nom,
                'enseignant_nom' => $e->enseignants->first() ? ($e->enseignants->first()->prenom.' '.$e->enseignants->first()->nom) : null,
                'groupe_code' => $e->groupes->first()?->code,
                'semestre' => $e->semestre?->code,
                'volume_horaire' => $e->volumeTotal(),
                'statut' => $e->statut,
            ])->all(),
        ];
    }

    public function fraicheur(): ?string
    {
        return $this->derniereMaj([
            Enseignement::max('updated_at'),
        ]);
    }
}
