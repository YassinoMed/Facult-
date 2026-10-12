<?php

namespace Database\Seeders;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Dataset FSEGS (docs/testing/fsegs-dataset.md) : ~5 000 étudiants
 * réalistes et fictifs, relationnellement cohérents (faculté →
 * programmes → niveaux → groupes → étudiants → inscriptions →
 * affectations via le VRAI moteur, jamais de boucle modulo).
 *
 * Idempotent (clés naturelles : codes, CIN, paires inscription,
 * triplet affectation) ; déterministe (seed config) ; bulk inserts
 * par lots de 500 ; transaction globale avec rollback.
 */
class FsegsDatasetSeeder extends Seeder
{
    private const NOMS = [
        'Ben Ammar', 'Trabelsi', 'Ben Salah', 'Gharbi', 'Haddad', 'Bouazizi', 'Mansouri', 'Khelifi',
        'Ayari', 'Dridi', 'Fetoui', 'Guesmi', 'Hammami', 'Jendoubi', 'Karray', 'Louati',
        'Mbarek', 'Nasri', 'Oueslati', 'Rebai', 'Saidi', 'Tlili', 'Zoghlami', 'Amri',
        'Belhaj', 'Cherif', 'Dahmani', 'Essid', 'Farhat', 'Guiga', 'Hichri', 'Jaziri',
        'Kacem', 'Ltaief', 'Mzoughi', 'Nabli', 'Othmani', 'Riahi', 'Sassi', 'Tounsi',
        'Zidi', 'Abidi', 'Baccouche', 'Chaabane', 'Damak', 'Elmi', 'Feki', 'Ghorbel',
        'Hamdi', 'Issaoui', 'Kefi', 'Lahmar', 'Masmoudi', 'Nouira', 'Ouertani', 'Rzig',
        'Sfaxi', 'Trifi', 'Zribi', 'Aydi', 'Boulares', 'Chebbi',
    ];

    private const PRENOMS_M = [
        'Mohamed', 'Ahmed', 'Yassine', 'Omar', 'Karim', 'Bilal', 'Hamza', 'Anas',
        'Rayen', 'Aziz', 'Slim', 'Fares', 'Nour', 'Rami', 'Skander', 'Wassim',
        'Ghazi', 'Hatem', 'Ilyes', 'Jasser', 'Khalil', 'Lotfi', 'Mahdi', 'Nizar',
        'Oussama', 'Riyadh', 'Seif', 'Taha', 'Walid', 'Zied', 'Ayoub', 'Dhaker',
        'Elyes', 'Fedi', 'Ghaith', 'Houssem', 'Iskander', 'Jawher', 'Kais', 'Louay',
    ];

    private const PRENOMS_F = [
        'Mariem', 'Amira', 'Sarra', 'Nourhane', 'Rim', 'Siwar', 'Yasmine', 'Ines',
        'Khadija', 'Olfa', 'Rania', 'Sana', 'Takwa', 'Wiem', 'Zayneb', 'Aya',
        'Baligha', 'Chaima', 'Donia', 'Emna', 'Farah', 'Ghofrane', 'Hiba', 'Ichrak',
        'Jihene', 'Kawther', 'Lina', 'Manel', 'Nada', 'Oumaima', 'Rahma', 'Salma',
        'Tasnim', 'Yosra', 'Zeineb', 'Aicha', 'Beya', 'Cyrine', 'Dorsaf', 'Eya',
    ];

    private const VILLES = [
        'Tunis', 'Sfax', 'Sousse', 'Gabès', 'Bizerte', 'Nabeul', 'Monastir', 'Kairouan',
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('FsegsDatasetSeeder ignoré (environnement non local).');

            return;
        }

        mt_srand((int) config('fsegs-dataset.seed', 20261001));
        $charge = max(1, (int) config('fsegs-dataset.charge', 1));

