<?php

namespace Tests\Feature\Acquis;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\User;
use App\Modules\AssistantIa\Exceptions\OutilNonAutoriseException;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;
use App\Modules\AssistantIa\Services\RegistreOutils;
use App\Modules\AssistantIa\Tools\Metier\ExpliquerDecisionAcademiqueTool;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\Acquis\ExplicationDecisionAcademiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP 46 — EXPLICABILITÉ DES DÉCISIONS ET IA STRICTEMENT EN LECTURE SEULE.
 *
 * Vérifie :
 * - le refus fail-closed de toute décision non publiée ou incohérente ;
 * - la concordance mesurée entre l'acte officiel et la lecture du moteur,
 *   sans réinterpréter la décision du jury ;
 * - les faits non comparables (notes cumulées, agrégats différents) ;
 * - l'absence totale d'écriture, de recommandation et de sanction ;
 * - l'anonymisation, l'empreinte vérifiable et les citations réelles ;
 * - l'outil AssistantIa : permission, périmètre facultaire, registre et
 *   interdiction de transfert vers un fournisseur distant.
 */
class ExplicationDecisionAcademiqueLoop46Test extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private TypeDiplome $typeLicence;

    private Programme $programmeA;

    private Niveau $niveauA;

    private SemestreLmd $semestreA1;

    private AnneeUniversitaire $annee;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    private Deliberation $deliberation;

    private DecisionDeliberation $decision;

    private User $adminScolarite;

    private User $adminFaculteA;

    private User $adminFaculteB;

    private User $auditeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST-L46',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit',
            'code' => 'FD-L46',
            'active' => true,
        ]);

        $this->typeLicence = TypeDiplome::firstOrCreate(
            ['code' => 'L'],
            ['nom' => 'Licence Nationale LMD', 'cycle' => 'premier_cycle', 'actif' => true]
        );

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'type_diplome_id' => $this->typeLicence->id,
            'code' => 'LIC-L46',
            'nom' => 'Licence en Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 12,
            'actif' => true,
        ]);

        $this->niveauA = Niveau::create([
            'programme_id' => $this->programmeA->id,
            'code' => 'L1',
            'nom' => 'Licence 1ère Année',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->semestreA1 = SemestreLmd::create([
            'niveau_id' => $this->niveauA->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        $this->etudiant = $this->creerEtudiant('04600001', 'etudiant.l46@univ.test', $this->faculteA, $this->programmeA);
        $this->inscription = $this->creerInscription($this->etudiant, $this->programmeA, $this->niveauA);

        $this->deliberation = Deliberation::create([
            'code' => 'DELIB-L46-001',
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $this->niveauA->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);

        $ueCapitalisee = $this->creerUe('UE-L46-01', 'Algorithmique', 6);
        $ecue1 = $this->creerEcue($ueCapitalisee, 'EC-L46-01', 'Algo 1', 3, 1.5);
        $ecue2 = $this->creerEcue($ueCapitalisee, 'EC-L46-02', 'Langage C', 3, 1.5);

        $ueNonAcquise = $this->creerUe('UE-L46-02', 'Mathématiques', 6);
        $ecue3 = $this->creerEcue($ueNonAcquise, 'EC-L46-03', 'Analyse', 3, 1.5);
        $ecue4 = $this->creerEcue($ueNonAcquise, 'EC-L46-04', 'Algèbre', 3, 1.5);

        $this->attribuerNote($ecue1, 14.0);
        $this->attribuerNote($ecue2, 12.0);
        $this->attribuerNote($ecue3, 8.0);
        $this->attribuerNote($ecue4, 9.0);

        $this->decision = $this->creerDecision($this->etudiant, $this->inscription, [
            'moyenne' => 8.5,
            'credits_obtenus' => 6,
            'credits_total' => 12,
            'ues_validees' => 1,
            'ues_total' => 2,
            'decision' => 'ajourne',
        ]);

        $this->adminScolarite = User::creerCompteBackOffice([
            'name' => 'Administrateur Scolarité',
            'email' => 'admin.scolarite.l46@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Administrateur Faculté A',
            'email' => 'admin.fac.a.l46@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminFaculteB = User::creerCompteBackOffice([
            'name' => 'Administrateur Faculté B',
            'email' => 'admin.fac.b.l46@univ.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteB->id,
        ], 'admin_faculte');

        $this->auditeur = User::creerCompteBackOffice([
            'name' => 'Auditeur',
            'email' => 'auditeur.l46@univ.test',
            'password' => 'Motdepasse@123',
        ], 'auditeur');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function creerEtudiant(
        string $cin,
        string $email,
        Faculte $faculte,
        ?Programme $programme = null
    ): Etudiant {
        return Etudiant::create([
            'cin' => $cin,
            'nom' => 'Ben Salah',
            'prenom' => 'Yassine',
            'email' => $email,
            'telephone' => '+21620000000',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'programme_id' => $programme?->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '12 Rue de la Liberté',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'mention_bac' => 'Bien',
        ]);
    }

    private function creerInscription(
        Etudiant $etudiant,
        ?Programme $programme,
        ?Niveau $niveau
    ): InscriptionAcademique {
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $programme?->id,
            'niveau_id' => $niveau?->id,
            'filiere' => 'Informatique',
            'niveau' => 'L1',
            'statut' => 'en_cours',
        ]);

        $inscription->semestres()->create(['numero' => 1]);

        return $inscription;
    }

    private function creerUe(string $code, string $nom, int $credits): UniteEnseignement
    {
        return UniteEnseignement::create([
            'programme_id' => $this->programmeA->id,
            'semestre_id' => $this->semestreA1->id,
            'code' => $code,
            'nom' => $nom,
            'credits' => $credits,
            'coefficient' => 3.0,
            'ordre' => 1,
            'actif' => true,
        ]);
    }

    private function creerEcue(
        UniteEnseignement $ue,
        string $code,
        string $nom,
        int $credits,
        float $coefficient
    ): Matiere {
        return Matiere::create([
            'ue_id' => $ue->id,
            'code' => $code,
            'nom' => $nom,
            'credits' => $credits,
            'coefficient' => $coefficient,
            'semestre' => 1,
            'niveau' => 'L1',
            'type_evaluation' => 'standard',
            'actif' => true,
        ]);
    }

    private function attribuerNote(
        Matiere $matiere,
        float $note,
        ?InscriptionAcademique $inscription = null
    ): Note {
        $inscription ??= $this->inscription;

        return Note::create([
            'etudiant_id' => $inscription->etudiant_id,
            'inscription_academique_id' => $inscription->id,
            'semestre_id' => $inscription->semestres()->first()?->id,
            'matiere_id' => $matiere->id,
            'note' => $note,
            'coefficient' => $matiere->coefficient,
            'credits' => $matiere->credits,
            'session' => 'normale',
        ]);
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function creerDecision(
        Etudiant $etudiant,
        ?InscriptionAcademique $inscription,
        array $surcharges = [],
        ?Deliberation $deliberation = null
    ): DecisionDeliberation {
        return DecisionDeliberation::create(array_merge([
            'deliberation_id' => ($deliberation ?? $this->deliberation)->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription?->id,
            'moyenne' => 8.5,
            'credits_obtenus' => 6,
            'credits_total' => 12,
            'ues_validees' => 1,
            'ues_total' => 2,
            'decision' => 'ajourne',
            'publie' => true,
        ], $surcharges));
    }

    private function service(): ExplicationDecisionAcademiqueService
    {
        return app(ExplicationDecisionAcademiqueService::class);
    }

    private function outil(): ExpliquerDecisionAcademiqueTool
    {
        return app(ExpliquerDecisionAcademiqueTool::class);
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return array<int, string>
     */
    private function clesRecursives(array $donnees): array
    {
        $cles = [];

        foreach ($donnees as $cle => $valeur) {
            if (is_string($cle)) {
                $cles[] = $cle;
            }

            if (is_array($valeur)) {
                $cles = array_merge($cles, $this->clesRecursives($valeur));
            }
        }

        return array_values(array_unique($cles));
    }

    // ---------------------------------------------------------------------
    // 1. Fail-closed : aucune décision non publiée ou incohérente
    // ---------------------------------------------------------------------

    public function test_decision_non_publiee_refusee_sans_lecture_moteur(): void
    {
        $this->decision->forceFill(['publie' => false])->save();

        $explication = $this->service()->expliquer($this->decision->fresh());

        $this->assertTrue($explication['lecture_seule']);
        $this->assertFalse($explication['explicable']);
        $this->assertSame('DECISION_NON_PUBLIEE', $explication['motif_indisponibilite']);
        $this->assertNull($explication['lecture_moteur']);
        $this->assertSame('NON_EXPLICABLE', $explication['concordance']['statut']);
        $this->assertSame([], $explication['citations']);
        $this->assertNull($explication['empreinte']);
        $this->assertTrue($explication['garanties']['aucune_sanction']);
    }

    public function test_deliberation_introuvable_refusee(): void
    {
        $orpheline = new DecisionDeliberation([
            'deliberation_id' => 999999,
            'etudiant_id' => $this->etudiant->id,
            'inscription_academique_id' => $this->inscription->id,
            'credits_obtenus' => 6,
            'credits_total' => 12,
            'ues_validees' => 1,
            'ues_total' => 2,
            'decision' => 'ajourne',
            'publie' => true,
        ]);
        $orpheline->setRelation('etudiant', $this->etudiant);

        $explication = $this->service()->expliquer($orpheline);

        $this->assertFalse($explication['explicable']);
        $this->assertSame('DELIBERATION_INTROUVABLE', $explication['motif_indisponibilite']);
    }

    public function test_deliberation_non_publiee_refusee(): void
    {
        $this->deliberation->forceFill(['statut' => 'validated', 'published_at' => null])->save();

        $explication = $this->service()->expliquer($this->decision->fresh());

        $this->assertFalse($explication['explicable']);
        $this->assertSame('DELIBERATION_NON_PUBLIEE', $explication['motif_indisponibilite']);
        $this->assertNotEmpty($explication['alertes_integrite']);
    }

    public function test_inscription_non_rattachee_refusee(): void
    {
        $etudiantSansInscription = $this->creerEtudiant('04600002', 'sans.inscription.l46@univ.test', $this->faculteA, $this->programmeA);
        $decision = $this->creerDecision($etudiantSansInscription, null);

        $explication = $this->service()->expliquer($decision);

        $this->assertFalse($explication['explicable']);
        $this->assertSame('INSCRIPTION_NON_RATTACHEE', $explication['motif_indisponibilite']);
    }

    public function test_programme_indetermine_refuse_sans_appeler_le_moteur(): void
    {
        $etudiantSansProgramme = $this->creerEtudiant('04600003', 'sans.programme.l46@univ.test', $this->faculteA, null);
        $inscription = $this->creerInscription($etudiantSansProgramme, null, null);
        $decision = $this->creerDecision($etudiantSansProgramme, $inscription);

        $explication = $this->service()->expliquer($decision);

        $this->assertFalse($explication['explicable']);
        $this->assertSame('PROGRAMME_INDETERMINE', $explication['motif_indisponibilite']);
        $this->assertNull($explication['lecture_moteur']);
    }

    public function test_regime_indetermine_refuse_au_lieu_de_deviner(): void
    {
        $typeInconnu = TypeDiplome::create([
            'code' => 'XX',
            'nom' => 'Diplôme expérimental',
            'cycle' => 'premier_cycle',
            'actif' => true,
        ]);

        $programmeInconnu = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'type_diplome_id' => $typeInconnu->id,
            'code' => 'EXP-L46',
            'nom' => 'Formation spéciale sans régime',
            'duree_annees' => 1,
            'semestres_nombre' => 2,
            'credits_requis' => 12,
            'actif' => true,
        ]);

        $etudiant = $this->creerEtudiant('04600004', 'regime.inconnu.l46@univ.test', $this->faculteA, $programmeInconnu);
        $inscription = $this->creerInscription($etudiant, $programmeInconnu, null);
        $decision = $this->creerDecision($etudiant, $inscription);

        $explication = $this->service()->expliquer($decision);

        $this->assertFalse($explication['explicable']);
        $this->assertSame('REGIME_INDETERMINE', $explication['motif_indisponibilite']);
        $this->assertNull($explication['lecture_moteur']);
    }

    // ---------------------------------------------------------------------
    // 2. Concordance mesurée, jamais décisionnaire
    // ---------------------------------------------------------------------

    public function test_decision_concordante_avec_la_lecture_du_moteur(): void
    {
        $explication = $this->service()->expliquer($this->decision);

        $this->assertTrue($explication['explicable']);
        $this->assertTrue($explication['lecture_seule']);
        $this->assertSame('CONCORDANTE', $explication['concordance']['statut']);
        $this->assertTrue($explication['concordance']['perimetre_comparable']);
        $this->assertFalse($explication['concordance']['consequence_automatique']);
        $this->assertSame(6, $explication['lecture_moteur']['credits']['capitalises']);

        $faits = collect($explication['concordance']['faits_compares'])->keyBy('fait');
        $this->assertTrue($faits['credits_acquis']['concordant']);
        $this->assertSame(0, $faits['credits_acquis']['ecart']);
        $this->assertTrue($faits['ues_validees']['concordant']);
        $this->assertTrue($explication['decision_officielle']['decision_officielle_fait_foi']);
        $this->assertSame('acte_souverain_du_jury', $explication['decision_officielle']['portee']);
    }

    public function test_ecart_mesure_reste_un_constat_sans_consequence(): void
    {
        $this->decision->forceFill(['credits_obtenus' => 3])->save();

        $explication = $this->service()->expliquer($this->decision->fresh());

        $this->assertSame('ECART_MESURE', $explication['concordance']['statut']);
        $this->assertFalse($explication['concordance']['consequence_automatique']);
        $this->assertSame('constat_technique', $explication['concordance']['nature']);
        $this->assertCount(1, $explication['concordance']['ecarts']);
        $this->assertSame(3, $explication['concordance']['ecarts'][0]['ecart']);
        $this->assertStringContainsString('sans conséquence automatique', $explication['concordance']['explication']);
        $this->assertStringContainsString('décision officielle fait foi', $explication['concordance']['explication']);
    }

    public function test_notes_cumulees_sur_plusieurs_inscriptions_rendent_la_comparaison_non_comparable(): void
    {
        $anneePrecedente = AnneeUniversitaire::create([
            'libelle' => '2024-2025',
            'date_debut' => '2024-09-01',
            'date_fin' => '2025-06-30',
            'active' => false,
        ]);

        $inscriptionPrecedente = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id,
            'annee_universitaire_id' => $anneePrecedente->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $this->niveauA->id,
            'filiere' => 'Informatique',
            'niveau' => 'L1',
            'statut' => 'ajourne',
        ]);
        $inscriptionPrecedente->semestres()->create(['numero' => 1]);

        $matiere = Matiere::where('code', 'EC-L46-03')->firstOrFail();
        $this->attribuerNote($matiere, 15.0, $inscriptionPrecedente);

        $explication = $this->service()->expliquer($this->decision->fresh());

        $this->assertSame('NON_COMPARABLE', $explication['concordance']['statut']);
        $this->assertFalse($explication['concordance']['perimetre_comparable']);

        foreach ($explication['concordance']['faits_compares'] as $fait) {
            $this->assertFalse($fait['comparable']);
            $this->assertNull($fait['concordant']);
            $this->assertNotNull($fait['raison_non_comparable']);
        }

        $this->assertStringContainsString('périmètre plus large', $explication['concordance']['explication']);
    }

    public function test_moyenne_et_total_officiels_ne_sont_pas_compares_abusivement(): void
    {
        $explication = $this->service()->expliquer($this->decision);

        $nonCompares = collect($explication['concordance']['faits_non_compares'])->keyBy('fait');
        $this->assertArrayHasKey('moyenne', $nonCompares);
        $this->assertArrayHasKey('credits_total', $nonCompares);
        $this->assertSame(8.5, $nonCompares['moyenne']['valeur_officielle']);
        $this->assertSame(12, $nonCompares['credits_total']['valeur_officielle']);
    }

    // ---------------------------------------------------------------------
    // 3. Lecture seule, anonymisation, citations, empreinte
    // ---------------------------------------------------------------------

    public function test_aucune_ecriture_en_base_pendant_l_explication(): void
    {
        $avant = [
            'decisions' => DecisionDeliberation::count(),
            'notes' => Note::count(),
            'inscriptions' => InscriptionAcademique::count(),
            'etudiants' => Etudiant::count(),
        ];

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service()->expliquer($this->decision->fresh());

        $ecritures = collect(DB::getQueryLog())
            ->filter(fn ($requete) => preg_match(
                '/^\s*(insert|update|delete|replace|alter|drop|truncate)\b/i',
                (string) ($requete['query'] ?? '')
            ))
            ->values();

        DB::disableQueryLog();

        $this->assertCount(0, $ecritures, 'L\'explication ne doit exécuter aucune écriture SQL.');
        $this->assertSame($avant, [
            'decisions' => DecisionDeliberation::count(),
            'notes' => Note::count(),
            'inscriptions' => InscriptionAcademique::count(),
            'etudiants' => Etudiant::count(),
        ]);
    }

    public function test_aucune_recommandation_ni_sanction_dans_le_payload(): void
    {
        $explication = $this->service()->expliquer($this->decision);

        $this->assertTrue($explication['garanties']['aucune_recommandation']);
        $this->assertTrue($explication['garanties']['aucune_sanction']);
        $this->assertTrue($explication['garanties']['aucune_consequence_automatique']);

        $cles = $this->clesRecursives($explication);
        foreach (['recommandation', 'recommandations', 'sanction', 'sanctions', 'plan_action', 'plan_deblocage', 'mesure_corrective', 'orientation'] as $interdite) {
            $this->assertNotContains($interdite, $cles, "La clé interdite '{$interdite}' ne doit pas apparaître.");
        }
    }

    public function test_identite_anonymisee_et_matricule_masque(): void
    {
        $explication = $this->service()->expliquer($this->decision);
        $json = json_encode($explication, JSON_UNESCAPED_UNICODE);

        $this->assertSame((int) $this->etudiant->id, $explication['etudiant']['id']);
        $this->assertSame(
            ExplicationDecisionAcademiqueService::masquerIdentifiant($this->etudiant->num_inscription),
            $explication['etudiant']['matricule_masque']
        );
        $this->assertNotSame($this->etudiant->num_inscription, $explication['etudiant']['matricule_masque']);
        $this->assertStringContainsString('*', (string) $explication['etudiant']['matricule_masque']);
        $this->assertArrayNotHasKey('nom', $explication['etudiant']);
        $this->assertArrayNotHasKey('prenom', $explication['etudiant']);
        $this->assertArrayNotHasKey('cin', $explication['etudiant']);
        $this->assertArrayNotHasKey('email', $explication['etudiant']);

        $this->assertStringNotContainsString((string) $this->etudiant->num_inscription, $json);
        $this->assertStringNotContainsString('Ben Salah', $json);
        $this->assertStringNotContainsString('Yassine', $json);
        $this->assertStringNotContainsString('etudiant.l46@univ.test', $json);
        $this->assertStringNotContainsString('04600001', $json);
    }

    public function test_citations_reelles_et_traçables(): void
    {
        $explication = $this->service()->expliquer($this->decision);

        $citations = collect($explication['citations']);
        $this->assertNotEmpty($citations);

        $references = $citations->pluck('reference')->all();
        $this->assertContains('evaluation_decisions#'.$this->decision->id, $references);
        $this->assertContains('evaluation_deliberations#'.$this->deliberation->id, $references);
        $this->assertContains('inscriptions_academiques#'.$this->inscription->id, $references);

        $officielles = $citations->where('origine', 'base_officielle');
        $this->assertTrue($officielles->isNotEmpty());
        $this->assertTrue($officielles->every(fn ($citation) => $citation['verifiee'] === true));

        $moteur = $citations->where('origine', 'moteur_decision');
        $this->assertTrue($moteur->isNotEmpty());
        $this->assertTrue($moteur->every(fn ($citation) => $citation['verifiee'] === false));

        $this->assertTrue($citations->contains(
            fn ($citation) => $citation['type'] === 'reference_regle'
                && str_contains((string) $citation['reference'], 'Arrêté du 7 juillet 2023')
        ));
    }

    public function test_empreinte_deterministe_et_verifiable(): void
    {
        $premiere = $this->service()->expliquer($this->decision);
        $seconde = $this->service()->expliquer($this->decision->fresh());

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $premiere['empreinte']);
        $this->assertSame($premiere['empreinte'], $seconde['empreinte']);
        $this->assertTrue($this->service()->verifierEmpreinte($premiere));

        $alteree = $premiere;
        $alteree['decision_officielle']['credits_obtenus'] = 99;
        $this->assertFalse($this->service()->verifierEmpreinte($alteree));

        $altereeMoteur = $premiere;
        $altereeMoteur['lecture_moteur']['credits']['capitalises'] = 99;
        $this->assertFalse($this->service()->verifierEmpreinte($altereeMoteur));
    }

    public function test_alertes_integrite_signalees_sans_bloquer(): void
    {
        $secondeDeliberation = Deliberation::create([
            'code' => 'DELIB-L46-002',
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programmeA->id,
            'niveau_id' => $this->niveauA->id,
            'statut' => 'published',
            'published_at' => now(),
        ]);

        $this->creerDecision($this->etudiant, $this->inscription, [
            'credits_obtenus' => 6,
        ], $secondeDeliberation);

        $explication = $this->service()->expliquer($this->decision->fresh());

        $this->assertTrue($explication['explicable']);
        $this->assertTrue(collect($explication['alertes_integrite'])->contains(
            fn ($alerte) => str_contains($alerte, 'Plusieurs décisions')
        ));
    }

    // ---------------------------------------------------------------------
    // 4. Outil AssistantIa : permissions, périmètre, registre, distant
    // ---------------------------------------------------------------------

    public function test_outil_refuse_un_identifiant_invalide(): void
    {
        $resultat = $this->outil()->executer(['decision_id' => 0], $this->adminScolarite);

        $this->assertFalse($resultat->succes);
        $this->assertStringContainsString('obligatoire', $resultat->message);
    }

    public function test_outil_refuse_une_decision_introuvable(): void
    {
        $resultat = $this->outil()->executer(['decision_id' => 999999], $this->adminScolarite);

        $this->assertFalse($resultat->succes);
        $this->assertStringContainsString('introuvable', $resultat->message);
    }

    public function test_outil_refuse_un_profil_sans_habilitation(): void
    {
        $this->expectException(SecuriteIaException::class);
        $this->expectExceptionMessage('habilitation');

        $this->outil()->executer(['decision_id' => $this->decision->id], $this->auditeur);
    }

    public function test_outil_controle_le_perimetre_facultaire(): void
    {
        try {
            $this->outil()->executer(['decision_id' => $this->decision->id], $this->adminFaculteB);
            $this->fail('Un administrateur d\'une autre faculté ne doit pas expliquer cette décision.');
        } catch (SecuriteIaException $exception) {
            $this->assertStringContainsString('périmètre', mb_strtolower($exception->getMessage()));
        }

        $resultat = $this->outil()->executer(['decision_id' => $this->decision->id], $this->adminFaculteA);
        $this->assertTrue($resultat->succes);
    }

    public function test_outil_retourne_un_succes_lecture_seule_et_anonymise(): void
    {
        $resultat = $this->outil()->executer(['decision_id' => $this->decision->id], $this->adminScolarite);

        $this->assertTrue($resultat->succes);
        $this->assertFalse($resultat->necessiteApprobation);
        $this->assertNull($resultat->typeAction);
        $this->assertTrue($resultat->donnees['lecture_seule']);
        $this->assertSame('expliquer_decision_academique', $resultat->donnees['outil']);
        $this->assertTrue($resultat->donnees['anonymisation']['matricule_masque']);
        $this->assertFalse($resultat->donnees['anonymisation']['nom_expose']);
        $this->assertNotEmpty($resultat->sources);
        $this->assertStringContainsString('sans recommandation ni sanction', $resultat->message);
        $this->assertStringNotContainsString('04600001', json_encode($resultat->donnees, JSON_UNESCAPED_UNICODE));
    }

    public function test_outil_refus_non_publie_retourne_un_echec_explicite(): void
    {
        $this->decision->forceFill(['publie' => false])->save();

        $resultat = $this->outil()->executer(['decision_id' => $this->decision->id], $this->adminScolarite);

        $this->assertFalse($resultat->succes);
        $this->assertTrue($resultat->donnees['lecture_seule']);
        $this->assertSame('DECISION_NON_PUBLIEE', $resultat->donnees['motif_indisponibilite']);
        $this->assertStringContainsString('publiée', $resultat->message);
    }

    public function test_outil_declare_son_contrat_et_est_enregistre(): void
    {
        $outil = $this->outil();

        $this->assertSame('expliquer_decision_academique', $outil->nom());
        $this->assertSame('metier', $outil->mode());
        $this->assertSame('assistant_ia.mode_metier', $outil->permissionRequise());
        $this->assertFalse($outil->estSensible());
        $this->assertContains('decision_id', $outil->schemaParametres()['required']);

        $registre = app(RegistreOutils::class);
        $this->assertTrue($registre->existe('expliquer_decision_academique'));
        $this->assertArrayHasKey(
            'expliquer_decision_academique',
            $registre->pourUtilisateurEtMode($this->adminScolarite, 'metier')
        );
        $this->assertArrayNotHasKey(
            'expliquer_decision_academique',
            $registre->pourUtilisateurEtMode($this->auditeur, 'metier')
        );
    }

    public function test_outil_est_interdit_au_fournisseur_distant(): void
    {
        $registre = app(RegistreOutils::class);

        $definitionsLocales = collect($registre->obtenirDefinitionsPourIa($this->adminScolarite, 'metier', 'local'))
            ->pluck('function.name');
        $definitionsDistantes = collect($registre->obtenirDefinitionsPourIa($this->adminScolarite, 'metier', 'distant'))
            ->pluck('function.name');

        $this->assertContains('expliquer_decision_academique', $definitionsLocales);
        $this->assertNotContains('expliquer_decision_academique', $definitionsDistantes);

        $this->expectException(OutilNonAutoriseException::class);
        $this->expectExceptionMessage('données académiques individuelles');

        $registre->executerOutil(
            'expliquer_decision_academique',
            ['decision_id' => $this->decision->id],
            $this->adminScolarite,
            'metier',
            'distant'
        );
    }
}
