<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Exceptions\RegroupementVirtuelRefuse;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Evaluation\Models\GroupeVirtuelMembre;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\GroupeVirtuelExamenService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT B — Groupes virtuels d'examen (§2.1).
 *
 * Prouve par exécution que le regroupement est calculé, persisté,
 * explicable, non destructif et propre à chaque module.
 */
class ExamGroupeVirtuelTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private SemestreLmd $semestre;

    private UniteEnseignement $ue;

    private Matiere $matiere;

    private SessionExamen $session;

    private Examen $examen;

    private Epreuve $epreuve;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Sciences GV', 'code' => 'FGV', 'active' => true,
        ]);

        $this->auteur = User::creerCompteBackOffice([
            'name' => 'Préparateur GV',
            'email' => 'prep.gv@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'P-GV', 'nom' => 'Programme GV',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'Licence 1', 'ordre' => 1,
        ]);

        $this->semestre = SemestreLmd::create([
            'niveau_id' => $this->niveau->id, 'code' => 'S1', 'nom' => 'Semestre 1',
            'numero' => 1, 'ordre' => 1,
        ]);

        $this->ue = UniteEnseignement::create([
            'semestre_id' => $this->semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE-GV', 'nom' => 'UE GV', 'credits' => 6, 'coefficient' => 2,
        ]);

        $this->matiere = Matiere::create([
            'code' => 'MAT-GV', 'nom' => 'Module GV', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4, 'actif' => true,
        ]);

        $this->session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-GV',
            'nom' => 'Session GV', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-20', 'statut' => 'draft',
        ]);

        $this->examen = Examen::create([
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'libelle' => 'Examen GV', 'type' => 'examen', 'statut' => 'draft',
        ]);

        $this->epreuve = $this->creerEpreuve($this->matiere);
    }

    private function creerEpreuve(Matiere $matiere): Epreuve
    {
        return Epreuve::create([
            'examen_id' => $this->examen->id,
            'session_id' => $this->session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiere->id,
            'date' => '2027-01-12', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'statut' => 'draft',
        ]);
    }

    private function creerGroupe(string $code, array $overrides = []): Group
    {
        return Group::create(array_merge([
            'code' => $code,
            'nom' => 'Groupe '.$code,
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'capacite' => 40,
            'actif' => true,
        ], $overrides));
    }

    private function creerEtudiant(Group $groupe, string $cin, bool $inscritAuModule = true): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prénom',
            'email' => strtolower($cin).'@gv.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $groupe->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Adresse GV',
            'ville' => 'Ville GV',
            'code_postal' => '1000',
            'annee_bac' => 2024,
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        if ($inscritAuModule) {
            $inscription->pedagogiques()->create([
                'semestre_id' => $this->semestre->id,
                'ue_id' => $this->ue->id,
                'matiere_id' => $this->matiere->id,
                'statut' => 'inscrit',
            ]);
        }

        return $etudiant;
    }

    /** AC-B4 — un même module agrège plusieurs groupes sources. */
    public function test_un_module_agrege_plusieurs_groupes_sources(): void
    {
        $groupeA = $this->creerGroupe('GV-A');
        $groupeB = $this->creerGroupe('GV-B');

        $this->creerEtudiant($groupeA, 'GV001');
        $this->creerEtudiant($groupeB, 'GV002');

        $groupe = app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuve, [$groupeA->id, $groupeB->id], $this->auteur->id);

        $this->assertSame(GroupeVirtuelExamen::STATUT_PROPOSE, $groupe->statut);
        $this->assertSame(2, $groupe->membres()->count());
        $this->assertSame(2, $groupe->effectifEligible());
        $this->assertEqualsCanonicalizing(
            [$groupeA->id, $groupeB->id],
            $groupe->identifiantsSources()
        );
    }

    /** AC-B1 — un étudiant non inscrit au module est exclu (et tracé comme tel). */
    public function test_etudiant_non_inscrit_au_module_est_exclu(): void
    {
        $groupeA = $this->creerGroupe('GV-C');
        $this->creerEtudiant($groupeA, 'GV003', inscritAuModule: true);
        $exclu = $this->creerEtudiant($groupeA, 'GV004', inscritAuModule: false);

        $groupe = app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuve, [$groupeA->id], $this->auteur->id);

        $this->assertSame(1, $groupe->effectifEligible());

        $membreExclu = $groupe->membres()->where('etudiant_id', $exclu->id)->first();
        $this->assertNotNull($membreExclu, 'La décision d’exclusion doit être persistée et explicable.');
        $this->assertSame(GroupeVirtuelMembre::DECISION_NON_ELIGIBLE, $membreExclu->decision);
        $this->assertNotEmpty($membreExclu->motif);
    }

    /** AC-B2 — niveaux ou programmes incompatibles : fusion refusée, rien n’est persisté. */
    public function test_groupes_incompatibles_ne_sont_jamais_fusionnes(): void
    {
        $groupeA = $this->creerGroupe('GV-D');

        $autreProgramme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'P-GV2', 'nom' => 'Programme GV 2',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $autreNiveau = Niveau::create([
            'programme_id' => $autreProgramme->id, 'code' => 'L2', 'nom' => 'Licence 2', 'ordre' => 2,
        ]);

        $groupeB = $this->creerGroupe('GV-E', [
            'programme_id' => $autreProgramme->id,
            'niveau_id' => $autreNiveau->id,
            'niveau' => 'L2',
        ]);

        $this->expectException(RegroupementVirtuelRefuse::class);
        $this->expectExceptionMessage('incompatible');

        try {
            app(GroupeVirtuelExamenService::class)
                ->proposer($this->epreuve, [$groupeA->id, $groupeB->id], $this->auteur->id);
        } finally {
            $this->assertSame(0, GroupeVirtuelExamen::query()->count(), 'Aucune proposition ne doit survivre à un refus.');
        }
    }

    /** AC-B3 — la création ne modifie ni les inscriptions ni les groupes d’origine. */
    public function test_creation_ne_modifie_ni_inscriptions_ni_groupes(): void
    {
        $groupeA = $this->creerGroupe('GV-F');
        $etudiant = $this->creerEtudiant($groupeA, 'GV005');

        $avantGroupe = $groupeA->fresh()->only(['code', 'nom', 'programme_id', 'niveau_id', 'capacite', 'actif']);
        $avantGroupeId = $etudiant->fresh()->groupe_id;
        $avantInscriptions = $etudiant->inscriptionsAcademiques()->count();
        $avantPedagogiques = $etudiant->inscriptionsAcademiques()->first()->pedagogiques()->count();

        app(GroupeVirtuelExamenService::class)
            ->proposer($this->epreuve, [$groupeA->id], $this->auteur->id);

        $this->assertSame($avantGroupe, $groupeA->fresh()->only(['code', 'nom', 'programme_id', 'niveau_id', 'capacite', 'actif']));
        $this->assertSame($avantGroupeId, $etudiant->fresh()->groupe_id);
        $this->assertSame($avantInscriptions, $etudiant->inscriptionsAcademiques()->count());
        $this->assertSame($avantPedagogiques, $etudiant->inscriptionsAcademiques()->first()->pedagogiques()->count());
    }

    /** AC-B5 — le regroupement diffère selon le module. */
    public function test_regroupement_differe_selon_le_module(): void
    {
        $groupeA = $this->creerGroupe('GV-G');
        $this->creerEtudiant($groupeA, 'GV006');

        $matiere2 = Matiere::create([
            'code' => 'MAT-GV2', 'nom' => 'Module GV 2', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4, 'actif' => true,
        ]);

        $epreuve2 = $this->creerEpreuve($matiere2);

        $service = app(GroupeVirtuelExamenService::class);
        $groupeModule1 = $service->proposer($this->epreuve, [$groupeA->id], $this->auteur->id);
        $groupeModule2 = $service->proposer($epreuve2, [$groupeA->id], $this->auteur->id);

        $this->assertNotSame($groupeModule1->id, $groupeModule2->id);
        $this->assertNotSame($groupeModule1->cle, $groupeModule2->cle);
        $this->assertSame($this->matiere->id, $groupeModule1->matiere_id);
        $this->assertSame($matiere2->id, $groupeModule2->matiere_id);
    }

    /** Une nouvelle proposition remplace l’ancienne SANS effacer l’historique. */
    public function test_nouvelle_proposition_conserve_l_historique(): void
    {
        $groupeA = $this->creerGroupe('GV-H');
        $groupeB = $this->creerGroupe('GV-I');
        $this->creerEtudiant($groupeA, 'GV007');
        $this->creerEtudiant($groupeB, 'GV008');

        $service = app(GroupeVirtuelExamenService::class);
        $premiere = $service->proposer($this->epreuve, [$groupeA->id], $this->auteur->id);
        $seconde = $service->proposer($this->epreuve, [$groupeB->id], $this->auteur->id);

        $this->assertSame(GroupeVirtuelExamen::STATUT_REMPLACE, $premiere->fresh()->statut);
        $this->assertSame($seconde->id, $premiere->fresh()->remplace_par_id);
        $this->assertSame(GroupeVirtuelExamen::STATUT_PROPOSE, $seconde->fresh()->statut);
        $this->assertSame(2, GroupeVirtuelExamen::query()->count(), 'L’ancienne version reste consultable.');
    }
}
