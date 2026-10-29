<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\AiAssistant\Data\ContexteExecution;
use App\Modules\AiAssistant\Exceptions\ActionRefuseeException;
use App\Modules\AiAssistant\Exceptions\AiAssistantException;
use App\Modules\AiAssistant\Exceptions\OutilRefuseException;
use App\Modules\AiAssistant\Models\AiApprobation;
use App\Modules\AiAssistant\Support\GestionnaireApprobations;
use App\Modules\AiAssistant\Support\RegistreOutils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOTS 7/9 — Outils développeur (lecture seule) + workflow
 * d'approbation : registre avec permission/mode/paramètres, sandbox
 * fichiers (traversal refusée, .env refusé), proposition de
 * correctif STOCKÉE (jamais appliquée), quatre yeux, anti-rejeu
 * (empreinte sha256), expiration, exécution refusée par construction.
 */
final class AiAssistantOutilsTest extends TestCase
{
    use RefreshDatabase;

    private RegistreOutils $registre;

    private Faculte $faculteA;

    private Faculte $faculteB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registre = app(RegistreOutils::class);

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FSC', 'description' => 'Sciences', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Faculté de Droit', 'code' => 'DRT', 'description' => 'Droit', 'active' => true,
        ]);
    }

    private function contexte(string $role = 'super_admin', string $mode = 'developpeur'): ContexteExecution
    {
        return new ContexteExecution(
            utilisateurId: 7,
            role: $role,
            mode: $mode,
            conversationId: null,
        );
    }

    private function proposen(int $demandeurId = 7): AiApprobation
    {
        return app(GestionnaireApprobations::class)->soumettre(
            action: 'proposer_patch',
            description: 'Durcir la validation du matricule',
            cibles: ['app/Models/Etudiant.php'],
            parametres: ['diff' => "--- a.php\n+++ b.php\n@@ -1,2 +1,3 @@\n x\n+y\n"],
            niveauRisque: 'eleve',
            contexte: new ContexteExecution($demandeurId, 'super_admin', 'developpeur'),
        );
    }

    /* ── Registre ──────────────────────────────────────────────── */

    public function test_les_outils_developpeur_ne_sortent_que_en_mode_developpeur(): void
    {
        $dev = $this->registre->nomsPourMode('developpeur');
        $metier = $this->registre->nomsPourMode('metier');

        $this->assertContains('inspecter_code', $dev);
        $this->assertContains('lire_extrait_code', $dev);
        $this->assertNotContains('inspecter_code', $metier);
        $this->assertNotContains('proposer_patch', $metier);
    }

    public function test_la_permission_est_refusee_au_service(): void
    {
        $this->expectException(OutilRefuseException::class);
        $this->expectExceptionMessage('permission absente');

        $this->registre->invoquer('inspecter_code', ['motif' => 'AiChat'], $this->contexte('agent_scolarite'));
    }

    public function test_le_mode_est_respecte(): void
    {
        $this->expectException(OutilRefuseException::class);
        $this->expectExceptionMessage('mode');

        $this->registre->invoquer('inspecter_code', ['motif' => 'AiChat'], $this->contexte('super_admin', 'metier'));
    }

    /* ── inspecter_code ─────────────────────────────────────────── */

    public function test_inspecter_code_retrouve_un_fichier_reel_sans_contenu(): void
    {
        $resultat = $this->registre->invoquer(
            'inspecter_code',
            ['motif' => 'AiChatService'],
            $this->contexte(),
        );

        $chemins = array_column($resultat['fichiers'], 'chemin');
        $this->assertContains('app/Modules/AiAssistant/Services/AiChatService.php', $chemins);

        foreach ($resultat['fichiers'] as $fichier) {
            $this->assertArrayNotHasKey('contenu', $fichier, 'inspection = métadonnées seulement.');
        }
    }

    public function test_la_traversee_de_repertoires_est_refusee(): void
    {
        $this->expectException(OutilRefuseException::class);
        $this->expectExceptionMessage('paramètres invalides');

        $this->registre->invoquer(
            'inspecter_code',
            ['motif' => 'env', 'dossier' => 'storage/../.'],
            $this->contexte(),
        );
    }

    /* ── lire_extrait_code ──────────────────────────────────────── */

    public function test_lire_extrait_code_restitue_des_lignes_numerotees(): void
    {
        $resultat = $this->registre->invoquer(
            'lire_extrait_code',
            ['chemin' => 'app/Models/User.php', 'depuis' => 1, 'lignes' => 5],
            $this->contexte(),
        );

        $this->assertSame('app/Models/User.php', $resultat['chemin']);
        $this->assertCount(5, $resultat['lignes']);
        $this->assertSame(1, (int) array_key_first($resultat['lignes']));
    }

    public function test_l_env_et_la_traversee_sont_refuses(): void
    {
        $this->expectException(OutilRefuseException::class);

        $this->registre->invoquer('lire_extrait_code', ['chemin' => '.env', 'depuis' => 1, 'lignes' => 5], $this->contexte());
    }

    /* ── proposer_patch : stockage, jamais application ──────────── */

    public function test_la_proposition_est_stockee_sans_toucher_aux_fichiers(): void
    {
        $avant = file_get_contents(base_path('app/Models/User.php'));
        $empreinteAvant = hash_file('sha256', base_path('app/Models/User.php'));

        $resultat = $this->registre->invoquer(
            'proposer_patch',
            [
                'description' => 'Add comment about matricule guard',
                'cibles' => ['app/Models/User.php'],
                'diff' => "--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -1,2 +1,3 @@\n x\n+y\n",
            ],
            $this->contexte(),
        );

        $this->assertTrue($resultat['fichier_modifie'] === false);
        $this->assertSame($empreinteAvant, hash_file('sha256', base_path('app/Models/User.php')), 'Aucun fichier modifié.');
        $this->assertSame($avant, file_get_contents(base_path('app/Models/User.php')));

        $demande = AiApprobation::where('identifiant', $resultat['reference'])->sole();
        $this->assertSame(AiApprobation::ETAT_PENDING, $demande->etat);
        $this->assertSame(64, mb_strlen($demande->empreinte), 'Empreinte sha256 enregistrée.');
        $this->assertNotSame(0, $demande->expire_le->timestamp);
    }

    public function test_le_anti_rejeu_refuse_une_demande_identique_en_cours(): void
    {
        $this->proposen(7);

        $this->expectException(ActionRefuseeException::class);
        $this->expectExceptionMessage('anti-rejeu');

        $this->proposen(15); // même action + cibles + diff → empreinte égale.
    }

    /* ── Workflow : 4 yeux, expiration, intégrité ───────────────── */

    public function test_le_demandeur_ne_peut_pas_decider_lui_meme(): void
    {
        $demande = $this->proposen(7);

        $this->expectException(ActionRefuseeException::class);
        $this->expectExceptionMessage('quatre yeux');

        app(GestionnaireApprobations::class)->decider($demande, 7, true);
    }

    public function test_la_decideur_autorise_puis_le_prochaine_decision_est_refusee(): void
    {
        $demande = $this->proposen(7);

        app(GestionnaireApprobations::class)->decider($demande, 9, true);

        $this->assertSame(AiApprobation::ETAT_APPROVED, $demande->fresh()->etat);
        $this->assertNotSame(7, (int) $demande->fresh()->approbateur_id, 'Le demandeur n\'est jamais l\'approbateur.');

        $this->expectException(ActionRefuseeException::class);
        app(GestionnaireApprobations::class)->decider($demande->fresh(), 22, false);
    }

    public function test_la_demande_expiree_est_marquee_et_refusee(): void
    {
        $demande = $this->proposen(7);

        $this->travelTo(now()->addDays(2));

        $this->expectException(ActionRefuseeException::class);
        $this->expectExceptionMessage('expirée');

        try {
            app(GestionnaireApprobations::class)->decider($demande, 9, true);
        } finally {
            $this->assertSame(AiApprobation::ETAT_EXPIRED, $demande->fresh()->etat);
        }
    }

    public function test_une_falsification_des_champs_signes_est_detectee(): void
    {
        $demande = $this->proposen(7);

        // Falsification simulée : cibles réécrites en base en
        // contournant le service (raw update → json_encode manuel).
        AiApprobation::where('identifiant', $demande->identifiant)
            ->update(['cibles' => json_encode(['app/Models/Amiante.php'])]);

        $demande->refresh();

        $this->expectException(AiAssistantException::class);
        $this->expectExceptionMessage('empreinte_approbation_invalide');

        app(GestionnaireApprobations::class)->decider($demande, 9, true);
    }

    public function test_l_execution_de_patch_est_refusee_par_construction(): void
    {
        $demande = $this->proposen(7);
        app(GestionnaireApprobations::class)->decider($demande, 9, true);

        $this->expectException(ActionRefuseeException::class);
        $this->expectExceptionMessage('jamais');

        app(GestionnaireApprobations::class)->executer($demande->fresh(), $this->contexte());
    }

    /* ── Lot 8 : outil métier (dossier étudiant masqué) ──────────── */

    public function test_le_dossier_etudiant_est_consulte_avec_pii_masquee(): void
    {
        Etudiant::create([
            'cin' => 'TEST4401', 'nom' => 'Ben Ali', 'prenom' => 'Sarra', 'sexe' => 'F',
            'email' => 'sarra.benali@etu.utunis.tn', 'telephone' => '+21660000001',
            'date_naissance' => '2003-05-11', 'faculte_id' => $this->faculteA->id,
            'niveau' => 'L2', 'annee_academique' => '2025/2026',
            'identifiant_national' => '12345678', 'adresse' => '12 rue du Lac',
            'ville' => 'Tunis', 'code_postal' => '1002', 'annee_bac' => '2022',
            'mention_bac' => 'Bien', 'password' => 'Secret@123',
        ]);

        $resultat = $this->registre->invoquer(
            'consulter_dossier_etudiant',
            ['cin' => 'TEST4401'],
            $this->contexte('agent_scolarite', 'metier'),
        );

        $this->assertTrue($resultat['trouve']);
        $this->assertSame('******01', $resultat['cin_masque']);
        $this->assertSame('sa'.'**********'.'@etu.utunis.tn', $resultat['email_masque']);
        $this->assertSame('Ben Ali', $resultat['nom']);
        $this->assertArrayNotHasKey('password', $resultat);
        $this->assertArrayNotHasKey('date_naissance', $resultat);
        $this->assertArrayNotHasKey('adresse', $resultat);
        $this->assertArrayNotHasKey('identifiant_national', $resultat, 'Registre national MENS : jamais restitue.');
    }

    public function test_le_perimetre_facultaire_est_borne_par_le_contexte(): void
    {
        Etudiant::create([
            'cin' => 'TEST7702', 'nom' => 'Trabelsi', 'prenom' => 'Amine', 'sexe' => 'M',
            'email' => 'amine.t@etu.utunis.tn', 'telephone' => '+21660000002',
            'date_naissance' => '2003-09-30', 'faculte_id' => $this->faculteA->id, 'niveau' => 'L1',
            'annee_academique' => '2025/2026', 'adresse' => '5 av. Habib', 'ville' => 'Tunis',
            'code_postal' => '1001', 'annee_bac' => '2022', 'mention_bac' => 'Passable',
            'password' => 'Secret@123',
        ]);

        $horsFaculte = $this->registre->invoquer(
            'consulter_dossier_etudiant',
            ['cin' => 'TEST7702'],
            new ContexteExecution(7, 'agent_scolarite', 'metier', $this->faculteB->id),
        );
        $this->assertFalse($horsFaculte['trouve'], 'Un agent non central ne voit que sa faculté.');

        $dansFaculte = $this->registre->invoquer(
            'consulter_dossier_etudiant',
            ['cin' => 'TEST7702'],
            new ContexteExecution(7, 'agent_scolarite', 'metier', $this->faculteA->id),
        );
        $this->assertTrue($dansFaculte['trouve']);
    }

    public function test_le_role_consultation_n_a_pas_l_outil_metier(): void
    {
        $this->expectException(OutilRefuseException::class);
        $this->expectExceptionMessage('permission absente');

        $this->registre->invoquer(
            'consulter_dossier_etudiant',
            ['cin' => 'TEST7702'],
            $this->contexte('consultation', 'metier'),
        );
    }

    public function test_un_cin_malforme_est_refuse_par_le_validateur(): void
    {
        $this->expectException(OutilRefuseException::class);
        $this->expectExceptionMessage('paramètres invalides');

        $this->registre->invoquer(
            'consulter_dossier_etudiant',
            ['cin' => 'TEST4401; DROP TABLE etudiants;--'],
            $this->contexte('agent_scolarite', 'metier'),
        );
    }
}
