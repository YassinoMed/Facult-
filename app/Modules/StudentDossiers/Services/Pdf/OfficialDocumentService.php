<?php

namespace App\Modules\StudentDossiers\Services\Pdf;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\LieuNaissance;
use App\Models\SituationEtudiant;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\Paiement;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\AuditService;
use App\Services\Documents\DocumentEligibiliteService;
use App\Services\Documents\DocumentSignatureService;
use App\Services\Identity\ArabicNormalizer;
use App\Services\NotificationService;
use App\Support\QrCodeSvg;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Documents académiques officiels (Phase 7) : relevé de notes et
 * attestation de réussite, versionnés + hashés + révoquables, stockés
 * via PdfDocumentService (registre GeneratedDocument, disque privé).
 *
 * Règles : catalogue config (jamais de type ad hoc) ; attestation
 * uniquement sur décision publiée admis* (§59) ; nouvelle version =
 * ancienne `remplace` (§54) ; référence UNIQUE + retry concurrence
 * (§64) ; audit + notification centralisés.
 */
class OfficialDocumentService
{
    public function __construct(
        private readonly PdfDocumentService $documents,
        private readonly StudentDossierService $dossiers,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly DocumentEligibiliteService $eligibilite,
        private readonly DocumentSignatureService $signature,
    ) {}

    /** @return array<string, mixed> */
    public static function catalogue(): array
    {
        return config('documents-officiels.types', []);
    }

