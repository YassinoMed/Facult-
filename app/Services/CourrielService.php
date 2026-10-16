<?php

namespace App\Services;

use App\Mail\CourrielMessage;
use App\Models\Courriel;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Service central de messagerie (LOOP-MAIL-ENTERPRISE-01) : sélection du
 * modèle au catalogue, validation des variables, journalisation expurgée,
 * classification des erreurs, idempotence et reprise encadrée.
 *
 * Portée volontairement stricte :
 * - les parcours OTP/sécurité existants NE sont PAS réécrits : ils sont
 *   journalisés passivement (événements mail, sans secret) et leur reprise
 *   reste la reprise du parcours (nouveau code) — SMTP ne garantit de toute
 *   façon pas une livraison exactement une fois ;
 * - seul `message_manuel` est émissible via le cockpit (double confirmation,
 *   destinataire validé, texte plafonné, acteur audité) ;
 * - le corps complet n'est JAMAIS persisté (ni en clair, ni chiffré) :
 *   une reprise exige la réémission depuis des variables sûres ou une
 *   nouvelle saisie administrative.
 */
class CourrielService
{
    /**
     * Envoie un courriel du catalogue et journalise (intention AVANT la
     * tentative technique, résultat connu APRÈS).
     *
     * @param  array<string, scalar|null>  $variables
     */
    public function envoyer(
        string $modele,
        string $destinataire,
        array $variables = [],
        array $options = [],
    ): Courriel {
        $entree = $this->entreeCatalogue($modele);

        if (($entree['emissible_service'] ?? false) !== true) {
            throw new InvalidArgumentException('Ce modèle est réservé à son parcours de sécurité.');
        }

        $destinataire = $this->validerDestinataire($destinataire);
        $variables = $this->validerVariables($modele, $entree, $variables);
        $lignes = $this->composerLignes($modele, $entree, $variables);

        $cle = (string) ($options['cle'] ?? $this->cleDerivee($modele, $destinataire, $variables));

        $existant = Courriel::where('cle_idempotence', $cle)->first();
        if ($existant !== null && ! $existant->peutEtreReessaye()) {
            return $existant;
        }
        if ($existant !== null && $existant->peutEtreReessaye()) {
            throw new DomainException('Ce message est déjà en échec : utilisez la reprise encadrée.');
        }

        try {
            $courriel = Courriel::create([
                'cle_idempotence' => $cle,
                'categorie' => $entree['categorie'],
                'modele' => $modele,
                'version_modele' => $entree['version'],
                'statut' => Courriel::STATUT_EN_COURS,
                'tentatives' => 0,
                'destinataire_masque' => Courriel::masquerDestinataire($destinataire),
                'destinataire_hash' => Courriel::condensatDestinataire($destinataire),
                'sujet' => $entree['sujet'],
                'correlation_id' => $options['correlation'] ?? null,
                'reference_metier' => isset($options['reference']) ? mb_substr((string) $options['reference'], 0, 80) : null,
                'acteur_admin_id' => $options['acteur_admin_id'] ?? null,
                'donnees' => [
                    'reemissible' => (bool) ($entree['reemissible'] ?? false),
                    // Seules les variables sûres des modèles réémissibles sont
                    // conservées (références, compteurs, adresses masquées) :
                    // jamais de corps libre, jamais de secret.
                    'variables_sures' => ($entree['reemissible'] ?? false) && isset($entree['lignes']) ? $variables : null,
                ],
            ]);
        } catch (UniqueConstraintViolationException|QueryException) {
            // Concurrence : une intention identique vient d'être enregistrée.
            $concurrent = Courriel::where('cle_idempotence', $cle)->first();
            if ($concurrent !== null) {
                return $concurrent;
            }
            throw new DomainException('Envoi déjà enregistré.');
        }

        $courriel->update(['tentatives' => 1]);

        try {
            Mail::to($destinataire)->send(new CourrielMessage(
                $courriel->id,
                $modele,
                $entree['sujet'],
                $lignes,
            ));
        } catch (Throwable $e) {
            $courriel->update([
                'statut' => Courriel::STATUT_ECHEC,
                'categorie_erreur' => $this->classifierErreur($e),
                'donnees' => array_merge($courriel->donnees ?? [], [
                    'diagnostic' => self::expurgerMessage($e),
                ]),
            ]);

            return $courriel;
        }

        $courriel->update([
            'statut' => Courriel::STATUT_ACCEPTE_SMTP,
            'envoye_le' => now(),
        ]);

        return $courriel;
    }

