<?php

namespace App\Modules\AiAssistant\Support;

use App\Models\Etudiant;
use App\Modules\AiAssistant\Data\ContexteExecution;
use App\Modules\AiAssistant\Exceptions\ActionRefuseeException;
use App\Modules\AiAssistant\Models\AiApprobation;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Enregistreur des outils du module (Lots 7/8/9) — appelé au boot du
 * provider. RÈGLES :
 *   - lecture seule ;
 *   - le sandbox et le registre font TOUTES les vérifications serveur
 *     (permission, mode, paramètres, périmètre) ;
 *   - `proposer_patch` ENREGISTRE une approbation humaine (4 yeux) et
 *     NE MODIFIE AUCUN FICHIER : l'exécution de correctifs est refusée
 *     par construction dans GestionnaireApprobations.
 */
final class EnregistreurOutils
{
    /** Dossiers inspectables par l'agent développeur (liste CLOSE). */
    public const DOSSIERS_CODE = ['app', 'routes'];

    public static function enregistrer(RegistreOutils $registre): void
    {
        $registre->enregistre(
            nom: 'inspecter_code',
            description: 'Liste les fichiers de code du projet (chemins + métadonnées, jAMAIS le contenu).',
            permission: PermissionsAi::DEVELOPPEUR,
            mode: 'developpeur',
            executeur: self::inspecteur(),
            parametresValides: function (array $parametres): bool {
                $motif = $parametres['motif'] ?? null;
                $dossier = $parametres['dossier'] ?? 'app';

                return is_string($motif) && mb_strlen(trim($motif)) >= 2 && mb_strlen($motif) <= 60
                    && is_string($dossier) && self::dossierAutorise($dossier);
            },
        );

        $registre->enregistre(
            nom: 'lire_extrait_code',
            description: 'Extrait borné (60 lignes max) et numéroté d\'un fichier de code autorisé.',
            permission: PermissionsAi::DEVELOPPEUR,
            mode: 'developpeur',
            executeur: function (array $parametres, ContexteExecution $contexte): array {
                return SandboxFichier::lireExtrait(
                    (string) ($parametres['chemin'] ?? ''),
                    (int) ($parametres['depuis'] ?? 1),
                    (int) ($parametres['lignes'] ?? 60),
                );
            },
            parametresValides: function (array $parametres): bool {
                $depuis = $parametres['depuis'] ?? 1;
                $lignes = $parametres['lignes'] ?? 60;

                return is_string($parametres['chemin'] ?? null) && $parametres['chemin'] !== ''
                    && is_int($depuis) && $depuis >= 1 && $depuis <= 10000
                    && is_int($lignes) && $lignes >= 1 && $lignes <= SandboxFichier::LIGNES_MAX;
            },
        );

        $registre->enregistre(
            nom: 'proposer_patch',
            description: 'Enregistre une proposition de correctif (diff unifié) pour approbation humaine. NE MODIFIE RIEN.',
            permission: PermissionsAi::DEVELOPPEUR,
            mode: 'developpeur',
            executeur: self::propositif(),
            parametresValides: function (array $parametres): bool {
                $description = $parametres['description'] ?? '';
                $diff = $parametres['diff'] ?? '';
                $cibles = $parametres['cibles'] ?? [];

                return is_string($description) && mb_strlen($description) >= 5 && mb_strlen($description) <= 255
                    && is_string($diff) && mb_strlen($diff) >= 10
                    && mb_strlen($diff) <= (int) config('ai-assistant.approbations.taille_diff_max', 8000)
                    && is_array($cibles) && count($cibles) >= 1
                    && count($cibles) <= (int) config('ai-assistant.approbations.cibles_max', 5)
                    && ! in_array(false, array_map('is_string', $cibles), true);
            },
        );
        $registre->enregistre(
            nom: 'consulter_dossier_etudiant',
            description: 'Consulte le dossier étudiant (statuts d\'inscription) avec CIN/email/téléphone masqués.',
            permission: PermissionsAi::METIER,
            mode: 'metier',
            executeur: self::consulteurDossier(),
            parametresValides: function (array $parametres): bool {
                $cin = $parametres['cin'] ?? '';

                return is_string($cin) && preg_match('/^[a-zA-Z0-9]{6,12}$/', $cin) === 1;
            },
        );
    }

    /* ── Exécuteurs ─────────────────────────────────────────────── */

    /**
     * Consultation métier (Lot 8) : RÉUTILISE le modèle Etudiant
     * existant (aucun service dupliqué), scopé faculté côté serveur
     * via le contexte, et restitue des IDENTIFIANTS TOUJOURS MASQUÉS.
     */
    private static function consulteurDossier(): \Closure
    {
        return function (array $parametres, ContexteExecution $contexte): array {
            $cin = (string) $parametres['cin'];

            $requette = Etudiant::query()->where('cin', $cin);

            // Périmètre facultaire : le contexte serveur borne la
            // recherche ; faculteId null = rôle central (lecture globale).
            if ($contexte->faculteId !== null) {
                $requette->where('faculte_id', $contexte->faculteId);
            }

            $etudiant = $requette->first();

            if ($etudiant === null) {
                return ['trouve' => false, 'cin_masque' => self::masquer($cin, 2)];
            }

            $inscriptions = $etudiant->inscriptionsAcademiques()
                ->orderByDesc('id')
                ->limit(3)
                ->get()
                ->map(fn ($inscription) => [
                    'id' => $inscription->id,
                    'annee' => $inscription->annee_academique,
                    'niveau' => $inscription->niveau,
                    'statut' => $inscription->statut,
                ])
                ->all();

            return [
                'trouve' => true,
                'etudiant_id' => $etudiant->id,
                'cin_masque' => self::masquer((string) $etudiant->cin, 2),
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'email_masque' => self::masquerEmail((string) $etudiant->email),
                'domaine' => 'scolarite',
                'faculte_id' => $etudiant->faculte_id,
                'groupe_id' => $etudiant->groupe_id,
                'niveau' => $etudiant->niveau,
                'annee_academique' => $etudiant->annee_academique,
                'inscriptions' => $inscriptions,
            ];
        };
    }

