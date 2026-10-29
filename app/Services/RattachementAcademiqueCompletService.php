<?php

namespace App\Services;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Schedule\Models\Group;
use Illuminate\Support\Facades\Log;

/**
 * Service central d'harmonisation et de rattachement académique complet.
 *
 * Associe de façon cohérente, déterministe et conforme LMD :
 * - pour chaque étudiant : un parcours (programme LMD), un groupe et un niveau référentiel
 * - pour chaque groupe : un programme et un niveau référentiel
 * - pour chaque inscription académique : le programme et le niveau de l'année concernée
 */
class RattachementAcademiqueCompletService
{
    /**
     * Spécifications des parcours de référence pour chaque faculté.
     */
    private const SPEC_PARCOURS = [
        1 => [
            'code' => 'DEMO-LGL',
            'nom' => 'Licence Génie Logiciel (démo)',
            'type_code' => 'LIC',
            'duree' => 3,
            'niveaux' => [
                'L1' => ['ordre' => 1, 'nom' => 'Première année Licence Génie Logiciel'],
                'L2' => ['ordre' => 2, 'nom' => 'Deuxième année Licence Génie Logiciel'],
                'L3' => ['ordre' => 3, 'nom' => 'Troisième année Licence Génie Logiciel'],
                'M1' => ['ordre' => 4, 'nom' => 'Master 1 Informatique & Génie Logiciel'],
            ],
        ],
        2 => [
            'code' => 'PAR-FDEG-DEG',
            'nom' => 'Licence en Droit, Économie et Gestion',
            'type_code' => 'LIC',
            'duree' => 3,
            'niveaux' => [
                'L1' => ['ordre' => 1, 'nom' => 'Première année Licence Droit-Économie-Gestion'],
                'L2' => ['ordre' => 2, 'nom' => 'Deuxième année Licence Droit-Économie-Gestion'],
                'L3' => ['ordre' => 3, 'nom' => 'Troisième année Licence Droit-Économie-Gestion'],
            ],
        ],
        3 => [
            'code' => 'PAR-FMP-MED',
            'nom' => 'Diplôme d\'Études Médicales et Pharmaceutiques',
            'type_code' => 'LIC',
            'duree' => 3,
            'niveaux' => [
                'L1' => ['ordre' => 1, 'nom' => 'Première année Médecine & Pharmacie'],
                'L2' => ['ordre' => 2, 'nom' => 'Deuxième année Médecine & Pharmacie'],
                'L3' => ['ordre' => 3, 'nom' => 'Troisième année Médecine & Pharmacie'],
            ],
        ],
        4 => [
            'code' => 'PAR-FLSH-LSH',
            'nom' => 'Licence en Lettres, Langues et Sciences Humaines',
            'type_code' => 'LIC',
            'duree' => 3,
            'niveaux' => [
                'L1' => ['ordre' => 1, 'nom' => 'Première année Licence Lettres & Sciences Humaines'],
                'L2' => ['ordre' => 2, 'nom' => 'Deuxième année Licence Lettres & Sciences Humaines'],
                'L3' => ['ordre' => 3, 'nom' => 'Troisième année Licence Lettres & Sciences Humaines'],
            ],
        ],
        5 => [
            'code' => 'PAR-FSIT-ING',
            'nom' => 'Cycle Sciences de l\'Ingénieur et Technologies',
            'type_code' => 'ING',
            'duree' => 5,
            'niveaux' => [
                'L1' => ['ordre' => 1, 'nom' => 'Première année Ingénieur / Licence SIT'],
                'L2' => ['ordre' => 2, 'nom' => 'Deuxième année Ingénieur / Licence SIT'],
                'L3' => ['ordre' => 3, 'nom' => 'Troisième année Ingénieur / Licence SIT'],
                'M1' => ['ordre' => 4, 'nom' => 'Master 1 Sciences de l\'Ingénieur'],
                'D1' => ['ordre' => 5, 'nom' => 'Doctorat 1 Sciences de l\'Ingénieur'],
            ],
        ],
        6 => [
            'code' => 'PAR-FSE-EDU',
            'nom' => 'Licence en Sciences de l\'Éducation et Formation',
            'type_code' => 'LIC',
            'duree' => 3,
            'niveaux' => [
                'L1' => ['ordre' => 1, 'nom' => 'Première année Sciences de l\'Éducation'],
                'L2' => ['ordre' => 2, 'nom' => 'Deuxième année Sciences de l\'Éducation'],
                'L3' => ['ordre' => 3, 'nom' => 'Troisième année Sciences de l\'Éducation'],
                'M2' => ['ordre' => 4, 'nom' => 'Master 2 Sciences de l\'Éducation'],
            ],
        ],
        7 => [
            'code' => 'PAR-FMD-DENT',
            'nom' => 'Diplôme d\'Études en Médecine Dentaire',
            'type_code' => 'LIC',
            'duree' => 3,
            'niveaux' => [
                'L1' => ['ordre' => 1, 'nom' => 'Première année Médecine Dentaire'],
                'L2' => ['ordre' => 2, 'nom' => 'Deuxième année Médecine Dentaire'],
                'L3' => ['ordre' => 3, 'nom' => 'Troisième année Médecine Dentaire'],
                'M2' => ['ordre' => 4, 'nom' => 'Master 2 Médecine Dentaire'],
            ],
        ],
    ];

