<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Services\RendezVousScolariteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * LOOP 16 — Rendez-vous ou demande d’aide à la scolarité.
 *
 * Vérifie :
 * - Pas de double réservation d'un même créneau côté serveur.
 * - Seuls les créneaux réellement disponibles sont proposés.
 * - Les demandes sont visibles aux agents autorisés.
 * - Données personnelles minimisées (motif catégorisé + description courte).
 * - Confirmation envoyée UNIQUEMENT après enregistrement réussi.
 * - Gestion de l'annulation et libération immédiate du créneau.
 * - Déplacement de rendez-vous avec mise à jour des disponibilités.
 * - Protection anti-IDOR entre étudiants.
 */
class InscriptionRendezVousAssistanceTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private RequestType $typeLibre;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.online-requests.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->typeLibre = RequestType::firstOrCreate(
            ['code' => 'DEMANDE_LIBRE'],
            [
                'name' => 'Demande personnalisée',
                'description' => 'Toute autre demande administrative.',
                'active' => true,
                'requires_attachment' => false,
                'generates_document' => false,
                'processing_days' => 5,
            ]
        );
    }

    private function creerEtudiant(string $cin = 'CIN-RDV-1'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'NomTest',
            'prenom' => 'PrenomTest',
            'email' => 'etu_'.uniqid().'@universite.tn',
            'date_naissance' => '2004-02-15',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue de l\'Université',
            'ville' => 'Tunis',
            'code_postal' => '1002',
            'annee_bac' => '2024',
            'password' => 'Password@123',
        ]);
        $etudiant->definirStatut('en_attente');

        return $etudiant->fresh();
    }

    private function dateOuvreeFutur(): string
    {
        $date = Carbon::tomorrow();
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date->format('Y-m-d');
    }

    /**
     * Critère 1 : Pas de double réservation d'un même créneau côté serveur.
     */
    public function test_pas_de_double_reservation_dun_meme_creneau(): void
    {
        $etudiantA = $this->creerEtudiant('CIN-RDV-A');
        $etudiantB = $this->creerEtudiant('CIN-RDV-B');
        $date = $this->dateOuvreeFutur();
        $heure = '10:00';

        $service = app(RendezVousScolariteService::class);

        // Étudiant A réserve avec succès
        $demandeA = $service->reserver($etudiantA, [
            'motif_categorie' => 'inscription_bloquee',
            'description' => 'Mon dossier bloque à l\'étape identification.',
            'date_creneau' => $date,
            'heure_creneau' => $heure,
        ]);

        $this->assertInstanceOf(OnlineRequest::class, $demandeA);
        $this->assertSame('submitted', $demandeA->status);

        // Étudiant B tente de réserver exactement le même créneau
        $this->expectException(ValidationException::class);

        $service->reserver($etudiantB, [
            'motif_categorie' => 'piece_rejetee',
            'description' => 'Je veux des explications sur mon rejet.',
            'date_creneau' => $date,
            'heure_creneau' => $heure,
        ]);
    }

    /**
     * Critère 2 : Seuls les créneaux disponibles sont proposés.
     */
    public function test_seuls_les_creneaux_disponibles_sont_proposes(): void
    {
        $etudiant = $this->creerEtudiant('CIN-RDV-DISPO');
        $date = $this->dateOuvreeFutur();
        $service = app(RendezVousScolariteService::class);

        // Avant réservation : tous les créneaux ouvrés sont disponibles
        $creneauxAvant = $service->obtenirCreneauxDisponibles($date, $this->faculte->id);
        $this->assertNotEmpty($creneauxAvant);
        $creneauCibleAvant = collect($creneauxAvant)->firstWhere('heure', '09:30');
        $this->assertTrue($creneauCibleAvant['disponible']);
        $this->assertSame(1, $creneauCibleAvant['places_restantes']);

        // Réservation du créneau 09:30
        $service->reserver($etudiant, [
            'motif_categorie' => 'orientation_reclamation',
            'description' => 'Demande d\'orientation spécifique.',
            'date_creneau' => $date,
            'heure_creneau' => '09:30',
        ]);

        // Après réservation : le créneau 09:30 est marqué indisponible
        $creneauxApres = $service->obtenirCreneauxDisponibles($date, $this->faculte->id);
        $creneauCibleApres = collect($creneauxApres)->firstWhere('heure', '09:30');
        $this->assertFalse($creneauCibleApres['disponible']);
        $this->assertSame(0, $creneauCibleApres['places_restantes']);

        // Les autres créneaux (ex: 10:00) restent disponibles
        $autreCreneau = collect($creneauxApres)->firstWhere('heure', '10:00');
        $this->assertTrue($autreCreneau['disponible']);
    }

    /**
     * Les dates non ouvrées (week-ends) ne retournent aucun créneau disponible.
     */
    public function test_jours_non_ouvres_sans_creneaux(): void
    {
        $service = app(RendezVousScolariteService::class);
        $samedi = Carbon::now()->next(Carbon::SATURDAY)->format('Y-m-d');

        $creneaux = $service->obtenirCreneauxDisponibles($samedi, $this->faculte->id);
        $this->assertEmpty($creneaux);
    }

    /**
     * Critère 3 : Les demandes sont visibles aux agents autorisés.
     */
    public function test_demandes_visibles_aux_agents_autorises(): void
    {
        $etudiant = $this->creerEtudiant('CIN-RDV-AGENT');
        $date = $this->dateOuvreeFutur();
        $service = app(RendezVousScolariteService::class);

        $demande = $service->reserver($etudiant, [
            'motif_categorie' => 'piece_rejetee',
            'description' => 'Justificatif de domicile contesté.',
            'date_creneau' => $date,
            'heure_creneau' => '14:00',
        ]);

        // Un administrateur avec le rôle et permissions requises accède à la demande
        $admin = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent@universite.tn',
            'password' => 'Admin@123',
        ], 'admin_scolarite');

        $this->actingAs($admin)
            ->get(route('admin.demandes.show', $demande))
            ->assertOk()
            ->assertSee($demande->reference)
            ->assertSee('Rendez-vous scolarité')
            ->assertSee('Justificatif de domicile contesté');
    }

    /**
     * Critère 4 : Données personnelles minimisées.
     */
    public function test_donnees_personnelles_minimisees(): void
    {
        $service = app(RendezVousScolariteService::class);
        $motifs = $service->motifs();

        $this->assertArrayHasKey('inscription_bloquee', $motifs);
        $this->assertArrayHasKey('piece_rejetee', $motifs);
        $this->assertArrayHasKey('autre', $motifs);

        $etudiant = $this->creerEtudiant('CIN-MINIMAL');
        $date = $this->dateOuvreeFutur();

        // Le rendez-vous ne requiert QUE motif, description courte, date et heure
        $demande = $service->reserver($etudiant, [
            'motif_categorie' => 'autre',
            'description' => 'Besoin d\'un échange téléphonique.',
            'date_creneau' => $date,
            'heure_creneau' => '15:00',
        ]);

        $formData = $demande->form_data;
        $this->assertSame('autre', $formData['motif_categorie']);
        $this->assertSame($date, $formData['date_creneau']);
        $this->assertSame('15:00', $formData['heure_creneau']);
        // Aucune information superflue demandée à l'étudiant
        $this->assertArrayNotHasKey('revenu_parental', $formData);
        $this->assertArrayNotHasKey('cin_saisi', $formData);
    }

    /**
     * Critère 5 : Confirmation envoyée UNIQUEMENT après enregistrement réussi.
     */
    public function test_confirmation_envoyee_uniquement_apres_enregistrement_reussi(): void
    {
        $etudiant = $this->creerEtudiant('CIN-NOTIF');
        $date = $this->dateOuvreeFutur();

        $service = app(RendezVousScolariteService::class);

        // 1. Succès : la notification est créée en base
        $demande = $service->reserver($etudiant, [
            'motif_categorie' => 'amenagement_particulier',
            'description' => 'Demande d\'horaires adaptés.',
            'date_creneau' => $date,
            'heure_creneau' => '10:30',
        ]);

        $this->assertDatabaseHas('notifications', [
            'etudiant_id' => $etudiant->id,
            'type' => 'rendez_vous.confirme',
        ]);

        // 2. Échec : tentative avec horaire invalide hors configuration
        $notifsAvant = AppNotification::count();

        try {
            $service->reserver($etudiant, [
                'motif_categorie' => 'autre',
                'description' => 'Horaire impossible.',
                'date_creneau' => $date,
                'heure_creneau' => '03:15', // horaire non configuré
            ]);
            $this->fail('Aurait dû lever une ValidationException');
        } catch (ValidationException) {
            // Attendu
        }

        // Aucune notification n'a été ajoutée
        $this->assertSame($notifsAvant, AppNotification::count());
    }

    /**
     * Annulation de rendez-vous et libération immédiate du créneau.
     */
    public function test_annulation_libere_immediatement_le_creneau(): void
    {
        $etudiantA = $this->creerEtudiant('CIN-ANNUL-A');
        $etudiantB = $this->creerEtudiant('CIN-ANNUL-B');
        $date = $this->dateOuvreeFutur();
        $heure = '14:30';

        $service = app(RendezVousScolariteService::class);

        // Étudiant A réserve
        $demande = $service->reserver($etudiantA, [
            'motif_categorie' => 'inscription_bloquee',
            'description' => 'Blocage transitoire.',
            'date_creneau' => $date,
            'heure_creneau' => $heure,
        ]);

        // Étudiant A annule son rendez-vous
        $service->annuler($demande, $etudiantA);

        $this->assertSame('cancelled', $demande->fresh()->status);

        // Le créneau est immédiatement redevenu disponible pour l'étudiant B
        $demandeB = $service->reserver($etudiantB, [
            'motif_categorie' => 'piece_rejetee',
            'description' => 'Reprise du créneau libéré.',
            'date_creneau' => $date,
            'heure_creneau' => $heure,
        ]);

        $this->assertSame('submitted', $demandeB->status);
    }

    /**
     * Modification / déplacement vers un autre créneau disponible.
     */
    public function test_deplacement_rendez_vous(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEPLACER');
        $date = $this->dateOuvreeFutur();
        $service = app(RendezVousScolariteService::class);

        $demande = $service->reserver($etudiant, [
            'motif_categorie' => 'autre',
            'description' => 'Entretien initial.',
            'date_creneau' => $date,
            'heure_creneau' => '09:00',
        ]);

        // Déplacement vers 15:30
        $demandeModifiee = $service->deplacer($demande, $etudiant, $date, '15:30');

        $this->assertSame('15:30', $demandeModifiee->form_data['heure_creneau']);
        $this->assertSame("{$date}_15:30", $demandeModifiee->form_data['creneau_id']);

        // L'ancien créneau 09:00 est de nouveau disponible
        $creneaux = $service->obtenirCreneauxDisponibles($date, $this->faculte->id);
        $creneau09 = collect($creneaux)->firstWhere('heure', '09:00');
        $this->assertTrue($creneau09['disponible']);
    }

    /**
     * Protection anti-IDOR : un étudiant ne peut pas annuler le rendez-vous d'un autre.
     */
    public function test_anti_idor_annulation(): void
    {
        $etudiantA = $this->creerEtudiant('CIN-IDOR-A');
        $etudiantB = $this->creerEtudiant('CIN-IDOR-B');
        $date = $this->dateOuvreeFutur();
        $service = app(RendezVousScolariteService::class);

        $demandeA = $service->reserver($etudiantA, [
            'motif_categorie' => 'autre',
            'description' => 'Rendez-vous étudiant A.',
            'date_creneau' => $date,
            'heure_creneau' => '10:00',
        ]);

        $this->expectException(ValidationException::class);
        $service->annuler($demandeA, $etudiantB);
    }

    /**
     * Test de l'endpoint web POST /etudiant/rendez-vous.
     */
    public function test_endpoint_post_rendez_vous(): void
    {
        $etudiant = $this->creerEtudiant('CIN-POST-RDV');
        $date = $this->dateOuvreeFutur();

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.rendez-vous.store'), [
                'motif_categorie' => 'inscription_bloquee',
                'description' => 'Problème de validation en ligne.',
                'date_creneau' => $date,
                'heure_creneau' => '14:00',
            ]);

        $demande = OnlineRequest::where('etudiant_id', $etudiant->id)->firstOrFail();
        $reponse->assertRedirect(route('etudiant.demandes.show', $demande));
        $reponse->assertSessionHas('success');
    }

    /**
     * Test de l'endpoint JSON GET /etudiant/rendez-vous/creneaux.
     */
    public function test_endpoint_get_creneaux(): void
    {
        $etudiant = $this->creerEtudiant('CIN-GET-CRENEAUX');
        $date = $this->dateOuvreeFutur();

        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->getJson(route('etudiant.rendez-vous.creneaux', ['date' => $date]));

        $reponse->assertOk()
            ->assertJsonStructure([
                'date',
                'creneaux' => [
                    '*' => ['heure', 'creneau_id', 'disponible', 'places_restantes'],
                ],
            ]);
    }
}
