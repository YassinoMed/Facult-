<?php

namespace App\Modules\Evaluation\Services;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationReconciliationEcart;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;

/**
 * §7.3 / §2.7 / §2.15 — harnais de CHARGE des examens.
 *
 * Le harnais s'exécute EXCLUSIVEMENT sur une base MySQL dédiée dont le nom
 * contient « _test » : aucune donnée de développement ou de production
 * n'est lue, écrite ou supprimée. Sans `EXAM_LOAD_DB_DRIVER=mysql`, la
 * commande et le test PHPUnit refusent de s'exécuter (skip explicite).
 *
 * Charges mesurées :
 *  - réconciliation tri-sources : lecture, persistance anti-doublon ;
 *  - circuit à quatre yeux : instruction puis validation distincte ;
 *  - gouvernance : soumission → certification → double approbation →
 *    publication officielle ;
 *  - lecture de la liste des dossiers d'écart.
 *
 * Le rapport contient p50/p95/p99 par opération, le taux d'erreur et le pic
 * mémoire. Le verdict compare ces mesures aux seuils env :
 * `EXAM_LOAD_P95_MS`, `EXAM_LOAD_MAX_ERREURS_PCT`, `EXAM_LOAD_MAX_MEMOIRE_MB`.
 */
class ExamChargeService
{
    /** @var array<int, array{operation: string, duree_ms: float, ok: bool, erreur: ?string}> */
    private array $mesures = [];

    /** @var array<string, mixed> */
    private array $contexte = [];

    private string $base;

    /**
     * Prépare la base dédiée, migre le schéma et exécute la charge.
     *
     * @return array<string, mixed>
     */
    public function executer(int $iterations, int $candidats): array
    {
        $iterations = max(1, $iterations);
        $candidats = max(2, $candidats);

        $debut = hrtime(true);
        $this->mesures = [];

        $infrastructure = $this->configurerBaseDediee();
        $this->preparerJeu($iterations, $candidats);

        $this->chargerReconciliation($iterations);
        $this->chargerQuatreYeux();
        $this->chargerGouvernance();
        $this->chargerLecture($iterations);

        $dureeTotale = (hrtime(true) - $debut) / 1e6;

        return $this->rapport($infrastructure, $dureeTotale, $iterations, $candidats);
    }

    /**
     * @return array{driver: string, database: string, migrations: string}
     */
    private function configurerBaseDediee(): array
    {
        $driver = (string) env('EXAM_LOAD_DB_DRIVER', '');

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new DomainException(
                'Charge NON EXÉCUTÉE : définir EXAM_LOAD_DB_DRIVER=mysql (ou mariadb) et une base dédiée '
                .'EXAM_LOAD_DB_DATABASE (nom contenant « _test »).'
            );
        }

        $defaut = (array) config('database.connections.mysql', []);
        $base = (string) env('EXAM_LOAD_DB_DATABASE', 'inscription_etudiants_load_test');
        $this->refuserBaseNonDediee($base, $defaut['database'] ?? null);

        $hote = (string) env('EXAM_LOAD_DB_HOST', $defaut['host'] ?? '127.0.0.1');
        $port = (int) env('EXAM_LOAD_DB_PORT', $defaut['port'] ?? 3306);
        $utilisateur = (string) env('EXAM_LOAD_DB_USERNAME', $defaut['username'] ?? 'root');
        $motDePasse = (string) env('EXAM_LOAD_DB_PASSWORD', $defaut['password'] ?? '');

