<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationIncident;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\ExamUrgenceSuppleanceService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F1 — Qualification et validation indépendante des incidents (§2.11).
 *
 * Prouve par exécution : gravité/délai/preuves sont persistés en colonnes
 * exploitables (le JSON reste compatible), la résolution est unique, et
 * la validation finale exige une personne distincte du déclarant et du
 * responsable du traitement.
 */
class ExamIncidentQualificationTest extends TestCase
{
    use RefreshDatabase;

    private ExamUrgenceSuppleanceService $service;

    private Epreuve $epreuve;

    private User $declarant;

    private User $responsable;

    private User $validateur;

    private User $sansHabilitation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true, 'modules.schedule.active' => true]);

        $faculte = Faculte::create(['nom' => 'Faculté Incidents', 'code' => 'FINC', 'active' => true]);

        $this->declarant = $this->creerUtilisateur('declarant.inc@univ.test', 'admin_scolarite');
        $this->responsable = $this->creerUtilisateur('responsable.inc@univ.test', 'admin_faculte', $faculte->id);
        $this->validateur = $this->creerUtilisateur('validateur.inc@univ.test', 'admin_scolarite');
        $this->sansHabilitation = $this->creerUtilisateur('lecture.inc@univ.test', 'consultation');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'P-INC', 'nom' => 'Programme Incidents',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S5', 'nom' => 'S5', 'numero' => 5, 'ordre' => 5,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-INC', 'nom' => 'UE Incidents', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-INC', 'nom' => 'Module Incidents', 'ue_id' => $ue->id,
            'niveau' => 'L3', 'semestre' => 5, 'credits' => 4, 'actif' => true,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S-INC',
            'nom' => 'Session Incidents', 'type' => 'principale',
            'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);

        $examen = Examen::create([
            'code' => 'EX-INC', 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen Incidents', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $this->epreuve = Epreuve::create([
            'code' => 'EPR-INC', 'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
            'date' => '2027-01-15', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled',
        ]);

        $this->service = app(ExamUrgenceSuppleanceService::class);
    }

    private function creerUtilisateur(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Utilisateur '.$email,
            'email' => $email,
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ], $role);
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function donneesIncident(array $surcharges = []): array
    {
        return array_merge([
            'epreuve_id' => $this->epreuve->id,
            'type' => 'materiel',
            'description' => 'Vidéoprojecteur hors service dans la salle B12.',
            'declarant_id' => $this->declarant->id,
            'gravite' => 'majeure',
            'delai_cible_minutes' => 45,
            'preuves_refs' => ['PV-SURV-001', 'photo-B12.jpg'],
        ], $surcharges);
    }

    public function test_declaration_persiste_gravite_delai_et_preuves_en_colonnes(): void
    {
        $incident = $this->service->declarerIncident($this->donneesIncident([
            'preuves_refs' => ['PV-SURV-001', '   ', 'photo-B12.jpg'],
        ]));

        $this->assertSame('majeure', $incident->gravite);
        $this->assertSame(['PV-SURV-001', 'photo-B12.jpg'], $incident->preuves);
        $this->assertSame([], $incident->actions_prises);
        $this->assertSame('signale', $incident->statut);
        $this->assertNotNull($incident->uuid);
        $this->assertTrue(
            $incident->delai_cible_at->between(now()->addMinutes(44), now()->addMinutes(46)),
            'Le délai cible doit refléter les 45 minutes demandées.'
        );

        $meta = $this->service->extraireMetadonneesIncident($incident->fresh());
        $this->assertSame('majeure', $meta['gravite']);
        $this->assertSame(['PV-SURV-001', 'photo-B12.jpg'], $meta['preuves_refs']);

        // Le JSON reste lisible pour les consommateurs historiques.
        $decode = json_decode((string) $incident->fresh()->description, true);
        $this->assertSame('majeure', $decode['gravite']);
        $this->assertSame(45, $decode['delai_cible_minutes']);
    }

    public function test_gravite_invalide_refusee(): void
    {
        $this->expectException(DomainException::class);

        $this->service->declarerIncident($this->donneesIncident(['gravite' => 'haute']));
    }

    public function test_delai_hors_bornes_refuse(): void
    {
        $this->expectException(DomainException::class);

        $this->service->declarerIncident($this->donneesIncident(['delai_cible_minutes' => 0]));
    }

    public function test_delai_par_defaut_depend_de_la_gravite(): void
    {
        $critique = $this->service->declarerIncident($this->donneesIncident([
            'gravite' => 'critique',
            'delai_cible_minutes' => null,
        ]));

        $mineure = $this->service->declarerIncident($this->donneesIncident([
            'gravite' => 'mineure',
            'delai_cible_minutes' => null,
        ]));

        $this->assertTrue($critique->delai_cible_at->between(now()->addMinutes(29), now()->addMinutes(31)));
        $this->assertTrue($mineure->delai_cible_at->between(now()->addMinutes(119), now()->addMinutes(121)));
    }

    public function test_mesure_urgence_alimente_actions_et_responsable(): void
    {
        $incident = $this->service->declarerIncident($this->donneesIncident());

        $this->service->consignerMesureUrgence(
            $incident,
            'Projecteur de secours installé',
            $this->responsable->id,
            'Continuité de l’épreuve'
        );

        $incident->refresh();

        $this->assertSame('en_cours', $incident->statut);
        $this->assertSame($this->responsable->id, $incident->responsable_id);
        $this->assertCount(1, $incident->actions_prises);
        $this->assertSame('mesure_urgence_temporaire', $incident->actions_prises[0]['type']);
        $this->assertSame('Projecteur de secours installé', $incident->actions_prises[0]['action']);
    }

    public function test_resolution_persiste_colonnes_et_refuse_double_resolution(): void
    {
        $incident = $this->service->declarerIncident($this->donneesIncident());

        $this->service->resoudreIncident(
            $incident,
            'Projecteur remplacé et épreuve terminée dans les conditions normales.',
            $this->responsable->id,
            ['PV-SURV-002']
        );

        $incident->refresh();

        $this->assertSame('resolu', $incident->statut);
        $this->assertNotNull($incident->resolu_at);
        $this->assertSame($this->responsable->id, $incident->responsable_id);
        $this->assertSame(['PV-SURV-001', 'photo-B12.jpg', 'PV-SURV-002'], $incident->preuves);
        $this->assertSame('resolution', $incident->actions_prises[array_key_last($incident->actions_prises)]['type']);
        $this->assertSame('resolu', $incident->statut);

        $this->expectException(DomainException::class);
        $this->service->resoudreIncident($incident, 'Deuxième résolution.', $this->responsable->id);
    }

    public function test_validation_refusee_si_incident_non_resolu(): void
    {
        $incident = $this->service->declarerIncident($this->donneesIncident());

        $this->expectException(DomainException::class);
        $this->service->validerResolutionIncident($incident, $this->validateur->id);
    }

    public function test_declarant_ne_peut_pas_valider_sa_resolution(): void
    {
        $incident = $this->service->declarerIncident($this->donneesIncident());
        $this->service->resoudreIncident($incident, 'Résolution motivée.', $this->responsable->id);

        $this->expectException(DomainException::class);
        $this->service->validerResolutionIncident($incident, $this->declarant->id);
    }

    public function test_responsable_reel_du_traitement_ne_peut_pas_valider_meme_si_colonne_differente(): void
    {
        // Une mesure d'urgence désigne A comme responsable de colonne ;
        // B prononce ensuite la résolution. B reste le résolveur réel et
        // ne peut pas s'auto-valider.
        $incident = $this->service->declarerIncident($this->donneesIncident());
        $this->service->consignerMesureUrgence($incident, 'Salle de repli ouverte', $this->responsable->id);

        $resolveur = $this->creerUtilisateur('resolveur.inc@univ.test', 'admin_scolarite');
        $this->service->resoudreIncident($incident->fresh(), 'Épreuve poursuivie en salle de repli.', $resolveur->id);

        $this->assertSame($this->responsable->id, $incident->fresh()->responsable_id);

        $this->expectException(DomainException::class);
        $this->service->validerResolutionIncident($incident->fresh(), $resolveur->id);
    }

    public function test_validateur_sans_habilitation_refuse(): void
    {
        $incident = $this->service->declarerIncident($this->donneesIncident());
        $this->service->resoudreIncident($incident, 'Résolution motivée.', $this->responsable->id);

        $this->expectException(DomainException::class);
        $this->service->validerResolutionIncident($incident, $this->sansHabilitation->id);
    }

    public function test_validation_independante_reussie_et_non_rejouable(): void
    {
        $incident = $this->service->declarerIncident($this->donneesIncident());
        $this->service->resoudreIncident($incident, 'Résolution motivée.', $this->responsable->id);

        $valide = $this->service->validerResolutionIncident(
            $incident->fresh(),
            $this->validateur->id,
            'Constats et preuves vérifiés.'
        );

        $this->assertSame($this->validateur->id, $valide->valide_par);
        $this->assertNotNull($valide->valide_at);
        $this->assertTrue($valide->estValide());

        $meta = $this->service->extraireMetadonneesIncident($valide->fresh());
        $this->assertSame($this->validateur->id, $meta['validation_finale']['valide_par']);
        $this->assertSame('Constats et preuves vérifiés.', $meta['validation_finale']['commentaire']);

        $this->expectException(DomainException::class);
        $this->service->validerResolutionIncident($valide->fresh(), $this->validateur->id);
    }

    public function test_http_declaration_qualifiee_et_validation_independante(): void
    {
        $this->actingAs($this->declarant)->post(
            route('admin.evaluations.epreuves.incidents.store', $this->epreuve),
            [
                'type' => 'materiel',
                'description' => 'Deux postes de composition hors service.',
                'gravite' => 'critique',
                'delai_cible_minutes' => 25,
                'preuves' => "PV-SURV-010\nphoto-poste-1.jpg",
            ]
        )->assertRedirect();

        $incident = EvaluationIncident::query()->latest('id')->firstOrFail();

        $this->assertSame('critique', $incident->gravite);
        $this->assertSame(['PV-SURV-010', 'photo-poste-1.jpg'], $incident->preuves);
        $this->assertTrue($incident->delai_cible_at->between(now()->addMinutes(24), now()->addMinutes(26)));

        $this->service->resoudreIncident($incident, 'Postes remplacés avant la fin de l’épreuve.', $this->responsable->id);

        $this->actingAs($this->validateur)->post(
            route('admin.evaluations.incidents.valider', $incident->fresh()),
            ['commentaire' => 'Vérifié sur le PV de surveillance.']
        )->assertRedirect()->assertSessionHas('succes');

        $this->assertSame($this->validateur->id, $incident->fresh()->valide_par);

        // Un profil de consultation ne peut pas valider.
        $autre = $this->service->declarerIncident($this->donneesIncident([
            'description' => 'Autre incident à valider.',
        ]));
        $this->service->resoudreIncident($autre, 'Résolution motivée.', $this->responsable->id);

        $this->actingAs($this->sansHabilitation)->post(
            route('admin.evaluations.incidents.valider', $autre)
        )->assertForbidden();
    }
}
