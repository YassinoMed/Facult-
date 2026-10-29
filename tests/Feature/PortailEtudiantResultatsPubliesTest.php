<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D-01 — le portail étudiant n'expose JAMAIS un résultat non publié.
 *
 * Source de vérité : `evaluation_decision_deliberations.publie = true`
 * (snapshot moyenne/crédits/décision). Les colonnes de
 * `inscriptions_academiques` (moyenne_annuelle, credits_obtenus,
 * decision) sont recalculées à chaque note : elles ne valent pas
 * publication et ne doivent jamais alimenter le portail.
 *
 * Couverture HTTP : dashboard, inscriptions, archives, notes —
 * non publié masqué, publié visible, publication partielle par
 * inscription, absence de délibération.
 */
class PortailEtudiantResultatsPubliesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeSuivante;

    private Etudiant $etudiant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        $this->anneeSuivante = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->etudiant = Etudiant::create([
            'cin' => 'D01-00001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'd01.portail@universite.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        DossierAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'matricule' => 'MAT-D01-001',
            'date_archivage' => now()->toDateString(),
        ]);

        $this->connecterEtudiant($this->etudiant);
    }

    // -----------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------

    public function test_dashboard_masque_un_resultat_non_publie(): void
    {
        $this->inscrireAvecResultat($this->annee, [
            'moyenne_annuelle' => 16.54,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis_compensation',
        ]);

        $this->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('est pas encore publiée')
            ->assertDontSee('16.54')
            ->assertDontSee('admis_compensation');
    }

    public function test_dashboard_affiche_le_snapshot_publie_et_non_les_colonnes_recalculees(): void
    {
        // Les colonnes d'inscription (16.54 / admis) diffèrent du snapshot
        // publié (11.79 / admis_compensation) : le dashboard doit rendre
        // le snapshot, seule valeur opposable à l'étudiant.
        $inscription = $this->inscrireAvecResultat($this->annee, [
            'moyenne_annuelle' => 16.54,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);
        $this->publierDecision($inscription, [
            'moyenne' => 11.79,
            'credits_obtenus' => 48,
            'credits_total' => 60,
            'decision' => 'admis_compensation',
        ]);

        $this->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('11.79')
            ->assertSee('48 / 60')
            ->assertSee('admis_compensation')
            ->assertDontSee('16.54');
    }

    // -----------------------------------------------------------------
    // Inscriptions
    // -----------------------------------------------------------------

    public function test_inscriptions_masque_moyenne_credits_et_decision_non_publies(): void
    {
        $this->inscrireAvecResultat($this->annee, [
            'niveau' => 'L1',
            'statut' => 'valide',
            'moyenne_annuelle' => 16.54,
            'credits_obtenus' => 42,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);

        $this->get(route('etudiant.inscriptions.index'))
            ->assertOk()
            // Métadonnées (non résultats) toujours visibles.
            ->assertSee('2025-2026')
            ->assertSee('L1')
            ->assertSee('Validé')
            // Aucun résultat non publié.
            ->assertSee('En attente de publication')
            ->assertDontSee('16.54')
            ->assertDontSee('42/60')
            ->assertDontSee('Admis');
    }

    public function test_inscriptions_affiche_les_resultats_publies(): void
    {
        $inscription = $this->inscrireAvecResultat($this->annee, [
            'niveau' => 'L1',
            'statut' => 'valide',
            'moyenne_annuelle' => 16.54,
            'credits_obtenus' => 42,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);
        $this->publierDecision($inscription, [
            'moyenne' => 16.54,
            'credits_obtenus' => 42,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);

        $this->get(route('etudiant.inscriptions.index'))
            ->assertOk()
            ->assertSee('16.54')
            ->assertSee('42/60')
            ->assertSee('Admis')
            ->assertDontSee('En attente de publication');
    }

    // -----------------------------------------------------------------
    // Archives
    // -----------------------------------------------------------------

    public function test_archives_masque_les_semestres_et_la_synthese_non_publies(): void
    {
        $this->inscrireAvecResultat($this->annee, [
            'moyenne_annuelle' => 16.54,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ], semestre: ['moyenne' => 15.06, 'credits_obtenus' => 30, 'credits_total' => 30, 'decision' => 'Validé']);

        $this->get(route('etudiant.archives.show'))
            ->assertOk()
            ->assertSee('En attente de publication')
            // Ni la moyenne annuelle, ni la ligne de semestre.
            ->assertDontSee('16.54')
            ->assertDontSee('15.06')
            ->assertDontSee('30/30');
    }

    public function test_archives_affiche_les_resultats_publies(): void
    {
        $inscription = $this->inscrireAvecResultat($this->annee, [
            'moyenne_annuelle' => 16.54,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ], semestre: ['moyenne' => 15.06, 'credits_obtenus' => 30, 'credits_total' => 30, 'decision' => 'Validé']);
        $this->publierDecision($inscription, [
            'moyenne' => 16.54,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);

        $this->get(route('etudiant.archives.show'))
            ->assertOk()
            ->assertSee('16.54')
            ->assertSee('30/30')
            ->assertSee('admis')
            ->assertDontSee('En attente de publication');
    }

    // -----------------------------------------------------------------
    // Publication partielle / absence de délibération
    // -----------------------------------------------------------------

    public function test_publication_partielle_par_inscription_seul_le_publie_est_affiche(): void
    {
        // Année 2025-2026 : publiée (14.56). Année 2026-2027 : notes
        // saisies mais décision NON publiée (18.73). La publication est
        // binaire par inscription : seul le publié sort.
        $publiee = $this->inscrireAvecResultat($this->annee, [
            'moyenne_annuelle' => 14.56,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);
        $this->publierDecision($publiee, [
            'moyenne' => 14.56,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);
        $this->inscrireAvecResultat($this->anneeSuivante, [
            'moyenne_annuelle' => 18.73,
            'credits_obtenus' => 30,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);

        $this->get(route('etudiant.archives.show'))
            ->assertOk()
            ->assertSee('14.56')
            ->assertSee('En attente de publication')
            ->assertDontSee('18.73');

        $this->get(route('etudiant.inscriptions.index'))
            ->assertOk()
            ->assertSee('14.56')
            ->assertSee('En attente de publication')
            ->assertDontSee('18.73');

        // Le dashboard porte sur la DERNIÈRE inscription (2026-2027,
        // non publiée) : aucun résultat n'est affiché.
        $this->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('est pas encore publiée')
            ->assertDontSee('18.73')
            ->assertDontSee('14.56');
    }

    public function test_absence_de_decision_conserve_les_metadonnees_sans_resultat(): void
    {
        $this->inscrireAvecResultat($this->annee, [
            'niveau' => 'L1',
            'statut' => 'valide',
            'moyenne_annuelle' => 13.27,
            'credits_obtenus' => 30,
            'credits_total' => 60,
            'decision' => null,
        ]);

        $this->get(route('etudiant.inscriptions.index'))
            ->assertOk()
            ->assertSee('2025-2026')
            ->assertSee('L1')
            ->assertSee('Validé')
            ->assertSee('En attente de publication')
            ->assertDontSee('13.27')
            ->assertDontSee('30/60');
    }

    // -----------------------------------------------------------------
    // Notes
    // -----------------------------------------------------------------

    public function test_notes_ne_divulgue_aucune_note_dune_inscription_non_publiee(): void
    {
        $publiee = $this->inscrireAvecResultat($this->annee, [
            'moyenne_annuelle' => 14.06,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);
        $this->publierDecision($publiee, [
            'moyenne' => 14.06,
            'credits_obtenus' => 60,
            'credits_total' => 60,
            'decision' => 'admis',
        ]);
        $this->noter($publiee, 'D01-PUBLIQUE', 14.06);

        $nonPubliee = $this->inscrireAvecResultat($this->anneeSuivante, [
            'moyenne_annuelle' => 9.53,
            'credits_obtenus' => 0,
            'credits_total' => 60,
            'decision' => 'ajourne',
        ]);
        $this->noter($nonPubliee, 'D01-SECRET', 9.53);

        $this->get(route('etudiant.notes.index'))
            ->assertOk()
            ->assertSee('D01-PUBLIQUE')
            ->assertDontSee('D01-SECRET')
            ->assertDontSee('9.53');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $resultats
     * @param  array<string, mixed>|null  $semestre
     */
    private function inscrireAvecResultat(AnneeUniversitaire $annee, array $resultats, ?array $semestre = []): InscriptionAcademique
    {
        $inscription = $this->etudiant->inscriptionsAcademiques()->create(array_merge([
            'annee_universitaire_id' => $annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
        ], $resultats));

        if ($semestre !== null) {
            $valeurs = array_merge(['numero' => 1], $semestre);
            $inscription->semestres()->create($valeurs);
        }

        return $inscription;
    }

    /** @param array<string, mixed> $snapshot */
    private function publierDecision(InscriptionAcademique $inscription, array $snapshot): DecisionDeliberation
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $inscription->annee_universitaire_id,
            'statut' => 'published',
            'published_at' => now(),
        ]);

        return DecisionDeliberation::create(array_merge([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'publie' => true,
        ], $snapshot));
    }

    private function noter(InscriptionAcademique $inscription, string $codeMatiere, float $note): void
    {
        $semestre = Semestre::where('inscription_academique_id', $inscription->id)->firstOrFail();
        $matiere = Matiere::firstOrCreate(
            ['code' => $codeMatiere],
            ['nom' => $codeMatiere, 'coefficient' => 1, 'credits' => 5, 'niveau' => 'L1', 'semestre' => 1],
        );

        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre->id,
            'matiere_id' => $matiere->id,
            'note' => $note,
            'coefficient' => $matiere->coefficient,
            'credits' => $matiere->credits,
            'session' => 'normale',
        ]);
    }

    private function connecterEtudiant(Etudiant $etudiant): void
    {
        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_auth_prenom' => $etudiant->prenom,
            'etudiant_auth_nom' => $etudiant->nom,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ]);
    }
}