    /**
     * Validation + aperçu SANS envoi ni écriture (première étape cockpit).
     *
     * @param  array<string, mixed>  $variables
     * @return array{sujet: string, lignes: list<string>}
     *
     * @throws InvalidArgumentException
     */
    public function valider(string $modele, string $destinataire, array $variables = []): array
    {
        $entree = $this->entreeCatalogue($modele);
        $this->validerDestinataire($destinataire);

        return $this->previsualiser($modele, $this->validerVariables($modele, $entree, $variables, true));
    }

    /**
     * Aperçu de rendu SANS envoi (cockpit, catalogue).
     *
     * @param  array<string, scalar|null>  $variables
     * @return array{sujet: string, lignes: list<string>}
     */
    public function previsualiser(string $modele, array $variables = []): array
    {
        $entree = $this->entreeCatalogue($modele);
        $variables = $this->validerVariables($modele, $entree, $variables, true);

        if ($modele === 'message_manuel') {
            return [
                'sujet' => $entree['sujet'],
                'lignes' => ['Bonjour,', (string) ($variables['message'] ?? ''), 'Service de la scolarité'],
            ];
        }

        return [
            'sujet' => $entree['sujet'],
            'lignes' => $this->composerLignes($modele, $entree, $variables),
        ];
    }

    /**
     * Compose les lignes d'un modèle sûr par interpolation stricte : seules
     * les variables validées remplacent leurs marqueurs `{cle}` dans les
     * phrases fixes du catalogue (aucune structure inventée, aucun HTML).
     *
     * @param  array<string, mixed>  $entree
     * @param  array<string, scalar|null>  $variables
     * @return list<string>
     */
    private function composerLignes(string $modele, array $entree, array $variables): array
    {
        if ($modele === 'message_manuel') {
            return [(string) ($variables['message'] ?? '')];
        }

        $lignes = [];
        foreach ((array) ($entree['lignes'] ?? []) as $phrase) {
            $lignes[] = (string) preg_replace_callback(
                '/\{([a-z_]+)\}/',
                static fn ($correspondance) => (string) ($variables[$correspondance[1]] ?? ''),
                (string) $phrase
            );
        }

        return $lignes;
    }

