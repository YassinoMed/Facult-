<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AppNotification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\RegistrationPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * B1 — Notifications des périodes d'inscription (dette résorbée) :
 * `periodes:avertir` diffuse via le centre existant (gabarits seedés,
 * préférences respectées), une fois par état — jamais de spam.
 */
class PeriodesNotifierTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->etudiantDe($faculte, 'INF-A');
        $this->etudiantDe($faculte, 'INF-B');
        // Hors portée : autre faculté, jamais notifié.
        $autreFaculte = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $this->etudiantDe($autreFaculte, 'HORS-PORTee');

        $this->scolarite('admin_scolarite');
    }

    private function etudiantDe(Faculte $faculte, string $marque): Etudiant
    {
        return Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => $marque, 'prenom' => 'Test',
            'email' => uniqid('per.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Sfax',
            'code_postal' => '3000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
    }

    private function periodeOuverte(string $fermetureRelative): RegistrationPeriod
    {
        return RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => Faculte::where('code', 'FA')->firstOrFail()->id,
            'type_periode' => 'normale', 'nom' => 'Principale 2026-2027',
            'opened_at' => now()->subDay(), 'closed_at' => now()->modify($fermetureRelative),
            'statut' => 'active', 'created_by' => User::firstOrFail()->id,
        ]);
    }

    public function test_ouverture_et_cloture_imminente_notifiees_une_fois(): void
    {
        $periode = $this->periodeOuverte('+3 days');

        Artisan::call('periodes:avertir');

        $ouvertes = AppNotification::where('type', 'periode.ouverte')->get();
        $imminentes = AppNotification::where('type', 'periode.bientot_fermee')->get();

        // Audience de la portée uniquement (2 étudiants FA, pas FB).
        $this->assertCount(2, $ouvertes);
        $this->assertCount(2, $imminentes);
        $this->assertFalse($ouvertes->pluck('etudiant_id')->contains(Etudiant::where('nom', 'HORS-PORTee')->firstOrFail()->id));

        // Gabarit rendu (variables substituées, pas de placeholder résiduel).
        $this->assertStringContainsString('Principale 2026-2027', $ouvertes->first()->message);
        $this->assertStringNotContainsString('{nom}', $ouvertes->first()->message);
        $this->assertStringContainsString('2026-2027', $ouvertes->first()->message);

        // Idempotence : second passage → rien de neuf.
        Artisan::call('periodes:avertir');
        $this->assertCount(2, AppNotification::where('type', 'periode.ouverte')->get());
        $this->assertCount(2, AppNotification::where('type', 'periode.bientot_fermee')->get());

        $this->assertNotNull($periode->fresh()->avertie_ouverture_at);
        $this->assertNotNull($periode->fresh()->avertie_fermeture_at);
    }

    public function test_fermeture_lointaine_ne_declenche_pas_l_alerte(): void
    {
        $periode = $this->periodeOuverte('+2 months');

        Artisan::call('periodes:avertir');

        $this->assertCount(2, AppNotification::where('type', 'periode.ouverte')->get());
        $this->assertCount(0, AppNotification::where('type', 'periode.bientot_fermee')->get());
        $this->assertNull($periode->fresh()->avertie_fermeture_at);
    }

    public function test_periode_future_ignoree(): void
    {
        RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'type_periode' => 'normale', 'nom' => 'Future',
            'opened_at' => now()->addDays(5), 'closed_at' => now()->addDays(25),
            'statut' => 'active', 'created_by' => User::firstOrFail()->id,
        ]);

        Artisan::call('periodes:avertir');

        $this->assertSame(0, AppNotification::whereIn('type', ['periode.ouverte', 'periode.bientot_fermee'])->count());
    }
}
