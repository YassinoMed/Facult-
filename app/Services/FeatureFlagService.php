<?php

namespace App\Services;

/**
 * DÉPLOIEMENT PROGRESSIF PAR INDICATEURS DE FONCTIONNALITÉ (LOOP 43).
 *
 * Évaluation déterministe et fail-closed :
 * - indicateur inconnu ⇒ faux ;
 * - indicateur désactivé ⇒ faux ;
 * - environnement non autorisé ⇒ faux ;
 * - contexte absent alors qu'une dimension est restreinte ⇒ faux ;
 * - pourcentage sans sujet stable ⇒ faux (aucun tirage aléatoire).
 *
 * Le service est en LECTURE SEULE : il ne persiste ni ne journalise de PII.
 * Il ne remplace aucune validation métier, réglementaire ou institutionnelle.
 */
class FeatureFlagService
{
    public const MOTIF_ACTIF = 'ACTIF';

    public const MOTIF_FLAG_INCONNU = 'FLAG_INCONNU';

    public const MOTIF_DESACTIVE = 'DESACTIVE';

    public const MOTIF_ENVIRONNEMENT = 'ENVIRONNEMENT_NON_AUTORISE';

    public const MOTIF_HORS_FACULTE = 'HORS_FACULTE';

    public const MOTIF_HORS_PROGRAMME = 'HORS_PROGRAMME';

    public const MOTIF_UTILISATEUR_HORS_LISTE = 'UTILISATEUR_HORS_LISTE';

    public const MOTIF_POURCENTAGE_SANS_SUJET = 'POURCENTAGE_SANS_SUJET';

    public const MOTIF_POURCENTAGE_NON_ATTEINT = 'POURCENTAGE_NON_ATTEINT';

    public const MOTIF_HORS_COHORTE = 'HORS_COHORTE';

    public const MOTIF_MODE_NON_AUTORISE = 'MODE_NON_AUTORISE';

    // Modes d'exécution formels (PHASE E - LOOP 43)
    public const MODE_AUDIT_ONLY = 'AUDIT_ONLY';

    public const MODE_SIMULATION = 'SIMULATION';

    public const MODE_PILOTE_CONTROLE = 'PILOTE_CONTROLE';

    public const MODE_MIGRATION_AUTORISEE = 'MIGRATION_AUTORISEE';

    public const MODES_AUTORISES_LOOP43 = [
        self::MODE_AUDIT_ONLY,
        self::MODE_SIMULATION,
    ];

    /**
     * Indicateur actif pour ce contexte ?
     *
     * @param  array{faculte_id?: int|string|null, programme_id?: int|string|null, user_id?: int|string|null, environnement?: string|null}  $contexte
     */
    public function estActif(string $flag, array $contexte = []): bool
    {
        return $this->evaluer($flag, $contexte)['actif'];
    }

    /**
     * Évaluation complète, avec motif exploitable en diagnostic.
     *
     * @param  array{faculte_id?: int|string|null, programme_id?: int|string|null, user_id?: int|string|null, environnement?: string|null}  $contexte
     * @return array{flag: string, actif: bool, motif: string}
     */
    public function evaluer(string $flag, array $contexte = []): array
    {
        $definition = config("feature_flags.flags.{$flag}");

        if (! is_array($definition)) {
            return $this->resultat($flag, false, self::MOTIF_FLAG_INCONNU);
        }

        if (($definition['enabled'] ?? false) !== true) {
            return $this->resultat($flag, false, self::MOTIF_DESACTIVE);
        }

        $environnement = $contexte['environnement'] ?? app()->environment();

        $environnementsGlobaux = $this->liste($this->configListe('feature_flags.environnements'));
        [$environnementAutorise, $motifEnvironnement] = $this->verifierEnvironnement(
            $environnement,
            $environnementsGlobaux,
            $this->liste($definition['environnements'] ?? [])
        );

        if (! $environnementAutorise) {
            return $this->resultat($flag, false, $motifEnvironnement);
        }

        [$faculteAutorisee, $motifFaculte] = $this->verifierDimension(
            $contexte['faculte_id'] ?? null,
            $this->liste($definition['facultes'] ?? []),
            self::MOTIF_HORS_FACULTE
        );

        if (! $faculteAutorisee) {
            return $this->resultat($flag, false, $motifFaculte);
        }

        [$programmeAutorise, $motifProgramme] = $this->verifierDimension(
            $contexte['programme_id'] ?? null,
            $this->liste($definition['programmes'] ?? []),
            self::MOTIF_HORS_PROGRAMME
        );

        if (! $programmeAutorise) {
            return $this->resultat($flag, false, $motifProgramme);
        }

        [$cohorteAutorisee, $motifCohorte] = $this->verifierDimension(
            $contexte['annee_universitaire_id'] ?? $contexte['cohorte'] ?? null,
            $this->liste($definition['annees'] ?? $definition['cohortes'] ?? []),
            self::MOTIF_HORS_COHORTE
        );

        if (! $cohorteAutorisee) {
            return $this->resultat($flag, false, $motifCohorte);
        }

        // Mode : seuls AUDIT_ONLY et SIMULATION sont autorisés en LOOP 43
        $modeDemande = $contexte['mode'] ?? null;
        if ($modeDemande !== null) {
            $modesAutorises = $this->liste($definition['modes'] ?? self::MODES_AUTORISES_LOOP43);
            [$modeAutorise, $motifMode] = $this->verifierDimension(
                $modeDemande,
                $modesAutorises,
                self::MOTIF_MODE_NON_AUTORISE
            );

            if (! $modeAutorise) {
                return $this->resultat($flag, false, $motifMode);
            }
        }

        [$utilisateurAutorise, $motifUtilisateur] = $this->verifierDimension(
            $contexte['user_id'] ?? null,
            $this->liste($definition['users'] ?? []),
            self::MOTIF_UTILISATEUR_HORS_LISTE
        );

        if (! $utilisateurAutorise) {
            return $this->resultat($flag, false, $motifUtilisateur);
        }

        $pourcentage = $definition['pourcentage'] ?? null;

        if ($pourcentage !== null) {
            $sujet = $contexte['user_id'] ?? null;

            if ($sujet === null || $sujet === '') {
                return $this->resultat($flag, false, self::MOTIF_POURCENTAGE_SANS_SUJET);
            }

            $pourcentage = max(0, min(100, (int) $pourcentage));

            if ($this->bucket($flag, $sujet) >= $pourcentage) {
                return $this->resultat($flag, false, self::MOTIF_POURCENTAGE_NON_ATTEINT);
            }
        }

        return $this->resultat($flag, true, self::MOTIF_ACTIF);
    }

