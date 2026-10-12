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
use App\Services\Affectations\AffectationEngine;
use App\Services\FicheEtudiantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de validation globale du LOOP mature (réalignée sur l'API réelle) :
 * - Moteur unique : automatique (certitude élevée) vs semi-automatique
 * - Éligibilité administrative préalable stricte
 * - Cycle de vie des propositions (acceptation, modification, rejet)
 * - Affectation exceptionnelle sécurisée (override + audit)
 * - Idempotence et fiche étudiant 6 axes
 */
class AffectationMatureLoopTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $simpleUser;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private AffectationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences Économiques',
            'code' => 'FSE',
            'description' => 'Économie et Gestion',
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
            'code' => 'LIC-GESTION',
            'nom' => 'Licence en Sciences de Gestion',
            'cycle' => 'licence',
            'actif' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Responsable',
            'email' => 'admin.mature@fse.rnu.tn',
            'password' => 'PassSecure@123',
        ], 'admin_scolarite');

        $this->simpleUser = User::creerCompteBackOffice([
            'name' => 'Agent Lecture',
            'email' => 'agent.lecture@fse.rnu.tn',
            'password' => 'PassSecure@123',
        ], 'agent_scolarite');

        $this->engine = app(AffectationEngine::class);
    }

    private function creerEtudiant(string $numero, string $niveau = 'L1', array $extra = []): Etudiant
    {
        static $compteur = 7000;
        $compteur++;

        $sansInscription = (bool) ($extra['sans_inscription'] ?? false);
        unset($extra['sans_inscription']);

        $etudiant = Etudiant::create(array_merge([
            'num_inscription' => "INS-{$compteur}",
            'nom' => "Nom_{$numero}",
            'prenom' => "Prenom_{$numero}",
            'email' => "{$numero}@etudiant.tn",
            'cin' => str_pad((string) $compteur, 8, '0', STR_PAD_LEFT),
            'date_naissance' => '2004-02-14',
            'sexe' => 'M',
            'telephone' => '21234567',
            'adresse' => 'Rue de Carthage',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2022',
            'password' => 'Secret@123',
            'annee_academique' => '2026-2027',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => $niveau,
            'statut' => 'valide',
        ], $extra));

        if (! $sansInscription) {
            InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id,
                'annee_universitaire_id' => $this->annee->id,
                'faculte_id' => $this->faculte->id,
                'programme_id' => $this->programme->id,
                'niveau' => $niveau,
                'statut' => 'valide',
                'date_inscription' => now(),
            ]);
        }

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

    /**
     * TEST 1 : Blocage préalable si l'étudiant est administrativement inéligible.
     */
    public function test_moteur_bloque_etudiant_ineligible_administrativement(): void
    {
        $this->creerGroupe('G1', 'L1', 30);
        $etudiantInvalide = $this->creerEtudiant('ETU_NO_INS', 'L1', ['sans_inscription' => true]);

        $evaluation = $this->engine->evaluer($etudiantInvalide, $this->annee);

        $this->assertFalse($evaluation['eligible']);
        $this->assertSame(AffectationEngine::STATUT_NON_AFFECTABLE, $evaluation['decision']);
        $this->assertContains('REGISTRATION_MISSING', array_column($evaluation['anomalies'], 'code'));
    }

    /**
     * TEST 2 : Mode automatique lorsque décision certaine et dossier parfait.
     */
    public function test_moteur_affecte_directement_en_mode_automatique(): void
    {
        $groupe = $this->creerGroupe('G1', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_AUTO', 'L1');

        $evaluation = $this->engine->evaluer($etudiant, $this->annee);

        $this->assertTrue($evaluation['eligible']);
        $this->assertSame(AffectationEngine::STATUT_AUTO_ASSIGNABLE, $evaluation['decision']);
        $this->assertSame(AffectationProposal::CERTITUDE_ELEVEE, $evaluation['certitude']);
        $this->assertSame($groupe->id, $evaluation['meilleur_groupe']->id);

        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', (int) $this->admin->id);

        $this->assertSame('affecte', $resultat['type']);
        $this->assertSame($groupe->id, $etudiant->fresh()->groupe_id);
    }

    /**
     * TEST 3 : Repli semi-automatique lorsque contact incomplet (avertissement).
     */
    public function test_repli_semi_automatique_si_avertissement_contact(): void
    {
        $groupe = $this->creerGroupe('G1', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_SEMI', 'L1', ['telephone' => null]);

        $evaluation = $this->engine->evaluer($etudiant, $this->annee);

        $this->assertTrue($evaluation['eligible']);
        $this->assertSame(AffectationEngine::STATUT_SEMI_AUTO_PROPOSAL, $evaluation['decision']);
        $this->assertSame(AffectationProposal::CERTITUDE_MOYENNE, $evaluation['certitude']);
        $this->assertContains('CONTACT_PHONE_MISSING', $evaluation['avertissements']);

        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', (int) $this->admin->id);

        $this->assertSame('proposition', $resultat['type']);
        $this->assertInstanceOf(AffectationProposal::class, $resultat['proposal']);
        $this->assertSame(AffectationProposal::STATUT_EN_ATTENTE, $resultat['proposal']->statut);
        $this->assertSame($groupe->id, $resultat['proposal']->proposed_group_id);
        $this->assertNull($etudiant->fresh()->groupe_id);
    }

    /**
     * TEST 4 : Cycle de vie proposition : acceptation administrative.
     */
    public function test_acceptation_proposition_semi_automatique(): void
    {
        $groupe = $this->creerGroupe('G1', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_PROP_OK', 'L1', ['telephone' => null]);

        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', (int) $this->admin->id);
        $proposition = $resultat['proposal'];
        $this->assertSame(AffectationProposal::STATUT_EN_ATTENTE, $proposition->statut);

        $affectation = $this->engine->accepterProposition($proposition, (int) $this->admin->id, 'Validation officielle');

        $this->assertInstanceOf(AffectationGroupe::class, $affectation);
        $this->assertSame(AffectationProposal::STATUT_ACCEPTEE, $proposition->fresh()->statut);
        $this->assertSame($groupe->id, $etudiant->fresh()->groupe_id);
    }

    /**
     * TEST 5 : Cycle de vie proposition : modification du groupe proposé.
     */
    public function test_modification_proposition_semi_automatique(): void
    {
        $groupe1 = $this->creerGroupe('G1', 'L1', 30);
        $groupe2 = $this->creerGroupe('G2', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_PROP_MODIF', 'L1', ['telephone' => null]);

        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', (int) $this->admin->id);
        $proposition = $resultat['proposal'];
        $this->assertSame($groupe1->id, $proposition->proposed_group_id);

        $affectation = $this->engine->modifierProposition($proposition, $groupe2->id, (int) $this->admin->id, 'Rééquilibrage pédagogique manuel');

        $this->assertInstanceOf(AffectationGroupe::class, $affectation);
        $this->assertSame(AffectationProposal::STATUT_MODIFIEE, $proposition->fresh()->statut);
        $this->assertSame($groupe2->id, $etudiant->fresh()->groupe_id);
    }

    /**
     * TEST 6 : Cycle de vie proposition : rejet par l'administrateur.
     */
    public function test_rejet_proposition_semi_automatique(): void
    {
        $this->creerGroupe('G1', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_PROP_REJET', 'L1', ['telephone' => null]);

        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', (int) $this->admin->id);
        $this->engine->rejeterProposition($resultat['proposal'], (int) $this->admin->id, 'Dossier à réexaminer en commission');

        $this->assertSame(AffectationProposal::STATUT_REJETEE, $resultat['proposal']->fresh()->statut);
        $this->assertNull($etudiant->fresh()->groupe_id);
    }

    /**
     * TEST 7 : Affectation exceptionnelle avec override sécurisé, motif obligatoire et audit.
     */
    public function test_affectation_exceptionnelle_autorisee_avec_motif_et_audit(): void
    {
        $groupe = $this->creerGroupe('G1', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_EXCEPT', 'L1');

        $affectation = $this->engine->affecterExceptionnel(
            $etudiant,
            $groupe,
            $this->annee,
            $this->admin,
            'Autorisation dérogatoire du Doyen (Réf: DEC-2026/04)'
        );

        $this->assertInstanceOf(AffectationGroupe::class, $affectation);
        $this->assertSame($groupe->id, $etudiant->fresh()->groupe_id);

        $this->assertDatabaseHas('historique_academique', [
            'action' => 'assignment.exceptional_override',
            'etudiant_id' => $etudiant->id,
        ]);
    }

    /**
     * TEST 8 : Refus de l'affectation exceptionnelle sans motif explicite (422).
     */
    public function test_affectation_exceptionnelle_echoue_sans_motif(): void
    {
        $groupe = $this->creerGroupe('G1', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_NO_MOTIF', 'L1');

        $this->expectException(\DomainException::class);

        $this->engine->affecterExceptionnel(
            $etudiant,
            $groupe,
            $this->annee,
            $this->admin,
            '   '
        );
    }

    /**
     * TEST 9 : Idempotence — une double affectation ne crée qu'une seule ligne.
     */
    public function test_idempotence_affectation(): void
    {
        $groupe = $this->creerGroupe('G1', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_IDEM', 'L1');

        $premier = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', (int) $this->admin->id);
        $this->assertSame('affecte', $premier['type']);
        $this->assertSame($groupe->id, $etudiant->fresh()->groupe_id);

        $second = $this->engine->traiterEtudiant($etudiant->fresh(), $this->annee, 'automatique', (int) $this->admin->id);
        $this->assertSame('bloque', $second['type']);
        $this->assertSame('ALREADY_ASSIGNED', $second['anomalies'][0]['code']);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)
            ->count());
    }

    /**
     * TEST 10 : Phase 22 — Service Fiche Étudiant & les 6 axes d'éligibilité.
     */
    public function test_fiche_etudiant_eligibilite_six_axes(): void
    {
        $this->creerGroupe('G1', 'L1', 30);
        $etudiant = $this->creerEtudiant('ETU_FICHE', 'L1');
        $resultat = $this->engine->traiterEtudiant($etudiant, $this->annee, 'automatique', (int) $this->admin->id);
        $this->assertSame('affecte', $resultat['type']);

        $fiche = app(FicheEtudiantService::class);
        $bilan = $fiche->eligibiliteAffectation($etudiant->fresh(), $this->annee);

        $this->assertTrue($bilan['eligible']);
        $this->assertSame('affecte', $bilan['statut_affectation']['etat']);
        $this->assertArrayHasKey('identite', $bilan['axes']);
        $this->assertArrayHasKey('contact', $bilan['axes']);
        $this->assertArrayHasKey('programme', $bilan['axes']);
        $this->assertArrayHasKey('inscription', $bilan['axes']);
        $this->assertArrayHasKey('documents', $bilan['axes']);
        $this->assertArrayHasKey('affectation', $bilan['axes']);
    }
}
