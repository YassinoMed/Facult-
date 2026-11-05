<?php

namespace App\Services\Acquis;

use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\ParcoursAcademique\Models\ParcoursPlan;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * GÉNÉRATEUR ET CERTIFICATEUR DE DOCUMENTS PÉDAGOGIQUES OFFICIELS (LOOP 23, 36 & 37).
 *
 * Émet les relevés de redoublement, les attestations de capitalisation d'acquis
 * et les contrats pédagogiques individuels avec scellement d'intégrité SHA-256 canonique,
 * sceau d'authenticité institutionnel HMAC-SHA-256 avec versionnement et rotation de clés,
 * gestion de la révocation / remplacement, et QR code sans fuite de données personnelles.
 */
class DocumentPedagogiqueOfficielService
{
    public const TYPE_RELEVE_REDOUBLEMENT = 'RELEVE_REDOUBLEMENT';

    public const TYPE_SYNTHESE_ACQUIS = 'SYNTHESE_ACQUIS';

    public const TYPE_CONTRAT_PEDAGOGIQUE = 'CONTRAT_PEDAGOGIQUE';

    public function __construct(
        private readonly MoteurDecisionAcademiqueService $moteurDecision,
    ) {}

    /**
     * Sérialise de manière canonique (déterministe) un tableau de données.
     * Trie récursivement toutes les clés pour garantir que le hash SHA-256
     * est rigoureusement indépendant de l'ordre d'insertion des éléments.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function serialiserCanonique(array $donnees): string
    {
        $donneesTriees = $this->trierRecursivementCles($donnees);

        return json_encode($donneesTriees, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Tri récursif des clés d'un tableau associatif.
     */
    private function trierRecursivementCles(mixed $donnees): mixed
    {
        if (! is_array($donnees)) {
            return $donnees;
        }

        // Si tableau associatif, trier alphabétiquement par clé
        if (! array_is_list($donnees)) {
            ksort($donnees);
        }

        foreach ($donnees as $cle => $valeur) {
            $donnees[$cle] = $this->trierRecursivementCles($valeur);
        }

        return $donnees;
    }

    /**
     * Résout la clé secrète HMAC et la version de clé active.
     * Prend en charge le versionnement des clés ('v1', 'v2') et la rotation sans interruption.
     *
     * @return array{cle: string, version: string, reconnue: bool}
     */
    public function obtenirCleHmac(?string $version = null): array
    {
        $versionActive = (string) config('acquis_reglementation.crypto.version_active', 'v1');
        $versionCible = $version ?: $versionActive;

        $cles = config('acquis_reglementation.crypto.cles', []);
        $clesAnciennes = config('acquis_reglementation.crypto.cles_anciennes', []);

        // Si une version explicite est fournie, vérifier qu'elle est homologuée
        if ($version !== null && ! isset($cles[$versionCible]) && ! isset($clesAnciennes[$versionCible]) && $versionCible !== $versionActive) {
            return [
                'cle' => '',
                'version' => $versionCible,
                'reconnue' => false,
            ];
        }

        $cle = $cles[$versionCible]
            ?? config('acquis_reglementation.crypto.hmac_key')
            ?? $clesAnciennes[$versionCible]
            ?? config('app.key')
            ?? 'uniregistre_secret_key';

        return [
            'cle' => (string) $cle,
            'version' => $versionCible,
            'reconnue' => true,
        ];
    }

