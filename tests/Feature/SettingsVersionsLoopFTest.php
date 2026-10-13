<?php

namespace Tests\Feature;

use App\Models\Parametre;
use App\Models\ParametreVersion;
use App\Models\User;
use App\Services\ParametreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP F — Versions append-only, concurrence optimiste, restauration
 * créant une nouvelle version.
 */
class SettingsVersionsLoopFTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): void
    {
        $user = User::creerCompteBackOffice([
            'name' => 'Super', 'email' => uniqid('sf.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'super_admin');
        $this->actingAs($user);
    }

    public function test_ecriture_cree_une_version_sans_ecraser(): void
    {
        $this->superAdmin();
        $cible = Parametre::where('cle', 'etablissement.nom')->first();

        $this->patch(route('admin.parametres.update', $cible), ['valeur' => 'Université Nouvelle', 'attendu' => $cible->updated_at?->toIso8601String()])
            ->assertRedirect();
        $this->patch(route('admin.parametres.update', $cible->fresh()), ['valeur' => 'Université Finale', 'attendu' => $cible->fresh()->updated_at?->toIso8601String()])
            ->assertRedirect();

        $versions = ParametreVersion::where('parametre_id', $cible->id)->orderBy('id')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('Université', $versions[0]->valeur_precedente);
        $this->assertSame('Université Nouvelle', $versions[0]->valeur);
        $this->assertSame('Université Finale', $versions[1]->valeur);
        $this->assertSame('Université Finale', app(ParametreService::class)->get('etablissement.nom'));
    }

    public function test_sans_changement_ni_version_ni_audit(): void
    {
        $this->superAdmin();
        $cible = Parametre::where('cle', 'etablissement.nom')->first();

        $this->patch(route('admin.parametres.update', $cible), ['valeur' => 'Université', 'attendu' => $cible->updated_at?->toIso8601String()])
            ->assertRedirect();
        $this->assertSame(0, ParametreVersion::where('parametre_id', $cible->id)->count());
    }

    public function test_concurrence_attendu_perime_refuse(): void
    {
        $this->superAdmin();
        $cible = Parametre::where('cle', 'etablissement.nom')->first();
        // Horodatage figé 5 min dans le passé : immunise contre la
        // précision à la seconde de SQLite (deux écritures < 1 s).
        DB::table('parametres')->where('id', $cible->id)
            ->update(['updated_at' => now()->subMinutes(5)->toDateTimeString()]);
        $frais = Parametre::find($cible->id)->updated_at?->toIso8601String();

        // Premier éditeur : OK.
        $this->patch(route('admin.parametres.update', $cible), [
            'valeur' => 'Première écriture', 'attendu' => $frais,
        ])->assertRedirect();

        // Second éditeur avec l'ancien horodatage : refus, valeur conservée.
        $this->patch(route('admin.parametres.update', $cible), [
            'valeur' => 'Écrasement silencieux', 'attendu' => $frais,
        ])->assertSessionHasErrors('concurrence', null, 'p'.$cible->id);
        $this->assertSame('Première écriture', app(ParametreService::class)->get('etablissement.nom'));
        $this->assertSame(1, ParametreVersion::where('parametre_id', $cible->id)->count());
    }

    public function test_restauration_cree_une_nouvelle_version(): void
    {
        $this->superAdmin();
        $cible = Parametre::where('cle', 'etablissement.nom')->first();

        $this->patch(route('admin.parametres.update', $cible), ['valeur' => 'Version deux', 'attendu' => $cible->updated_at?->toIso8601String()])
            ->assertRedirect();
        $this->patch(route('admin.parametres.update', $cible->fresh()), ['valeur' => 'Version trois', 'attendu' => $cible->fresh()->updated_at?->toIso8601String()])
            ->assertRedirect();
        $v1 = ParametreVersion::where('parametre_id', $cible->id)->orderBy('id')->first();

        $this->post(route('admin.parametres.restaurer', $cible->fresh()), ['version_id' => $v1->id])
            ->assertRedirect();
        $this->assertSame('Version deux', app(ParametreService::class)->get('etablissement.nom'));
        $versions = ParametreVersion::where('parametre_id', $cible->id)->orderBy('id')->get();
        $this->assertCount(3, $versions);
        $this->assertSame('Version trois', $versions[2]->valeur_precedente);
        $this->assertStringContainsString('Restauration', (string) $versions[2]->motif);
    }

    public function test_restauration_version_autre_parametre_404(): void
    {
        $this->superAdmin();
        $cible = Parametre::where('cle', 'etablissement.nom')->first();
        $autre = Parametre::where('cle', 'interface.pagination')->first();
        app(ParametreService::class)->set('interface.pagination', '20', null);
        $versionAutre = ParametreVersion::where('parametre_id', $autre->id)->first();

        $this->post(route('admin.parametres.restaurer', $cible), ['version_id' => $versionAutre->id])
            ->assertSessionHasErrors('version');
        $this->assertSame('Université', app(ParametreService::class)->get('etablissement.nom'));
    }

    public function test_restauration_reservee_super_admin(): void
    {
        $cible = Parametre::where('cle', 'etablissement.nom')->first();
        $restreint = User::creerCompteBackOffice([
            'name' => 'Restreint', 'email' => uniqid('rf.', true).'@scolarite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_faculte');
        $this->actingAs($restreint);

        $this->post(route('admin.parametres.restaurer', $cible), ['version_id' => 1])
            ->assertForbidden();
    }
}
