<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Services\Pdf\OfficialDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Phase G (roadmap « restes connus ») : génération du DIPLOME —
 * décision admis* PUBLIÉE + FIN DE CYCLE exclusivement (même source
 * de vérité que la progression : niveauApres === null).
 */
class DiplomePhaseGTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Etudiant $etudiantL3;

    private Etudiant $etudiantL2;

    private InscriptionAcademique $inscriptionL3;

    private InscriptionAcademique $inscriptionL2;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'LINFO', 'nom' => 'Licence Informatique',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $l2 = Niveau::create(['programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'Licence 2', 'ordre' => 2]);
        $l3 = Niveau::create(['programme_id' => $programme->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3]);

        $this->etudiantL3 = $this->creerEtudiant($faculte, 'L3');
        $this->etudiantL2 = $this->creerEtudiant($faculte, 'L2');

        $this->inscriptionL3 = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantL3->id, 'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $l3->id,
            'niveau' => 'L3', 'filiere' => 'Info', 'statut' => 'valide',
        ]);
        $this->inscriptionL2 = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantL2->id, 'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $l2->id,
            'niveau' => 'L2', 'filiere' => 'Info', 'statut' => 'valide',
        ]);

        $this->auteur = $this->scolarite('admin_scolarite');
    }

    private function creerEtudiant(Faculte $faculte, string $niveau): Etudiant
    {
        return Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => 'Diplomé', 'prenom' => 'Test '.$niveau,
            'email' => uniqid('dip.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => $niveau, 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Sfax',
            'code_postal' => '3000', 'annee_bac' => '2021',
            'password' => 'motdepasse1',
        ]);
    }

    private function publierDecision(InscriptionAcademique $inscription, Etudiant $etudiant, string $decision): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $inscription->annee_universitaire_id,
            'statut' => 'published',
            'created_by' => $this->auteur->id,
            'published_at' => now(),
        ]);

        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'inscription_academique_id' => $inscription->id,
            'etudiant_id' => $etudiant->id,
            'moyenne' => 14.5,
            'decision' => $decision,
            'publie' => true,
        ]);
    }

    public function test_diplome_exige_decision_admis_publiee(): void
    {
        $officiels = app(OfficialDocumentService::class);

        // Aucune décision publiée → refus.
        $this->expectException(\DomainException::class);
        $officiels->generer($this->etudiantL3, 'diplome', $this->inscriptionL3->id, (int) $this->auteur->id);
    }

    public function test_diplome_refuse_hors_fin_de_cycle(): void
    {
        $this->publierDecision($this->inscriptionL2, $this->etudiantL2, 'admis');

        $officiels = app(OfficialDocumentService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('fin de cycle');
        $officiels->generer($this->etudiantL2, 'diplome', $this->inscriptionL2->id, (int) $this->auteur->id);
    }

    public function test_diplome_genere_en_fin_de_cycle_avec_decision_admise(): void
    {
        $this->publierDecision($this->inscriptionL3, $this->etudiantL3, 'admis');

        $officiels = app(OfficialDocumentService::class);
        $document = $officiels->generer($this->etudiantL3, 'diplome', $this->inscriptionL3->id, (int) $this->auteur->id);

        $this->assertSame('diplome', $document->type);
        $this->assertSame('valide', $document->statut);
        $this->assertStringStartsWith('DIP-', $document->reference);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiantL3->id, 'action' => 'document.genere',
        ]);
    }

    public function test_diplome_refuse_decision_ajournee(): void
    {
        $this->publierDecision($this->inscriptionL3, $this->etudiantL3, 'ajourne');

        $officiels = app(OfficialDocumentService::class);

        $this->expectException(\DomainException::class);
        $officiels->generer($this->etudiantL3, 'diplome', $this->inscriptionL3->id, (int) $this->auteur->id);
    }
}