    private static function masquer(string $valeur, int $queueVisible): string
    {
        $longueur = mb_strlen($valeur);

        if ($longueur <= $queueVisible) {
            return str_repeat('*', $longueur);
        }

        return str_repeat('*', $longueur - $queueVisible).mb_substr($valeur, -$queueVisible);
    }

    private static function masquerEmail(string $email): string
    {
        $arobase = strpos($email, '@');

        if ($arobase === false || $arobase === 0) {
            return '***';
        }

        $local = mb_substr($email, 0, min(2, $arobase));

        return $local.str_repeat('*', max(1, $arobase - mb_strlen($local))).mb_substr($email, $arobase);
    }

    /**
     * Recherche de fichiers : métadonnées uniquement, alpha-bêtes
     * interdits filtrés, cap sévère (20) sans contenu de fichier.
     */
    private static function inspecteur(): \Closure
    {
        return function (array $parametres, ContexteExecution $contexte): array {
            $motif = mb_strtolower(trim((string) $parametres['motif']));
            $dossier = self::normaliserDossier((string) ($parametres['dossier'] ?? 'app'));

            if ($dossier === null) {
                throw new ActionRefuseeException('inspecter_code', 'dossier hors périmètre autorisé');
            }

            $trouves = [];

            /** @var SplFileInfo $fichier */
            foreach (File::allFiles(base_path($dossier)) as $fichier) {
                $relatif = str_replace(base_path().'/', '', $fichier->getPathname());
                $nom = $fichier->getFilename();

                if (str_starts_with($nom, '.env') || str_ends_with($nom, '.env')
                    || str_ends_with($nom, '.key') || str_ends_with($nom, '.pem')
                    || str_contains($relatif, 'private')) {
                    continue;
                }

                if (str_contains(mb_strtolower($relatif), $motif) === false
                    && str_contains(mb_strtolower($nom), $motif) === false) {
                    continue;
                }

                $trouves[] = [
                    'chemin' => $relatif,
                    'taille_octets' => $fichier->getSize(),
                    'modifie_le' => date('Y-m-d H:i:s', $fichier->getMTime()),
                ];

                if (count($trouves) >= 20) {
                    break;
                }
            }

            return ['dossier' => $dossier, 'fichiers' => $trouves];
        };
    }

    /**
     * Proposition de correctif : STOCKAGE UNIQUEMENT. Tout correctif
     * reste à approbation humaine ; l'exécution est refusée ailleurs.
     */
    private static function propositif(): \Closure
    {
        return function (array $parametres, ContexteExecution $contexte): array {
            $description = trim((string) $parametres['description']);
            $diff = (string) $parametres['diff'];
            $cibles = array_values(array_slice($parametres['cibles'], 0, 5));

            foreach ($cibles as $chemin) {
                if (SandboxFichier::resoudre($chemin) === null) {
                    throw new ActionRefuseeException('proposer_patch', "cible hors périmètre : {$chemin}");
                }
            }

            /** @var AiApprobation $demande */
            $demande = app(GestionnaireApprobations::class)->soumettre(
                action: 'proposer_patch',
                description: $description,
                cibles: $cibles,
                parametres: ['diff' => $diff],
                niveauRisque: 'eleve',
                contexte: $contexte,
            );

            return [
                'reference' => $demande->identifiant,
                'etat' => $demande->etat,
                'fichier_modifie' => false,
                'message' => 'Proposition STOCKÉE pour l\'approbation 4 yeux ; aucun fichier modifié.',
            ];
        };
    }

    /* ── Utilitaires ────────────────────────────────────────────── */

    private static function dossierAutorise(string $dossier): bool
    {
        $normalise = self::normaliserDossier($dossier);

        return $normalise !== null;
    }

    /**
     * @return string|null dossier relatif normalisé, ou null si hors liste
     */
    private static function normaliserDossier(string $dossier): ?string
    {
        $chemin = str_replace('\\', '/', trim($dossier));

        if ($chemin === '' || str_starts_with($chemin, '/')
            || preg_match('#(^|/)\.\.?(/|$)#', $chemin)) {
            return null;
        }

        foreach (self::DOSSIERS_CODE as $autorise) {
            if ($chemin === $autorise || str_starts_with($chemin, $autorise.'/')) {
                return $chemin;
            }
        }

        return null;
    }

    private static function entierDans(mixed $valeur, int $minimum, int $maximum): bool
    {
        return is_int($valeur) && $valeur >= $minimum && $valeur <= $maximum;
    }
}
