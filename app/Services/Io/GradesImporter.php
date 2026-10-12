<?php

namespace App\Services\Io;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Services\AcademicCalculationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Import des notes — règles identiques à la saisie interne (§38) :
 * étudiant existant (CIN), matière existante (code), année et niveau
 * cohérents, semestre 1|2, note 0-20, session normale/rattrapage.
 *
 * Idempotence (§50) : unicité naturelle (semestre, matière, session) —
 * ré-envoyer la même note ne crée jamais de doublon.
 * Après import, les moyennes sont recalculées via le service officiel.
 */
class GradesImporter
{
    public function __construct(
        private readonly AcademicCalculationService $calculs,
    ) {}

    private const REGLES = [
        'matricule' => ['required_without:cin', 'regex:/^MAT-[0-9A-Z\-]{3,30}$/'],
        'cin' => ['required_without:matricule', 'regex:/^[A-Z0-9]{5,30}$/'],
        'code_matiere' => ['required_without:matiere', 'string', 'max:20'],
        'matiere' => ['required_without:code_matiere', 'string', 'max:20'],
        'annee_universitaire' => ['required_without:annee', 'regex:/^\d{4}-\d{4}$/'],
        'annee' => ['required_without:annee_universitaire', 'regex:/^\d{4}-\d{4}$/'],
        'niveau' => ['nullable', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
        'semestre' => ['required', 'integer', 'in:1,2'],
        'note' => ['required', 'numeric', 'min:0', 'max:20'],
        'session' => ['nullable', 'in:normale,rattrapage'],
        'observation' => ['nullable', 'string', 'max:500'],
    ];

    public function valider(array $lignes): ImportPreview
    {
        $valides = [];
        $erreurs = [];
        $doublons = [];
        $vusDansLeFichier = [];

        // Résolutions en une passe (anti N+1) — matricule (modèle §92) ET CIN (variante)
        $etudiants = Etudiant::pluck('id', 'cin');
        $matricules = Etudiant::whereHas('dossierAcademique')->with('dossierAcademique')->get()
            ->keyBy(fn ($e) => $e->dossierAcademique->matricule);
        $matieres = Matiere::get()->keyBy(fn ($m) => $m->code);
        $annees = AnneeUniversitaire::pluck('id', 'libelle');
        $inscriptions = InscriptionAcademique::with('semestres')->get();

        foreach ($lignes as $index => $ligne) {
            $numero = $index + 2;
            $donnees = $this->normaliser($ligne);

            $validateur = Validator::make($donnees, self::REGLES);

            if ($validateur->fails()) {
                foreach ($validateur->errors()->messages() as $champ => $messages) {
                    $erreurs[] = ['ligne' => $numero, 'champ' => $champ, 'message' => $messages[0]];
                }

                continue;
            }

            // Résolution étudiant : matricule (modèle officiel §92) ou CIN (variante)
            $etudiant = ! empty($donnees['matricule'])
                ? ($matricules[$donnees['matricule']] ?? null)
                : ($etudiants[$donnees['cin']] ?? null);

            if ($etudiant === null) {
                $identifiant = $donnees['matricule'] ?? $donnees['cin'] ?? '?';
                $erreurs[] = ['ligne' => $numero, 'champ' => 'matricule', 'message' => "Étudiant « {$identifiant} » introuvable."];

                continue;
            }
            $etudiantId = $etudiant instanceof Etudiant ? $etudiant->id : (int) $etudiant;

            // Résolution matière : code_matiere (modèle officiel) ou matiere (variante)
            $codeMatiere = mb_strtoupper($donnees['code_matiere'] ?? $donnees['matiere'] ?? '');
            $matiere = $matieres[$codeMatiere] ?? null;
            if ($matiere === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'code_matiere', 'message' => "Matière « {$codeMatiere} » introuvable."];

                continue;
            }

            // Cohérence matière/niveau/semestre (§38) — niveau facultatif avec matricule
            $niveau = $donnees['niveau'] ?? null;
            if ($niveau !== null && ($matiere->niveau !== $niveau || (int) $matiere->semestre !== (int) $donnees['semestre'])) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'code_matiere', 'message' => "La matière {$matiere->code} n'est pas enseignée en {$niveau} S{$donnees['semestre']}."];

                continue;
            }

            // Résolution année : annee_universitaire (modèle officiel) ou annee (variante)
            $libelleAnnee = $donnees['annee_universitaire'] ?? $donnees['annee'] ?? '';
            $anneeId = $annees[$libelleAnnee] ?? null;
            if ($anneeId === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'annee_universitaire', 'message' => "Année universitaire « {$libelleAnnee} » introuvable."];

