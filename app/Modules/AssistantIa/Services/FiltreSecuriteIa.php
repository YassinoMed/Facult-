<?php

namespace App\Modules\AssistantIa\Services;

use App\Models\User;
use App\Modules\AssistantIa\Exceptions\SecuriteIaException;

/**
 * Filtre de sécurité applicatif pour UniRegistre AI :
 * Détection des injections de prompt, filtrage des sorties et isolation stricte par faculté.
 */
class FiltreSecuriteIa
{
    /**
     * Motifs d'injection de prompt directe et indirecte.
     */
    protected const MOTIFS_INJECTION = [
        '/\b(system\s+override|admin\s+override|bypass\s+security)\b/iu',
        '/(output|print|show|dump|affiche|donne)\s+(all\s+)?.*(passwords?|mots?\s+de\s+passe)/iu',
        '/ignore\s+(all\s+)?(previous|prior|above)\s+instructions/iu',
        '/ignore\s+(toutes\s+)?(les\s+)?instructions\s+pr[ée]c[ée]dentes/iu',
        '/(?:forget|disregard|reset)\s+(all\s+)?(rules|instructions|constraints|prior\s+context)/iu',
        '/(?:oublie|ignore)\s+(toutes\s+les\s+r[èe]gles|tous\s+les\s+ordres|le\s+contexte\s+pr[ée]c[ée]dent)/iu',
        '/(montre|donne|affiche|r[ée]v[èe]le|expose)\s+(le|les|tes|la)?\s*(prompt|instructions?|system\s+prompt|secrets?|clefs?|cl[ée]s?)/iu',
        '/(reveal|show|print|display|output)\s+(your\s+)?(system\s+prompt|developer\s+mode|instructions?|api\s*keys?)/iu',
        '/\b(dan\s+mode|jailbreak|unrestricted\s+mode)\b/iu',
        '/(lis|affiche|donne|expose)\s+le\s+fichier\s+\.env/iu',
        '/(?:\[\/?(?:system|sys|instruction)\]|<<sys>>|<\|system\|>|<\|im_start\|>)/iu',
        '/\b(union\s+select|drop\s+table|information_schema|or\s+[\'"]?1[\'"]?\s*=\s*[\'"]?1)\b/iu',
        '/<script\b[^>]*>(.*?)<\/script>/isu',
    ];

    /**
     * Analyse un prompt ou document à la recherche de tentatives d'injection ou d'exfiltration.
     *
     * @return array{estSuspect: bool, motif: ?string}
     */
    public static function detecterInjection(string $texte): array
    {
        foreach (self::MOTIFS_INJECTION as $motif) {
            if (preg_match($motif, $texte)) {
                return [
                    'estSuspect' => true,
                    'motif' => $motif,
                ];
            }
        }

        return [
            'estSuspect' => false,
            'motif' => null,
        ];
    }

    /**
     * Détecte une consigne hostile (injection indirecte) dans un passage documentaire récupéré.
     *
     * Seuls les motifs de manipulation du modèle sont utilisés : les deux derniers motifs
     * (SQL et balise script) sont exclus pour ne pas écarter une documentation technique légitime.
     *
     * @return array{estSuspect: bool, motif: ?string}
     */
    public static function detecterInstructionHostileDansPassage(string $texte): array
    {
        foreach (array_slice(self::MOTIFS_INJECTION, 0, -2) as $motif) {
            if (preg_match($motif, $texte)) {
                return [
                    'estSuspect' => true,
                    'motif' => $motif,
                ];
            }
        }

        return [
            'estSuspect' => false,
            'motif' => null,
        ];
    }

