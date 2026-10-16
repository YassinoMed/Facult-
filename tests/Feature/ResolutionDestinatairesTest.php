<?php

namespace Tests\Feature;

use App\Models\CampagneCourriel;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\GroupeDestinataires;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use App\Services\ResolutionDestinatairesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolutionDestinatairesTest extends TestCase
{
    use RefreshDatabase;

    private ResolutionDestinatairesService $service;

    private Faculte $faculteParDefaut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ResolutionDestinatairesService::class);
        $this->faculteParDefaut = Faculte::create(['code' => 'FDEF', 'nom' => 'Faculté Défaut', 'actif' => true]);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 1000;
        $compteur++;

        return Etudiant::create(array_merge([
            'cin' => str_pad((string) $compteur, 8, '0', STR_PAD_LEFT),
            'nom' => 'Nom'.$compteur,
            'prenom' => 'Prenom'.$compteur,
            'email' => "user{$compteur}@univ.test",
            'sexe' => 'M',
            'date_naissance' => '2002-01-01',
            'telephone' => '20000000',
            'statut' => 'valide',
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'faculte_id' => $this->faculteParDefaut->id,
            'adresse' => '1 rue Exemple',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2022',
        ], $attributs));
    }

    public function test_ciblage_etudiants_par_filtres_et_deduplication(): void
    {
        $faculte1 = Faculte::create(['code' => 'F1', 'nom' => 'Sciences', 'actif' => true]);
        $faculte2 = Faculte::create(['code' => 'F2', 'nom' => 'Lettres', 'actif' => true]);

        $this->creerEtudiant([
            'nom' => 'Ben Ali',
            'prenom' => 'Sami',
            'email' => 'sami@univ.test',
            'faculte_id' => $faculte1->id,
            'statut' => 'valide',
            'niveau' => 'L1',
        ]);

        $this->creerEtudiant([
            'nom' => 'Ben Salah',
            'prenom' => 'Nadia',
            'email' => 'nadia@univ.test',
            'faculte_id' => $faculte1->id,
            'statut' => 'valide',
            'niveau' => 'L2',
        ]);

        // Autre faculté
        $this->creerEtudiant([
            'nom' => 'Trabelsi',
            'prenom' => 'Karim',
            'email' => 'karim@univ.test',
            'faculte_id' => $faculte2->id,
            'statut' => 'valide',
            'niveau' => 'L1',
        ]);

        $resultat = $this->service->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS, [
            'filtres' => [
                'faculte_id' => $faculte1->id,
                'niveau' => 'L1',
            ],
        ]);

        $this->assertSame(1, $resultat['total_valides']);
        $this->assertSame('sami@univ.test', $resultat['destinataires'][0]['email']);
    }

    public function test_elimination_des_doublons_et_adresses_invalides(): void
    {
        $fac = Faculte::create(['code' => 'FD', 'nom' => 'Droit', 'actif' => true]);

        // Deux étudiants avec le même email (cas de doublon)
        $this->creerEtudiant([
            'nom' => 'Dupont',
            'prenom' => 'Marc',
            'email' => 'commun@univ.test',
            'faculte_id' => $fac->id,
        ]);
        $this->creerEtudiant([
            'nom' => 'Dupont',
            'prenom' => 'Marc Deux',
            'email' => 'COMMUN@univ.test', // casse différente
            'faculte_id' => $fac->id,
        ]);

        // Adresse avec retour chariot interdit
        $this->creerEtudiant([
            'nom' => 'Invalide',
            'prenom' => 'Injection',
            'email' => "invalide\n@univ.test",
            'faculte_id' => $fac->id,
        ]);

        $resultat = $this->service->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS, [
            'filtres' => ['faculte_id' => $fac->id],
        ]);

        $this->assertSame(1, $resultat['total_valides']);
        $this->assertSame(1, $resultat['total_doublons']);
        $this->assertSame(1, $resultat['total_invalides']);
        $this->assertSame('commun@univ.test', $resultat['destinataires'][0]['email']);
    }

    public function test_ciblage_enseignants_par_departement(): void
    {
        $fac = Faculte::create(['code' => 'FS', 'nom' => 'Sciences', 'actif' => true]);
        $dep = Departement::create(['code' => 'INFO', 'nom' => 'Informatique', 'faculte_id' => $fac->id, 'actif' => true]);

        Teacher::create([
            'nom' => 'Driss',
            'prenom' => 'Hédi',
            'email' => 'hedi.driss@univ.test',
            'departement_id' => $dep->id,
            'actif' => true,
            'statut' => 'actif',
        ]);

        $resultat = $this->service->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ENSEIGNANTS, [
            'filtres' => ['departement_id' => $dep->id],
        ]);

        $this->assertSame(1, $resultat['total_valides']);
        $this->assertSame('hedi.driss@univ.test', $resultat['destinataires'][0]['email']);
        $this->assertSame('enseignant', $resultat['destinataires'][0]['type_destinataire']);
    }

    public function test_resolution_groupe_enregistre_dynamique(): void
    {
        $fac = Faculte::create(['code' => 'MED', 'nom' => 'Médecine', 'actif' => true]);

        $this->creerEtudiant([
            'nom' => 'Mansour',
            'prenom' => 'Amira',
            'email' => 'amira@univ.test',
            'faculte_id' => $fac->id,
            'niveau' => 'D1',
        ]);

        $groupe = GroupeDestinataires::create([
            'nom' => 'Doctorants Médecine',
            'type' => GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS,
            'criteres' => [
                'faculte_id' => $fac->id,
                'niveau' => 'D1',
            ],
        ]);

        $resultat = $this->service->resoudre(CampagneCourriel::CIBLAGE_GROUPE_ENREGISTRE, [
            'groupe_enregistre_id' => $groupe->id,
        ]);

        $this->assertSame(1, $resultat['total_valides']);
        $this->assertSame('amira@univ.test', $resultat['destinataires'][0]['email']);
    }

    public function test_envoi_individuel_et_liste_fixe(): void
    {
        $resIndiv = $this->service->resoudre(CampagneCourriel::CIBLAGE_INDIVIDUEL, [
            'email' => 'direct@univ.test',
            'nom_complet' => 'Directeur Scolarité',
        ]);

        $this->assertSame(1, $resIndiv['total_valides']);
        $this->assertSame('direct@univ.test', $resIndiv['destinataires'][0]['email']);

        $e = $this->creerEtudiant([
            'email' => 'etudiant.fixe@univ.test',
        ]);

        $resFixe = $this->service->resoudre(CampagneCourriel::CIBLAGE_LISTE_FIXE, [
            'etudiant_ids' => [$e->id],
        ]);

        $this->assertSame(1, $resFixe['total_valides']);
        $this->assertSame('etudiant.fixe@univ.test', $resFixe['destinataires'][0]['email']);
    }
}