    /**
     * @param  array{paiement_id?: int}  $options
     */
    public function generer(Etudiant $etudiant, string $type, int $inscriptionId, ?int $auteurId, ?int $semestreNumero = null, array $options = []): GeneratedDocument
    {
        $definition = self::catalogue()[$type] ?? null;

        if ($definition === null) {
            throw new DomainException("Type de document « {$type} » hors catalogue.");
        }

        if (($definition['semestre_requis'] ?? false) && $semestreNumero === null) {
            throw new DomainException('Relevé semestriel : semestre obligatoire (1 ou 2).');
        }

        $inscription = InscriptionAcademique::whereKey($inscriptionId)
            ->where('etudiant_id', $etudiant->id)
            ->firstOrFail();

        // LOT 2 : règles métier centralisées (`DocumentEligibiliteService`,
        // checklist réutilisée par l'UI). Messages inchangés : le 1er
        // blocage porte le message historique du type.
        $controle = $this->eligibilite->verifier($etudiant, $type, $inscription, $options);

        if (! $controle['eligible']) {
            throw new DomainException($controle['blocages'][0]['message']);
        }

        $decision = $controle['decision'];

        $dossier = $this->dossiers->obtenirOuCreer($etudiant);
        $donnees = ($definition['cursus'] ?? false)
            ? $this->donneesCursus($etudiant, $definition)
            : $this->donnees($etudiant, $inscription, $decision, $definition, $semestreNumero, $controle['paiement'], $controle['transfert'], $options);
        $contenuHash = hash('sha256', json_encode($donnees, JSON_UNESCAPED_UNICODE));

        // Anti-régénération : contenu identique → document existant.
        $frais = $this->documents->documentFrais($dossier, $type, $contenuHash);

        if ($frais !== null && $frais->statut === 'valide') {
            return $frais;
        }

        // Code de vérification opaque réservé AVANT rendu : imprimé dans
        // le document avec QR code SVG scannable (norme ISO/IEC 18004).
        $codeVerification = mb_strtoupper(Str::random(32));

        while (GeneratedDocument::where('code_verification', $codeVerification)->exists()) {
            $codeVerification = mb_strtoupper(Str::random(32));
        }

        $donnees['codeVerification'] = $codeVerification;
        $donnees['urlVerification'] = url('/verify/'.$codeVerification);

        // R-07/1 : l'interrupteur `DOCUMENTS_QR_ACTIF` coupe réellement
        // l'image QR (l'URL et le code restent imprimés et vérifiables).
        if ((bool) config('documents-officiels.qr.actif', true)) {
            $donnees['qrCodeSvg'] = QrCodeSvg::generer($donnees['urlVerification'], 80);
        }

        // Concurrence (§64) : verrou + référence UNIQUE + rejeu.
        for ($tentative = 0; $tentative < 3; $tentative++) {
            try {
                return DB::transaction(function () use ($dossier, $type, $donnees, $contenuHash, $auteurId, $definition, $etudiant, $codeVerification) {
                    GeneratedDocument::where('student_dossier_id', $dossier->id)
                        ->where('type', $type)->lockForUpdate()->get();

                    $document = $this->documents->genererEtStocker(
                        $dossier, $type,
                        view($definition['gabarit'], $donnees)->render(),
                        $contenuHash, $auteurId, [], $codeVerification,
                    );

                    $reference = sprintf('%s-%s-%02d', $definition['prefixe'], $dossier->reference, $document->version);

                    // R-07/6 : signature calculée sur la référence définitive
                    // et conservée avec le snapshot imprimé.
                    $donnees['signature_document'] = [
                        'algorithme' => DocumentSignatureService::ALGORITHME,
                        'valeur' => $this->signature->signer($type, $reference, $contenuHash, $codeVerification),
                    ];

                    $document->update([
                        'reference' => $reference,
                        'statut' => 'valide',
                        'snapshot_donnees' => $donnees,
                    ]);

                    // Supersession (§54) : les versions valides
                    // précédentes deviennent `remplace`, jamais supprimées.
                    GeneratedDocument::where('student_dossier_id', $dossier->id)
                        ->where('type', $type)
                        ->whereKeyNot($document->id)
                        ->where('statut', 'valide')
                        ->update(['statut' => 'remplace']);

                    $this->audit->tracer(
                        $etudiant, 'document.genere', 'document', $document->id, null,
                        ['reference' => $document->reference, 'type' => $type, 'version' => $document->version],
                    );

                    $this->notifications->notifierEtudiant(
                        $etudiant, 'document.genere', 'Document disponible',
                        "Votre {$definition['libelle']} ({$document->reference}) est disponible.",
                        route('etudiant.dossier.show'),
                    );

                    return $document->fresh();
                });
            } catch (QueryException $e) {
                // Collision de référence sous concurrence : rejeu.
                if (! str_contains($e->getMessage(), 'reference') && $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new DomainException('Génération impossible après 3 tentatives (concurrence).');
    }

    public function revoquer(GeneratedDocument $document, string $motif, ?int $auteurId): GeneratedDocument
    {
        if ($document->statut === 'revoque') {
            return $document;
        }

        return DB::transaction(function () use ($document, $motif, $auteurId) {
            $verrouille = GeneratedDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $verrouille->update([
                'statut' => 'revoque',
                'revoque_le' => now(),
                'revoque_par' => $auteurId,
                'motif_revocation' => $motif,
            ]);

            $etudiant = $verrouille->dossier->etudiant;

            $this->audit->tracer(
                $etudiant, 'document.revoque', 'document', $verrouille->id,
                ['statut' => $document->statut],
                ['statut' => 'revoque', 'motif' => $motif],
            );

            $this->notifications->notifierEtudiant(
                $etudiant, 'document.revoque', 'Document révoqué',
                "Le document {$verrouille->reference} a été révoqué : {$motif}",
                route('etudiant.dossier.show'),
            );

            // $auteurId tracé via auth() dans AuditService (signature
            // conservée pour la traçabilité d'origine).
            return $verrouille->fresh();
        });
    }

    /** @return array<string, mixed> */
    private function donnees(Etudiant $etudiant, InscriptionAcademique $inscription, ?DecisionDeliberation $decision, array $definition, ?int $semestreNumero = null, ?Paiement $paiement = null, ?SituationEtudiant $transfert = null, array $options = []): array
    {
        $etudiant->loadMissing('faculte');
        $inscription->loadMissing([
            'anneeUniversitaire', 'programmeRef.specialite', 'programmeRef.typeDiplome', 'niveauRef',
            'semestres.notes.matiere',
        ]);

        $semestres = $inscription->semestres
            ->when($semestreNumero !== null, fn ($q) => $q->where('numero', $semestreNumero))
            ->map(fn ($semestre) => [
                'numero' => $semestre->numero,
                'moyenne' => $semestre->moyenne !== null ? (float) $semestre->moyenne : null,
                'credits' => [(int) $semestre->credits_obtenus, (int) $semestre->credits_total],
                'decision' => $semestre->decision,
                'notes' => $semestre->notes->map(fn ($note) => [
                    'code' => $note->matiere?->code,
                    'matiere' => $note->matiere?->nom,
                    'note' => (float) $note->note,
                    'session' => $note->session,
                ])->values()->all(),
            ])->values()->all();

        $codeNiveau = strtoupper((string) ($inscription->niveauRef?->code ?? $inscription->niveau ?? ''));
        $niveauLibelle = match ($codeNiveau) {
            'L1', 'M1', 'D1', '1' => 'Première année',
            'L2', 'M2', 'D2', '2' => 'Deuxième année',
            'L3', '3' => 'Troisième année',
            default => (string) ($inscription->niveauRef?->nom ?? $inscription->niveau ?? 'Année en cours'),
        };

        $niveauArabe = match ($codeNiveau) {
            'L1', 'M1', 'D1', '1' => 'الأولى',
            'L2', 'M2', 'D2', '2' => 'الثانية',
            'L3', '3' => 'الثالثة',
            default => (string) ($inscription->niveauRef?->code ?? $inscription->niveau ?? 'الحالية'),
        };

        $typeDiplomeNom = (string) ($inscription->programmeRef?->typeDiplome?->nom ?? '');
        $cycleDiplome = $typeDiplomeNom !== '' ? $typeDiplomeNom : 'Mastère Professionnel';
        $cycleArabe = str_contains(mb_strtolower($cycleDiplome), 'licence') ? 'الإجازة الوطنية' : 'الماجستير المهني';

        $sessionArabe = in_array(mb_strtolower((string) ($decision?->session ?? '')), ['rattrapage', 'controle'], true)
            ? 'دورة التدارك'
            : 'الدورة الرئيسية';

        $moy = (float) ($decision?->moyenne ?? $inscription->moyenne_annuelle ?? -1);
        $mentionArabe = match (true) {
            $moy >= 16 => 'حسن جداً',
            $moy >= 14 => 'حسن',
            $moy >= 12 => 'مستحسن',
            $moy >= 10 => 'متوسط',
            default => 'متوسط',
        };

        $ville = (string) ($etudiant->faculte?->ville ?? $etudiant->ville ?? 'Sfax');
        $villeArabe = LieuNaissance::resoudreNomArabe($ville) ?? (ArabicNormalizer::contientArabe($ville) ? $ville : $ville);

        $lieuRaw = (string) ($etudiant->ville ?? '—');
        $lieuNaissanceArabe = LieuNaissance::resoudreNomArabe($lieuRaw)
            ?? (ArabicNormalizer::contientArabe($lieuRaw) ? $lieuRaw : ($lieuRaw === 'Non renseignée' || $lieuRaw === '—' ? $villeArabe : $lieuRaw));

        $nomArabe = $etudiant->nom_ar
            ?? (ArabicNormalizer::contientArabe($etudiant->nom) ? $etudiant->nom : null);
        $prenomArabe = $etudiant->prenom_ar
            ?? (ArabicNormalizer::contientArabe($etudiant->prenom) ? $etudiant->prenom : null);

        if ($nomArabe === null) {
            $nomArabe = match (mb_strtolower(trim($etudiant->nom))) {
                'bouneb', 'bounab' => 'بوناب',
                'ben ali' => 'بن علي',
                'sassi' => 'الساسي',
                'alami' => 'العلمي',
                'trabelsi' => 'الطرابلسي',
                'driss' => 'دريس',
                'ayadi' => 'العيادي',
                'hamdi' => 'حمدي',
                default => (string) $etudiant->nom,
            };
        }

        if ($prenomArabe === null) {
            $prenomArabe = match (mb_strtolower(trim($etudiant->prenom))) {
                'mohamed yassine', 'mohamed-yassine' => 'محمد ياسين',
                'mohamed' => 'محمد',
                'yassine' => 'ياسين',
                'ahmed' => 'أحمد',
                'ali' => 'علي',
                'omar' => 'عمر',
                'mehdi' => 'مهدي',
                'fatma' => 'فاطمة',
                'mariem', 'meriem' => 'مريم',
                'salma' => 'سلمى',
                'amine' => 'أمين',
                'karim' => 'كريم',
                default => (string) $etudiant->prenom,
            };
        }

        $faculteNom = (string) ($etudiant->faculte?->nom ?? '—');
        $faculteArabe = ArabicNormalizer::contientArabe($faculteNom)
            ? $faculteNom
            : match (true) {
                str_contains($faculteNom, 'ISET') || str_contains($faculteNom, 'Technologique') => 'المعهد العالي للدراسات التكنولوجية بـ'.$villeArabe,
                str_contains($faculteNom, 'Sciences') => 'كلية العلوم بـ'.$villeArabe,
                str_contains($faculteNom, 'Droit') => 'كلية الحقوق بـ'.$villeArabe,
                str_contains($faculteNom, 'Médecine') => 'كلية الطب بـ'.$villeArabe,
                str_contains($faculteNom, 'Economie') || str_contains($faculteNom, 'Gestion') => 'كلية العلوم الاقتصادية والتصرف بـ'.$villeArabe,
                str_contains($faculteNom, 'Ingénieur') || str_contains($faculteNom, 'ENI') => 'المدرسة الوطنية للمهندسين بـ'.$villeArabe,
                default => $faculteNom,
            };

        $filiereNom = (string) ($inscription->programmeRef?->nom ?? $inscription->filiere ?? '—');
        $filiereArabe = ArabicNormalizer::contientArabe($filiereNom)
            ? $filiereNom
            : match (true) {
                str_contains(mb_strtolower($filiereNom), 'logiciel') || str_contains(mb_strtolower($filiereNom), 'informatique') => 'تطوير أنظمة الإعلامية و الشبكات',
                str_contains(mb_strtolower($filiereNom), 'economie') || str_contains(mb_strtolower($filiereNom), 'gestion') => 'العلوم الاقتصادية والتصرف',
                str_contains(mb_strtolower($filiereNom), 'mecanique') => 'الهندسة الميكانيكية',
                str_contains(mb_strtolower($filiereNom), 'electrique') => 'الهندسة الكهربائية',
                str_contains(mb_strtolower($filiereNom), 'civil') => 'الهندسة المدنية',
                default => $filiereNom,
            };

        $specialiteNom = (string) ($inscription->programmeRef?->specialite?->nom ?? $inscription->programmeRef?->nom ?? $inscription->filiere ?? '—');
        $specialiteArabe = ArabicNormalizer::contientArabe($specialiteNom)
            ? $specialiteNom
            : match (true) {
                str_contains(mb_strtolower($specialiteNom), 'logiciel') || str_contains(mb_strtolower($specialiteNom), 'technologies') => 'هندسة البرمجيات و التكنولوجيات الحديثة',
                str_contains(mb_strtolower($specialiteNom), 'tronc') || str_contains(mb_strtolower($specialiteNom), 'commun') => 'جذع مشترك',
                default => $specialiteNom,
            };

        return [
            'institution' => config('app.name'),
            'titreDocument' => $definition['libelle'],
            'dateGeneration' => now()->format('d/m/Y'),
            'nom' => (string) $etudiant->nom,
            'prenom' => (string) $etudiant->prenom,
            'nomArabe' => $nomArabe,
            'prenomArabe' => $prenomArabe,
            'cin' => (string) ($etudiant->cin ?? '—'),
            'civilite' => $etudiant->sexe === 'F' ? 'Mme' : 'Mr',
            'civiliteArabe' => $etudiant->sexe === 'F' ? 'الطالبة' : 'الطالب',
            'lieuNaissance' => $lieuRaw,
            'lieuNaissanceArabe' => $lieuNaissanceArabe,
            'inscription' => (string) $etudiant->num_inscription,
            'naissance' => $etudiant->date_naissance?->format('d/m/Y') ?? '—',
            'faculte' => $faculteNom,
            'faculteArabe' => $faculteArabe,
            'programme' => (string) ($inscription->programmeRef?->nom ?? $inscription->filiere ?? '—'),
            'filiere' => (string) ($inscription->programmeRef?->nom ?? $inscription->filiere ?? '—'),
            'filiereArabe' => $filiereArabe,
            'specialite' => (string) ($inscription->programmeRef?->specialite?->nom ?? $inscription->programmeRef?->nom ?? $inscription->filiere ?? '—'),
            'specialiteArabe' => $specialiteArabe,
            'cycleDiplome' => $cycleDiplome,
            'niveau' => (string) ($inscription->niveauRef?->code ?? $inscription->niveau),
            'niveauLibelle' => $niveauLibelle,
            'niveauArabe' => $niveauArabe,
            'cycleArabe' => $cycleArabe,
            'sessionArabe' => $sessionArabe,
            'mentionArabe' => $mentionArabe,
            'annee' => (string) ($inscription->anneeUniversitaire?->libelle ?? '—'),
            'semestres' => $semestres,
            'semestre_numero' => $semestreNumero,
            'moyenne' => $inscription->moyenne_annuelle !== null ? (float) $inscription->moyenne_annuelle : null,
            'credits' => [(int) $inscription->credits_obtenus, (int) $inscription->credits_total],
            'decision' => $decision?->decision,
            'decisionMoyenne' => $decision?->moyenne !== null ? (float) $decision->moyenne : null,
            'decisionSession' => $decision?->session ?? 'principale',
            'session' => $decision?->session ?? 'principale',
            'ville' => $ville,
            'villeArabe' => $villeArabe,
            'secretaireGeneral' => 'Ben Abdallah Fethi',
            'secretaireGeneralArabe' => 'فتحي عبد الله',
            'tutelle' => 'DIR. GEN. DES ETUDES TECHNOLOGIQUES',
            'tutelleArabe' => 'الإدارة العامة للدراسات التكنولوجية',
            'langue' => $options['langue'] ?? 'fr',
            ...$this->donneesComplementaires($etudiant, $inscription, $decision, $definition, $paiement, $transfert),
        ];
    }

    /**
     * Données spécifiques aux nouveaux types LOT 2 (fusionnées
     * UNIQUEMENT quand le type les exige : le hash des 6 types
     * historiques reste strictement identique).
     *
     * @return array<string, mixed>
     */
    private function donneesComplementaires(Etudiant $etudiant, InscriptionAcademique $inscription, ?DecisionDeliberation $decision, array $definition, ?Paiement $paiement, ?SituationEtudiant $transfert): array
    {
        $extras = [];

        // Données étendues LOT 2 (opt-in par type : jamais sur les 6
        // types historiques dont le hash doit rester identique) :
        // contact, dates d'inscription/jury, mention du jury.
        if ($definition['donnees_etendues'] ?? false) {
            $extras['telephone'] = (string) ($etudiant->telephone ?? '—');
            $extras['email'] = (string) ($etudiant->email ?? '—');
            $extras['dateInscription'] = $inscription->created_at?->format('d/m/Y') ?? '—';
            $extras['dateJury'] = $decision?->deliberation?->published_at?->format('d/m/Y')
                ?? $decision?->created_at?->format('d/m/Y') ?? '—';
            $extras['mention'] = $this->mention((float) ($decision?->moyenne ?? -1));
        }

        if ($definition['presence_requise'] ?? false) {
            $stats = $this->eligibilite->statistiquesPresence($etudiant);
            $extras['presenceSeances'] = $stats['seances'];
            $extras['presenceTaux'] = $stats['taux'];
        }

        if ($paiement !== null) {
            $paiement->loadMissing('facture');
            $facture = $paiement->facture;
            $extras['paiement'] = [
                'reference' => (string) $paiement->reference,
                'montant' => Facture::formater((int) $paiement->montant_centimes),
                'methode' => (string) $paiement->methode,
                'date' => $paiement->confirme_a?->format('d/m/Y') ?? $paiement->created_at?->format('d/m/Y') ?? '—',
                'referenceExterne' => (string) ($paiement->reference_externe ?? '—'),
            ];
            $extras['facture'] = [
                'reference' => (string) ($facture?->reference ?? '—'),
                'net' => $facture === null ? '—' : Facture::formater($facture->netCentimes()),
                'paye' => $facture === null ? '—' : Facture::formater($facture->payeCentimes()),
                'reste' => $facture === null ? '—' : Facture::formater($facture->resteCentimes()),
                'statut' => (string) ($facture?->statut ?? '—'),
            ];
        }

        if ($transfert !== null) {
            $extras['transfert'] = [
                'ancienne' => (string) ($transfert->ancienne_valeur ?? '—'),
                'nouvelle' => (string) ($transfert->nouvelle_valeur ?? '—'),
                'motif' => (string) ($transfert->motif ?? '—'),
                'date' => $transfert->date_effet?->format('d/m/Y') ?? '—',
            ];
        }

        if ($definition['abandon_requis'] ?? false) {
            $extras['statutInscription'] = (string) $inscription->statut;
            $extras['observationInscription'] = (string) ($inscription->observation ?? '—');
        }

        return $extras;
    }

    /**
     * Mention académique standard à partir de la moyenne (−1 = sans
     * décision : aucun affichage, jamais inventée).
     */
    private function mention(float $moyenne): string
    {
        return match (true) {
            $moyenne < 0 => '—',
            $moyenne >= 16 => 'Très Bien',
            $moyenne >= 14 => 'Bien',
            $moyenne >= 12 => 'Assez Bien',
            $moyenne >= 10 => 'Passable',
            default => 'Non validé',
        };
    }

    /**
     * Cursus complet : toutes les inscriptions de l'étudiant, années
     * ordonnées (relevé multi-années, jamais de moyenne inventée).
     *
     * @return array<string, mixed>
     */
    private function donneesCursus(Etudiant $etudiant, array $definition): array
    {
        $etudiant->loadMissing('faculte');

        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['anneeUniversitaire', 'programmeRef', 'niveauRef', 'semestres.notes.matiere'])
            ->orderBy('annee_universitaire_id')
            ->get();

        abort_unless($inscriptions->isNotEmpty(), 422, 'Aucune inscription pour constituer le cursus.');

        $annees = $inscriptions->map(function (InscriptionAcademique $inscription) use ($etudiant, $definition) {
            $decision = DecisionDeliberation::where('inscription_academique_id', $inscription->id)
                ->where('publie', true)->latest('id')->first();

            return array_merge(
                $this->donnees($etudiant, $inscription, $decision, $definition, null),
                ['decision' => $decision?->decision]
            );
        })->values()->all();

        return [
            'institution' => config('app.name'),
            'titreDocument' => $definition['libelle'],
            'dateGeneration' => now()->format('d/m/Y'),
            'nom' => (string) $etudiant->nom,
            'prenom' => (string) $etudiant->prenom,
            'inscription' => (string) $etudiant->num_inscription,
            'naissance' => $etudiant->date_naissance?->format('d/m/Y') ?? '—',
            'faculte' => (string) ($etudiant->faculte?->nom ?? '—'),
            'annees' => $annees,
        ];
    }
}