    /**
     * Génère la structure de données certifiée d'un document académique officiel.
     *
     * @return array<string, mixed>
     */
    public function genererDonneesDocument(
        Etudiant $etudiant,
        string $typeDocument,
        ?ParcoursPlan $plan = null,
        ?InscriptionAcademique $inscription = null
    ): array {
        $etudiant->loadMissing(['programmeRef', 'faculte']);
        $decision = $this->moteurDecision->determinerDecision($etudiant, null, $etudiant->programme_id);

        $estOfficiel = ($plan !== null && strtolower((string) $plan->statut) === 'valide')
            || ($decision['decision_definitive'] && $decision['sources_valides']);

        $mentionStatut = $estOfficiel
            ? 'DOCUMENT OFFICIEL CERTIFIÉ — CONFORME AUX DÉLIBÉRATIONS DU JURY'
            : 'SIMULATION PRÉVISIONNELLE — DOCUMENT NON OFFICIEL SANS VALEUR JURIDIQUE';

        $timestamp = now()->timestamp;
        $referenceDoc = sprintf(
            'DOC-%s-%s-%d-%d',
            substr($typeDocument, 0, 3),
            date('Y'),
            $etudiant->id,
            $timestamp
        );

        $infoCle = $this->obtenirCleHmac();

        $donneesCorps = [
            'reference' => $referenceDoc,
            'type_document' => $typeDocument,
            'mention_juridique' => $mentionStatut,
            'est_officiel' => $estOfficiel,
            'statut_document' => 'VALIDE',
            'version_document' => '1.0',
            'cle_version' => $infoCle['version'],
            'date_generation' => now()->toIso8601String(),
            'etudiant' => [
                'id' => $etudiant->id,
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'cin' => $etudiant->cin,
                'matricule' => $etudiant->num_inscription ?? $etudiant->matricule ?? "ETU-{$etudiant->id}",
                'faculte' => $etudiant->faculte?->nom ?? 'Faculté Universitaire',
            ],
            'programme' => [
                'nom' => $decision['regime']['nom'] ?? 'Formation universitaire',
                'version' => $decision['version_programme'],
                'annee_universitaire' => $decision['annee_universitaire'],
            ],
            'credits' => $decision['credits'],
            'elements_conserves' => $decision['elements_conserves'],
            'elements_a_reprendre' => $decision['elements_a_reprendre'],
            'unites_detaillees' => $decision['unites'],
            'deliberation' => [
                'publiee' => $decision['sources_valides'],
                'statut' => $decision['sources_valides'] ? 'PUBLIEE' : 'EN_ATTENTE',
            ],
            'references_reglementaires' => $decision['references_regles'],
        ];

        // 1. Empreinte de hachage SHA-256 canonique (intégrité absolue du contenu)
        $payloadCanonique = $this->serialiserCanonique($donneesCorps);
        $empreinteSha256 = hash('sha256', $payloadCanonique);
        $donneesCorps['empreinte_sha256'] = $empreinteSha256;

        // 2. Sceau d'authenticité institutionnel HMAC-SHA-256 (clé dédiée et versionnée)
        $sceauHmac = hash_hmac('sha256', $empreinteSha256, $infoCle['cle']);
        $donneesCorps['sceau_hmac'] = $sceauHmac;

        // 3. Référence de vérification en ligne sans PII (QR code avec uniquement ref et empreinte)
        $donneesCorps['qr_verification_url'] = "/verification/document?ref={$referenceDoc}&empreinte={$empreinteSha256}";

        return $donneesCorps;
    }