    /**
     * Audite l'état complet de la base de données concernant les étudiants, parcours et affectations.
     *
     * @return array<string, mixed>
     */
    public function auditerEtatActuel(?int $faculteId = null): array
    {
        $queryEtudiants = Etudiant::query()->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId));
        $queryInscriptions = InscriptionAcademique::query()->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($sq) => $sq->where('faculte_id', $faculteId)));
        $queryGroupes = Group::query()->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId));

        $totalEtudiants = (clone $queryEtudiants)->count();
        $sansGroupe = (clone $queryEtudiants)->whereNull('groupe_id')->count();
        $sansProgramme = (clone $queryEtudiants)->whereNull('programme_id')->count();
        $sansNiveau = (clone $queryEtudiants)->whereNull('niveau_id')->count();

        $repartitionFacultes = (clone $queryEtudiants)
            ->groupBy('faculte_id')
            ->selectRaw('faculte_id, count(*) as total')
            ->get()
            ->map(function ($row) {
                $f = Faculte::find($row->faculte_id);

                return [
                    'faculte_id' => $row->faculte_id,
                    'code' => $f?->code ?? ('FAC-'.$row->faculte_id),
                    'nom' => $f?->nom ?? 'Faculté inconnue',
                    'total' => $row->total,
                ];
            })->all();

        $repartitionNiveaux = (clone $queryEtudiants)
            ->groupBy('niveau')
            ->selectRaw('niveau, count(*) as total')
            ->orderBy('niveau')
            ->pluck('total', 'niveau')
            ->all();

        $inscriptionsTotales = (clone $queryInscriptions)->count();
        $inscriptionsSansProgramme = (clone $queryInscriptions)->whereNull('programme_id')->count();
        $inscriptionsSansNiveau = (clone $queryInscriptions)->whereNull('niveau_id')->count();

        $groupesTotaux = (clone $queryGroupes)->count();
        $groupesSansProgramme = (clone $queryGroupes)->whereNull('programme_id')->count();
        $groupesSansNiveau = (clone $queryGroupes)->whereNull('niveau_id')->count();

        $programmes = Programme::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->withCount(['niveaux', 'etudiants'])
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'code' => $p->code,
                'nom' => $p->nom,
                'faculte_id' => $p->faculte_id,
                'nb_niveaux' => $p->niveaux_count,
                'nb_etudiants' => $p->etudiants_count,
            ])->all();

        return [
            'total_etudiants' => $totalEtudiants,
            'sans_groupe' => $sansGroupe,
            'sans_programme' => $sansProgramme,
            'sans_niveau' => $sansNiveau,
            'repartition_facultes' => $repartitionFacultes,
            'repartition_niveaux' => $repartitionNiveaux,
            'inscriptions_totales' => $inscriptionsTotales,
            'inscriptions_sans_programme' => $inscriptionsSansProgramme,
            'inscriptions_sans_niveau' => $inscriptionsSansNiveau,
            'groupes_totaux' => $groupesTotaux,
            'groupes_sans_programme' => $groupesSansProgramme,
            'groupes_sans_niveau' => $groupesSansNiveau,
            'programmes' => $programmes,
        ];
    }

    /**
     * Initialise et garantit l'existence des programmes LMD et niveaux référentiels pour les facultés cibles.
     *
     * @return array<int, array{programme: Programme, niveaux: array<string, Niveau>}>
     */
    public function initialiserReferentielParcours(?int $faculteId = null, bool $force = false): array
    {
        $typesDiplomes = TypeDiplome::all()->keyBy('code');
        $resultats = [];

        foreach (self::SPEC_PARCOURS as $facId => $spec) {
            if ($faculteId !== null && $faculteId !== $facId) {
                continue;
            }

            $faculte = Faculte::find($facId);
            if (! $faculte) {
                continue;
            }

            $typeDiplome = $typesDiplomes->get($spec['type_code']) ?? $typesDiplomes->first();

            // Recherche ou instanciation du programme
            $programme = Programme::where('faculte_id', $facId)
                ->where('code', $spec['code'])
                ->first();

            if (! $programme) {
                // Pour la faculté 1, on cherche si le code DEMO-LGL existe déjà
                if ($facId === 1) {
                    $programme = Programme::where('code', 'DEMO-LGL')->first();
                }
            }

            if (! $programme) {
                $programme = new Programme;
                $programme->faculte_id = $facId;
                $programme->code = $spec['code'];
                $programme->nom = $spec['nom'];
                $programme->type_diplome_id = $typeDiplome?->id;
                $programme->duree_annees = $spec['duree'];
                $programme->semestres_nombre = $spec['duree'] * 2;
                $programme->credits_requis = $spec['duree'] * 60;
                $programme->mode = 'initiale';
                $programme->actif = true;
                $programme->statut = 'actif';

                if ($force) {
                    $programme->save();
                } else {
                    $programme->id = 9900 + $facId;
                }
            }

            // Création ou complétion des niveaux référentiels du programme
            $niveauxProgram = [];
            foreach ($spec['niveaux'] as $codeNiveau => $metaNiveau) {
                $niveau = null;
                if ($programme->id && $force) {
                    $niveau = Niveau::where('programme_id', $programme->id)
                        ->where('code', $codeNiveau)
                        ->first();
                }

                if (! $niveau) {
                    $niveau = new Niveau;
                    $niveau->programme_id = $programme->id ?? 0;
                    $niveau->code = $codeNiveau;
                    $niveau->nom = $metaNiveau['nom'];
                    $niveau->ordre = $metaNiveau['ordre'];
                    $niveau->semestres_nombre = 2;
                    $niveau->credits_attendus = 60;
                    $niveau->actif = true;

                    if ($force && $programme->id) {
                        $niveau->save();
                    } else {
                        $niveau->id = 99000 + ($facId * 10) + ($metaNiveau['ordre'] ?? 1);
                    }
                }

                $niveauxProgram[$codeNiveau] = $niveau;
            }

            $resultats[$facId] = [
                'programme' => $programme,
                'niveaux' => $niveauxProgram,
            ];
        }

        return $resultats;
    }

    /**
     * Synchronise les 160 groupes pour les relier à leur programme et niveau référentiels,
     * et crée les groupes spécifiques requis pour les niveaux avancés (M1, M2, D1).
     *
     * @param  array<int, array{programme: Programme, niveaux: array<string, Niveau>}>  $referentiel
     * @return array{groupes_maj: int, groupes_crees: int}
     */
    public function synchroniserGroupesReferentiel(array $referentiel, ?int $faculteId = null, bool $force = false): array
    {
        $groupesMaj = 0;
        $groupesCrees = 0;

        $groupes = Group::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();

        foreach ($groupes as $groupe) {
            $facId = (int) $groupe->faculte_id;
            if (! isset($referentiel[$facId])) {
                continue;
            }

            $programme = $referentiel[$facId]['programme'];
            $niveauStr = (string) $groupe->niveau;
            $niveau = $referentiel[$facId]['niveaux'][$niveauStr] ?? null;

            $doitMettreAJour = false;

            if ($groupe->programme_id !== $programme->id) {
                $groupe->programme_id = $programme->id;
                $doitMettreAJour = true;
            }

            if ($niveau && $groupe->niveau_id !== $niveau->id) {
                $groupe->niveau_id = $niveau->id;
                $doitMettreAJour = true;
            }

            if (empty($groupe->filiere)) {
                $groupe->filiere = $programme->nom;
                $doitMettreAJour = true;
            }

            if ($doitMettreAJour) {
                if ($force) {
                    $groupe->save();
                }
                $groupesMaj++;
            }
        }

        // Création des groupes pour les étudiants des niveaux spécifiques (M1, M2, D1)
        $groupesSpecifiques = [
            1 => ['M1' => 'M1-INFO-A'],
            5 => ['M1' => 'M1-ING-A', 'D1' => 'D1-ING-A'],
            6 => ['M2' => 'M2-EDU-A'],
            7 => ['M2' => 'M2-DENT-A'],
        ];

        foreach ($groupesSpecifiques as $facId => $specNiveaux) {
            if ($faculteId !== null && $faculteId !== $facId) {
                continue;
            }

            if (! isset($referentiel[$facId])) {
                continue;
            }

            $programme = $referentiel[$facId]['programme'];

            foreach ($specNiveaux as $codeNiveau => $codeGroupe) {
                $existant = Group::where('faculte_id', $facId)->where('code', $codeGroupe)->first();
                if (! $existant) {
                    $niveauObj = $referentiel[$facId]['niveaux'][$codeNiveau] ?? null;

                    $nouveauGroupe = new Group;
                    $nouveauGroupe->code = $codeGroupe;
                    $nouveauGroupe->nom = $codeGroupe.' — '.($programme->nom ?? 'Programme');
                    $nouveauGroupe->faculte_id = $facId;
                    $nouveauGroupe->programme_id = $programme->id ?? null;
                    $nouveauGroupe->niveau_id = $niveauObj?->id;
                    $nouveauGroupe->niveau = $codeNiveau;
                    $nouveauGroupe->filiere = $programme->nom;
                    $nouveauGroupe->actif = true;

                    if ($force) {
                        $nouveauGroupe->save();
                    }
                    $groupesCrees++;
                }
            }
        }

        return [
            'groupes_maj' => $groupesMaj,
            'groupes_crees' => $groupesCrees,
        ];
    }

    /**
     * Affecte les étudiants orphelins (sans groupe) à un groupe de leur faculté et niveau.
     *
     * @return array{etudiants_affectes: int}
     */
    public function affecterEtudiantsSansGroupe(?int $faculteId = null, bool $force = false): int
    {
        $etudiantsSansGroupe = Etudiant::query()
            ->whereNull('groupe_id')
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->get();

        $affectes = 0;

        foreach ($etudiantsSansGroupe as $etudiant) {
            $facId = (int) $etudiant->faculte_id;
            $niveauStr = (string) $etudiant->niveau;

            // Recherche des groupes disponibles pour cette faculté et ce niveau
            $groupeCandidat = Group::query()
                ->where('faculte_id', $facId)
                ->where('niveau', $niveauStr)
                ->withCount('etudiants')
                ->orderBy('etudiants_count', 'asc')
                ->first();

            if (! $groupeCandidat) {
                // Fallback : n'importe quel groupe actif de la même faculté
                $groupeCandidat = Group::where('faculte_id', $facId)->first();
            }

            if ($groupeCandidat) {
                $etudiant->groupe_id = $groupeCandidat->id;

                if ($force) {
                    $etudiant->save();

                    $anneeId = $etudiant->inscriptionsAcademiques()->latest('id')->value('annee_universitaire_id')
                        ?? AnneeUniversitaire::where('active', true)->value('id')
                        ?? 7;

                    // Traçabilité dans affectations_groupe si non présente
                    AffectationGroupe::firstOrCreate([
                        'etudiant_id' => $etudiant->id,
                        'groupe_id' => $groupeCandidat->id,
                        'annee_universitaire_id' => $anneeId,
                    ], [
                        'statut' => 'active',
                        'date_debut' => now()->toDateString(),
                        'motif' => 'Harmonisation automatique LMD',
                    ]);
                }

                $affectes++;
            }
        }

        return $affectes;
    }

    /**
     * Associe pour CHAQUE étudiant son programme, son niveau référentiel et son groupe,
     * et répercute la cohérence sur toutes ses inscriptions académiques.
     *
     * @param  array<int, array{programme: Programme, niveaux: array<string, Niveau>}>  $referentiel
     * @return array{
     *     total_traites: int,
     *     etudiants_maj: int,
     *     inscriptions_maj: int
     * }
     */
    public function associerEtudiantsParcoursEtNiveaux(array $referentiel, ?int $faculteId = null, bool $force = false): array
    {
        $etudiants = Etudiant::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->with(['groupe', 'inscriptionsAcademiques'])
            ->get();

        $etudiantsMaj = 0;
        $inscriptionsMaj = 0;

        foreach ($etudiants as $etudiant) {
            $facId = (int) $etudiant->faculte_id;
            if (! isset($referentiel[$facId])) {
                continue;
            }

            $programme = $referentiel[$facId]['programme'];
            $niveauStr = (string) $etudiant->niveau;
            $niveau = $referentiel[$facId]['niveaux'][$niveauStr] ?? null;

            $etudiantModifie = false;

            // 1. Rattachement au programme LMD de la faculté
            if ($programme && $etudiant->programme_id !== $programme->id) {
                $etudiant->programme_id = $programme->id;
                $etudiantModifie = true;
            }

            // 2. Rattachement au niveau référentiel
            if ($niveau && $etudiant->niveau_id !== $niveau->id) {
                $etudiant->niveau_id = $niveau->id;
                $etudiantModifie = true;
            }

            // 3. Sauvegarde étudiant
            if ($etudiantModifie) {
                if ($force) {
                    $etudiant->save();
                }
                $etudiantsMaj++;
            }

            // 4. Synchronisation de ses inscriptions académiques
            $groupeCode = $etudiant->groupe?->code;

            foreach ($etudiant->inscriptionsAcademiques as $inscription) {
                $inscrNiveauStr = (string) $inscription->niveau;
                $inscrNiveau = $referentiel[$facId]['niveaux'][$inscrNiveauStr] ?? $niveau;

                $inscrModifiee = false;

                if ($programme && $inscription->programme_id !== $programme->id) {
                    $inscription->programme_id = $programme->id;
                    $inscrModifiee = true;
                }

                if ($inscrNiveau && $inscription->niveau_id !== $inscrNiveau->id) {
                    $inscription->niveau_id = $inscrNiveau->id;
                    $inscrModifiee = true;
                }

                if (empty($inscription->filiere) && $programme) {
                    $inscription->filiere = $programme->nom;
                    $inscrModifiee = true;
                }

                if (empty($inscription->groupe) && $groupeCode) {
                    $inscription->groupe = $groupeCode;
                    $inscrModifiee = true;
                }

                if ($inscrModifiee) {
                    if ($force) {
                        $inscription->save();
                    }
                    $inscriptionsMaj++;
                }
            }
        }

        return [
            'total_traites' => $etudiants->count(),
            'etudiants_maj' => $etudiantsMaj,
            'inscriptions_maj' => $inscriptionsMaj,
        ];
    }

    /**
     * Exécute le processus complet d'harmonisation avec rapport avant / après.
     *
     * @return array{
     *     force: bool,
     *     audit_avant: array<string, mixed>,
     *     audit_apres: array<string, mixed>,
     *     resume_actions: array<string, int>
     * }
     */
    public function executerHarmonisation(?int $faculteId = null, bool $force = false): array
    {
        $auditAvant = $this->auditerEtatActuel($faculteId);

        $referentiel = $this->initialiserReferentielParcours($faculteId, $force);
        $resGroupes = $this->synchroniserGroupesReferentiel($referentiel, $faculteId, $force);
        $affectesSansGroupe = $this->affecterEtudiantsSansGroupe($faculteId, $force);
        $resEtudiants = $this->associerEtudiantsParcoursEtNiveaux($referentiel, $faculteId, $force);

        $auditApres = $this->auditerEtatActuel($faculteId);

        if ($force) {
            Log::info('Harmonisation complète des parcours et groupes étudiants exécutée.', [
                'etudiants_maj' => $resEtudiants['etudiants_maj'],
                'affectes_sans_groupe' => $affectesSansGroupe,
                'inscriptions_maj' => $resEtudiants['inscriptions_maj'],
                'groupes_maj' => $resGroupes['groupes_maj'],
            ]);
        }

        return [
            'force' => $force,
            'audit_avant' => $auditAvant,
            'audit_apres' => $auditApres,
            'resume_actions' => [
                'groupes_maj' => $resGroupes['groupes_maj'],
                'groupes_crees' => $resGroupes['groupes_crees'],
                'etudiants_sans_groupe_affectes' => $affectesSansGroupe,
                'etudiants_maj' => $resEtudiants['etudiants_maj'],
                'inscriptions_maj' => $resEtudiants['inscriptions_maj'],
            ],
        ];
    }
}