    /**
     * Valide l'absence d'injection et lève une exception typée en cas d'attaque flagrante.
     *
     * @throws SecuriteIaException
     */
    public static function validerTexteEntrant(string $texte, string $source = 'utilisateur'): void
    {
        $detection = self::detecterInjection($texte);
        if ($detection['estSuspect']) {
            JournalisationIa::alerteSecurite("Tentative d'injection de prompt détectée ({$source})", [
                'source' => $source,
                'motif' => $detection['motif'],
                'extrait' => mb_substr($texte, 0, 100),
            ]);

            throw new SecuriteIaException("Requête rejetée : tentative d'injection ou d'instruction non autorisée détectée.");
        }
    }

    /**
     * Purge la réponse du modèle avant affichage ou persistance (XSS, PII, fuites de secrets).
     */
    public static function purgerSortie(string $texte): string
    {
        // 1. Éliminer les scripts HTML bruts
        $texte = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '[BLOC_SCRIPT_SUPPRIMÉ]', $texte);

        // 2. Masquer les données sensibles
        $texte = JournalisationIa::masquerDonneesSensibles($texte);

        return $texte;
    }

    /**
     * Contrôle strict anti-IDOR/BOLA : vérifie le rattachement facultaire de l'utilisateur.
     *
     * @throws SecuriteIaException
     */
    public static function verifierPerimetreFaculte(User $utilisateur, ?int $faculteIdCible): void
    {
        if ($faculteIdCible === null) {
            return;
        }

        if (! $utilisateur->peutAccederFaculte($faculteIdCible)) {
            JournalisationIa::alerteSecurite('Tentative de violation de périmètre facultaire', [
                'utilisateur_id' => $utilisateur->id,
                'faculte_utilisateur' => $utilisateur->faculteRestreinteId(),
                'faculte_cible' => $faculteIdCible,
            ]);

            throw new SecuriteIaException("Accès refusé : la ressource demandée n'appartient pas à votre faculté de rattachement.");
        }
    }

    /**
     * Évalue la sensibilité d'un texte et de ses sources documentaires associées pour le routage hybride.
     * Détermine si le contenu peut légitimement transiter vers un fournisseur distant.
     *
     * @param  array<int, mixed>  $sources
     * @return array{estSensible: bool, estDistantInterdit: bool, categories: array<int, string>, motifs: array<int, string>, explication: string}
     */
    public static function evaluerSensibiliteDonnees(string $texte, array $sources = []): array
    {
        $categories = [];
        $motifs = [];

        // 1. Détection de secrets ou identifiants d'infrastructure
        $motifsSecrets = [
            '/APP_KEY\s*=\s*base64:[A-Za-z0-9+\/=]+/i' => 'Clé d\'application Laravel (APP_KEY)',
            '/-----BEGIN\s+(?:RSA\s+)?PRIVATE\s+KEY-----/i' => 'Clé cryptographique privée',
            '/(?:bearer\s+[a-zA-Z0-9_\-\.~+\/]+=*|sk-[a-zA-Z0-9]{20,})/i' => 'Jeton d\'authentification / Clé d\'API',
            '/(?:password|mot_de_passe|db_password)\s*[:=]\s*["\']?[^"\'\s]{4,}["\']?/i' => 'Mot de passe d\'infrastructure',
        ];

        foreach ($motifsSecrets as $pattern => $label) {
            if (preg_match($pattern, $texte)) {
                $categories[] = 'secrets';
                $motifs[] = $label;
            }
        }

        // 2. Détection de données personnelles identifiantes (PII)
        $motifsPii = [
            '/\b\d{8}\b/' => 'Numéro de Carte d\'Identité Nationale (CIN)',
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/' => 'Adresse email personnelle',
            '/(?:\+216\s*)?[24579]\d{7}/' => 'Numéro de téléphone mobile',
        ];

        foreach ($motifsPii as $pattern => $label) {
            if (preg_match($pattern, $texte)) {
                $categories[] = 'pii_etudiant';
                $motifs[] = $label;
            }
        }

        // 3. Détection de données académiques et financières sensibles
        $motifsAcademiques = [
            '/\b(?:notes?|relev[ée]s?\s+de\s+notes?|moyennes?|bulletins?|r[ée]sultats?\s+d\'examens?)\b/iu' => 'Notes ou relevés académiques',
            '/\b(?:d[ée]lib[ée]rations?|pv\s+de\s+d[ée]lib[ée]ration|jury\s+d\'examen|admis|ajourn[ée]|rattrapage)\b/iu' => 'Données de délibération ou décisions de jury',
            '/\b(?:paiements?|frais\s+de\s+scolarit[ée]|quittances?|tranches?|montant\s+pay[ée]|impay[ée]s?)\b/iu' => 'Données de paiement ou financières',
            '/\b(?:dossiers?\s+(?:d\'inscription|d\'admission|scolaire|m[ée]dical|disciplinaire))\b/iu' => 'Dossier individuel d\'étudiant',
            '/\b(?:conseil\s+de\s+discipline|sanction\s+disciplinaire|exclusion|bl[âa]me)\b/iu' => 'Dossier disciplinaire',
        ];

        foreach ($motifsAcademiques as $pattern => $label) {
            if (preg_match($pattern, $texte)) {
                $categories[] = 'donnees_academiques_sensibles';
                $motifs[] = $label;
            }
        }

        // 4. Examen des sources documentaires RAG
        foreach ($sources as $source) {
            $permission = is_array($source) ? ($source['permission_requise'] ?? '') : ($source->permission_requise ?? '');
            $domaine = is_array($source) ? ($source['domaine_metier'] ?? '') : ($source->domaine_metier ?? '');
            $titre = is_array($source) ? ($source['titre'] ?? '') : ($source->titre ?? '');

            if ($permission !== '' && $permission !== 'public') {
                $categories[] = 'source_interne_restreinte';
                $motifs[] = "Source RAG à accès restreint ('{$titre}', perm: {$permission})";
            }

            if (in_array($domaine, ['notes', 'deliberations', 'finances', 'discipline'], true)) {
                $categories[] = 'source_academique_protegee';
                $motifs[] = "Source RAG du domaine protégé '{$domaine}'";
            }
        }

        $categories = array_values(array_unique($categories));
        $motifs = array_values(array_unique($motifs));

        $estSensible = ! empty($categories);
        // Tout secret, PII ou donnée académique interdit formellement le transfert distant par défaut
        $estDistantInterdit = in_array('secrets', $categories, true)
            || in_array('pii_etudiant', $categories, true)
            || in_array('donnees_academiques_sensibles', $categories, true)
            || in_array('source_interne_restreinte', $categories, true)
            || in_array('source_academique_protegee', $categories, true);

        $explication = $estDistantInterdit
            ? 'Transfert distant refusé : présence de données protégées ('.implode(', ', $motifs).').'
            : ($estSensible ? 'Données sensibles identifiées mais non strictement prohibées.' : 'Contenu non sensible, conforme pour traitement distant après consentement.');

        return [
            'estSensible' => $estSensible,
            'estDistantInterdit' => $estDistantInterdit,
            'categories' => $categories,
            'motifs' => $motifs,
            'explication' => $explication,
        ];
    }

    /**
     * Minimise et assainit le contexte pour transmission vers un fournisseur distant autorisé.
     * Supprime les chemins locaux du serveur, tronque l'historique non essentiel et masque les identifiants résiduels.
     */
    public static function minimiserTextePourDistant(string $texte): string
    {
        // Supprimer les chemins absolus locaux
        $texte = preg_replace('#(/Users/[a-zA-Z0-9_\-\.]+/[a-zA-Z0-9_\-\./]+)#i', '[CHEMIN_FICHIER_INTERNE]', $texte);
        $texte = preg_replace('#(/[a-zA-Z0-9_\-\.]+/Desktop/[a-zA-Z0-9_\-\./]+)#i', '[CHEMIN_FICHIER_INTERNE]', $texte);

        // Masquer les PII éventuels
        $texte = JournalisationIa::masquerDonneesSensibles($texte);

        return $texte;
    }
}