    /**
     * Vérifie l'intégrité, l'authenticité et la validité d'un document académique.
     *
     * @param  array<string, mixed>  $donneesDocument
     * @return array{
     *     valide: bool,
     *     integre: bool,
     *     authentique: bool,
     *     est_revoque: bool,
     *     est_remplace: bool,
     *     cle_version: string,
     *     message: string
     * }
     */
    public function verifierIntegriteDocument(array $donneesDocument): array
    {
        $empreinteFournie = $donneesDocument['empreinte_sha256'] ?? '';
        $sceauFourni = $donneesDocument['sceau_hmac'] ?? '';
        $cleVersion = $donneesDocument['cle_version'] ?? 'v1';
        $reference = (string) ($donneesDocument['reference'] ?? '');

        if (empty($empreinteFournie) || empty($sceauFourni)) {
            return [
                'valide' => false,
                'integre' => false,
                'authentique' => false,
                'est_revoque' => false,
                'est_remplace' => false,
                'cle_version' => (string) $cleVersion,
                'message' => 'Métadonnées de sécurité (empreinte SHA-256 ou sceau HMAC) absentes du document.',
            ];
        }

        // Vérification du statut de révocation / remplacement
        $statutDoc = mb_strtoupper((string) ($donneesDocument['statut_document'] ?? $donneesDocument['statut'] ?? 'VALIDE'));
        $estRevoque = $statutDoc === 'REVOQUE' || $this->estReferenceRevoquee($reference);
        $estRemplace = $statutDoc === 'REMPLACE' || $this->estReferenceRemplacee($reference);

        if ($estRevoque) {
            return [
                'valide' => false,
                'integre' => false,
                'authentique' => false,
                'est_revoque' => true,
                'est_remplace' => false,
                'cle_version' => (string) $cleVersion,
                'message' => 'Document académique formellement révoqué par l\'administration institutionnelle.',
            ];
        }

        if ($estRemplace) {
            return [
                'valide' => false,
                'integre' => false,
                'authentique' => false,
                'est_revoque' => false,
                'est_remplace' => true,
                'cle_version' => (string) $cleVersion,
                'message' => 'Document académique obsolète : une version plus récente a remplacé cette référence.',
            ];
        }

        // Reconstruction du corps sans les signatures et l'URL de vérification
        $corpsSansSignatures = $donneesDocument;
        unset($corpsSansSignatures['empreinte_sha256'], $corpsSansSignatures['sceau_hmac'], $corpsSansSignatures['qr_verification_url']);

        // 1. Calcul SHA-256 canonique
        $payloadCanonique = $this->serialiserCanonique($corpsSansSignatures);
        $empreinteCalculee = hash('sha256', $payloadCanonique);
        $integre = hash_equals($empreinteCalculee, $empreinteFournie);

        // Rétrocompatibilité avec l'ancien format non canonique si besoin
        if (! $integre) {
            $legacyPayload = json_encode($corpsSansSignatures, JSON_UNESCAPED_UNICODE);
            if (hash_equals(hash('sha256', $legacyPayload), $empreinteFournie)) {
                $integre = true;
            }
        }

        // 2. Vérification HMAC avec résolution dynamique de clé (rotation)
        $infoCle = $this->obtenirCleHmac($cleVersion);

        if (! ($infoCle['reconnue'] ?? true)) {
            return [
                'valide' => false,
                'integre' => $integre,
                'authentique' => false,
                'est_revoque' => false,
                'est_remplace' => false,
                'cle_version' => (string) $cleVersion,
                'message' => "Version de clé cryptographique inconnue (« {$cleVersion} ») : authenticité institutionnelle rejetée (fail-closed).",
            ];
        }

        $sceauAttendu = hash_hmac('sha256', $empreinteFournie, $infoCle['cle']);
        $authentique = hash_equals($sceauAttendu, $sceauFourni);

        // Fallback rétrocompatible app.key si version active
        if (! $authentique && $cleVersion === config('acquis_reglementation.crypto.version_active', 'v1')) {
            $cleFallback = (string) (config('app.key') ?? 'uniregistre_secret_key');
            if (hash_equals(hash_hmac('sha256', $empreinteFournie, $cleFallback), $sceauFourni)) {
                $authentique = true;
            }
        }

        $valide = $integre && $authentique;

        return [
            'valide' => $valide,
            'integre' => $integre,
            'authentique' => $authentique,
            'est_revoque' => false,
            'est_remplace' => false,
            'cle_version' => (string) $cleVersion,
            'message' => $valide
                ? 'Document intègre et authentifié avec succès par le serveur d\'UniRegistre.'
                : ($integre ? 'Authenticité institutionnelle non prouvée (HMAC invalide).' : 'Altération du contenu détectée : l\'empreinte SHA-256 ne correspond plus.'),
        ];
    }

