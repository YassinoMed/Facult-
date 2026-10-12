<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AffectationProposal;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\AffectationEligibilityService;
use App\Services\Affectations\AffectationEngine;
use App\Services\FicheEtudiantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boucle affectation intelligente (Phases 1, 8, 9, 11) : verrouille le
 * comportement du moteur unique sans le dupliquer.
 *
 * - Homonymie autorisée (même nom/prénom, CIN différentes).
 * - Doublon CIN normalisé bloquant (espaces/casse indifférents).
 * - Niveau strict L1 → L1 uniquement, même avec places en L2.
 * - Groupe complet exclu, scoring configurable, fiche 360 proposition.
 */
class AffectationIntelligenteLoopTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private AffectationEngine $engine;

    private AffectationEligibilityService $eligibilite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'LIC-INFO',
            'nom' => 'Licence en Informatique',
            'cycle' => 'licence',
            'actif' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarité',
            'email' => 'admin.intelligent@fst.rnu.tn',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->engine = app(AffectationEngine::class);
        $this->eligibilite = app(AffectationEligibilityService::class);
    }

    private function creerEtudiant(string $nom, string $prenom, string $cin, array $extra = []): Etudiant
    {
        static $compteur = 9000;
        $compteur++;

        $etudiant = Etudiant::create(array_merge([
            'num_inscription' => "INS-{$compteur}",
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => "etu{$compteur}@etudiant.tn",
            'cin' => $cin,
            'date_naissance' => '2004-06-10',
            'sexe' => 'M',
            'telephone' => '20123456',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
            'annee_academique' => '2026-2027',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ], $extra));

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'valide',
            'date_inscription' => now(),
        ]);

        return $etudiant;
    }

    private function creerGroupe(string $code, string $niveau = 'L1', int $capacite = 30): Group
    {
        return Group::create([
            'code' => $code,
            'nom' => "Groupe {$code}",
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => $niveau,
            'capacite' => $capacite,
            'actif' => true,
        ]);
    }

    public function test_homonymes_meme_nom_prenom_sont_deux_personnes_distinctes(): void
    {
        $groupe = $this->creerGroupe('L1-G01', 'L1', 30);

        $premier = $this->creerEtudiant('BEN ALI', 'Mohamed', '11111111');
        $second = $this->creerEtudiant('BEN ALI', 'Mohamed', '22222222');

        // La recherche par nom retourne plusieurs dossiers : jamais
        // une identité unique supposée.
        $this->assertSame(2, Etudiant::where('nom', 'BEN ALI')->where('prenom', 'Mohamed')->count());

        // Tous deux éligibles : l'homonymie n'est pas une anomalie.
        $this->assertTrue($this->eligibilite->verifier($premier, $this->annee)['eligible']);
        $this->assertTrue($this->eligibilite->verifier($second, $this->annee)['eligible']);

        // L'affectation utilise les IDs : deux lignes distinctes, sans confusion.
        $resultatPremier = $this->engine->traiterEtudiant($premier, $this->annee, 'automatique', $this->admin->id);
        $resultatSecond = $this->engine->traiterEtudiant($second, $this->annee, 'automatique', $this->admin->id);

        $this->assertSame('affecte', $resultatPremier['type']);
        $this->assertSame('affecte', $resultatSecond['type']);
        $this->assertSame(2, AffectationGroupe::where('groupe_id', $groupe->id)
            ->where('annee_universitaire_id', $this->annee->id)
            ->where('statut', 'active')
            ->distinct('etudiant_id')
            ->count('etudiant_id'));
    }

    public function test_doublon_cin_normalise_est_bloquant(): void
    {
        $this->creerGroupe('L1-G01', 'L1', 30);

        $premier = $this->creerEtudiant('BEN ALI', 'Mohamed', '12345678');
        $this->assertTrue($this->eligibilite->verifier($premier, $this->annee)['eligible']);

        // Variante espacée de la même CIN : la contrainte unique DB
        // (chaînes brutes différentes) laisse passer, le moteur bloque.
        $second = $this->creerEtudiant('TRABELSI', 'Ahmed', '12 345678');

        $bilan = $this->eligibilite->verifier($second, $this->annee);

        $this->assertFalse($bilan['eligible']);
        $this->assertFalse($bilan['checks']['identity']);
        $this->assertSame(AffectationEligibilityService::IDENTITY_CONFLICT, $bilan['statuses']['identity']);
        $this->assertContains(AffectationEligibilityService::IDENTITY_CIN_DUPLICATE, $bilan['blocking_reasons']);

        $evaluation = $this->engine->evaluer($second, $this->annee);
        $this->assertFalse($evaluation['eligible']);
    }

    public function test_niveau_strict_l1_jamais_vers_groupe_l2(): void
    {
        $groupeL1 = $this->creerGroupe('L1-G01', 'L1', 30);
        $groupeL2 = $this->creerGroupe('L2-G01', 'L2', 30);
        $etudiant = $this->creerEtudiant('BEN ALI', 'Karim', '33333333');

        $evaluation = $this->engine->evaluer($etudiant, $this->annee);

        $this->assertTrue($evaluation['eligible']);
        $this->assertSame($groupeL1->id, $evaluation['meilleur_groupe']->id);
        $this->assertNotContains($groupeL2->id, array_column($evaluation['alternatives'], 'group_id'));
    }

    public function test_groupe_complet_exclu_de_la_selection(): void
    {
        $groupePlein = $this->creerGroupe('L1-G01', 'L1', 1);
        $occupant = $this->creerEtudiant('DUPONT', 'Jean', '44444444');
        $resultat = $this->engine->traiterEtudiant($occupant, $this->annee, 'automatique', $this->admin->id);
        $this->assertSame('affecte', $resultat['type']);

        $suivant = $this->creerEtudiant('MARTIN', 'Paul', '55555555');
        $evaluation = $this->engine->evaluer($suivant, $this->annee);

        $this->assertFalse($evaluation['eligible']);
        $this->assertContains('AUCUN_GROUPE_COMPATIBLE', array_column($evaluation['anomalies'], 'code'));
    }

    public function test_scoring_centralise_en_configuration(): void
    {
        $this->creerGroupe('L1-G01', 'L1', 30);
        $etudiant = $this->creerEtudiant('BEN SALAH', 'Rim', '66666666');

        $evaluation = $this->engine->evaluer($etudiant, $this->annee);
        $this->assertSame(25, $evaluation['scoring_breakdown']['parcours']);

        config(['affectations.scoring.parcours_exact' => 5]);

        try {
            $evaluationReduite = $this->engine->evaluer($etudiant->fresh(), $this->annee);
            $this->assertSame(5, $evaluationReduite['scoring_breakdown']['parcours']);
        } finally {
            config(['affectations.scoring.parcours_exact' => 25]);
        }
    }

    public function test_fiche_360_affiche_la_proposition_en_attente(): void
    {
        $groupe = $this->creerGroupe('L1-G01', 'L1', 30);
        // Contact partiel → repli semi-automatique (proposition, pas d'écriture).
        $etudiant = $this->creerEtudiant('BEN AMOR', 'Sana', '77777777', ['telephone' => null]);

        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', $this->admin->id);
        $this->assertSame('proposition', $resultat['type']);
        $this->assertSame(AffectationProposal::STATUT_EN_ATTENTE, $resultat['proposal']->statut);

        $bilan = app(FicheEtudiantService::class)->eligibiliteAffectation($etudiant->fresh(), $this->annee);

        $this->assertSame('proposition', $bilan['statut_affectation']['etat']);
        $this->assertStringContainsString($groupe->code, $bilan['statut_affectation']['label']);
    }
}
