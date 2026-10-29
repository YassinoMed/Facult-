<?php

namespace App\DataMesh\Produits;

use App\DataMesh\Contrats\ProduitDonnees;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Produit `resultats_examens` v1.0 — domaine Évaluation.
 * Expose les résultats et décisions des jurys délibérés.
 * Respecte la stricte confidentialité des notes et la souveraineté des jurys.
 */
final class ResultatsExamens extends ProduitSocle implements ProduitDonnees
{
    public function code(): string
    {
        return 'resultats_examens';
    }

    public function domaine(): string
    {
        return 'evaluation';
    }

    public function version(): string
    {
        return '1.0';
    }

    public function decrire(): array
    {
        return [
            'nom' => 'ExamResults',
            'description' => 'Décisions officielles des jurys de délibération : moyennes, crédits et mentions publiées.',
            'finalite' => 'Diffusion des résultats après délibération officielle, sans divulgation anticipée.',
            'proprietaire_metier' => 'Présidence des jurys et cellule des examens',
            'source_verite' => 'Module Evaluation (table evaluation_decisions et deliberations)',
            'classification' => 'confidentiel',
            'fraicheur_attendue' => 'après clôture et publication de jury',
            'consommation' => 'Catalogue::produit(code)->donnees([utilisateur, faculte_id?, deliberation_id?])',
            'compatibilite' => 'Ajouts MINEURs compatibles ; retrait/renommage = version MAJEUR',
            'erreurs' => 'Enveloppe {ok, code, message} sans PII ; AuthorizationException si refusé',
        ];
    }

    public function schema(): array
    {
        return [
            ['champ' => 'total', 'type' => 'int', 'signification' => 'Nombre de résultats délibérés', 'sensible' => false],
            ['champ' => 'resultats[].reference_etudiant', 'type' => 'string|null', 'signification' => 'Numéro officiel de l’étudiant', 'sensible' => false],
            ['champ' => 'resultats[].moyenne', 'type' => 'float|null', 'signification' => 'Moyenne générale délibérée', 'sensible' => true],
            ['champ' => 'resultats[].credits_obtenus', 'type' => 'int', 'signification' => 'Total des crédits ECTS capitalisés', 'sensible' => false],
            ['champ' => 'resultats[].decision', 'type' => 'string', 'signification' => 'Décision officielle du jury', 'sensible' => false],
            ['champ' => 'resultats[].publie', 'type' => 'bool', 'signification' => 'Indicateur de publication officielle', 'sensible' => false],
        ];
    }

    public function donnees(array $contexte = []): array
    {
        $utilisateur = $this->consommateur($contexte);
        $this->roleBackOfficeRequis($utilisateur);

        // Seuls les profils autorisés peuvent consulter les résultats d'examen
        if (! in_array($utilisateur->role, ['super_admin', 'admin_scolarite', 'admin_faculte'], true) && ! Gate::forUser($utilisateur)->allows('resultats.view')) {
            throw new AuthorizationException('Produit de données : permission resultats.view requise.');
        }

        $faculteId = $this->perimetreFaculte($utilisateur, isset($contexte['faculte_id']) ? (int) $contexte['faculte_id'] : null);

        $requete = DecisionDeliberation::query()
            ->where('publie', true) // Confidentialité : seules les délibérations publiées sont visibles
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->when(! empty($contexte['deliberation_id']), fn ($q) => $q->where('deliberation_id', $contexte['deliberation_id']))
            ->with(['etudiant:id,num_inscription,faculte_id'])
            ->orderBy('id')
            ->limit(1000);

        $decisions = $requete->get();

        return [
            'total' => $decisions->count(),
            'resultats' => $decisions->map(fn ($d) => [
                'reference_etudiant' => $d->etudiant?->num_inscription,
                'moyenne' => $d->moyenne !== null ? (float) $d->moyenne : null,
                'credits_obtenus' => (int) $d->credits_obtenus,
                'decision' => $d->decision,
                'publie' => (bool) $d->publie,
            ])->all(),
        ];
    }

    public function fraicheur(): ?string
    {
        return $this->derniereMaj([
            DecisionDeliberation::where('publie', true)->max('updated_at'),
        ]);
    }
}