    /**
     * Vérifie un document avec protection contre les attaques par force brute (rate limiting).
     *
     * @param  array<string, mixed>  $donneesDocument
     * @return array<string, mixed>
     */
    public function verifierDocumentAvecLimite(array $donneesDocument, string $identifiantClient = '127.0.0.1'): array
    {
        $limiteMax = (int) config('acquis_reglementation.crypto.limite_verifications_par_minute', 30);
        $cleCache = "acquis_verif_rate_{$identifiantClient}";

        $tentatives = (int) Cache::get($cleCache, 0);
        if ($tentatives >= $limiteMax) {
            return [
                'valide' => false,
                'integre' => false,
                'authentique' => false,
                'est_revoque' => false,
                'est_remplace' => false,
                'rate_limited' => true,
                'message' => 'Quota de vérification dépassé (limite anti-bruteforce). Veuillez patienter avant de renouveler la vérification.',
            ];
        }

        Cache::put($cleCache, $tentatives + 1, now()->addMinute());

        return $this->verifierIntegriteDocument($donneesDocument);
    }

    /**
     * Révoque un document officiel pour motif administratif ou erreur matérielle.
     *
     * @return array<string, mixed>
     */
    public function revoquerDocument(string $reference, string $motif, ?int $agentId = null): array
    {
        $cleCache = "acquis_document_revoque_{$reference}";
        $donneesRevocation = [
            'reference' => $reference,
            'statut' => 'REVOQUE',
            'motif' => $motif,
            'revoque_par' => $agentId,
            'date_revocation' => now()->toIso8601String(),
        ];

        Cache::forever($cleCache, $donneesRevocation);

        return $donneesRevocation;
    }

    /**
     * Remplace un document officiel par un document corrigé.
     *
     * @return array<string, mixed>
     */
    public function remplacerDocument(
        string $ancienneReference,
        Etudiant $etudiant,
        string $typeDocument,
        string $motif,
        ?int $agentId = null
    ): array {
        $nouveauDocument = $this->genererDonneesDocument($etudiant, $typeDocument);

        // Marquer l'ancien comme remplacé
        $cleCache = "acquis_document_remplace_{$ancienneReference}";
        Cache::forever($cleCache, [
            'ancienne_reference' => $ancienneReference,
            'nouvelle_reference' => $nouveauDocument['reference'],
            'statut' => 'REMPLACE',
            'motif' => $motif,
            'remplace_par' => $agentId,
            'date_remplacement' => now()->toIso8601String(),
        ]);

        $nouveauDocument['remplace_document_reference'] = $ancienneReference;

        return $nouveauDocument;
    }

    public function estReferenceRevoquee(string $reference): bool
    {
        return ! empty($reference) && Cache::has("acquis_document_revoque_{$reference}");
    }

    public function estReferenceRemplacee(string $reference): bool
    {
        return ! empty($reference) && Cache::has("acquis_document_remplace_{$reference}");
    }

    /**
     * Enregistre le document dans le registre DocumentAcademique.
     */
    public function persisterDocumentOfficiel(
        Etudiant $etudiant,
        string $typeDocument,
        ?ParcoursPlan $plan = null,
        ?int $agentId = null
    ): DocumentAcademique {
        $donnees = $this->genererDonneesDocument($etudiant, $typeDocument, $plan);

        if (! $donnees['est_officiel']) {
            throw new DomainException(
                'Impossible d\'archiver comme document officiel une simulation prévisionnelle non validée.'
            );
        }

        $userValide = $agentId !== null && User::where('id', $agentId)->exists();
        $agentFinal = $userValide ? $agentId : null;

        $nomFichier = "documents/academiques/{$donnees['reference']}.json";
        Storage::disk('local')->put($nomFichier, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return DocumentAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $etudiant->inscriptionsAcademiques()->latest('id')->value('annee_universitaire_id'),
            'type_document' => strtolower($typeDocument),
            'nom_original' => "{$donnees['reference']}.json",
            'chemin' => $nomFichier,
            'mime_type' => 'application/json',
            'taille' => strlen(json_encode($donnees)),
            'hash_fichier' => $donnees['empreinte_sha256'],
            'uploaded_by' => $agentFinal,
        ]);
    }
}
