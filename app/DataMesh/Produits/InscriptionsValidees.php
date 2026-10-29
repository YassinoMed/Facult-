<?php

namespace App\DataMesh\Produits;

use App\DataMesh\Contrats\ProduitDonnees;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use Illuminate\Support\Facades\Gate;

/**
 * Produit `inscriptions_validees` v1.0 — domaine Scolarité.
 * Inscriptions validées exposées via contrat stable, PII minimisée
 * (CIN/email masqués, calqué sur SuiviDossierService). Lecture seule.
 */
final class InscriptionsValidees extends ProduitSocle implements ProduitDonnees
{
    public function code(): string
    {
        return 'inscriptions_validees';
    }

    public function domaine(): string
    {
        return 'scolarite';
    }

    public function version(): string
    {
        return '1.1';
    }

    public function decrire(): array
    {
        return [
            'nom' => 'StudentEnrollment',
            'description' => 'Inscriptions validées : étudiant (minimisé), année, programme, niveau, statut, transitions.',
            'finalite' => 'Fournir les inscriptions nécessaires aux consommateurs autorisés sans exposer les données personnelles.',
            'proprietaire_metier' => 'Scolarité centrale',
            'source_verite' => 'Tables inscriptions_academiques + etudiants',
            'classification' => 'interne-sensible-minimise',
            'fraicheur_attendue' => 'temps réel (lecture directe, sans cache)',
            'consommation' => 'Catalogue::produit(code)->donnees([utilisateur, faculte_id?, niveau?, annee?, page?=1, par_page?=100 max 1000, tri?=id|created_at, sens?=asc|desc])',
            'compatibilite' => 'v1.1 MINEUR : pagination ajoutée (page, par_page, pages), clés v1.0 inchangées',
            'erreurs' => 'Enveloppe {ok, code, message} sans PII ; AuthorizationException si refusé',
        ];
    }

    public function schema(): array
    {
        return [
            ['champ' => 'total', 'type' => 'int', 'signification' => 'Nombre total filtré (toutes pages)', 'sensible' => false],
            ['champ' => 'page', 'type' => 'int', 'signification' => 'Page courante (≥ 1)', 'sensible' => false],
            ['champ' => 'par_page', 'type' => 'int', 'signification' => 'Taille de page (1 à 1000, défaut 100)', 'sensible' => false],
            ['champ' => 'pages', 'type' => 'int', 'signification' => 'Nombre total de pages', 'sensible' => false],
            ['champ' => 'reference', 'type' => 'string', 'signification' => 'Numéro officiel d’inscription', 'sensible' => false],
            ['champ' => 'annee', 'type' => 'string', 'signification' => 'Libellé année universitaire', 'sensible' => false],
            ['champ' => 'niveau', 'type' => 'string|null', 'signification' => 'Niveau officiel LMD', 'sensible' => false],
            ['champ' => 'programme_code', 'type' => 'string|null', 'signification' => 'Code officiel du programme LMD', 'sensible' => false],
            ['champ' => 'statut', 'type' => 'string', 'signification' => 'Toujours `valide` dans ce produit', 'sensible' => false],
            ['champ' => 'etudiant.nom_complet', 'type' => 'string', 'signification' => 'Nom + prénom (usage interne autorisé)', 'sensible' => true],
            ['champ' => 'etudiant.cin_masquee', 'type' => 'string', 'signification' => 'CIN masquée (ex. 12***678)', 'sensible' => true],
            ['champ' => 'etudiant.email_masque', 'type' => 'string', 'signification' => 'Email masqué', 'sensible' => true],
            ['champ' => 'faculte_code', 'type' => 'string|null', 'signification' => 'Faculté de rattachement', 'sensible' => false],
        ];
    }

    public function donnees(array $contexte = []): array
    {
        $utilisateur = $this->consommateur($contexte);
        $this->roleBackOfficeRequis($utilisateur);
        Gate::forUser($utilisateur)->authorize('viewAny', Etudiant::class);
        $faculteId = $this->perimetreFaculte($utilisateur, isset($contexte['faculte_id']) ? (int) $contexte['faculte_id'] : null);

        // Pagination bornée v1.1 (compatible : clés v1.0 conservées).
        $page = max(1, (int) ($contexte['page'] ?? 1));
        $parPage = min(1000, max(1, (int) ($contexte['par_page'] ?? 100)));
        $triDemande = $contexte['tri'] ?? 'id';
        $tri = in_array($triDemande, ['id', 'created_at'], true) ? $triDemande : 'id';
        $sens = strtolower((string) ($contexte['sens'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $requete = InscriptionAcademique::query()
            ->where('statut', 'valide')
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->when(! empty($contexte['niveau']), fn ($q) => $q->where('niveau', mb_substr(trim((string) $contexte['niveau']), 0, 10)))
            ->when(! empty($contexte['annee']), fn ($q) => $q->whereHas('anneeUniversitaire', fn ($a) => $a->where('libelle', mb_substr(trim((string) $contexte['annee']), 0, 20))))
            ->when(! empty($contexte['programme_id']), fn ($q) => $q->where('programme_id', (int) $contexte['programme_id']));

        $total = (clone $requete)->count();

        $inscriptions = $requete
            ->with([
                'etudiant:id,num_inscription,nom,prenom,cin,email,faculte_id',
                'anneeUniversitaire:id,libelle',
                'programmeRef:id,code,nom',
            ])
            ->orderBy($tri, $sens)->orderBy('id', $sens)
            ->forPage($page, $parPage)
            ->get();

        $codesFacultes = Faculte::query()->pluck('code', 'id');

        return [
            'total' => $total,
            'page' => $page,
            'par_page' => $parPage,
            'pages' => (int) ceil($total / $parPage),
            'inscriptions' => $inscriptions->map(fn ($i) => [
                'reference' => $i->etudiant?->num_inscription ?? "INS-{$i->id}",
                'annee' => $i->anneeUniversitaire?->libelle,
                'niveau' => $i->niveau,
                'programme_code' => $i->programmeRef?->code,
                'statut' => $i->statut,
                'etudiant' => [
                    'nom_complet' => $i->etudiant?->nom_complet,
                    'cin_masquee' => $i->etudiant?->cin_masquee,
                    'email_masque' => $i->etudiant?->email_masque,
                ],
                'faculte_code' => $i->etudiant ? ($codesFacultes[$i->etudiant->faculte_id] ?? null) : null,
            ])->all(),
        ];
    }

    public function fraicheur(): ?string
    {
        return $this->derniereMaj([
            InscriptionAcademique::where('statut', 'valide')->max('updated_at'),
        ]);
    }
}