        DB::transaction(function () use ($charge) {
            $faculte = $this->faculte();
            $annee = $this->annee();
            $referentiel = $this->referentiel($faculte);
            $groupes = $this->groupes($faculte, $annee, $referentiel, $charge);
            $etudiants = $this->etudiants($faculte, $referentiel, $groupes, $charge);
            $this->inscriptions($annee, $referentiel, $etudiants);
            $stats = $this->affectations($annee, $groupes, $etudiants);
            $this->scenariosSpeciaux($faculte, $annee, $groupes);
            $this->rapport($faculte, $annee, $referentiel, $groupes, $stats);
        });
    }

    private function faculte(): Faculte
    {
        $config = config('fsegs-dataset.faculte');

        return Faculte::firstOrCreate(
            ['code' => $config['code']],
            ['nom' => $config['nom'], 'description' => 'Dataset de test FSEGS.', 'active' => true]
        );
    }

    private function annee(): AnneeUniversitaire
    {
        $libelle = (string) config('fsegs-dataset.annee');

        return AnneeUniversitaire::firstOrCreate(
            ['libelle' => $libelle],
            ['date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true]
        );
    }

    /**
     * @return array<string, array{niveau: array<string, Niveau>, programme: Programme}>
     */
    private function referentiel(Faculte $faculte): array
    {
        $resultat = [];

        foreach (config('fsegs-dataset.formations') as $cle => $formation) {
            $programme = Programme::firstOrCreate(
                ['code' => 'FSEGS-'.$formation['code'], 'faculte_id' => $faculte->id],
                [
                    'nom' => $formation['nom'], 'duree_annees' => 3,
                    'semestres_nombre' => 6, 'credits_requis' => 180, 'actif' => true,
                ]
            );

            $niveaux = [];

            foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $code => $ordre) {
                $niveaux[$code] = Niveau::firstOrCreate(
                    ['programme_id' => $programme->id, 'code' => $code],
                    ['nom' => $formation['nom'].' — '.$ordre.'ère année', 'ordre' => $ordre, 'actif' => true]
                );
            }

            $resultat[$cle] = ['programme' => $programme, 'niveaux' => $niveaux];
        }

        return $resultat;
    }

    /**
     * @return array<string, array<int, Group>>
     */
    private function groupes(Faculte $faculte, AnneeUniversitaire $annee, array $referentiel, int $charge): array
    {
        $parCohorte = [];

        foreach (config('fsegs-dataset.cohortes') as $index => $cohorte) {
            $nombre = (int) ceil($cohorte['groupes'] * $charge);
            $amplitude = $cohorte['cap_max'] - $cohorte['cap_min'] + 1;
            $groupes = [];

            for ($i = 1; $i <= $nombre; $i++) {
                $capacite = $cohorte['cap_min'] + (($i * 7 + $index * 3) % $amplitude);

                $groupes[] = Group::firstOrCreate(
                    ['code' => $cohorte['prefixe'].sprintf('%02d', $i)],
                    [
                        'nom' => 'Groupe '.$cohorte['prefixe'].sprintf('%02d', $i),
                        'faculte_id' => $faculte->id,
                        'niveau' => $cohorte['niveau'],
                        'filiere' => $referentiel[$cohorte['formation']]['programme']->nom,
                        'programme_id' => $referentiel[$cohorte['formation']]['programme']->id,
                        'niveau_id' => $referentiel[$cohorte['formation']]['niveaux'][$cohorte['niveau']]->id,
                        'annee_universitaire_id' => $annee->id,
                        'capacite' => $capacite,
                        'actif' => true,
                    ]
                );
            }

            $parCohorte[$cohorte['formation'].'-'.$cohorte['niveau']] = $groupes;
        }

        return $parCohorte;
    }

    /**
     * @return array<string, array<int, int>> ids étudiants par cohorte.
     */
    private function etudiants(Faculte $faculte, array $referentiel, array $groupes, int $charge): array
    {
        $existant = Etudiant::where('cin', 'like', 'FSEG%')->count();
        $compteur = $existant;
        $hash = Hash::make('Etudiant@123');
        $maintenant = now();
        $parCohorte = [];

        foreach (config('fsegs-dataset.cohortes') as $cohorte) {
            $cle = $cohorte['formation'].'-'.$cohorte['niveau'];
            $capaciteTotale = array_sum(array_map(fn (Group $g) => (int) $g->capacite, $groupes[$cle]));
            // Jamais au-delà de la capacité totale (§8).
            $cible = (int) min($cohorte['etudiants'] * $charge, $capaciteTotale);
            $deja = Etudiant::where('cin', 'like', 'FSEG%')
                ->where('faculte_id', $faculte->id)
                ->where('programme_id', $referentiel[$cohorte['formation']]['programme']->id)
                ->where('niveau', $cohorte['niveau'])
                ->count();
            $aCreer = max(0, $cible - $deja);
            $ids = Etudiant::where('cin', 'like', 'FSEG%')
                ->where('faculte_id', $faculte->id)
                ->where('programme_id', $referentiel[$cohorte['formation']]['programme']->id)
                ->where('niveau', $cohorte['niveau'])
                ->orderBy('id')
                ->pluck('id')
                ->all();

            for ($lot = 0; $lot * 500 < $aCreer; $lot++) {
                $lignes = [];
                $debut = $lot * 500 + 1;
                $fin = min($debut + 499, $aCreer);

                for ($k = $debut; $k <= $fin; $k++) {
                    $compteur++;
                    $n = $compteur;
                    $feminin = $n % 2 === 0;
                    $lignes[] = $this->ligneEtudiant($n, $feminin, $cohorte, $faculte, $referentiel, $hash, $maintenant);
                    $ids[] = -$n; // Marqueur : remplacé par les vrais ids après insert.
                }

                $cins = array_column($lignes, 'cin');
                $doublons = Etudiant::whereIn('cin', $cins)->pluck('cin')->all();

                if ($doublons !== []) {
                    $lignes = array_values(array_filter($lignes, fn ($l) => ! in_array($l['cin'], $doublons, true)));
                }

                if ($lignes !== []) {
                    DB::table('etudiants')->insert($lignes);
                    $nouveaux = Etudiant::whereIn('cin', array_column($lignes, 'cin'))->orderBy('id')->pluck('id')->all();
                    array_splice($ids, count($ids) - count($lignes), count($lignes), $nouveaux);
                } else {
                    array_splice($ids, count($ids) - ($fin - $debut + 1));
                }
            }

            $parCohorte[$cle] = array_values(array_filter($ids, fn ($id) => $id > 0));
        }

        return $parCohorte;
    }

    /**
     * @param  array<string, mixed>  $cohorte
     * @return array<string, mixed>
     */
    private function ligneEtudiant(int $n, bool $feminin, array $cohorte, Faculte $faculte, array $referentiel, string $hash, mixed $maintenant): array
    {
        $noms = self::NOMS;
        $prenoms = $feminin ? self::PRENOMS_F : self::PRENOMS_M;
        $nom = $noms[($n * 37) % count($noms)];
        $prenom = $prenoms[($n * 53) % count($prenoms)];
        $baseAge = ['L1' => 2006, 'L2' => 2005, 'L3' => 2004][$cohorte['niveau']];
        // Quelques reprises plus âgées (cas réels, §39).
        $anneeNaissance = $n % 97 === 0 ? $baseAge - mt_rand(4, 9) : $baseAge - ($n % 5);

        return [
            'cin' => sprintf('FSEG%06d', $n),
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => sprintf('etudiant%05d@%s', $n, config('fsegs-dataset.email_domaine')),
            'telephone' => sprintf('+216%02d%06d', 20 + ($n % 75), $n % 1000000),
            'date_naissance' => sprintf('%04d-%02d-%02d', $anneeNaissance, 1 + ($n % 12), 1 + ($n % 28)),
            'sexe' => $feminin ? 'F' : 'M',
            'faculte_id' => $faculte->id,
            'programme_id' => $referentiel[$cohorte['formation']]['programme']->id,
            'niveau_id' => $referentiel[$cohorte['formation']]['niveaux'][$cohorte['niveau']]->id,
            'niveau' => $cohorte['niveau'],
            'annee_academique' => '2026-2027',
            'adresse' => sprintf('%d rue %s', 1 + ($n % 180), $noms[($n * 11) % count($noms)]),
            'ville' => self::VILLES[$n % count(self::VILLES)],
            'code_postal' => sprintf('%04d', 1000 + ($n % 8000)),
            'annee_bac' => (string) ($anneeNaissance + 18),
            'password' => $hash,
            'statut' => 'valide',
            'num_inscription' => sprintf('FSEGS2026%05d', $n),
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ];
    }

    /**
     * @param  array<string, array<int, int>>  $etudiants
     */
    private function inscriptions(AnneeUniversitaire $annee, array $referentiel, array $etudiants): void
    {
        foreach (config('fsegs-dataset.cohortes') as $cohorte) {
            $cle = $cohorte['formation'].'-'.$cohorte['niveau'];
            $ids = $etudiants[$cle] ?? [];

            if ($ids === []) {
                continue;
            }

            $existantes = InscriptionAcademique::where('annee_universitaire_id', $annee->id)
                ->whereIn('etudiant_id', $ids)->pluck('etudiant_id')->all();
            $lignes = [];

            foreach ($ids as $position => $etudiantId) {
                if (in_array($etudiantId, $existantes, true)) {
                    continue;
                }

                // Cas D (5 %, i%20==4) : contrainte réelle — la moitié en
                // inscription ajournée, l'autre suspendue (créée après).
                $contrainte = $position % 20 === 4;
                $lignes[] = [
                    'etudiant_id' => $etudiantId,
                    'annee_universitaire_id' => $annee->id,
                    'programme_id' => $referentiel[$cohorte['formation']]['programme']->id,
                    'niveau_id' => $referentiel[$cohorte['formation']]['niveaux'][$cohorte['niveau']]->id,
                    'niveau' => $cohorte['niveau'],
                    'filiere' => $referentiel[$cohorte['formation']]['programme']->nom,
                    'statut' => $contrainte && $position % 40 === 4 ? 'ajourne' : ($position % 5 === 0 ? 'valide' : 'en_cours'),
                    'type' => 'premiere',
                    'regime' => 'initiale',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($lignes) >= 500) {
                    DB::table('inscriptions_academiques')->insert($lignes);
                    $lignes = [];
                }
            }

            if ($lignes !== []) {
                DB::table('inscriptions_academiques')->insert($lignes);
            }

            // Suspensions (2e moitié du cas D) + redoublements (§40).
            foreach ($ids as $position => $etudiantId) {
                if ($position % 40 === 24) {
                    DB::table('situations_etudiant')->updateOrInsert(
                        ['etudiant_id' => $etudiantId, 'type' => 'suspension', 'date_effet' => now()->subMonth()->toDateString()],
                        ['motif' => 'Seed FSEGS : cas disciplinaire.', 'date_fin' => now()->addYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()]
                    );
                }

                if ($position % 97 === 0) {
                    $precedente = AnneeUniversitaire::firstOrCreate(
                        ['libelle' => '2025-2026'],
                        ['date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false]
                    );
                    InscriptionAcademique::firstOrCreate(
                        ['etudiant_id' => $etudiantId, 'annee_universitaire_id' => $precedente->id],
                        [
                            'programme_id' => $referentiel[$cohorte['formation']]['programme']->id,
                            'niveau' => $cohorte['niveau'], 'filiere' => $referentiel[$cohorte['formation']]['programme']->nom,
                            'statut' => 'valide', 'type' => 'reinscription', 'regime' => 'initiale',
                        ]
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, array<int, Group>>  $groupes
     * @param  array<string, array<int, int>>  $etudiants
     * @return array{affectes: int, verrouilles: int}
     */
    private function affectations(AnneeUniversitaire $annee, array $groupes, array $etudiants): array
    {
        $moteur = app(AffectationService::class);
        $affectes = 0;
        $verrouilles = 0;

        foreach (config('fsegs-dataset.cohortes') as $cohorte) {
            $cle = $cohorte['formation'].'-'.$cohorte['niveau'];
            $ids = $etudiants[$cle] ?? [];
            $liste = $groupes[$cle] ?? [];

            if ($ids === [] || $liste === []) {
                continue;
            }

            // 15 % affectés (i%20<3) + 5 % verrouillés (i%20==3), remplis
            // groupe par groupe via le VRAI moteur : premiers groupes
            // pleins, derniers partiels → déséquilibre testable (§19).
            $candidats = [];

            foreach ($ids as $position => $etudiantId) {
                $modulo = $position % 20;

                if ($modulo < 3) {
                    $candidats[] = ['id' => $etudiantId, 'verrou' => false];
                } elseif ($modulo === 3) {
                    $candidats[] = ['id' => $etudiantId, 'verrou' => true];
                }
            }

            $curseur = 0;

            foreach ($liste as $rang => $groupe) {
                // Dernier groupe : remplissage partiel (~60 %) pour
                // garantir un cas de rééquilibrage.
                $places = $rang === count($liste) - 1
                    ? (int) floor(((int) $groupe->capacite) * 0.6)
                    : (int) $groupe->capacite;

                for ($p = 0; $p < $places && $curseur < count($candidats); $p++) {
                    $candidat = $candidats[$curseur++];

                    $deja = AffectationGroupe::where('etudiant_id', $candidat['id'])
                        ->where('annee_universitaire_id', $annee->id)
                        ->where('statut', 'active')->exists();

                    if ($deja) {
                        continue;
                    }

                    try {
                        $etudiant = Etudiant::find($candidat['id']);

                        if ($etudiant === null) {
                            continue;
                        }

                        $affectation = $moteur->affecter($etudiant, $groupe, $annee, null, 'Seed FSEGS : pré-affectation.');
                        $affectes++;

                        if ($candidat['verrou']) {
                            $affectation->update(['verrouille' => true]);
                            $verrouilles++;
                        }
                    } catch (\DomainException) {
                        // Contrainte réelle (suspendu, inscription…) :
                        // l'étudiant reste non affecté, cas testable.
                        continue;
                    }
                }
            }

            $this->command?->info("  {$cle} : ".count($candidats).' candidats pré-affectés.');
        }

        return ['affectes' => $affectes, 'verrouilles' => $verrouilles];
    }

    private function scenariosSpeciaux(Faculte $faculte, AnneeUniversitaire $annee, array $groupes): void
    {
        $tousGroupes = collect($groupes)->flatten()->all();
        $premier = $tousGroupes[0] ?? null;

        $speciaux = [
            // [cin, nom, attributs étudiants, inscription ?, suspension ?, verrou ?]
            ['TEST-AFFECT-001', 'SansInscription', ['niveau' => 'L1'], false, false, false],
            ['TEST-AFFECT-002', 'Suspendu', ['niveau' => 'L1'], true, true, false],
            ['TEST-AFFECT-003', 'Verrouille', ['niveau' => 'L1'], true, false, true],
            ['TEST-AFFECT-004', 'SansGroupeCompatible', ['niveau' => 'D1'], true, false, false],
            ['TEST-AFFECT-005', 'LibreG1', ['niveau' => 'L1'], true, false, false],
            ['TEST-AFFECT-006', 'LibreG2', ['niveau' => 'L1'], true, false, false],
            ['TEST-AFFECT-007', 'LibreG3', ['niveau' => 'L1'], true, false, false],
            ['TEST-AFFECT-008', 'CandidatSwapA', ['niveau' => 'L1'], true, false, false],
            ['TEST-AFFECT-009', 'CandidatSwapB', ['niveau' => 'L1'], true, false, false],
            ['TEST-AFFECT-010', 'CandidatMove', ['niveau' => 'L1'], true, false, false],
            ['TEST-AFFECT-011', 'Redoublant', ['niveau' => 'L1'], true, false, false],
            ['TEST-AFFECT-012', 'Etranger', ['niveau' => 'L1'], true, false, false],
        ];

        foreach ($speciaux as [$cin, $nom, $attrs, $avecInscription, $suspendu, $verrou]) {
            $etudiant = Etudiant::firstOrCreate(
                ['cin' => $cin],
                array_merge([
                    'nom' => $nom, 'prenom' => 'Test', 'email' => strtolower($cin).'@fsegs.example',
                    'telephone' => '+21620000000', 'date_naissance' => '2004-06-15', 'sexe' => 'M',
                    'faculte_id' => $faculte->id, 'annee_academique' => '2026-2027',
                    'adresse' => '1 rue des Tests', 'ville' => 'Tunis', 'code_postal' => '1000',
                    'annee_bac' => '2024', 'password' => Hash::make('Etudiant@123'),
                    'statut' => 'valide', 'num_inscription' => $cin,
                ], $attrs)
            );

            if ($avecInscription) {
                InscriptionAcademique::firstOrCreate(
                    ['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id],
                    ['niveau' => $attrs['niveau'], 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale']
                );
            }

            if ($suspendu) {
                DB::table('situations_etudiant')->updateOrInsert(
                    ['etudiant_id' => $etudiant->id, 'type' => 'suspension', 'date_effet' => now()->subMonth()->toDateString()],
                    ['motif' => 'Seed FSEGS : scénario.', 'created_at' => now(), 'updated_at' => now()]
                );
            }

            if ($verrou && $premier !== null) {
                // Groupe avec places libres (jamais de dépassement,
                // même pour un scénario : la capacité reste dure).
                $cible = collect($tousGroupes)->first(
                    fn (Group $g) => $g->niveau === $attrs['niveau']
                        && ($g->capacite === null || $g->effectifActuel() < (int) $g->capacite)
                ) ?? $premier;

                if ($cible === null) {
                    continue;
                }

                $affectation = AffectationGroupe::firstOrCreate(
                    ['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id, 'statut' => 'active'],
                    ['groupe_id' => $cible->id, 'date_debut' => now()->toDateString(), 'motif' => 'Seed FSEGS : scénario verrouillé.']
                );
                $affectation->update(['verrouille' => true]);
                $etudiant->update(['groupe_id' => $cible->id]);
            }
        }
    }

    /**
     * @param  array<string, array<int, Group>>  $groupes
     * @param  array{affectes: int, verrouilles: int}  $stats
     */
    private function rapport(Faculte $faculte, AnneeUniversitaire $annee, array $referentiel, array $groupes, array $stats): void
    {
        $info = fn (string $message) => $this->command?->info($message);
        $info('========================================');
        $info('FSEGS DATASET');
        $info('========================================');
        $info("Faculté : {$faculte->code}");
        $info("Année : {$annee->libelle}");

        $totalEtudiants = 0;
        $totalGroupes = 0;
        $info('Formation                  Niveau    Étudiants    Groupes    Affectés');
        $info('-----------------------------------------------------------------------');

        foreach (config('fsegs-dataset.cohortes') as $cohorte) {
            $cle = $cohorte['formation'].'-'.$cohorte['niveau'];
            $nbEtudiants = Etudiant::where('faculte_id', $faculte->id)
                ->where('programme_id', $referentiel[$cohorte['formation']]['programme']->id)
                ->where('niveau', $cohorte['niveau'])->count();
            $nbAffectes = AffectationGroupe::where('annee_universitaire_id', $annee->id)
                ->where('statut', 'active')
                ->whereIn('groupe_id', collect($groupes[$cle] ?? [])->map(fn (Group $g) => $g->id)->all())->count();
            $totalEtudiants += $nbEtudiants;
            $totalGroupes += count($groupes[$cle] ?? []);
            $info(sprintf(
                '%-27s %-8s %-11d %-9d %d',
                $referentiel[$cohorte['formation']]['programme']->nom, $cohorte['niveau'], $nbEtudiants, count($groupes[$cle] ?? []), $nbAffectes
            ));
        }

        $info('-----------------------------------------------------------------------');
        $info("Étudiants : {$totalEtudiants} | Groupes : {$totalGroupes}");
        $info("Affectations : {$stats['affectes']} | Verrouillées : {$stats['verrouilles']}");
        $info('========================================');
    }
}