                continue;
            }

            // Cohérence inscription (étudiant + année [+ niveau s'il est fourni])
            $inscription = $inscriptions->first(function ($i) use ($etudiantId, $anneeId, $niveau) {
                if ((int) $i->etudiant_id !== (int) $etudiantId || (int) $i->annee_universitaire_id !== (int) $anneeId) {
                    return false;
                }

                return $niveau === null || $i->niveau === $niveau;
            });

            if ($inscription === null) {
                $identifiant = $donnees['matricule'] ?? $donnees['cin'] ?? '?';
                $erreurs[] = ['ligne' => $numero, 'champ' => 'annee_universitaire', 'message' => "Aucune inscription en {$libelleAnnee} pour « {$identifiant} »".($niveau !== null ? " ({$niveau})" : '').'.'];

                continue;
            }

            $semestre = $inscription->semestres->firstWhere('numero', (int) $donnees['semestre']);
            if ($semestre === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'semestre', 'message' => "Semestre S{$donnees['semestre']} introuvable pour cette inscription."];

                continue;
            }

            // Idempotence : note déjà saisie pour (semestre, matière, session)
            $dejaPresente = Note::where('semestre_id', $semestre->id)
                ->where('matiere_id', $matiere->id)
                ->where('session', $donnees['session'])
                ->exists();

            $cleInterne = implode('|', [$semestre->id, $matiere->id, $donnees['session']]);

            if ($dejaPresente) {
                $doublons[] = "Ligne {$numero} : {$matiere->code} déjà notée ({$donnees['session']}) — ignorée.";

                continue;
            }

            if (in_array($cleInterne, $vusDansLeFichier, true)) {
                $doublons[] = "Ligne {$numero} : doublon interne au fichier ({$matiere->code} {$donnees['session']}).";

                continue;
            }

            $vusDansLeFichier[] = $cleInterne;
            $donnees['_semestre_id'] = $semestre->id;
            $donnees['_inscription_id'] = $inscription->id;
            $donnees['_etudiant_id'] = $etudiantId;
            $donnees['_matiere_id'] = $matiere->id;
            $donnees['_coefficient'] = $matiere->coefficient;
            $donnees['_credits'] = $matiere->credits;
            $valides[] = $donnees;
        }

        return new ImportPreview($valides, $erreurs, $doublons);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array{importes: int, ignores: int, en_erreur: int, erreurs: array<int, string>}
     */
    public function importer(array $lignes, string $mode): array
    {
        $preview = $this->valider($lignes);

        $erreurs = array_map(fn ($e) => "Ligne {$e['ligne']} ({$e['champ']}) : {$e['message']}", $preview->erreurs);

        if ($preview->valides() === 0) {
            return ['importes' => 0, 'ignores' => $preview->doublons(), 'en_erreur' => $preview->enErreur(), 'erreurs' => $erreurs];
        }

        $importes = 0;
        $ignores = $preview->doublons();

        DB::transaction(function () use ($preview, $mode, &$importes, &$ignores, &$erreurs) {
            $inscriptionsATraiter = [];

            foreach ($preview->lignesValides as $ligne) {
                try {
                    Note::create([
                        'etudiant_id' => $ligne['_etudiant_id'],
                        'inscription_academique_id' => $ligne['_inscription_id'],
                        'semestre_id' => $ligne['_semestre_id'],
                        'matiere_id' => $ligne['_matiere_id'],
                        'note' => $ligne['note'],
                        'coefficient' => $ligne['_coefficient'],
                        'credits' => $ligne['_credits'],
                        'session' => $ligne['session'],
                        'observation' => $ligne['observation'] ?? null,
                    ]);
                    $inscriptionsATraiter[$ligne['_inscription_id']] = true;
                    $importes++;
                } catch (UniqueConstraintViolationException) {
                    $ignores++;
                    $erreurs[] = "{$ligne['matiere']} ({$ligne['session']}) : insertion concurrente détectée, ligne ignorée.";
                }
            }

            // Recalcul officiel des moyennes pour chaque inscription touchée
            foreach (array_keys($inscriptionsATraiter) as $inscriptionId) {
                $this->calculs->recalculerInscription(InscriptionAcademique::findOrFail($inscriptionId));
            }

            // Mode atomique : toute erreur au milieu → rollback complet
            if ($mode === 'atomique' && $erreurs !== [] && $importes !== count($preview->lignesValides)) {
                throw new \RuntimeException('Import atomique interrompu : au moins une ligne est devenue invalide.');
            }
        });

        return ['importes' => $importes, 'ignores' => $ignores, 'en_erreur' => count($erreurs), 'erreurs' => $erreurs];
    }

    private function normaliser(array $ligne): array
    {
        $donnees = [];
        foreach ($ligne as $cle => $valeur) {
            // Les clés techniques du modèle JSON (_template, _exemple) sont ignorées (§100)
            if (str_starts_with($cle, '_')) {
                continue;
            }
            $donnees[$cle] = is_string($valeur) ? trim($valeur) : $valeur;
        }

        // Mise en forme SANS créer de clés absentes (les ?? de résolution restent fiables)
        foreach (['cin', 'matricule', 'matiere', 'code_matiere'] as $cle) {
            if (array_key_exists($cle, $donnees) && is_string($donnees[$cle])) {
                $donnees[$cle] = mb_strtoupper($donnees[$cle]);
            }
        }
        if (($donnees['session'] ?? '') === '') {
            $donnees['session'] = 'normale';
        }

        return $donnees;
    }
}
