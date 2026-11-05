<?php

namespace App\Services\Iam;

use App\Models\Iam\EvenementIam;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Journal d'audit IAM append-only (LOOP 43-BIS).
 *
 * Distinct de l'audit académique (`AuditService`) : il trace les actes de
 * GOUVERNANCE des droits, jamais des données pédagogiques. Règles :
 *
 *  - aucune mise à jour ni suppression (refus au niveau du modèle) ;
 *  - aucune donnée secrète (mots de passe, tokens, secrets, codes MFA) ;
 *  - valeurs tronquées et nettoyées récursivement avant écriture ;
 *  - chaînage SHA-256 : `empreinte = h(empreinte précédente | contenu)`,
 *    ce qui rend toute altération détectable (sans prétendre à une
 *    immuabilité physique : la base reste administrable par l'hébergeur).
 */
final class ServiceAuditIam
{
    /** Clés dont la valeur ne doit JAMAIS être journalisée. */
    private const CLES_INTERDITES = [
        'password', 'mot_de_passe', 'password_confirmation', 'token',
        'access_token', 'refresh_token', 'secret', 'mfa_secret',
        'mfa_recovery_codes', 'code_mfa', 'otp', 'authorization',
        'api_key', 'cle_api', 'remember_token', 'session_id',
    ];

    public function enregistrer(
        string $type,
        ?User $acteur,
        ?User $cible = null,
        ?string $objetType = null,
        ?int $objetId = null,
        ?array $avant = null,
        ?array $apres = null,
        ?string $adresseIp = null,
        ?string $userAgent = null,
    ): ?EvenementIam {
        if (! $this->disponible()) {
            return null;
        }

        $avant = $this->nettoyer($avant);
        $apres = $this->nettoyer($apres);

        try {
            return DB::transaction(function () use ($type, $acteur, $cible, $objetType, $objetId, $avant, $apres, $adresseIp, $userAgent): EvenementIam {
                $dernier = EvenementIam::query()->orderByDesc('id')->lockForUpdate()->first();
                $horodatage = now();

                $contenu = [
                    'type' => $type,
                    'acteur_id' => $acteur?->id,
                    'cible_user_id' => $cible?->id,
                    'objet_type' => $objetType,
                    'objet_id' => $objetId,
                    'avant' => $avant,
                    'apres' => $apres,
                    'adresse_ip' => $adresseIp,
                    'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
                    'created_at' => $horodatage->toDateTimeString(),
                ];

                $empreinte = hash('sha256', ($dernier?->empreinte ?? 'GENESE').'|'.$this->encoder($contenu));

                return EvenementIam::query()->create($contenu + ['empreinte' => $empreinte]);
            });
        } catch (Throwable) {
            // Un journal défaillant ne doit jamais casser l'opération
            // métier déjà transactionnalisée : l'échec est signalé par
            // `verifierChaine()` et les métriques du cockpit.
            return null;
        }
    }

    /**
     * Vérifie l'intégrité de la chaîne d'empreintes.
     *
     * @return array{valide: bool, total: int, premiere_rupture: int|null}
     */
    public function verifierChaine(?int $limite = null): array
    {
        if (! $this->disponible()) {
            return ['valide' => true, 'total' => 0, 'premiere_rupture' => null];
        }

        $requete = EvenementIam::query()->orderBy('id');
        if ($limite !== null) {
            $requete->limit($limite);
        }

        $precedente = 'GENESE';
        $total = 0;
        $rupture = null;

        foreach ($requete->cursor() as $evenement) {
            $total++;
            $contenu = [
                'type' => $evenement->type,
                'acteur_id' => $evenement->acteur_id,
                'cible_user_id' => $evenement->cible_user_id,
                'objet_type' => $evenement->objet_type,
                'objet_id' => $evenement->objet_id,
                'avant' => $evenement->avant,
                'apres' => $evenement->apres,
                'adresse_ip' => $evenement->adresse_ip,
                'user_agent' => $evenement->user_agent,
                'created_at' => $evenement->created_at?->toDateTimeString(),
            ];

            $attendue = hash('sha256', $precedente.'|'.$this->encoder($contenu));

            if ($evenement->empreinte !== $attendue) {
                $rupture ??= (int) $evenement->id;
            }

            $precedente = (string) $evenement->empreinte;
        }

        return ['valide' => $rupture === null, 'total' => $total, 'premiere_rupture' => $rupture];
    }

    /**
     * Journalise un refus sensible (tentative d'escalade, auto-approbation,
     * falsification de sélection envoyée par le navigateur...). Jamais
     * bloquant pour la réponse d'erreur.
     */
    public function refus(string $type, User $acteur, ?string $motif = null, ?array $contexte = null): void
    {
        $this->enregistrer(
            type: $type,
            acteur: $acteur,
            apres: ['refus' => true, 'motif' => $motif !== null ? mb_substr($motif, 0, 500) : null] + ($contexte ?? []),
            adresseIp: request()?->ip(),
            userAgent: request()?->userAgent(),
        );
    }

    private function nettoyer(?array $valeurs): ?array
    {
        if ($valeurs === null) {
            return null;
        }

        $propres = [];
        foreach ($valeurs as $cle => $valeur) {
            $cleTexte = mb_strtolower((string) $cle);

            if (in_array($cleTexte, self::CLES_INTERDITES, true) || str_contains($cleTexte, 'password') || str_contains($cleTexte, 'secret')) {
                continue;
            }

            if (is_array($valeur)) {
                $propres[$cle] = $this->nettoyer($valeur);
            } elseif (is_object($valeur)) {
                $propres[$cle] = '[objet]';
            } elseif (is_bool($valeur) || is_int($valeur) || is_float($valeur) || $valeur === null) {
                $propres[$cle] = $valeur;
            } else {
                $max = (int) config('iam.audit_taille_max_valeur', 4000);
                $texte = (string) $valeur;
                $propres[$cle] = mb_strlen($texte) > $max ? mb_substr($texte, 0, $max).'…[tronqué]' : $texte;
            }
        }

        return $propres;
    }

    private function encoder(array $contenu): string
    {
        return (string) json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function disponible(): bool
    {
        try {
            return Schema::hasTable('iam_evenements');
        } catch (Throwable) {
            return false;
        }
    }
}