        $pdo = new PDO(
            "mysql:host={$hote};port={$port}",
            $utilisateur,
            $motDePasse,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = null;

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $hote,
            'database.connections.mysql.port' => $port,
            'database.connections.mysql.database' => $base,
            'database.connections.mysql.username' => $utilisateur,
            'database.connections.mysql.password' => $motDePasse,
        ]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        // Base dédiée uniquement : le garde ci-dessus a déjà refusé tout
        // nom qui ne contient pas « _test ».
        Artisan::call('migrate:fresh', [
            '--database' => 'mysql',
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $migrations = trim(Artisan::output());

        $this->base = $base;

        return ['driver' => 'mysql', 'database' => $base, 'migrations' => $migrations];
    }

    private function refuserBaseNonDediee(string $base, mixed $baseDev): void
    {
        $interdites = array_filter([
            is_string($baseDev) ? $baseDev : null,
            'mysql', 'information_schema', 'performance_schema', 'sys',
        ]);

        if (in_array($base, $interdites, true) || ! str_contains($base, '_test')) {
            throw new DomainException(
                "Base de charge refusée : « {$base} » n'est pas une base dédiée de test. "
                .'Le nom doit contenir « _test » et ne jamais désigner la base de développement.'
            );
        }
    }

    private function preparerJeu(int $iterations, int $candidats): void
    {
        $faculte = Faculte::create(['nom' => 'Faculté Charge', 'code' => 'FCHG', 'active' => true]);

        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01',
            'date_fin' => '2027-08-31', 'active' => true,
        ]);

        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'P-CHG', 'nom' => 'Programme Charge',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L3', 'nom' => 'Licence 3', 'ordre' => 3,
        ]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S5', 'nom' => 'S5', 'numero' => 5, 'ordre' => 5,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-CHG', 'nom' => 'UE Charge', 'credits' => 6, 'coefficient' => 2,
        ]);
        $matiere = Matiere::create([
            'code' => 'MAT-CHG', 'nom' => 'Module Charge', 'ue_id' => $ue->id,
            'niveau' => 'L3', 'semestre' => 5, 'credits' => 4, 'actif' => true,
        ]);
        $groupe = Group::create([
            'code' => 'GRP-CHG', 'nom' => 'Groupe Charge',
            'faculte_id' => $faculte->id, 'programme_id' => $programme->id,
            'niveau_id' => $niveau->id, 'niveau' => 'L3', 'capacite' => $candidats, 'actif' => true,
        ]);
        // Capacité doublée : l'audit de gouvernance applique un espacement de
        // 50 % (ratio par défaut). Une salle trop juste ferait échouer la
        // soumission pour un motif de capacité, pas pour la charge testée.
        $salle = Room::create([
            'code' => 'SALLE-CHG', 'nom' => 'Amphi Charge', 'capacite' => $candidats * 2 + 20,
            'type' => 'amphi', 'actif' => true, 'faculte_id' => $faculte->id,
            'heure_ouverture' => '07:00:00', 'heure_fermeture' => '20:00:00',
        ]);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $annee->id, 'code' => 'S-CHG',
            'nom' => 'Session Charge', 'type' => 'principale',
            'date_debut' => '2027-06-01', 'date_fin' => '2027-07-31', 'statut' => 'open',
        ]);

        $preparateur = $this->creerUtilisateur('charge.prep@univ.test', 'admin_faculte', $faculte->id);
        $certificateur = $this->creerUtilisateur('charge.cert@univ.test', 'certificateur_qualite');
        $approbateur1 = $this->creerUtilisateur('charge.app1@univ.test', 'direction_etudes');
        $approbateur2 = $this->creerUtilisateur('charge.app2@univ.test', 'direction_etudes');
        $publicateur = $this->creerUtilisateur('charge.pub@univ.test', 'autorite_publication');
        $detecteur = $this->creerUtilisateur('charge.det@univ.test', 'admin_scolarite');
        $valideur = $this->creerUtilisateur('charge.val@univ.test', 'admin_scolarite');

        // Épreuve de réconciliation tri-sources.
        $examenReconciliation = Examen::create([
            'code' => 'CHG-EX-REC', 'session_id' => $session->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
            'semestre_lmd_id' => $semestre->id,
            'libelle' => 'Examen Charge Réconciliation', 'type' => 'examen', 'statut' => 'scheduled',
        ]);

        $epreuveReconciliation = Epreuve::create([
            'code' => 'CHG-EP-REC', 'examen_id' => $examenReconciliation->id,
            'session_id' => $session->id, 'annee_universitaire_id' => $annee->id,
            'matiere_id' => $matiere->id, 'groupe_id' => $groupe->id, 'salle_id' => $salle->id,
            'date' => '2027-06-01', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
            'duree_minutes' => 120, 'statut' => 'scheduled', 'capacite_necessaire' => $candidats,
        ]);

        $pointages = [];

        foreach (range(1, $candidats) as $i) {
            $matricule = 'CHG'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $etudiant = Etudiant::create([
                'num_inscription' => $matricule,
                'email' => strtolower($matricule).'@etudiant.univ.test',
                'nom' => 'Charge', 'prenom' => 'Etudiant'.$i,
                'cin' => 'CIN-'.$matricule,
                'date_naissance' => '2004-05-10', 'sexe' => 'M',
                'faculte_id' => $faculte->id, 'groupe_id' => $groupe->id,
                'niveau' => 'L3', 'annee_academique' => '2026-2027',
                'adresse' => 'Campus', 'ville' => 'Tunis', 'code_postal' => '1000',
                'annee_bac' => '2024',
            ]);

            // Une moitié divergente (papier présent, serveur absent), l'autre
            // concordante : le harnais exerce les deux chemins.
            $candidat = Candidat::create([
                'epreuve_id' => $epreuveReconciliation->id,
                'etudiant_id' => $etudiant->id,
                'statut' => $i % 2 === 0 ? 'absent' : 'present',
                'numero_anonyme' => 'ANON-'.$matricule,
            ]);

            IntegrationLog::tracer(
                'evaluation', 'internal', 'exam.attendance.pointed', 'completed',
                null, 200, null, null, (string) Str::uuid(), 'CAND-'.$candidat->id
            );

            $pointages[] = [
                'candidat_id' => $candidat->id,
                'statut_papier' => 'present',
                'signature_presente' => true,
            ];
        }

        // Examens de gouvernance : un par itération, jours distincts pour
        // éviter toute collision de salle.
        $examens = [];
        $teacher1 = Teacher::create([
            'nom' => 'Charge', 'prenom' => 'Prof1', 'email' => 'charge.prof1@univ.test', 'statut' => 'actif',
        ]);
        $teacher2 = Teacher::create([
            'nom' => 'Charge', 'prenom' => 'Prof2', 'email' => 'charge.prof2@univ.test', 'statut' => 'actif',
        ]);

        foreach (range(1, $iterations) as $i) {
            $jour = now()->addDays($i + 1)->startOfDay();

            $examen = Examen::create([
                'code' => 'CHG-EX-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'session_id' => $session->id, 'annee_universitaire_id' => $annee->id,
                'programme_id' => $programme->id, 'niveau_id' => $niveau->id,
                'semestre_lmd_id' => $semestre->id,
                'libelle' => 'Examen Charge '.$i, 'type' => 'examen', 'statut' => 'scheduled',
            ]);

            $epreuve = Epreuve::create([
                'code' => 'CHG-EP-'.str_pad((string) $i, 3, 0, STR_PAD_LEFT),
                'examen_id' => $examen->id, 'session_id' => $session->id,
                'annee_universitaire_id' => $annee->id, 'matiere_id' => $matiere->id,
                'groupe_id' => $groupe->id, 'salle_id' => $salle->id,
                'date' => $jour->toDateString(), 'heure_debut' => '09:00', 'heure_fin' => '11:00',
                'duree_minutes' => 120, 'statut' => 'scheduled', 'capacite_necessaire' => 30,
            ]);

            foreach ([$teacher1, $teacher2] as $teacher) {
                Surveillance::create([
                    'epreuve_id' => $epreuve->id, 'teacher_id' => $teacher->id,
                    'role' => 'principal', 'statut' => 'confirme',
                ]);
            }

            $examens[] = $examen;
        }

        $this->contexte = [
            'epreuve_reconciliation' => $epreuveReconciliation,
            'pointages' => $pointages,
            'examens' => $examens,
            'preparateur' => $preparateur,
            'certificateur' => $certificateur,
            'approbateur1' => $approbateur1,
            'approbateur2' => $approbateur2,
            'publicateur' => $publicateur,
            'detecteur' => $detecteur,
            'valideur' => $valideur,
        ];
    }

    private function creerUtilisateur(string $email, string $role, ?int $faculteId = null): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Charge '.$email,
            'email' => $email,
            'password' => 'Motdepasse@123',
            'faculte_id' => $faculteId,
        ], $role);
    }

    private function chargerReconciliation(int $iterations): void
    {
        /** @var ExamContinuiteReconciliationService $service */
        $service = app(ExamContinuiteReconciliationService::class);
        $epreuveId = (int) $this->contexte['epreuve_reconciliation']->id;
        $pointages = $this->contexte['pointages'];
        $detecteurId = (int) $this->contexte['detecteur']->id;

        for ($i = 0; $i < $iterations; $i++) {
            $resultat = null;

            $this->mesurer('reconciliation.tri_sources', function () use ($service, $epreuveId, $pointages, &$resultat): void {
                $resultat = $service->rapprocherTriSources([
                    'epreuve_id' => $epreuveId,
                    'pointages_papier' => $pointages,
                ]);
            });

            if (is_array($resultat)) {
                $this->mesurer('reconciliation.persister_ecarts', function () use ($service, $resultat, $detecteurId): void {
                    $service->persisterEcarts($resultat, $detecteurId);
                });
            }
        }
    }

    private function chargerQuatreYeux(): void
    {
        /** @var ExamContinuiteReconciliationService $service */
        $service = app(ExamContinuiteReconciliationService::class);

        $dossiers = EvaluationReconciliationEcart::query()
            ->where('statut', EvaluationReconciliationEcart::STATUT_OUVERT)
            ->orderBy('id')
            ->get();

        $instructeurId = (int) $this->contexte['detecteur']->id;
        $valideurId = (int) $this->contexte['valideur']->id;

        foreach ($dossiers as $dossier) {
            $this->mesurer('reconciliation.instruire', function () use ($service, $dossier, $instructeurId): void {
                $service->instruireEcart(
                    $dossier,
                    $instructeurId,
                    'present',
                    'Vérification de charge : émargement papier contrôlé.'
                );
            });

            $this->mesurer('reconciliation.valider', function () use ($service, $dossier, $valideurId): void {
                $service->validerEcart($dossier, $valideurId, 'Validation de charge contradictoire.');
            });
        }
    }

    private function chargerGouvernance(): void
    {
        /** @var ExamGouvernanceValidationService $service */
        $service = app(ExamGouvernanceValidationService::class);

        $preparateurId = (int) $this->contexte['preparateur']->id;
        $certificateurId = (int) $this->contexte['certificateur']->id;
        $approbateur1Id = (int) $this->contexte['approbateur1']->id;
        $approbateur2Id = (int) $this->contexte['approbateur2']->id;
        $publicateurId = (int) $this->contexte['publicateur']->id;

        foreach ($this->contexte['examens'] as $examen) {
            $validation = null;

            $this->mesurer('gouvernance.soumettre', function () use ($service, $examen, $preparateurId, &$validation): void {
                $validation = $service->soumettrePourValidation($examen, $preparateurId);
            });

            if ($validation === null) {
                continue;
            }

            $this->mesurer('gouvernance.certifier', function () use ($service, $validation, $certificateurId): void {
                $service->certifierConformite($validation, $certificateurId);
            });

            $this->mesurer('gouvernance.approuver_1', function () use ($service, $validation, $approbateur1Id): void {
                $service->approuverPremierNiveau($validation, $approbateur1Id);
            });

            $this->mesurer('gouvernance.approuver_2', function () use ($service, $validation, $approbateur2Id): void {
                $service->approuverSecondNiveau($validation, $approbateur2Id);
            });

            $this->mesurer('gouvernance.publier', function () use ($service, $validation, $publicateurId): void {
                $service->publierExamenOfficiel($validation, $publicateurId);
            });
        }
    }

    private function chargerLecture(int $iterations): void
    {
        for ($i = 0; $i < $iterations; $i++) {
            $this->mesurer('reconciliation.liste', function (): void {
                EvaluationReconciliationEcart::query()
                    ->with(['epreuve.matiere', 'candidat.etudiant', 'instructeur:id,name', 'valideur:id,name'])
                    ->orderByRaw("CASE statut WHEN 'ouvert' THEN 0 WHEN 'instruit' THEN 1 ELSE 2 END")
                    ->orderByDesc('id')
                    ->paginate(25);
            });
        }
    }

    private function mesurer(string $operation, callable $action): void
    {
        $debut = hrtime(true);
        $ok = true;
        $erreur = null;

        try {
            $action();
        } catch (\Throwable $e) {
            $ok = false;
            $erreur = $e::class.': '.$e->getMessage();
        }

        $this->mesures[] = [
            'operation' => $operation,
            'duree_ms' => round((hrtime(true) - $debut) / 1e6, 2),
            'ok' => $ok,
            'erreur' => $erreur,
        ];
    }

    /**
     * @param  array{driver: string, database: string, migrations: string}  $infrastructure
     * @return array<string, mixed>
     */
    private function rapport(array $infrastructure, float $dureeTotale, int $iterations, int $candidats): array
    {
        $seuils = [
            'p95_ms' => (float) env('EXAM_LOAD_P95_MS', 1500),
            'taux_erreur_pct' => (float) env('EXAM_LOAD_MAX_ERREURS_PCT', 0.0),
            'memoire_mb' => (float) env('EXAM_LOAD_MAX_MEMOIRE_MB', 512),
        ];

        $durees = array_column($this->mesures, 'duree_ms');
        $erreurs = array_values(array_filter($this->mesures, fn (array $m): bool => ! $m['ok']));
        $p95Global = $this->percentile($durees, 95);

        $operations = [];
        foreach (array_unique(array_column($this->mesures, 'operation')) as $operation) {
            $echantillons = array_values(array_filter(
                $this->mesures,
                fn (array $m): bool => $m['operation'] === $operation
            ));
            $valeurs = array_column($echantillons, 'duree_ms');
            $erreursOperation = count(array_filter($echantillons, fn (array $m): bool => ! $m['ok']));

            $operations[] = [
                'operation' => $operation,
                'executions' => count($echantillons),
                'erreurs' => $erreursOperation,
                'p50_ms' => $this->percentile($valeurs, 50),
                'p95_ms' => $this->percentile($valeurs, 95),
                'p99_ms' => $this->percentile($valeurs, 99),
                'max_ms' => round(max($valeurs), 2),
                'moyenne_ms' => round(array_sum($valeurs) / count($valeurs), 2),
            ];
        }

        usort($operations, fn (array $a, array $b): int => $b['p95_ms'] <=> $a['p95_ms']);

        $total = count($this->mesures);
        $tauxErreur = $total === 0 ? 100.0 : round(count($erreurs) / $total * 100, 2);
        $memoireMb = round(memory_get_peak_usage(true) / 1048576, 2);

        $raisons = [];
        if ($total === 0) {
            $raisons[] = 'Aucune mesure exécutée.';
        }
        if ($p95Global > $seuils['p95_ms']) {
            $raisons[] = "p95 global {$p95Global} ms > seuil {$seuils['p95_ms']} ms.";
        }
        if ($tauxErreur > $seuils['taux_erreur_pct']) {
            $raisons[] = "Taux d'erreur {$tauxErreur} % > seuil {$seuils['taux_erreur_pct']} %.";
        }
        if ($memoireMb > $seuils['memoire_mb']) {
            $raisons[] = "Pic mémoire {$memoireMb} Mo > seuil {$seuils['memoire_mb']} Mo.";
        }

        return [
            'infrastructure' => $infrastructure,
            'parametres' => ['iterations' => $iterations, 'candidats' => $candidats],
            'duree_totale_ms' => round($dureeTotale, 2),
            'executions' => $total,
            'erreurs' => count($erreurs),
            'taux_erreur_pct' => $tauxErreur,
            'p50_ms' => $this->percentile($durees, 50),
            'p95_ms' => $p95Global,
            'p99_ms' => $this->percentile($durees, 99),
            'memoire_pic_mb' => $memoireMb,
            'seuils' => $seuils,
            'respecte' => $raisons === [],
            'raisons' => $raisons,
            'operations' => $operations,
            'detail_erreurs' => array_slice(array_map(
                fn (array $m): array => ['operation' => $m['operation'], 'erreur' => $m['erreur']],
                $erreurs
            ), 0, 20),
        ];
    }

    /**
     * @param  array<int, float>  $valeurs
     */
    private function percentile(array $valeurs, float $centile): float
    {
        if ($valeurs === []) {
            return 0.0;
        }

        sort($valeurs);
        $index = (int) ceil($centile / 100 * count($valeurs)) - 1;
        $index = max(0, min($index, count($valeurs) - 1));

        return round((float) $valeurs[$index], 2);
    }
}
