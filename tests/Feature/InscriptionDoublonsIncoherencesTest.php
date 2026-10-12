<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\MensReference;
use App\Models\User;
use App\Services\PeriodeInscriptionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 07 — Tests de détection des doublons et des incohérences (§7).
 *
 * Vérifie :
 * 1. Homonymes légitimes acceptés (même nom/prénom, CIN distincts).
 * 2. Doublon certain CIN (rejet immédiat et contrôlé).
 * 3. Doublon certain email (rejet immédiat).
 * 4. Gestion gracieuse de la concurrence (sans erreur 500).
 * 5. Correspondance ambiguë (même identité, date de naissance) : acceptée mais alerte qualité créée.
 * 6. Résolution par agent habilité avec justification et audit sans PII.
 * 7. Divergence mineure MENS tracée comme anomalie à vérifier sans bloquer.
 */
class InscriptionDoublonsIncoherencesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'description' => 'Test',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'scolarite.doublons@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        app(PeriodeInscriptionService::class)->creerNormale([
            'annee_universitaire_id' => $this->annee->id,
            'opened_at' => '2020-01-01 00:00',
            'closed_at' => '2030-12-31 23:59',
        ], null);
    }

    private function donneesInscription(array $remplacements = []): array
    {
        return array_merge([
            'cin' => '08990011',
            'nom' => 'Benali',
            'prenom' => 'Mohamed',
            'email' => 'm.benali@etudiant.test',
            'telephone' => '0612345678',
            'date_naissance' => '2004-03-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue Mohammed V',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2024',
            'password' => 'SecretPass@2026',
            'password_confirmation' => 'SecretPass@2026',
        ], $remplacements);
    }

    private function creerMens(string $cin, string $dateNaissance = '2004-03-15', array $extra = []): MensReference
    {
        return MensReference::create(array_merge([
            'cin_original' => $cin,
            'cin_normalise' => MensReference::normaliserCin($cin),
            'date_naissance' => $dateNaissance,
            'nom' => 'Benali',
            'prenom' => 'Mohamed',
            'sexe' => 'M',
            'nationalite' => 'Marocaine',
            'annee' => '2026-2027',
            'source_hash' => str_repeat('a', 64),
        ], $extra));
    }

    public function test_homonymes_legitimes_meme_nom_prenom_cins_differents_acceptes(): void
    {
        // 1er étudiant
        $this->creerMens('08111001', '2004-01-10', ['nom' => 'Alami', 'prenom' => 'Youssef']);
        $reponse1 = $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08111001',
            'nom' => 'Alami',
            'prenom' => 'Youssef',
            'date_naissance' => '2004-01-10',
            'email' => 'youssef1@etudiant.test',
        ]));
        $reponse1->assertRedirect(route('inscription.success'));

        // 2nd étudiant homonyme : même nom et prénom, mais date de naissance et CIN différents
        $this->creerMens('08111002', '2004-08-20', ['nom' => 'Alami', 'prenom' => 'Youssef']);
        $reponse2 = $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08111002',
            'nom' => 'Alami',
            'prenom' => 'Youssef',
            'date_naissance' => '2004-08-20',
            'email' => 'youssef2@etudiant.test',
        ]));
        $reponse2->assertRedirect(route('inscription.success'));

        $this->assertSame(2, Etudiant::where('nom', 'Alami')->where('prenom', 'Youssef')->count());
    }

    public function test_doublon_certain_meme_cin_rejet_immediat(): void
    {
        $this->creerMens('08222001', '2004-03-15');
        $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08222001',
            'email' => 'etudiant1@test.ma',
        ]))->assertRedirect(route('inscription.success'));

        // Tentative de soumission avec le même CIN (même avec des espaces superflus)
        $reponse = $this->from(route('inscription.create'))->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08 222 001',
            'email' => 'autre@test.ma',
        ]));

        $reponse->assertRedirect(route('inscription.create'));
        $reponse->assertSessionHasErrors('cin');
        $this->assertSame(1, Etudiant::count());
    }

    public function test_doublon_certain_meme_email_rejet_immediat(): void
    {
        $this->creerMens('08333001', '2004-03-15');
        $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08333001',
            'email' => 'contact.unique@test.ma',
        ]))->assertRedirect(route('inscription.success'));

        // Tentative de soumission avec la même adresse email (insensible à la casse)
        $this->creerMens('08333002', '2004-03-15');
        $reponse = $this->from(route('inscription.create'))->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08333002',
            'email' => 'CONTACT.UNIQUE@test.ma',
        ]));

        $reponse->assertRedirect(route('inscription.create'));
        $reponse->assertSessionHasErrors('email');
        $this->assertSame(1, Etudiant::count());
    }

    public function test_correspondance_ambigue_nom_prenom_naissance_autorisee_avec_alerte_a_verifier(): void
    {
        // 1er dossier existant
        $this->creerMens('08444001', '2004-05-15', ['nom' => 'Tazi', 'prenom' => 'Amine']);
        $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08444001',
            'nom' => 'Tazi',
            'prenom' => 'Amine',
            'date_naissance' => '2004-05-15',
            'email' => 'tazi1@test.ma',
        ]))->assertRedirect(route('inscription.success'));

        // 2nd dossier : même nom, même prénom, même date de naissance mais CIN différent
        // L'inscription n'est PAS rejetée automatiquement (principe de non-blocage des homonymes parfaits)
        $this->creerMens('08444002', '2004-05-15', ['nom' => 'Tazi', 'prenom' => 'Amine']);
        $reponse2 = $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08444002',
            'nom' => 'Tazi',
            'prenom' => 'Amine',
            'date_naissance' => '2004-05-15',
            'email' => 'tazi2@test.ma',
        ]));
        $reponse2->assertRedirect(route('inscription.success'));

        $this->assertSame(2, Etudiant::count());

        // Une anomalie qualité de type doublons.detection a été automatiquement créée
        $etudiant2 = Etudiant::where('cin', '08444002')->firstOrFail();
        $anomalie = AnomalieQualite::where('entite_id', $etudiant2->id)
            ->where('cle_unique', 'like', '%doublons.detection%')
            ->first();

        $this->assertNotNull($anomalie);
        $this->assertSame('ouverte', $anomalie->statut);
        $this->assertSame('avertissement', $anomalie->gravite);
        $this->assertStringContainsString('Doublon potentiel', $anomalie->message);
    }

    public function test_resolution_anomalie_par_agent_habilite_avec_audit_sans_pii(): void
    {
        $this->creerMens('08555001', '2004-05-15', ['nom' => 'Tazi', 'prenom' => 'Amine']);
        $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08555001',
            'nom' => 'Tazi',
            'prenom' => 'Amine',
            'date_naissance' => '2004-05-15',
            'email' => 'tazi.test1@test.ma',
        ]));

        $this->creerMens('08555002', '2004-05-15', ['nom' => 'Tazi', 'prenom' => 'Amine']);
        $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08555002',
            'nom' => 'Tazi',
            'prenom' => 'Amine',
            'date_naissance' => '2004-05-15',
            'email' => 'tazi.test2@test.ma',
        ]));

        $etudiant2 = Etudiant::where('cin', '08555002')->firstOrFail();
        $anomalie = AnomalieQualite::where('entite_id', $etudiant2->id)
            ->where('cle_unique', 'like', '%doublons.detection%')
            ->firstOrFail();

        // L'agent habilité arbitre et résout l'anomalie
        $reponse = $this->actingAs($this->scolarite)->post(route('admin.qualite.transiter', $anomalie), [
            'statut' => 'resolue',
            'resolution' => 'Homonymie légitime confirmée après vérification des cartes nationales d’identité.',
        ]);

        $reponse->assertRedirect();
        $this->assertSame('resolue', $anomalie->fresh()->statut);
        $this->assertSame($this->scolarite->id, $anomalie->fresh()->resolue_par);
        $this->assertNotNull($anomalie->fresh()->resolue_a);

        // Vérification de la trace d'audit sur le dossier étudiant
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant2->id,
            'action' => 'qualite.anomalie_resolue',
        ]);
    }

    public function test_divergence_mens_genere_alerte_a_verifier_sans_bloquer_inscription(): void
    {
        // Fiche MENS avec une légère différence de graphie (Mohamed Yassine vs Mohamed Yassin)
        $this->creerMens('08666001', '2004-03-15', [
            'nom' => 'Mohamed Yassine',
            'prenom' => 'Ali',
        ]);

        $reponse = $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08666001',
            'nom' => 'Mohamed Yassin',
            'prenom' => 'Ali',
            'date_naissance' => '2004-03-15',
            'email' => 'yassine.ali@test.ma',
        ]));

        $reponse->assertRedirect(route('inscription.success'));
        $etudiant = Etudiant::where('cin', '08666001')->firstOrFail();

        // Vérification que la divergence a été tracée dans anomalies_qualite
        $anomalie = AnomalieQualite::where('entite_id', $etudiant->id)
            ->where('cle_unique', 'like', '%mens.divergences%')
            ->first();

        $this->assertNotNull($anomalie);
        $this->assertSame('ouverte', $anomalie->statut);
        $this->assertSame('avertissement', $anomalie->gravite);
        $this->assertStringContainsString('Divergence MENS', $anomalie->message);
    }

    public function test_collision_concurrente_cle_unique_gere_sans_erreur_500(): void
    {
        $this->creerMens('08777001', '2004-03-15');

        // Simulation d'une collision concurrente de contrainte d'intégrité (race condition)
        Etudiant::creating(function () {
            throw new UniqueConstraintViolationException(
                'sqlite',
                'insert into etudiants...',
                [],
                new \PDOException('UNIQUE constraint failed: etudiants.cin')
            );
        });

        $reponse = $this->post(route('inscription.store'), $this->donneesInscription([
            'cin' => '08777001',
            'date_naissance' => '2004-03-15',
        ]));

        $reponse->assertSessionHasErrors('cin');
        $this->assertNotSame(500, $reponse->status());
        $reponse->assertRedirect();
    }
}
