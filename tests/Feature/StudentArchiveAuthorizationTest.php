<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RBAC archive (§43) : 4 profils × 6 onglets, droits réels du projet
 * (jamais supposés).
 */
class StudentArchiveAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Etudiant $etudiant;

    /** @var array<string, User> */
    private array $profils = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.student-archive.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        foreach (['admin_scolarite' => 'sco', 'agent_scolarite' => 'agt', 'consultation' => 'con'] as $role => $prefix) {
            $this->profils[$role] = User::creerCompteBackOffice([
                'name' => $role, 'email' => $prefix.'.authz@universite.test', 'password' => 'Motdepasse@123',
            ], $role);
        }
        $this->profils['admin_faculte'] = User::creerCompteBackOffice([
            'name' => 'fac', 'email' => 'fac.authz@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->etudiant = Etudiant::create([
            'cin' => 'ATZ00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'atz@atz.test', 'date_naissance' => '2004-01-01', 'sexe' => 'F',
            'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'X', 'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');
        $this->etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
    }

    #[DataProvider('fournisseurOnglets')]
    public function test_profil_onglet(string $role, string $onglet, int $statut): void
    {
        $this->actingAs($this->profils[$role])->get(route('admin.etudiants.archive', [
            $this->etudiant, 'onglet' => $onglet,
        ]))->assertStatus($statut);
    }

    /** @return array<string, array{string, string, int}> */
    public static function fournisseurOnglets(): array
    {
        return [
            // admin_scolarite : tout (dont audit).
            'sco general' => ['admin_scolarite', 'general', 200],
            'sco academique' => ['admin_scolarite', 'academique', 200],
            'sco inscriptions' => ['admin_scolarite', 'inscriptions', 200],
            'sco documents' => ['admin_scolarite', 'documents', 200],
            'sco timeline' => ['admin_scolarite', 'timeline', 200],
            'sco audit' => ['admin_scolarite', 'audit', 200],
            // agent : tout sauf audit.
            'agt general' => ['agent_scolarite', 'general', 200],
            'agt academique' => ['agent_scolarite', 'academique', 200],
            'agt inscriptions' => ['agent_scolarite', 'inscriptions', 200],
            'agt documents' => ['agent_scolarite', 'documents', 200],
            'agt timeline' => ['agent_scolarite', 'timeline', 200],
            'agt audit' => ['agent_scolarite', 'audit', 403],
            // consultation : lecture (audit exclu).
            'con general' => ['consultation', 'general', 200],
            'con academique' => ['consultation', 'academique', 200],
            'con inscriptions' => ['consultation', 'inscriptions', 200],
            'con documents' => ['consultation', 'documents', 200],
            'con timeline' => ['consultation', 'timeline', 200],
            'con audit' => ['consultation', 'audit', 403],
            // admin_faculte (même faculté) : tout sauf audit.
            'fac general' => ['admin_faculte', 'general', 200],
            'fac academique' => ['admin_faculte', 'academique', 200],
            'fac inscriptions' => ['admin_faculte', 'inscriptions', 200],
            'fac documents' => ['admin_faculte', 'documents', 200],
            'fac timeline' => ['admin_faculte', 'timeline', 200],
            'fac audit' => ['admin_faculte', 'audit', 403],
        ];
    }
}
