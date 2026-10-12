<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\PeriodeInscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests fonctionnels pour LOOP 09 :
 * Validation avant soumission, résumé final, idempotence et contrôle d'état.
 */
class InscriptionResumeValidationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteSciences;

    private Programme $programmeMathInfo;

    private AnneeUniversitaire $anneeUniv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteSciences = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->programmeMathInfo = Programme::create([
            'faculte_id' => $this->faculteSciences->id,
            'code' => 'L-MI',
            'nom' => 'Licence Mathématiques et Informatique',
            'statut' => Programme::STATUT_ACTIF,
            'capacite' => 100,
        ]);

        Niveau::create([
            'programme_id' => $this->programmeMathInfo->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->anneeUniv = AnneeUniversitaire::create([
            'code' => '2025-2026',
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);

        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->anneeUniv->id,
            'opened_at' => '2020-01-01 00:00',
            'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    private function creerMens(string $cin, string $dateNaissance): MensReference
    {
        return MensReference::create([
            'cin_original' => $cin,
            'cin_normalise' => MensReference::normaliserCin($cin),
            'date_naissance' => $dateNaissance,
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'sexe' => 'M',
            'nationalite' => 'Marocaine',
            'annee' => '2025-2026',
            'source_hash' => str_repeat('a', 64),
        ]);
    }

    private function donneesValides(array $surcharges = []): array
    {
        return array_merge([
            'cin' => '08987654',
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'email' => 'karim.benali@test.ma',
            'telephone' => '+212622334455',
            'date_naissance' => '2005-06-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculteSciences->id,
            'programme_id' => $this->programmeMathInfo->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Allal Ben Abdellah',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => (string) date('Y'),
            'mention_bac' => 'Bien',
            'type_inscription' => 'premiere',
            'confirmation' => '1',
            'idempotency_token' => (string) Str::uuid(),
            'password' => 'SecuriteP@ss123!',
            'password_confirmation' => 'SecuriteP@ss123!',
        ], $surcharges);
    }

    /**
     * Test 1 : Soumission bloquée si confirmation d'attestation sur l'honneur non acceptée.
     */
    public function test_soumission_bloquee_si_confirmation_non_acceptee(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        $payload = $this->donneesValides(['confirmation' => '0']);

        $reponse = $this->post(route('inscription.store'), $payload);

        $reponse->assertSessionHasErrors('confirmation');
        $this->assertSame(0, Etudiant::count());
    }

    /**
     * Test 2 : Soumission bloquée si des exigences obligatoires manquent (ex: email invalide).
     */
    public function test_soumission_bloquee_si_exigences_obligatoires_manquantes(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        $payload = $this->donneesValides(['email' => 'email-non-valide']);

        $reponse = $this->post(route('inscription.store'), $payload);

        $reponse->assertSessionHasErrors('email');
        $this->assertSame(0, Etudiant::count());
    }

    /**
     * Test 3 : Double soumission rapide/successive est idempotente sans doublon.
     */
    public function test_double_soumission_successive_idempotente(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        $payload = $this->donneesValides();

        // 1ère soumission
        $premiereReponse = $this->post(route('inscription.store'), $payload);
        $premiereReponse->assertRedirect(route('inscription.success'));
        $this->assertSame(1, Etudiant::count());

        $etudiant = Etudiant::first();
        $this->assertNotNull($etudiant);

        // 2ème soumission immédiate (double-clic ou répétition réseau avec même token)
        $secondeReponse = $this->post(route('inscription.store'), $payload);
        $secondeReponse->assertRedirect(route('inscription.success'));

        // Vérification stricte : toujours 1 seul étudiant enregistré
        $this->assertSame(1, Etudiant::count());
        $this->assertSame($etudiant->id, Etudiant::first()->id);
    }

    /**
     * Test 4 : Référence générée conforme au format officiel INS-YYYY-XXXXX.
     */
    public function test_reference_officielle_conforme_au_format_ins(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        $anneeCourante = date('Y');
        $reponse = $this->post(route('inscription.store'), $this->donneesValides());

        $reponse->assertRedirect(route('inscription.success'));
        $reponse->assertSessionHas('num_inscription');

        $numInscription = session('num_inscription');
        $this->assertMatchesRegularExpression("/^INS-{$anneeCourante}-\\d{5}$/", $numInscription);

        $etudiant = Etudiant::first();
        $this->assertSame($numInscription, $etudiant->num_inscription);
    }

    /**
     * Test 5 : État initial en base de données strictement 'en_attente' et distinct de validé.
     */
    public function test_etat_initial_du_dossier_est_strictement_en_attente(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        $this->post(route('inscription.store'), $this->donneesValides());

        $etudiant = Etudiant::first();
        $this->assertNotNull($etudiant);
        $this->assertSame('en_attente', $etudiant->statut);
        $this->assertNotSame('valide', $etudiant->statut);
    }

    /**
     * Test 6 : Navigation arrière et ré-envoi depuis la même session n'entraîne pas de double dépôt.
     */
    public function test_navigation_arriere_avec_session_active_ne_cree_pas_de_doublon(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        $payload = $this->donneesValides();

        // 1ère soumission
        $this->post(route('inscription.store'), $payload);
        $this->assertSame(1, Etudiant::count());

        // Simulation navigation arrière du navigateur : l'utilisateur reclique sur valider avec un nouveau token
        // mais sa session conserve la trace de son inscription
        $payloadRetourArriere = $this->donneesValides(['idempotency_token' => (string) Str::uuid()]);

        $reponseRetour = $this->post(route('inscription.store'), $payloadRetourArriere);
        $reponseRetour->assertRedirect(route('inscription.success'));

        $this->assertSame(1, Etudiant::count());
    }

    /**
     * Test 7 : Rejeu récent (<60s) avec le même token même sans session redirige idempotemment sans erreur 500.
     */
    public function test_rejeu_recent_identique_avec_token_sans_session_reste_idempotent(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        $token = (string) Str::uuid();
        $payload = $this->donneesValides(['idempotency_token' => $token]);

        $this->post(route('inscription.store'), $payload);
        $this->assertSame(1, Etudiant::count());

        // Vider la session pour simuler un client tiers / rafraîchissement sans cookie
        $this->flushSession();

        $secondeReponse = $this->post(route('inscription.store'), $payload);
        $secondeReponse->assertRedirect(route('inscription.success'));

        $this->assertSame(1, Etudiant::count());
    }

    /**
     * Test 8 : Doublon non-idempotent (ancien dossier ou autre email) reste rigoureusement bloqué.
     */
    public function test_doublon_non_idempotent_autre_personne_ou_ancien_dossier_bloque(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        // Créer un étudiant plus ancien (> 120s) avec un autre email
        $ancienEtudiant = Etudiant::create([
            'cin' => '08987654',
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'email' => 'ancien.compte@test.ma',
            'sexe' => 'M',
            'faculte_id' => $this->faculteSciences->id,
            'niveau' => 'L1',
            'annee_academique' => '2024-2025',
            'adresse' => 'Rabat',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => bcrypt('SecuriteP@ss123!'),
        ]);
        $ancienEtudiant->created_at = now()->subMinutes(10);
        $ancienEtudiant->saveQuietly();

        $payload = $this->donneesValides([
            'cin' => '08987654',
            'email' => 'nouveau.pirate@test.ma',
        ]);

        $reponse = $this->post(route('inscription.store'), $payload);

        $reponse->assertSessionHasErrors('cin');
        $this->assertSame(1, Etudiant::count());
    }

    /**
     * Test 9 : Collision concurrente de clé unique résolue de manière idempotente sans 500.
     */
    public function test_collision_concurrente_unique_constraint_resolue_idempotente(): void
    {
        $this->creerMens('08987654', '2005-06-15');

        // Créer l'étudiant juste avant le bloc transaction (simulant une création concurrente milliseconde)
        $etudiantConcurrant = Etudiant::create([
            'cin' => '08987654',
            'nom' => 'Benali',
            'prenom' => 'Karim',
            'email' => 'karim.benali@test.ma',
            'sexe' => 'M',
            'faculte_id' => $this->faculteSciences->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Rabat',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2025',
            'password' => bcrypt('SecuriteP@ss123!'),
        ]);

        $payload = $this->donneesValides();

        $reponse = $this->post(route('inscription.store'), $payload);

        $reponse->assertRedirect(route('inscription.success'));
        $this->assertSame(1, Etudiant::count());
        $this->assertSame($etudiantConcurrant->id, Etudiant::first()->id);
    }
}
