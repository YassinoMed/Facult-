<?php

namespace Tests\Feature;

use App\Jobs\TraiterLotCampagneJob;
use App\Mail\CampagneCourrielMailable;
use App\Models\CampagneCourriel;
use App\Models\CampagneCourrielDestinataire;
use App\Models\Courriel;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\CampagneCourrielService;
use App\Services\CourrielService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampagneCourrielTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $admin;

    private User $agentSansDroit;

    private CampagneCourrielService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['code' => 'F1', 'nom' => 'Sciences', 'actif' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Scolarite',
            'email' => 'admin.scol@univ.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->agentSansDroit = User::creerCompteBackOffice([
            'name' => 'Agent Lecture',
            'email' => 'agent.lecture@univ.test',
            'password' => 'Motdepasse@123',
        ], 'consultation');

        $this->service = app(CampagneCourrielService::class);
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 2000;
        $compteur++;

        return Etudiant::create(array_merge([
            'cin' => str_pad((string) $compteur, 8, '0', STR_PAD_LEFT),
            'nom' => 'Nom'.$compteur,
            'prenom' => 'Prenom'.$compteur,
            'email' => "etudiant{$compteur}@univ.test",
            'sexe' => 'M',
            'date_naissance' => '2002-01-01',
            'telephone' => '20000000',
            'statut' => 'valide',
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'faculte_id' => $this->faculte->id,
            'adresse' => '1 rue Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2022',
        ], $attributs));
    }

    public function test_cycle_de_vie_complet_envoi_immediat_et_execution_job(): void
    {
        Mail::fake();

        $e1 = $this->creerEtudiant(['email' => 'etudiant1@univ.test']);
        $e2 = $this->creerEtudiant(['email' => 'etudiant2@univ.test']);

        // 1. Enregistrement brouillon
        $campagne = $this->service->enregistrerBrouillon([
            'titre' => 'Rentrée Universitaire 2026',
            'sujet' => 'Informations importantes pour votre rentrée',
            'type_ciblage' => CampagneCourriel::CIBLAGE_LISTE_FIXE,
            'contenu_texte' => 'Bienvenue à tous les étudiants.',
        ], null, $this->admin->id);

        $this->assertSame(CampagneCourriel::STATUT_BROUILLON, $campagne->statut);
        $this->assertNotNull($campagne->cle_idempotence);

        // 2. Figer les destinataires
        $campagne = $this->service->figerDestinataires($campagne, [
            'etudiant_ids' => [$e1->id, $e2->id],
        ]);

        $this->assertSame(2, $campagne->total_destinataires);
        $this->assertCount(2, $campagne->destinataires);

        // 3. Envoi immédiat (dispatche le job)
        Queue::fake();
        $this->service->envoyer($campagne);

        $this->assertSame(CampagneCourriel::STATUT_EN_ATTENTE, $campagne->fresh()->statut);
        Queue::assertPushed(TraiterLotCampagneJob::class);

        // 4. Exécution du job réel avec Mail simulé
        $destinataireIds = $campagne->destinataires()->pluck('id')->all();
        $job = new TraiterLotCampagneJob($campagne->id, $destinataireIds, 1);
        $job->handle($this->service, app(CourrielService::class));

        // Vérifications
        $campagne->refresh();
        $this->assertSame(CampagneCourriel::STATUT_TERMINEE, $campagne->statut);
        $this->assertSame(2, $campagne->total_envoyes);
        $this->assertSame(0, $campagne->total_echecs);

        Mail::assertSent(CampagneCourrielMailable::class, 2);

        // Vérification de la journalisation sans PII
        $this->assertDatabaseCount('courriels', 2);
        $this->assertDatabaseHas('courriels', [
            'categorie' => 'campagne',
            'statut' => Courriel::STATUT_ACCEPTE_SMTP,
            'reference_metier' => 'campagne:'.$campagne->id,
        ]);
    }

    public function test_programmation_et_declenchement_par_commande(): void
    {
        Mail::fake();
        Queue::fake();

        $e = $this->creerEtudiant(['email' => 'futur@univ.test']);

        $campagne = $this->service->enregistrerBrouillon([
            'titre' => 'Campagne programmée',
            'sujet' => 'Objet planifié',
            'type_ciblage' => CampagneCourriel::CIBLAGE_LISTE_FIXE,
            'contenu_texte' => 'Message programmé.',
        ], null, $this->admin->id);

        $this->service->figerDestinataires($campagne, ['etudiant_ids' => [$e->id]]);

        // Planifie dans le passé pour tester l'échéance immédiate
        $this->service->planifier($campagne, now()->subMinute());
        $this->assertSame(CampagneCourriel::STATUT_PLANIFIEE, $campagne->fresh()->statut);

        // Exécution de la commande console planifiée
        $this->artisan('campagnes-courriel:traiter-planifiees')
            ->assertSuccessful();

        $this->assertSame(CampagneCourriel::STATUT_EN_ATTENTE, $campagne->fresh()->statut);
        Queue::assertPushed(TraiterLotCampagneJob::class);
    }

    public function test_annulation_atomique_empeche_les_envois_futurs(): void
    {
        Mail::fake();

        $e = $this->creerEtudiant(['email' => 'annule@univ.test']);

        $campagne = $this->service->enregistrerBrouillon([
            'titre' => 'Campagne à annuler',
            'sujet' => 'Sujet',
            'type_ciblage' => CampagneCourriel::CIBLAGE_LISTE_FIXE,
            'contenu_texte' => 'Texte',
        ], null, $this->admin->id);

        $this->service->figerDestinataires($campagne, ['etudiant_ids' => [$e->id]]);
        $this->service->planifier($campagne, now()->addDay());

        // Annulation
        $this->service->annuler($campagne);

        $campagne->refresh();
        $this->assertSame(CampagneCourriel::STATUT_ANNULEE, $campagne->statut);
        $this->assertSame(CampagneCourrielDestinataire::STATUT_ANNULE, $campagne->destinataires()->first()->statut);

        // Un job lancé sur une campagne annulée ne doit rien envoyer
        $destId = $campagne->destinataires()->first()->id;
        $job = new TraiterLotCampagneJob($campagne->id, [$destId], 1);
        $job->handle($this->service, app(CourrielService::class));

        Mail::assertNothingSent();
    }

    public function test_relance_selective_des_echecs_uniquement(): void
    {
        Mail::fake();
        Queue::fake();

        $e1 = $this->creerEtudiant(['email' => 'succes@univ.test']);
        $e2 = $this->creerEtudiant(['email' => 'echec@univ.test']);

        $campagne = $this->service->enregistrerBrouillon([
            'titre' => 'Campagne avec reprise',
            'sujet' => 'Objet',
            'type_ciblage' => CampagneCourriel::CIBLAGE_LISTE_FIXE,
            'contenu_texte' => 'Texte',
        ], null, $this->admin->id);

        $this->service->figerDestinataires($campagne, ['etudiant_ids' => [$e1->id, $e2->id]]);

        $d1 = $campagne->destinataires()->where('email', 'succes@univ.test')->first();
        $d2 = $campagne->destinataires()->where('email', 'echec@univ.test')->first();

        // Simulation : d1 envoyé avec succès, d2 en échec
        $d1->update(['statut' => CampagneCourrielDestinataire::STATUT_ENVOYE, 'envoye_a' => now()]);
        $d2->update(['statut' => CampagneCourrielDestinataire::STATUT_ECHEC, 'derniere_erreur' => 'Erreur de connexion SMTP']);

        $campagne->update([
            'statut' => CampagneCourriel::STATUT_TERMINEE_ERREURS,
            'total_envoyes' => 1,
            'total_echecs' => 1,
        ]);

        $this->assertTrue($campagne->peutEtreRelancee());

        // Relance sélective
        $this->service->relancerEchecs($campagne);

        $d1->refresh();
        $d2->refresh();

        // d1 doit rester inchangé (envoyé), seul d2 repasse en attente
        $this->assertSame(CampagneCourrielDestinataire::STATUT_ENVOYE, $d1->statut);
        $this->assertSame(CampagneCourrielDestinataire::STATUT_EN_ATTENTE, $d2->statut);

        Queue::assertPushed(TraiterLotCampagneJob::class, function ($job) use ($d2) {
            return $job->destinataireIds === [$d2->id];
        });
    }

    public function test_controle_des_acces_et_permissions(): void
    {
        // 1. Non authentifié redirigé vers admin.connexion.show
        $this->get(route('admin.campagnes-courriel.index'))
            ->assertRedirect(route('admin.connexion.show'));

        // 2. Utilisateur consultation a le droit de voir mais pas de gérer
        $this->actingAs($this->agentSansDroit)
            ->get(route('admin.campagnes-courriel.index'))
            ->assertOk();

        // 3. Utilisateur sans permission `campagnes.manage` ne peut pas composer (403)
        $this->actingAs($this->agentSansDroit)
            ->get(route('admin.campagnes-courriel.composer'))
            ->assertForbidden();

        // 4. Admin autorisé accède avec succès à la consultation et à la composition
        $this->actingAs($this->admin)
            ->get(route('admin.campagnes-courriel.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('admin.campagnes-courriel.composer'))
            ->assertOk();
    }
}
