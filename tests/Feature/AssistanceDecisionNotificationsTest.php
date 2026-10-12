<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\OnlineRequests\Events\OnlineRequestApproved;
use App\Modules\OnlineRequests\Events\OnlineRequestRejected;
use App\Modules\OnlineRequests\Events\OnlineRequestWaitingForStudent;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\OnlineRequests\Services\OnlineRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 16 — notifications de décision sur demandes d'assistance
 * (complète InscriptionRendezVousAssistanceTest 11 tests : créneaux,
 * double réservation, annulation, déplacement, endpoints) :
 *
 * - Les 4 décisions agent notifient l'étudiant (le câblage audit existait
 *   mais seule `approbation` était branchée — `rejet`/`cloture`/
 *   `pieceAttendee` étaient des méthodes mortes).
 * - Idempotence : un event rejoué ne duplique jamais (clés dédup).
 * - PII minimisées : aucun type actif n'exige de champ sensible.
 */
class AssistanceDecisionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $agent;

    private RequestType $typeLibre;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.online-requests.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.dec@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->typeLibre = RequestType::where('code', 'DEMANDE_LIBRE')->where('active', true)->firstOrFail();
    }

    private function creerEtudiant(string $cin = 'CIN-DEC-1'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Nom', 'prenom' => 'Prenom',
            'email' => 'etu'.uniqid().'@test.ma', 'date_naissance' => '2004-01-01',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'rue', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('en_attente');

        return $etudiant->fresh();
    }

    private function creerDemandeSoumise(Etudiant $etudiant): OnlineRequest
    {
        $demande = app(OnlineRequestService::class)->creer($etudiant, [
            'request_type_id' => $this->typeLibre->id,
            'objet' => 'Aide pièce rejetée',
            'description' => 'Je ne comprends pas le motif.',
            'confirmation' => '1',
        ]);

        return app(OnlineRequestService::class)->soumettre($demande);
    }

    public function test_approbation_notifie_etudiant(): void
    {
        $etudiant = $this->creerEtudiant();
        $demande = $this->creerDemandeSoumise($etudiant);

        app(OnlineRequestService::class)->recevoir($demande);
        app(OnlineRequestService::class)->examiner($demande->fresh());
        app(OnlineRequestService::class)->approuver($demande->fresh());

        $notification = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'demande.approved')->firstOrFail();

        $this->assertStringContainsString($demande->reference, $notification->message);
        $this->assertStringContainsString(route('etudiant.demandes.show', $demande), (string) $notification->lien);
    }

    public function test_rejet_notifie_avec_motif_priorite_haute(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEC-2');
        $demande = $this->creerDemandeSoumise($etudiant);

        app(OnlineRequestService::class)->recevoir($demande);
        app(OnlineRequestService::class)->examiner($demande->fresh());
        app(OnlineRequestService::class)->rejeter($demande->fresh(), 'Hors compétence de la scolarité.');

        $notification = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'demande.rejected')->firstOrFail();

        $this->assertSame(2, $notification->priorite);
        $this->assertStringContainsString('Hors compétence', $notification->message);
    }

    public function test_complement_demande_notifie_avec_commentaire(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEC-3');
        $demande = $this->creerDemandeSoumise($etudiant);

        app(OnlineRequestService::class)->recevoir($demande);
        app(OnlineRequestService::class)->examiner($demande->fresh());
        app(OnlineRequestService::class)->demanderPiece($demande->fresh(), 'Joindre le relevé de notes.');

        $notification = AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'demande.waiting')->firstOrFail();

        $this->assertStringContainsString('relevé de notes', $notification->message);
    }

    public function test_cloture_notifie_etudiant(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEC-4');
        $demande = $this->creerDemandeSoumise($etudiant);

        app(OnlineRequestService::class)->recevoir($demande);
        app(OnlineRequestService::class)->examiner($demande->fresh());
        app(OnlineRequestService::class)->approuver($demande->fresh());
        app(OnlineRequestService::class)->terminer($demande->fresh());

        $this->assertNotNull(AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'demande.completed')->first());
    }

    public function test_events_rejoues_ne_dupliquent_pas(): void
    {
        $etudiant = $this->creerEtudiant('CIN-DEC-5');
        $demande = $this->creerDemandeSoumise($etudiant);

        OnlineRequestApproved::dispatch($demande);
        OnlineRequestApproved::dispatch($demande->fresh());
        OnlineRequestRejected::dispatch($demande->fresh());
        OnlineRequestRejected::dispatch($demande->fresh());
        OnlineRequestWaitingForStudent::dispatch($demande->fresh(), 'Complément.');

        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'demande.approved')->count());
        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'demande.rejected')->count());
        $this->assertSame(1, AppNotification::where('etudiant_id', $etudiant->id)
            ->where('type', 'demande.waiting')->count());
    }

    public function test_aucun_type_actif_n_exige_de_champ_sensible(): void
    {
        $sensibles = ['cin', 'mot_de_passe', 'password', 'telephone', 'email', 'naissance', 'bancaire', 'rib'];

        $types = RequestType::where('active', true)->get();
        $this->assertNotEmpty($types);

        foreach ($types as $type) {
            foreach ((array) ($type->form_schema ?? []) as $champ) {
                $nom = mb_strtolower((string) ($champ['name'] ?? '').' '.($champ['label'] ?? ''));
                foreach ($sensibles as $interdit) {
                    $this->assertStringNotContainsString(
                        $interdit, $nom,
                        "Type {$type->code} : champ sensible « {$champ['name']} »."
                    );
                }
            }
        }
    }
}