    /**
     * Reprise administrative encadrée avec réémission réelle : refusée sauf
     * garde explicite. Les codes OTP ne sont JAMAIS réémis (reprise =
     * nouveau parcours) et aucun corps n'étant persisté, seules les lignes
     * sûres rejouables repartent — vers la SEULE adresse d'origine (le
     * condensat stocké doit correspondre : aucun relais arbitraire).
     *
     * @throws DomainException|InvalidArgumentException
     */
    public function reessayer(Courriel $courriel, string $destinataire, ?int $acteurAdminId = null): Courriel
    {
        if (! $courriel->peutEtreReessaye()) {
            throw new DomainException('Seul un courriel en échec peut être repris.');
        }

        $variablesSures = $courriel->donnees['variables_sures'] ?? null;
        if (($courriel->donnees['reemissible'] ?? false) !== true || ! is_array($variablesSures)) {
            throw new DomainException('Ce message ne peut pas être réémis depuis le journal (secret à usage unique ou corps non conservé) : reprenez le parcours d’origine.');
        }

        $destinataire = $this->validerDestinataire($destinataire);
        if (! hash_equals($courriel->destinataire_hash, Courriel::condensatDestinataire($destinataire))) {
            throw new DomainException('La reprise n’est autorisée que vers l’adresse destinataire d’origine.');
        }

        $reessais = (int) ($courriel->donnees['reessais_manuels'] ?? 0);
        if ($reessais >= (int) config('courriels.max_reessais_manuels', 3)) {
            throw new DomainException('Plafond de reprises atteint pour ce message.');
        }

        $dernierEchec = $courriel->updated_at;
        if ($dernierEchec !== null && $dernierEchec->diffInSeconds(now()) < (int) config('courriels.delai_reprise_secondes', 60)) {
            throw new DomainException('Patientez avant de relancer ce message (anti-boucle).');
        }

        $entree = $this->entreeCatalogue($courriel->modele);
        $lignes = $this->composerLignes($courriel->modele, $entree, $variablesSures);

        $courriel->update([
            'statut' => Courriel::STATUT_REESSAI,
            'tentatives' => $courriel->tentatives + 1,
            'categorie_erreur' => null,
            'acteur_admin_id' => $acteurAdminId ?? $courriel->acteur_admin_id,
            'donnees' => array_merge($courriel->donnees ?? [], ['reessais_manuels' => $reessais + 1]),
        ]);

        try {
            Mail::to($destinataire)->send(new CourrielMessage(
                $courriel->id,
                $courriel->modele,
                $courriel->sujet,
                $lignes,
            ));
        } catch (Throwable $e) {
            $courriel->update([
                'statut' => Courriel::STATUT_ECHEC,
                'categorie_erreur' => $this->classifierErreur($e),
                'donnees' => array_merge($courriel->donnees ?? [], [
                    'diagnostic' => self::expurgerMessage($e),
                ]),
            ]);

            return $courriel;
        }

        $courriel->update([
            'statut' => Courriel::STATUT_ACCEPTE_SMTP,
            'envoye_le' => now(),
        ]);

        return $courriel;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function entreeCatalogue(string $modele): array
    {
        $entree = config('courriels.modeles.'.$modele);

        if (! is_array($entree)) {
            throw new InvalidArgumentException('Modèle de courriel inconnu.');
        }

        return $entree;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function validerDestinataire(string $destinataire): string
    {
        $destinataire = trim($destinataire);

        if (str_contains($destinataire, "\r") || str_contains($destinataire, "\n")) {
            throw new InvalidArgumentException('Destinataire invalide.');
        }

        if (mb_strlen($destinataire) > 254 || filter_var($destinataire, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Adresse destinataire invalide.');
        }

        return mb_strtolower($destinataire);
    }

    /**
     * @param  array<string, mixed>  $entree
     * @param  array<string, mixed>  $variables
     * @return array<string, scalar|null>
     *
     * @throws InvalidArgumentException
     */
    private function validerVariables(string $modele, array $entree, array $variables, bool $apercu = false): array
    {
        $autorisées = $entree['variables'] ?? [];
        $filtrees = [];

        foreach ($variables as $cle => $valeur) {
            if (! in_array($cle, $autorisées, true)) {
                throw new InvalidArgumentException('Variable non autorisée pour ce modèle.');
            }

            if ($valeur !== null && ! is_scalar($valeur)) {
                throw new InvalidArgumentException('Variable non scalaire refusée.');
            }

            $texte = (string) ($valeur ?? '');

            if (str_contains($texte, "\r")) {
                throw new InvalidArgumentException('Variable invalide (retour chariot).');
            }

            // Garde anti-injection : aucun HTML actif dans les variables.
            $texte = strip_tags($texte);

            $plafond = $cle === 'message' ? 500 : 200;
            if (mb_strlen($texte) > $plafond) {
                throw new InvalidArgumentException('Variable trop longue pour ce modèle.');
            }

            $filtrees[$cle] = $apercu ? $texte : $texte;
        }

        if ($modele === 'message_manuel' && ! $apercu && trim((string) ($filtrees['message'] ?? '')) === '') {
            throw new InvalidArgumentException('Le message ne peut pas être vide.');
        }

        return $filtrees;
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    private function cleDerivee(string $modele, string $destinataire, array $variables): string
    {
        ksort($variables);

        return hash('sha256', implode('|', [
            $modele,
            Courriel::condensatDestinataire($destinataire),
            (string) json_encode($variables, JSON_UNESCAPED_UNICODE),
        ]));
    }

    /**
     * Classification technique SANS secret (classe + motifs connus).
     */
    public function classifierErreur(Throwable $e): string
    {
        $message = mb_strtolower($e->getMessage().' '.$e::class);

        if (str_contains($message, '535') || str_contains($message, 'authent')) {
            return 'authentification';
        }

        if (str_contains($message, '550') || str_contains($message, 'mailbox') || str_contains($message, 'recipient') || str_contains($message, '5.1.1')) {
            return 'destinataire';
        }

        if (str_contains($message, 'connect') || str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'network') || str_contains($message, 'dns')) {
            return 'connexion';
        }

        if (str_contains($message, 'config') || str_contains($message, 'transport') || str_contains($message, 'mailer') || str_contains($message, 'dsn')) {
            return 'configuration';
        }

        return 'inconnue';
    }

    /**
     * Diagnostic expurgé : classe + message tronqué, adresses caviarder,
     * jamais de secret ni de trace complète.
     */
    public static function expurgerMessage(Throwable $e): string
    {
        $message = mb_substr($e->getMessage(), 0, 255);
        $message = (string) preg_replace(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '[adresse]',
            $message
        );

        return $e::class.' : '.$message;
    }
}
