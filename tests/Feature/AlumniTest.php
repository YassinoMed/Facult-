<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Alumni\Models\ConsentementAlumni;
use App\Modules\Alumni\Models\Diplome;
use App\Modules\Alumni\Models\ImportDiplomes;
use App\Modules\Alumni\Models\ProfilAlumni;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOOP-17 — portail alumni.
 *
 * Preuves attendues :
 * - le registre `diplomes` n'est alimenté QUE par un import officiel :
 *   aucun diplôme n'est calculé depuis les notes, crédits ou délibérations ;
 * - l'import est idempotent (même fichier = un seul registre) et son
 *   rapport de rejet ne contient aucune donnée personnelle ;
 * - les lignes invalides sont rejetées avec un motif, jamais corrigées
 *   silencieusement (CIN inconnu, date impossible/future, numéro dupliqué,
 *   programme hors faculté, type inconnu, mauvais nombre de colonnes) ;
 * - le consentement est opt-in explicite, sans création de compte, via un
 *   lien signé et temporaire ; il est retirable et idempotent ;
 * - l'annuaire ne contient que les consentants visibles, les coordonnées
 *   privées ne sortent que pour `alumni.manage` + visibilité dédiée ;
 * - les agrégats sont k-anonymes (aucun effectif < k, pas de soustraction
 *   possible) et n'exposent aucun identifiant personnel ;
 * - permissions `alumni.view` / `alumni.import` / `alumni.manage` distinctes ;
 * - module désactivé ⇒ 404 sur toutes les routes, y compris les liens signés.
 */
class AlumniTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private TypeDiplome $typeLicence;

    private Programme $programmeA;

    private Programme $programmeB;

    private User $admin;

    private User $agent;

    private User $consultation;

    private User $adminFaculteA;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.alumni.active' => true,
            'modules.alumni.seuil_k' => 5,
            'modules.alumni.lien_jours' => 30,
            'modules.alumni.lignes_max' => 1000,
        ]);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FSA',
            'description' => 'Test', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit', 'code' => 'FDB',
            'description' => 'Test', 'active' => true,
        ]);

        $this->typeLicence = TypeDiplome::create([
            'code' => 'LIC', 'nom' => 'Licence', 'cycle' => 'licence',
            'duree_annees' => 3, 'actif' => true,
        ]);

        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'INFO-A', 'nom' => 'Informatique',
            'statut' => 'actif', 'actif' => true,
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'DROIT-B', 'nom' => 'Droit',
            'statut' => 'actif', 'actif' => true,
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        $this->admin = $this->creerUtilisateur('admin_scolarite');
        $this->agent = $this->creerUtilisateur('agent_scolarite');
        $this->consultation = $this->creerUtilisateur('consultation');
        $this->adminFaculteA = $this->creerUtilisateur('admin_faculte', $this->faculteA);
    }

    /* ── Activation du module ── */

    public function test_module_desactive_repond_404_sur_toutes_les_routes(): void
    {
        $etudiant = $this->creerEtudiant('AB000001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-404-001');
        $lien = URL::temporarySignedRoute(
            'alumni.consentement.show',
            now()->addDay(),
            ['diplome' => $diplome->id],
        );

        config(['modules.alumni.active' => false]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.diplomes.index'))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.agregats'))
            ->assertNotFound();

        $this->get($lien)->assertNotFound();
    }

    /* ── Import officiel ── */

    public function test_import_officiel_valide_alimente_le_registre_sans_dupliquer_l_identite(): void
    {
        $premier = $this->creerEtudiant('AB100001', $this->faculteA, $this->programmeA);
        $second = $this->creerEtudiant('AB100002', $this->faculteA, $this->programmeA);

        $reponse = $this->importer($this->csv([
            'AB100001;DIP-2025-0001;LIC;INFO-A;2025-06-30;Très bien',
            'AB100002;DIP-2025-0002;LIC;INFO-A;2025-06-30;',
        ]));

        $reponse->assertCreated()
            ->assertJsonPath('statut', 'termine')
            ->assertJsonPath('lignes_total', 2)
            ->assertJsonPath('lignes_importees', 2)
            ->assertJsonPath('lignes_rejetees', 0);

        $this->assertSame(2, Diplome::count());
        $this->assertSame(1, ImportDiplomes::count());

        $diplome = Diplome::where('numero_diplome', 'DIP-2025-0001')->firstOrFail();
        $this->assertSame($premier->id, $diplome->etudiant_id);
        $this->assertSame($this->faculteA->id, $diplome->faculte_id);
        $this->assertSame($this->programmeA->id, $diplome->programme_id);
        $this->assertSame('Très bien', $diplome->mention);

        // L'identité reste dans `etudiants` : aucune duplication de PII.
        $attributs = $diplome->getAttributes();
        $this->assertArrayNotHasKey('cin', $attributs);
        $this->assertArrayNotHasKey('nom', $attributs);
        $this->assertArrayNotHasKey('prenom', $attributs);
        $this->assertSame('AB100001', $diplome->etudiant->cin);

        // Le second diplôme est bien rattaché à l'autre étudiant.
        $this->assertSame(
            $second->id,
            Diplome::where('numero_diplome', 'DIP-2025-0002')->value('etudiant_id'),
        );
    }

    public function test_rejouer_le_meme_fichier_ne_cree_pas_un_second_registre(): void
    {
        $this->creerEtudiant('AB110001', $this->faculteA, $this->programmeA);
        $contenu = $this->csv(['AB110001;DIP-2025-1000;LIC;INFO-A;2025-06-30;']);

        $this->importer($contenu)->assertCreated();
        $this->importer($contenu)
            ->assertOk()
            ->assertJsonPath('statut', 'termine');

        $this->assertSame(1, ImportDiplomes::count());
        $this->assertSame(1, Diplome::count());
    }

    public function test_import_refuse_un_entete_invalide_un_encodage_non_utf8_et_un_fichier_binaire(): void
    {
        $this->creerEtudiant('AB120001', $this->faculteA, $this->programmeA);

        $this->importer("cin;nom\nAB120001;Alaoui\n")
            ->assertStatus(422)
            ->assertJsonPath('erreur', 'En-tête CSV invalide. Colonnes attendues : cin;numero_diplome;type_diplome;programme;date_delivrance;mention.');

        $this->importer($this->csv(['AB120001;DIP-2025-2000;LIC;INFO-A;2025-06-30;'])."\xFF\xFE")
            ->assertStatus(422);

        // Contenu non textuel : refusé par l'import, pas interprété comme CSV.
        $this->importer("\x00\x01\x02\x03\x04\x05")
            ->assertStatus(422);

        $this->assertSame(0, ImportDiplomes::count());
        $this->assertSame(0, Diplome::count());
    }

    public function test_import_refuse_une_extension_dangereuse_et_un_fichier_trop_volumineux(): void
    {
        $this->creerEtudiant('AB130001', $this->faculteA, $this->programmeA);
        $contenu = $this->csv(['AB130001;DIP-2025-3000;LIC;INFO-A;2025-06-30;']);

        // Fichier réel : le type MIME est deviné depuis le contenu (text/plain).
        $chemin = tempnam(sys_get_temp_dir(), 'loop17');
        file_put_contents($chemin, $contenu);

        // `.php` : bloqué par la validation Laravel elle-même.
        $this->actingAs($this->admin)
            ->post(route('admin.alumni.diplomes.import'), [
                'fichier' => new UploadedFile($chemin, 'diplomes.php', 'text/csv', null, true),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fichier');

        // `.pdf` au contenu CSV : le MIME passe, l'extension est refusée par
        // le contrôleur (défense en profondeur, pas seulement `mimes`).
        $this->actingAs($this->admin)
            ->post(route('admin.alumni.diplomes.import'), [
                'fichier' => new UploadedFile($chemin, 'diplomes.pdf', 'text/csv', null, true),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('erreur', 'Seuls les fichiers .csv ou .txt sont acceptés.');

        @unlink($chemin);

        $this->actingAs($this->admin)
            ->post(route('admin.alumni.diplomes.import'), [
                'fichier' => UploadedFile::fake()->create('diplomes.csv', 3000),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, ImportDiplomes::count());
    }

    public function test_import_rejette_les_lignes_invalides_avec_un_rapport_sans_pii(): void
    {
        $this->creerEtudiant('AB140001', $this->faculteA, $this->programmeA);
        $candidatInconnu = 'ZZ999999';
        $demain = now()->addDay()->format('Y-m-d');

        $reponse = $this->importer($this->csv([
            'AB140001;DIP-2025-4000;LIC;INFO-A;2025-06-30;',        // 2 : valide
            $candidatInconnu.';DIP-2025-4001;LIC;INFO-A;2025-06-30;', // 3 : cin_inconnu
            'AB140001;DIP-2025-4002;LIC;INFO-A;2026-02-31;',        // 4 : date_invalide
            'AB140001;DIP-2025-4003;LIC;INFO-A;'.$demain.';',       // 5 : date_future
            'AB140001;DIP-2025-4000;LIC;INFO-A;2025-06-30;',        // 6 : numero_duplique
            'AB140001;DIP-2025-4004;LIC;DROIT-B;2025-06-30;',       // 7 : programme_hors_faculte
            'AB140001;DIP-2025-4005;XXX;INFO-A;2025-06-30;',        // 8 : type_inconnu
            'AB140001;DIP-2025-4006;LIC;INFO-A;2025-06-30',         // 9 : nombre_colonnes
            'AB140001;DIP-2025-4007;LIC;INCONNU;2025-06-30;',       // 10 : programme_inconnu
        ]));

        $reponse->assertCreated()
            ->assertJsonPath('statut', 'partiel')
            ->assertJsonPath('lignes_total', 9)
            ->assertJsonPath('lignes_importees', 1)
            ->assertJsonPath('lignes_rejetees', 8);

        $this->assertSame([
            ['ligne' => 3, 'motif' => 'cin_inconnu'],
            ['ligne' => 4, 'motif' => 'date_invalide'],
            ['ligne' => 5, 'motif' => 'date_future'],
            ['ligne' => 6, 'motif' => 'numero_duplique'],
            ['ligne' => 7, 'motif' => 'programme_hors_faculte'],
            ['ligne' => 8, 'motif' => 'type_inconnu'],
            ['ligne' => 9, 'motif' => 'nombre_colonnes'],
            ['ligne' => 10, 'motif' => 'programme_inconnu'],
        ], $reponse->json('rapport'));

        $this->assertSame(1, Diplome::count());
        $this->assertStringNotContainsString($candidatInconnu, $reponse->getContent());
        $this->assertStringNotContainsString('AB140001', $reponse->getContent());
        $this->assertStringNotContainsString('Alaoui', $reponse->getContent());
    }

    public function test_un_numero_deja_importe_est_rejete_et_trace(): void
    {
        $this->creerEtudiant('AB150001', $this->faculteA, $this->programmeA);

        $this->importer($this->csv([
            'AB150001;DIP-2025-5000;LIC;INFO-A;2025-06-30;',
        ]))->assertCreated();

        $reponse = $this->importer($this->csv([
            'AB150001;DIP-2025-5001;LIC;INFO-A;2025-06-30;',
            'AB150001;DIP-2025-5000;LIC;INFO-A;2025-06-30;',
        ]));

        $reponse->assertCreated()
            ->assertJsonPath('statut', 'partiel')
            ->assertJsonPath('lignes_importees', 1)
            ->assertJsonPath('lignes_rejetees', 1)
            ->assertJsonPath('rapport.0.motif', 'numero_deja_importe');

        $this->assertSame(2, Diplome::count());
        $this->assertSame(2, ImportDiplomes::count());
    }

    public function test_aucun_diplome_n_est_calcule_automatiquement(): void
    {
        $sansDiplome = $this->creerEtudiant('AB160001', $this->faculteA, $this->programmeA);
        $avecDiplome = $this->creerEtudiant('AB160002', $this->faculteA, $this->programmeA);

        $this->importer($this->csv([
            'AB160002;DIP-2025-6000;LIC;INFO-A;2025-06-30;',
        ]))->assertCreated();

        // Aucune ligne n'est déduite des notes/crédits : l'étudiant sans
        // import officiel n'a simplement aucun diplôme.
        $this->assertSame(1, Diplome::count());
        $this->assertFalse(
            Diplome::where('etudiant_id', $sansDiplome->id)->exists(),
            'Aucun diplôme ne doit être calculé pour un étudiant sans import officiel.',
        );
        $this->assertTrue(Diplome::where('etudiant_id', $avecDiplome->id)->exists());
    }

    /* ── Lien signé et consentement ── */

    public function test_lien_signe_affiche_le_diplome_sans_dossier_academique_ni_cin(): void
    {
        $etudiant = $this->creerEtudiant('AB200001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-7000');

        $reponse = $this->actingAs($this->admin)
            ->postJson(route('admin.alumni.diplomes.lien', $diplome))
            ->assertOk()
            ->assertJsonPath('duree_jours', 30)
            ->assertJsonStructure(['url', 'expire_le']);

        $this->get($reponse->json('url'))
            ->assertOk()
            ->assertSee('DIP-2025-7000')
            ->assertSee('Alaoui')
            ->assertDontSee($etudiant->cin)
            ->assertDontSee($etudiant->email);
    }

    public function test_lien_signe_altere_ou_expire_est_refuse(): void
    {
        $etudiant = $this->creerEtudiant('AB210001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-7100');

        $valide = URL::temporarySignedRoute(
            'alumni.consentement.show',
            now()->addDay(),
            ['diplome' => $diplome->id],
        );

        $this->get($valide)->assertOk();

        $altere = preg_replace('/signature=[^&]+/', 'signature=0000', $valide);
        $this->get($altere)->assertForbidden();

        $expire = URL::temporarySignedRoute(
            'alumni.consentement.show',
            now()->subMinute(),
            ['diplome' => $diplome->id],
        );
        $this->get($expire)->assertForbidden();
    }

    public function test_consentement_opt_in_sans_compte_cree_le_profil_et_le_journal(): void
    {
        $etudiant = $this->creerEtudiant('AB220001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-7200');

        $this->assertGuest();

        $this->consentir($diplome, [
            'nom_affichage' => 'Yasmine A.',
            'visibilite' => ProfilAlumni::VISIBILITE_ANNUAIRE_CONTACT,
        ])->assertRedirect()->assertSessionHas('statut', 'consentement_enregistre');

        $profil = ProfilAlumni::where('etudiant_id', $etudiant->id)->firstOrFail();

        $this->assertNotNull($profil->consentement_le);
        $this->assertNull($profil->retire_le);
        $this->assertSame(ProfilAlumni::VISIBILITE_ANNUAIRE_CONTACT, $profil->visibilite);
        $this->assertSame(ProfilAlumni::VERSION_CONSENTEMENT, $profil->consentement_version);
        $this->assertSame($diplome->id, $profil->diplome_id);

        $this->assertDatabaseHas('consentements_alumni', [
            'profil_alumni_id' => $profil->id,
            'action' => 'opt_in',
            'canal' => 'lien_signe',
        ]);
        $this->assertSame(1, ConsentementAlumni::count());

        // Aucun dossier académique n'est stocké dans le profil.
        $attributs = $profil->getAttributes();
        foreach (['cin', 'note', 'moyenne', 'credits', 'decision'] as $interdit) {
            $this->assertArrayNotHasKey($interdit, $attributs);
        }

        $this->assertGuest();
    }

    public function test_consentement_exige_la_case_explicite(): void
    {
        $etudiant = $this->creerEtudiant('AB230001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-7300');

        $this->consentir($diplome, ['consentement' => ''])
            ->assertRedirect()
            ->assertSessionHasErrors('consentement');

        $this->assertSame(0, ProfilAlumni::count());
        $this->assertSame(0, ConsentementAlumni::count());
    }

    public function test_consentement_idempotent_ne_cree_qu_un_profil_et_un_journal(): void
    {
        $etudiant = $this->creerEtudiant('AB240001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-7400');

        $cle = (string) Str::uuid();

        $this->consentir($diplome, [
            'nom_affichage' => 'Premier nom',
            'cle_idempotence' => $cle,
        ])->assertRedirect();

        $this->consentir($diplome, [
            'nom_affichage' => 'Second nom',
            'cle_idempotence' => $cle,
        ])->assertRedirect();

        $this->assertSame(1, ProfilAlumni::count());
        $this->assertSame(1, ConsentementAlumni::count());
        $this->assertSame('Premier nom', ProfilAlumni::firstOrFail()->nom_affichage);
    }

    public function test_visibilite_avec_contact_exige_une_coordonnee(): void
    {
        $etudiant = $this->creerEtudiant('AB250001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-7500');

        $this->consentir($diplome, [
            'email_contact' => null,
            'telephone_contact' => null,
            'visibilite' => ProfilAlumni::VISIBILITE_ANNUAIRE_CONTACT,
        ])->assertRedirect()->assertSessionHasErrors('consentement');

        $this->assertSame(0, ProfilAlumni::count());
    }

    public function test_retrait_du_consentement_est_idempotent_et_retire_de_l_annuaire(): void
    {
        $etudiant = $this->creerEtudiant('AB260001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-7600');

        $this->consentir($diplome, [
            'visibilite' => ProfilAlumni::VISIBILITE_ANNUAIRE_CONTACT,
        ])->assertRedirect();

        $profil = ProfilAlumni::firstOrFail();
        $cleRetrait = (string) Str::uuid();

        $this->retirer($diplome, $cleRetrait)
            ->assertRedirect()
            ->assertSessionHas('statut', 'consentement_retire');

        $profil->refresh();
        $this->assertNotNull($profil->retire_le);
        $this->assertSame(ProfilAlumni::VISIBILITE_AGREGATS, $profil->visibilite);
        $this->assertFalse($profil->estConsenti());

        // Rejeu du retrait : aucune seconde ligne de journal.
        $this->retirer($diplome, $cleRetrait)->assertRedirect();
        $this->assertSame(2, ConsentementAlumni::count());
        $this->assertSame(1, ConsentementAlumni::where('action', 'opt_out')->count());

        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.annuaire'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ── Annuaire et contacts ── */

    public function test_annuaire_ne_contient_que_les_consentants_visibles_et_masque_les_contacts(): void
    {
        $annuaire = $this->diplomeConsenti('AB300001', 'Annuaire Visible', ProfilAlumni::VISIBILITE_ANNUAIRE);
        $contact = $this->diplomeConsenti('AB300002', 'Contact Visible', ProfilAlumni::VISIBILITE_ANNUAIRE_CONTACT);
        $agregats = $this->diplomeConsenti('AB300003', 'Agregats Seulement', ProfilAlumni::VISIBILITE_AGREGATS);

        $sansConsentement = $this->creerEtudiant('AB300004', $this->faculteA, $this->programmeA);
        $this->creerDiplome($sansConsentement, $this->programmeA, 'DIP-2025-8300', '2024-06-30');

        $reponse = $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.annuaire'))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $lignes = collect($reponse->json('data'))->keyBy('nom_affichage');

        $this->assertTrue($lignes->has('Annuaire Visible'));
        $this->assertTrue($lignes->has('Contact Visible'));
        $this->assertFalse($lignes->has('Agregats Seulement'));

        // Visibilité `annuaire` : aucune coordonnée privée, même pour un
        // porteur de `alumni.manage`.
        $this->assertArrayNotHasKey('email_contact', $lignes['Annuaire Visible']);
        $this->assertArrayNotHasKey('telephone_contact', $lignes['Annuaire Visible']);

        // Visibilité `annuaire_contact` + `alumni.manage` : contacts visibles.
        $this->assertSame('contact@alumni.test', $lignes['Contact Visible']['email_contact']);

        // Un simple lecteur `alumni.view` ne voit jamais les coordonnées.
        $lecture = $this->actingAs($this->agent)
            ->getJson(route('admin.alumni.annuaire'))
            ->assertOk()
            ->json('data');

        $ligneContact = collect($lecture)->firstWhere('nom_affichage', 'Contact Visible');
        $this->assertArrayNotHasKey('email_contact', $ligneContact);
        $this->assertArrayNotHasKey('telephone_contact', $ligneContact);

        // Aucun identifiant personnel dans la réponse.
        $this->assertStringNotContainsString('AB300001', $reponse->getContent());
        $this->assertStringNotContainsString('etudiant_id', $reponse->getContent());

        // Le filtre d'année reste opérant.
        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.annuaire', ['annee' => 2025]))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.annuaire', ['annee' => 2024]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotNull($annuaire);
        $this->assertNotNull($contact);
        $this->assertNotNull($agregats);
    }

    /* ── Isolation facultaire ── */

    public function test_isolation_facultaire_du_registre_de_l_annuaire_et_des_agregats(): void
    {
        // Six diplômés en faculté A, trois en faculté B.
        foreach (range(1, 6) as $index) {
            $etudiant = $this->creerEtudiant('AB40'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), $this->faculteA, $this->programmeA);
            $this->creerDiplome($etudiant, $this->programmeA, 'DIP-A-40'.$index);
        }

        foreach (range(1, 3) as $index) {
            $etudiant = $this->creerEtudiant('AB41'.str_pad((string) $index, 4, '0', STR_PAD_LEFT), $this->faculteB, $this->programmeB);
            $this->creerDiplome($etudiant, $this->programmeB, 'DIP-B-41'.$index);
        }

        $profilA = $this->diplomeConsenti('AB420001', 'Profil A', ProfilAlumni::VISIBILITE_ANNUAIRE);
        $this->diplomeConsentiFaculte('AB430001', $this->faculteB, $this->programmeB, 'Profil B');

        // Registre : l'admin de faculté A ne voit que sa faculté.
        $registre = $this->actingAs($this->adminFaculteA)
            ->getJson(route('admin.alumni.diplomes.index'))
            ->assertOk();

        // 6 diplômés + le profil consentant de la faculté A.
        $this->assertSame(7, $registre->json('total'));
        foreach ($registre->json('data') as $ligne) {
            $this->assertSame($this->faculteA->id, $ligne['faculte_id']);
        }

        // Annuaire : seul le consentant de sa faculté.
        $this->actingAs($this->adminFaculteA)
            ->getJson(route('admin.alumni.annuaire'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nom_affichage', 'Profil A');

        // Agrégats : compartiment unique de 7 ≥ k dans son périmètre,
        // alors que le périmètre global (7 + 4) ne permet pas de publier.
        $this->actingAs($this->adminFaculteA)
            ->getJson(route('admin.alumni.agregats'))
            ->assertOk()
            ->assertJsonPath('dimensions.par_faculte.diffusable', true)
            ->assertJsonPath('dimensions.par_faculte.total_publie', 7);

        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.agregats'))
            ->assertOk()
            ->assertJsonPath('dimensions.par_faculte.diffusable', false);

        $this->assertNotNull($profilA);
    }

    /* ── Agrégats k-anonymes ── */

    public function test_agregats_masquent_un_compartiment_unique_sous_le_seuil(): void
    {
        $this->creerDiplomesEnNombre(4, $this->faculteA, $this->programmeA, 'DIP-K1');

        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.agregats'))
            ->assertOk()
            ->assertJsonPath('seuil_k', 5)
            ->assertJsonPath('dimensions.par_faculte.diffusable', false)
            ->assertJsonPath('dimensions.par_faculte.lignes', [])
            ->assertJsonPath('dimensions.par_faculte.non_diffuses', null);
    }

    public function test_agregats_publient_un_compartiment_unique_superieur_au_seuil(): void
    {
        $this->creerDiplomesEnNombre(5, $this->faculteA, $this->programmeA, 'DIP-K2');

        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.agregats'))
            ->assertOk()
            ->assertJsonPath('dimensions.par_faculte.diffusable', true)
            ->assertJsonPath('dimensions.par_faculte.lignes.0.total', 5)
            ->assertJsonPath('dimensions.par_faculte.non_diffuses', 0)

            // Un seul compartiment est aussi publié pour l'année et le type.
            ->assertJsonPath('dimensions.par_annee.diffusable', true)
            ->assertJsonPath('dimensions.par_type_diplome.diffusable', true);
    }

    public function test_agregats_masquent_une_dimension_avec_un_seul_compartiment_publiable(): void
    {
        $this->creerDiplomesEnNombre(5, $this->faculteA, $this->programmeA, 'DIP-K3');
        $this->creerDiplomesEnNombre(1, $this->faculteB, $this->programmeB, 'DIP-K4');

        // 5 et 1 : le compartiment de 1 serait devinable par soustraction.
        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.agregats'))
            ->assertOk()
            ->assertJsonPath('dimensions.par_faculte.diffusable', false)
            ->assertJsonPath('dimensions.par_faculte.lignes', []);
    }

    public function test_agregats_publient_deux_compartiments_et_masquent_le_reste_sous_le_seuil(): void
    {
        $this->creerDiplomesEnNombre(6, $this->faculteA, $this->programmeA, 'DIP-K5');
        $this->creerDiplomesEnNombre(6, $this->faculteB, $this->programmeB, 'DIP-K6');

        $faculteC = Faculte::create(['nom' => 'Faculté C', 'code' => 'FSC', 'description' => 'Test', 'active' => true]);
        $programmeC = Programme::create([
            'faculte_id' => $faculteC->id, 'code' => 'C', 'nom' => 'Programme C',
            'statut' => 'actif', 'actif' => true,
        ]);
        $faculteD = Faculte::create(['nom' => 'Faculté D', 'code' => 'FSD', 'description' => 'Test', 'active' => true]);
        $programmeD = Programme::create([
            'faculte_id' => $faculteD->id, 'code' => 'D', 'nom' => 'Programme D',
            'statut' => 'actif', 'actif' => true,
        ]);

        $this->creerDiplomesEnNombre(3, $faculteC, $programmeC, 'DIP-K7');
        $this->creerDiplomesEnNombre(4, $faculteD, $programmeD, 'DIP-K8');

        $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.agregats'))
            ->assertOk()
            ->assertJsonPath('dimensions.par_faculte.diffusable', true)
            ->assertJsonCount(2, 'dimensions.par_faculte.lignes')
            ->assertJsonPath('dimensions.par_faculte.total_publie', 12)
            // 3 + 4 = 7 restants ≥ k : le reste est annoncé sans détail.
            ->assertJsonPath('dimensions.par_faculte.non_diffuses', 7);
    }

    public function test_agregats_n_exposent_aucun_identifiant_personnel(): void
    {
        $etudiant = $this->creerEtudiant('AB500001', $this->faculteA, $this->programmeA);
        $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-9001');
        $this->creerDiplomesEnNombre(4, $this->faculteA, $this->programmeA, 'DIP-K9');

        $reponse = $this->actingAs($this->admin)
            ->getJson(route('admin.alumni.agregats'))
            ->assertOk();

        $contenu = $reponse->getContent();
        $this->assertStringNotContainsString('DIP-2025-9001', $contenu);
        $this->assertStringNotContainsString('AB500001', $contenu);
        $this->assertStringNotContainsString('etudiant_id', $contenu);
        $this->assertStringNotContainsString('nom_affichage', $contenu);
        $this->assertStringNotContainsString('email', $contenu);
    }

    /* ── Permissions et journal append-only ── */

    public function test_permissions_alumni_view_import_manage_sont_distinctes(): void
    {
        $etudiant = $this->creerEtudiant('AB600001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-9100');
        $contenu = $this->csv(['AB600001;DIP-2025-9101;LIC;INFO-A;2025-06-30;']);

        // Agent de scolarité : lecture seule.
        $this->actingAs($this->agent)->getJson(route('admin.alumni.annuaire'))->assertOk();
        $this->actingAs($this->agent)->getJson(route('admin.alumni.diplomes.index'))->assertOk();
        $this->actingAs($this->agent)->getJson(route('admin.alumni.agregats'))->assertOk();
        $this->actingAs($this->agent)->getJson(route('admin.alumni.imports.index'))->assertForbidden();
        $this->actingAs($this->agent)
            ->post(route('admin.alumni.diplomes.import'), [
                'fichier' => UploadedFile::fake()->createWithContent('diplomes.csv', $contenu),
            ])
            ->assertForbidden();
        $this->actingAs($this->agent)
            ->postJson(route('admin.alumni.diplomes.lien', $diplome))
            ->assertForbidden();

        // Admin de faculté : consultation de son périmètre, ni import ni lien.
        $this->actingAs($this->adminFaculteA)->getJson(route('admin.alumni.diplomes.index'))->assertOk();
        $this->actingAs($this->adminFaculteA)
            ->post(route('admin.alumni.diplomes.import'), [
                'fichier' => UploadedFile::fake()->createWithContent('diplomes.csv', $contenu),
            ])
            ->assertForbidden();
        $this->actingAs($this->adminFaculteA)
            ->postJson(route('admin.alumni.diplomes.lien', $diplome))
            ->assertForbidden();

        // Consultation : lecture des agrégats, aucune écriture.
        $this->actingAs($this->consultation)->getJson(route('admin.alumni.agregats'))->assertOk();
        $this->actingAs($this->consultation)
            ->postJson(route('admin.alumni.diplomes.lien', $diplome))
            ->assertForbidden();

        $this->assertSame(1, Diplome::count());
    }

    public function test_journal_de_consentement_est_append_only(): void
    {
        $etudiant = $this->creerEtudiant('AB700001', $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-2025-9200');
        $this->consentir($diplome)->assertRedirect();

        $consentement = ConsentementAlumni::firstOrFail();

        try {
            $consentement->update(['action' => 'opt_out']);
            $this->fail('La mise à jour du journal aurait dû être refusée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $consentement->delete();
            $this->fail('La suppression du journal aurait dû être refusée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(1, ConsentementAlumni::count());
    }

    /* ── Helpers ── */

    private function creerUtilisateur(string $role, ?Faculte $faculte = null): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Agent '.$role.' '.Str::random(4),
            'email' => 'loop17.'.Str::random(10).'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculte?->id,
        ], $role);
    }

    private function creerEtudiant(string $cin, Faculte $faculte, Programme $programme): Etudiant
    {
        return Etudiant::create([
            'cin' => $cin,
            'nom' => 'Alaoui',
            'prenom' => 'Yasmine',
            'email' => strtolower($cin).'@etudiant.test',
            'date_naissance' => '2002-01-01',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'programme_id' => $programme->id,
            'niveau' => 'L3',
            'annee_academique' => '2024-2025',
            'adresse' => '12 rue des Tests',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2020',
            'password' => 'Etudiant@123',
        ]);
    }

    private function creerDiplome(
        Etudiant $etudiant,
        Programme $programme,
        string $numero,
        string $date = '2025-06-30',
    ): Diplome {
        return Diplome::create([
            'numero_diplome' => $numero,
            'etudiant_id' => $etudiant->id,
            'faculte_id' => $etudiant->faculte_id,
            'programme_id' => $programme->id,
            'type_diplome_id' => $this->typeLicence->id,
            'date_delivrance' => $date,
            'mention' => null,
            'source' => 'csv',
            'importe_le' => now(),
        ]);
    }

    /**
     * Crée N diplômés consentants dans la faculté A.
     */
    private function creerDiplomesEnNombre(int $nombre, Faculte $faculte, Programme $programme, string $prefixe): void
    {
        foreach (range(1, $nombre) as $index) {
            $cin = $faculte->code.'-'.substr(md5($prefixe.$index), 0, 10);
            $etudiant = $this->creerEtudiant($cin, $faculte, $programme);
            $this->creerDiplome($etudiant, $programme, $prefixe.'-'.$index);
        }
    }

    private function diplomeConsenti(string $cin, string $nomAffiche, string $visibilite): Diplome
    {
        $etudiant = $this->creerEtudiant($cin, $this->faculteA, $this->programmeA);
        $diplome = $this->creerDiplome($etudiant, $this->programmeA, 'DIP-'.$cin);

        $this->consentir($diplome, [
            'nom_affichage' => $nomAffiche,
            'visibilite' => $visibilite,
        ])->assertRedirect();

        return $diplome;
    }

    private function diplomeConsentiFaculte(
        string $cin,
        Faculte $faculte,
        Programme $programme,
        string $nomAffiche,
    ): Diplome {
        $etudiant = $this->creerEtudiant($cin, $faculte, $programme);
        $diplome = $this->creerDiplome($etudiant, $programme, 'DIP-'.$cin);

        $this->consentir($diplome, [
            'nom_affichage' => $nomAffiche,
            'visibilite' => ProfilAlumni::VISIBILITE_ANNUAIRE,
        ])->assertRedirect();

        return $diplome;
    }

    /**
     * @param  array<string, mixed>  $surcharges
     */
    private function consentir(Diplome $diplome, array $surcharges = [])
    {
        $url = URL::temporarySignedRoute(
            'alumni.consentement.store',
            now()->addHours(2),
            ['diplome' => $diplome->id],
        );

        return $this->post($url, array_merge($this->chargeConsentement(), $surcharges));
    }

    private function retirer(Diplome $diplome, string $cle)
    {
        $url = URL::temporarySignedRoute(
            'alumni.consentement.retrait',
            now()->addHours(2),
            ['diplome' => $diplome->id],
        );

        return $this->post($url, ['cle_idempotence' => $cle]);
    }

    /**
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function chargeConsentement(array $surcharges = []): array
    {
        return array_merge([
            'consentement' => '1',
            'nom_affichage' => 'Yasmine Alaoui',
            'email_contact' => 'contact@alumni.test',
            'telephone_contact' => '+212 600-000000',
            'poste_actuel' => 'Ingénieure',
            'employeur' => 'ACME',
            'secteur' => 'Informatique',
            'ville' => 'Casablanca',
            'pays' => 'Maroc',
            'visibilite' => ProfilAlumni::VISIBILITE_ANNUAIRE,
            'cle_idempotence' => (string) Str::uuid(),
        ], $surcharges);
    }

    /**
     * @param  array<int, string>  $lignes
     */
    private function csv(array $lignes): string
    {
        $entete = 'cin;numero_diplome;type_diplome;programme;date_delivrance;mention';

        return $entete."\n".implode("\n", $lignes)."\n";
    }

    private function importer(string $contenu, string $nom = 'diplomes.csv')
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.alumni.diplomes.import'), [
                'fichier' => UploadedFile::fake()->createWithContent($nom, $contenu),
            ], ['Accept' => 'application/json']);
    }
}
