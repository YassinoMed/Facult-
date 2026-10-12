<?php

namespace Tests\Feature;

use App\Jobs\Io\ExportStudentsJob;
use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\ImportReport;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Exports académiques multicritères (§70-90) : combinaisons de filtres,
 * types d'export, colonnes personnalisées, permissions §85, expiration
 * §82, purge, audit, volumétrie → job, injection via filtres.
 */
class AcademicExportTest extends TestCase
{
    use ActAsApiClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Deux facultés, deux étudiants, une année, deux matières S1, notes
        $fst = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        Faculte::create(['nom' => 'Faculté de Droit', 'code' => 'FDEG', 'description' => 'T', 'active' => true]);

        $annee = AnneeUniversitaire::create(['libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15']);

        foreach ([['BX111', 'Diop', 'Awa', 'FST', 'G1', 14.5], ['BX222', 'Fall', 'Moussa', 'FST', 'G2', 8.25]] as [$cin, $nom, $prenom, $code, $groupe, $moyenne]) {
            $etudiant = Etudiant::create(['cin' => $cin,  'nom' => $nom, 'prenom' => $prenom,
                'email' => mb_strtolower($prenom).'.'.mb_strtolower($nom).'@example.com',
                'sexe' => 'F', 'faculte_id' => $fst->id, 'niveau' => 'L3', 'annee_academique' => '2025-2026',
                'adresse' => 'Rue', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2022', ]);
            $etudiant->forceFill(['num_inscription' => 'INS-EXP-'.$cin])->save();
            $etudiant->definirStatut('valide');

            DossierAcademique::create(['etudiant_id' => $etudiant->id, 'matricule' => 'MAT-'.$cin, 'date_archivage' => now()->toDateString()]);

            $inscription = InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id, 'niveau' => 'L3',
                'filiere' => 'Informatique', 'groupe' => $groupe,
                'moyenne_annuelle' => $moyenne, 'credits_obtenus' => 30, 'credits_total' => 30,
                'decision' => $moyenne >= 10 ? 'Admis(e)' : 'Ajourné(e) — rattrapage',
                'statut' => $moyenne >= 10 ? 'valide' : 'ajourne',
            ]);

            $s1 = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1, 'moyenne' => $moyenne, 'credits_obtenus' => 15, 'credits_total' => 15, 'decision' => $moyenne >= 10 ? 'Validé' : 'Rattrapage']);
            $ia = Matiere::firstOrCreate(
                ['code' => 'IA301'],
                ['nom' => 'Intelligence Artificielle', 'coefficient' => 3, 'credits' => 5, 'niveau' => 'L3', 'semestre' => 1],
            );
            $bd = Matiere::firstOrCreate(
                ['code' => 'BD301'],
                ['nom' => 'Bases de données', 'coefficient' => 2, 'credits' => 4, 'niveau' => 'L3', 'semestre' => 1],
            );

            Note::create(['etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
                'semestre_id' => $s1->id, 'matiere_id' => $ia->id,
                'note' => $moyenne, 'coefficient' => 3, 'credits' => 5, 'session' => 'normale']);
            Note::create(['etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
                'semestre_id' => $s1->id, 'matiere_id' => $bd->id,
                'note' => max(0, $moyenne - 2), 'coefficient' => 2, 'credits' => 4, 'session' => 'normale']);
        }
    }

    private function exportApi(array $payload): TestResponse
    {
        [, $token] = $this->clientApi(['exports:create']);

        return $this->post('/api/v1/exports', $payload, ['Authorization' => 'Bearer '.$token]);
    }

    private function contenu(TestResponse $creation, string $token): string
    {
        $url = $creation->json('report.download_url');

        return $this->get($url, ['Authorization' => 'Bearer '.$token])->assertOk()->streamedContent();
    }

    /* ── §70 : filtres combinés — la règle d'or §90 ── */

    public function test_regle_d_or_notes_une_matiere_un_groupe_une_annee(): void
    {
        $reponse = $this->exportApi([
            'type' => 'subject', 'format' => 'json',
            'filters' => ['faculte' => 'FST', 'filiere' => 'Informatique', 'niveau' => 'L3',
                'groupe' => 'G2', 'annee' => '2025-2026', 'semestre' => 1,
                'matiere' => 'IA301', 'session' => 'normale'],
        ])->assertStatus(201);

        $contenu = $this->contenu($reponse, $this->dernierToken);

        $lignes = json_decode($contenu, true);
        $this->assertCount(1, $lignes);
        $this->assertSame('Fall', $lignes[0]['nom']);
        $this->assertSame(8.25, (float) $lignes[0]['note']);
    }

    public function test_tous_les_etudiants_d_une_faculte(): void
    {
        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'json', 'profil' => 'academique',
            'filters' => ['faculte' => 'FST', 'annee' => '2025-2026'],
        ])->assertStatus(201);

        $contenu = $this->contenu($reponse, $this->dernierToken);
        $this->assertCount(2, json_decode($contenu, true));
    }

    public function test_filtres_combines_groupe_et_statut_academique(): void
    {
        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'json', 'profil' => 'academique',
            'filters' => ['groupe' => 'G1', 'statut_academique' => 'valide', 'moyenne_min' => 10],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);
        $this->assertCount(1, $lignes);
        $this->assertSame('Diop', $lignes[0]['nom']);
    }

    public function test_intervalle_de_moyenne(): void
    {
        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'json', 'profil' => 'academique',
            'filters' => ['moyenne_max' => 10],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);
        $this->assertCount(1, $lignes);
        $this->assertSame('Fall', $lignes[0]['nom']);
    }

    public function test_recherche_individuelle_par_matricule(): void
    {
        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'json', 'profil' => 'academique',
            'filters' => ['matricule' => 'MAT-BX222'],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);
        $this->assertCount(1, $lignes);
        $this->assertSame('Fall', $lignes[0]['nom']);
    }

    /* ── §74 : relevé collectif du groupe (matières dynamiques) ── */

    public function test_releve_collectif_avec_matieres_en_colonnes(): void
    {
        $reponse = $this->exportApi([
            'type' => 'group', 'format' => 'json',
            'filters' => ['faculte' => 'FST', 'filiere' => 'Informatique', 'niveau' => 'L3', 'annee' => '2025-2026'],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);

        $this->assertCount(2, $lignes); // les deux étudiants du périmètre
        $this->assertArrayHasKey('IA301', $lignes[0]); // matière en colonne dynamique
        $this->assertArrayHasKey('BD301', $lignes[0]);
        $this->assertSame('14.50', $lignes[0]['IA301']); // note validée de Diop
    }

    /* ── §73 : moyennes aux 4 niveaux ── */

    public function test_moyennes_semestrielles(): void
    {
        $reponse = $this->exportApi([
            'type' => 'averages', 'format' => 'json', 'granularite' => 'semestre',
            'filters' => ['groupe' => 'G1'],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);
        $this->assertCount(1, $lignes);
        $this->assertSame('14.50', $lignes[0]['moyenne']);
    }

    public function test_moyennes_generales_parcours_complet(): void
    {
        $reponse = $this->exportApi([
            'type' => 'averages', 'format' => 'json', 'granularite' => 'generale',
            'filters' => ['matricule' => 'MAT-BX111'],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);
        $this->assertCount(1, $lignes);
        $this->assertSame(14.5, (float) $lignes[0]['moyenne_generale']);
        $this->assertSame(30, $lignes[0]['total_credits']);
    }

    /* ── §76 : synthèse par filière ── */

    public function test_synthese_filiere_par_niveau_et_groupe(): void
    {
        $reponse = $this->exportApi([
            'type' => 'filiere', 'format' => 'json',
            'filters' => ['filiere' => 'Informatique', 'annee' => '2025-2026'],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);

        $this->assertCount(2, $lignes); // G1 et G2
        $g1 = collect($lignes)->firstWhere('groupe', 'G1');
        $g2 = collect($lignes)->firstWhere('groupe', 'G2');
        $this->assertSame(1, $g1['effectif']);
        $this->assertSame(1, $g1['admis']);
        $this->assertSame(1, $g2['ajournes']);
    }

    /* ── §86 : statistiques par matière ── */

    public function test_statistiques_par_matiere(): void
    {
        $reponse = $this->exportApi([
            'type' => 'stats', 'format' => 'json',
            'filters' => ['niveau' => 'L3', 'semestre' => 1],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);

        $ia = collect($lignes)->firstWhere('matiere', 'IA301');
        $this->assertSame(2, $ia['effectif']);
        $this->assertSame('50%', $ia['taux_reussite']); // 14.5 validé / 8.25 non
    }

    /* ── §78/§85 : colonnes personnalisées + permissions ── */

    public function test_colonnes_personnalisees_restreintes_au_catalogue(): void
    {
        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'csv', 'profil' => 'administratif',
            'columns' => ['cin', 'nom', 'statut', 'colonne_inexistante'],
            'filters' => ['faculte' => 'FST', 'annee' => '2025-2026'],
        ])->assertStatus(201);

        $contenu = $this->contenu($reponse, $this->dernierToken);

        // En-têtes = strictement les colonnes choisies (catalogue ∩ choix)
        $entetes = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', explode("\n", $contenu)[0]));
        $this->assertSame('cin;nom;statut', $entetes);

        // colonne_inexistante ignorée — pas d'email, pas de téléphone
        $this->assertStringNotContainsStringIgnoringCase('email', $contenu);
        $this->assertStringNotContainsStringIgnoringCase('telephone', $contenu);

        // Les lignes ne contiennent que les colonnes choisies (3 colonnes = 2 séparateurs)
        $premiereLigne = explode("\n", $contenu)[1] ?? '';
        $this->assertSame(2, substr_count($premiereLigne, ';'));
    }

    public function test_agent_sans_permission_personnelle_n_obtient_pas_les_colonnes_sensibles(): void
    {
        // Un client API dont le COMPTE a le rôle agent_scolarite : pas exports.personal
        $client = User::creerCompteBackOffice([
            'name' => 'Agent Externe', 'email' => uniqid('agent.', true).'@integration.systeme',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
        $token = $client->createToken('agent-export', ['exports:create'])->plainTextToken;

        $reponse = $this->post('/api/v1/exports', [
            'type' => 'students', 'format' => 'json', 'profil' => 'administratif',
            'filters' => ['faculte' => 'FST'],
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $corps = $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$token])
            ->assertOk()->streamedContent();

        // §85 : CIN et téléphone absents (moindre privilège)
        $this->assertStringNotContainsString('BX111', $corps);
        $this->assertStringNotContainsString('telephone', $corps);
    }

    /* ── §80/§82 : volumétrie → job, expiration + purge ── */

    public function test_export_volumineux_passe_par_job(): void
    {
        config(['integrations.queue_threshold' => 1]);
        Queue::fake();

        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'json', 'profil' => 'academique',
            'filters' => ['faculte' => 'FST'],
        ])->assertStatus(201);

        // §80 : job dispatché — le rapport reste en « processing » jusqu'à son exécution
        Queue::assertPushed(ExportStudentsJob::class);
        $this->assertSame('processing', $reponse->json('report.status'));
        $this->assertNotNull(ImportReport::find($reponse->json('report.id'))->expires_at); // §82
    }

    public function test_export_expire_refuse_le_telechargement_et_purge_le_fichier(): void
    {
        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'csv', 'profil' => 'academique',
            'filters' => ['faculte' => 'FST'],
        ])->assertStatus(201);

        $rapport = ImportReport::find($reponse->json('report.id'));
        $rapport->update(['expires_at' => now()->subHour()]); // simule l'expiration

        $statut = $this->get($reponse->json('report.download_url'), ['Authorization' => 'Bearer '.$this->dernierToken])
            ->assertStatus(404);

        // §82 : fichier purgé + statut expired + journalisation
        $this->assertSame('expired', $rapport->fresh()->status);
        $this->assertNull($rapport->fresh()->file_path);
        $this->assertDatabaseHas('integration_logs', ['operation' => 'export.expired']);
    }

    public function test_commande_purge_supprime_les_exports_expires(): void
    {
        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'csv', 'profil' => 'academique',
            'filters' => ['faculte' => 'FST'],
        ])->assertStatus(201);

        ImportReport::find($reponse->json('report.id'))->update(['expires_at' => now()->subDay()]);

        $this->artisan('exports:purge')->assertSuccessful();

        $this->assertDatabaseHas('import_reports', ['id' => $reponse->json('report.id'), 'status' => 'expired']);
    }

    /* ── §84 : injection SQL via filtres ── */

    public function test_injection_sql_via_filtres_neutree(): void
    {
        // La valeur est traitée comme une chaîne LIKE paramétrée — jamais du SQL
        $reponse = $this->exportApi([
            'type' => 'students', 'format' => 'json', 'profil' => 'academique',
            'filters' => ['nom' => "'; DROP TABLE etudiants; --"],
        ])->assertStatus(201);

        $this->assertDatabaseCount('etudiants', 2); // la table est intacte
        $this->assertSame('[]', trim($this->contenu($reponse, $this->dernierToken)));
    }

    public function test_filtre_hors_liste_blanche_rejete(): void
    {
        $this->exportApi([
            'type' => 'students', 'format' => 'json',
            'filters' => ['sort' => 'password', 'sql' => '1=1'],
        ])->assertStatus(422);
    }

    /* ── §83 : audit de l'export (filtres + comptes + durée) ── */

    public function test_audit_trace_le_type_les_filtres_et_le_volume(): void
    {
        $this->exportApi([
            'type' => 'subject', 'format' => 'json',
            'filters' => ['matiere' => 'IA301', 'groupe' => 'G1'],
        ])->assertStatus(201);

        $rapport = ImportReport::where('direction', 'export')->first();

        $this->assertSame('subject', $rapport->type);
        $this->assertEqualsCanonicalizing(['matiere' => 'IA301', 'groupe' => 'G1'], $rapport->preview['filtres']);
        $this->assertSame(1, $rapport->total_rows);
        $this->assertNotNull($rapport->duration_ms);
        $this->assertDatabaseHas('integration_logs', ['operation' => 'export.subject']);
    }

    /* ── §75 : sessions — principale / rattrapage / toutes ── */

    public function test_export_par_matiere_session_rattrapage(): void
    {
        $note = Note::first();
        Note::create([
            'etudiant_id' => $note->etudiant_id, 'inscription_academique_id' => $note->inscription_academique_id,
            'semestre_id' => $note->semestre_id, 'matiere_id' => $note->matiere_id,
            'note' => 12.0, 'coefficient' => $note->coefficient, 'credits' => $note->credits, 'session' => 'rattrapage',
        ]);

        $reponse = $this->exportApi([
            'type' => 'subject', 'format' => 'json',
            'filters' => ['matiere' => 'IA301', 'session' => 'rattrapage'],
        ])->assertStatus(201);

        $lignes = json_decode($this->contenu($reponse, $this->dernierToken), true);
        $this->assertCount(1, $lignes);
        $this->assertSame(12.0, (float) $lignes[0]['note']); // la note de rattrapage remplace
    }
}