    /**
     * Seau déterministe 0-99 pour un couple indicateur / sujet.
     * Le même sujet tombe toujours dans le même seau, quel que soit le serveur.
     */
    public function bucket(string $flag, int|string $sujet): int
    {
        return (int) (sprintf('%u', crc32($flag.'|'.$sujet)) % 100);
    }

    /**
     * Diagnostic sans PII : les listes d'identifiants sont remplacées par
     * leur cardinalité.
     *
     * @return array<string, array<string, mixed>>
     */
    public function inventaire(): array
    {
        $flags = config('feature_flags.flags', []);
        $resultat = [];

        foreach ($flags as $nom => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $resultat[$nom] = [
                'enabled' => ($definition['enabled'] ?? false) === true,
                'description' => $definition['description'] ?? null,
                'environnements' => $this->liste($definition['environnements'] ?? []),
                'facultes' => count($this->liste($definition['facultes'] ?? [])),
                'programmes' => count($this->liste($definition['programmes'] ?? [])),
                'users' => count($this->liste($definition['users'] ?? [])),
                'pourcentage' => $definition['pourcentage'] ?? null,
            ];
        }

        return $resultat;
    }

    /**
     * Journal d'activation sans PII (PHASE G - LOOP 43).
     *
     * @param  array<string, mixed>  $contexte
     * @return array<string, mixed>
     */
    public function journaliserActivation(string $flag, array $contexte, bool $actif, string $motif): array
    {
        return [
            'execution_id' => 'FF-'.bin2hex(random_bytes(6)),
            'flag' => $flag,
            'actif' => $actif,
            'motif' => $motif,
            'environnement' => $contexte['environnement'] ?? app()->environment(),
            'horodatage' => now()->toIso8601String(),
            'contexte_anonymise' => [
                'a_user' => isset($contexte['user_id']),
                'a_faculte' => isset($contexte['faculte_id']),
                'a_programme' => isset($contexte['programme_id']),
                'a_annee' => isset($contexte['annee_universitaire_id']) || isset($contexte['cohorte']),
                'mode' => $contexte['mode'] ?? self::MODE_SIMULATION,
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $global
     * @param  array<int, mixed>  $specifique
     * @return array{0: bool, 1: string}
     */
    private function verifierEnvironnement(string $environnement, array $global, array $specifique): array
    {
        if ($global !== [] && ! in_array($environnement, $global, true)) {
            return [false, self::MOTIF_ENVIRONNEMENT];
        }

        if ($specifique !== [] && ! in_array($environnement, $specifique, true)) {
            return [false, self::MOTIF_ENVIRONNEMENT];
        }

        return [true, self::MOTIF_ACTIF];
    }

    /**
     * @param  array<int, mixed>  $liste
     * @return array{0: bool, 1: string}
     */
    private function verifierDimension(int|string|null $valeur, array $liste, string $motif): array
    {
        if ($liste === []) {
            return [true, self::MOTIF_ACTIF];
        }

        if ($valeur === null || $valeur === '') {
            return [false, $motif];
        }

        foreach ($liste as $candidat) {
            if ($candidat === $valeur) {
                return [true, self::MOTIF_ACTIF];
            }

            if (is_numeric($candidat) && is_numeric($valeur) && (int) $candidat === (int) $valeur) {
                return [true, self::MOTIF_ACTIF];
            }
        }

        return [false, $motif];
    }

    /**
     * @param  array<int, mixed>  $valeurs
     * @return array<int, mixed>
     */
    private function liste(array $valeurs): array
    {
        return array_values(array_filter($valeurs, fn ($valeur) => $valeur !== null && $valeur !== ''));
    }

    /**
     * @return array<int, mixed>
     */
    private function configListe(string $cle): array
    {
        $valeur = config($cle, []);

        return is_array($valeur) ? $valeur : [];
    }

    /**
     * @return array{flag: string, actif: bool, motif: string}
     */
    private function resultat(string $flag, bool $actif, string $motif): array
    {
        return [
            'flag' => $flag,
            'actif' => $actif,
            'motif' => $motif,
        ];
    }
}
