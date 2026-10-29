<?php

namespace App\Services\Documents;

use App\Models\DocumentEvent;
use App\Models\Etudiant;
use App\Models\LieuNaissance;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\AuditService;
use App\Services\Identity\ArabicNormalizer;
use App\Support\QrCodeSvg;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Phase 16 §16.3 : implémentation du contrat `DocumentGenerator`.
 * Pipeline : résolution données → validation schéma (blocage explicite
 * si donnée obligatoire manquante) → rendu HTML → PDF (moteur Dompdf
 * existant) → hash SHA-256 → stockage privé → registre. Snapshot
 * immuable : le document émis ne change jamais ; toute régénération
 * crée un nouveau document qui `supersede` l'ancien.
 */
final class DefaultDocumentGenerator implements DocumentGenerator
{
    public function __construct(
        private readonly PdfGeneratorInterface $moteur,
        private readonly StudentDossierService $dossiers,
        private readonly DocumentNumberingService $numerotation,
        private readonly AuditService $audit,
        private readonly DocumentSignatureService $signature,
    ) {}

    public function types(): array
    {
        return array_keys(config('documents-officiels.types', []));
    }

    public function supporte(string $type): bool
    {
        return array_key_exists($type, config('documents-officiels.types', []));
    }

    /**
     * @param  array<string, mixed>  $contexte  (inscription_id, locale, validite_jours, duplicata_de)
     */
    public function generer(Etudiant $etudiant, string $type, array $contexte, ?int $auteurId): GeneratedDocument
    {
        $definition = config("documents-officiels.types.{$type}");

        if (! is_array($definition)) {
            throw new DomainException("Type de document « {$type} » hors catalogue.");
        }

        $inscriptionId = $contexte['inscription_id'] ?? null;

        if ($inscriptionId === null) {
            throw new DomainException('Inscription visée obligatoire (§16.6 cas 1 : choix explicite si ambiguïté).');
        }

        $inscription = $etudiant->inscriptionsAcademiques()->whereKey($inscriptionId)->firstOrFail();

        // Idempotence concurrence (§16.6 cas 11) : clé métier
        // type+inscription+hash contenu → un seul document émis.
        $donnees = $this->donnees($etudiant, $inscription, $definition, $contexte);
        $contenuHash = hash('sha256', json_encode($donnees, JSON_UNESCAPED_UNICODE));

        $dossier = $this->dossiers->obtenirOuCreer($etudiant);

        $frais = GeneratedDocument::where('student_dossier_id', $dossier->id)
            ->where('type', $type)
            ->where('contenu_hash', $contenuHash)
            ->where('statut', 'valide')
            ->latest('id')
            ->first();

        if ($frais !== null && Storage::disk('private')->exists($frais->storage_path)) {
            return $frais;
        }

        for ($tentative = 0; $tentative < 3; $tentative++) {
            try {
                return DB::transaction(function () use ($dossier, $type, $definition, $donnees, $contenuHash, $auteurId, $contexte, $etudiant) {
                    GeneratedDocument::where('student_dossier_id', $dossier->id)
                        ->where('type', $type)->lockForUpdate()->get();

                    $codeVerification = $this->codeOpaque();
                    $donnees['codeVerification'] = $codeVerification;
                    $donnees['urlVerification'] = url('/verify/'.$codeVerification);

                    if ((bool) config('documents-officiels.qr.actif', true)) {
                        $donnees['qrCodeSvg'] = QrCodeSvg::generer($donnees['urlVerification'], 80);
                    }

                    $html = view($definition['gabarit'], $donnees)->render();

                    try {
                        $pdf = $this->moteur->generer($html);
                    } catch (\Throwable $e) {
                        // §16.6 cas 12 : statut failed, aucun numéro consommé
                        // (transaction annulée), relance possible.
                        report($e);

                        throw new DomainException('Échec de rendu PDF : réessayez (aucun numéro consommé).');
                    }

                    // Numéro réservé DANS la transaction : pas de trou en
                    // cas d'échec, unicité garantie en concurrence.
                    $numero = $this->numerotation->reserver($type, $definition['prefixe']);

                    $duplicataDe = isset($contexte['duplicata_de']) ? (int) $contexte['duplicata_de'] : null;

                    if ($duplicataDe !== null) {
                        $origine = GeneratedDocument::whereKey($duplicataDe)->where('type', $type)->firstOrFail();
                        $numero = $origine->numero.'-DUP'.(((int) $origine->duplicatas) + 1);
                        $origine->increment('duplicatas');
                    }

                    $donnees['signature_document'] = [
                        'algorithme' => DocumentSignatureService::ALGORITHME,
                        'valeur' => $this->signature->signer($type, $numero, $contenuHash, $codeVerification),
                    ];

                    $chemin = implode('/', [
                        'documents-centre',
                        now()->format('Y'),
                        Str::uuid()->toString().'.pdf',
                    ]);

                    Storage::disk('private')->put($chemin, $pdf);

                    $document = GeneratedDocument::create([
                        'reference' => $numero,
                        'numero' => $numero,
                        'code_verification' => $codeVerification,
                        'student_dossier_id' => $dossier->id,
                        'type' => $type,
                        'version' => ((int) GeneratedDocument::where('student_dossier_id', $dossier->id)->where('type', $type)->max('version')) + 1,
                        'statut' => 'valide',
                        'storage_path' => $chemin,
                        'sha256' => hash('sha256', $pdf),
                        'contenu_hash' => $contenuHash,
                        'snapshot_donnees' => $donnees,
                        'locale' => $contexte['locale'] ?? 'fr',
                        'emis_le' => now(),
                        'emis_par' => $auteurId,
                        'valide_jusquau' => isset($contexte['validite_jours']) ? now()->addDays((int) $contexte['validite_jours']) : null,
                        'lot_id' => $contexte['lot_id'] ?? null,
                        'generated_by' => $auteurId,
                    ]);

                    // Supersession : les versions valides précédentes
                    // deviennent remplacées, lien bidirectionnel.
                    $anciens = GeneratedDocument::where('student_dossier_id', $dossier->id)
                        ->where('type', $type)
                        ->whereKeyNot($document->id)
                        ->where('statut', 'valide')
                        ->get();

                    foreach ($anciens as $ancien) {
                        $ancien->update(['statut' => 'remplace', 'remplace_id' => $document->id]);
                    }

                    DocumentEvent::create([
                        'document_id' => $document->id,
                        'evenement' => 'genere',
                        'auteur_id' => $auteurId,
                        'meta' => ['numero' => $numero, 'type' => $type],
                    ]);

                    $this->audit->tracer(
                        $etudiant, 'document.genere', 'document', $document->id, null,
                        ['reference' => $numero, 'type' => $type],
                    );

                    return $document->fresh();
                });
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'numero') && $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new DomainException('Génération impossible après 3 tentatives (concurrence).');
    }

    /**
     * Code opaque non séquentiel (§16.3) : 32 caractères, pas
     * d'énumération possible.
     */
    private function codeOpaque(): string
    {
        do {
            $code = Str::upper(Str::random(32));
        } while (GeneratedDocument::where('code_verification', $code)->exists());

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function donnees(Etudiant $etudiant, mixed $inscription, array $definition, array $contexte): array
    {
        // §16.3 : données manquantes = blocage avec liste précise.
        $manquantes = [];

        if (trim((string) $etudiant->nom) === '') {
            $manquantes[] = 'nom de famille manquant';
        }

        if (trim((string) $etudiant->prenom) === '') {
            $manquantes[] = 'prénom manquant';
        }

        if ($etudiant->date_naissance === null) {
            $manquantes[] = 'date de naissance manquante';
        }

        if ($manquantes !== []) {
            throw new DomainException('Génération bloquée : '.implode(', ', $manquantes).'. Corrigez la fiche étudiant.');
        }

        $etudiant->loadMissing('faculte');
        $inscription->loadMissing(['anneeUniversitaire', 'programmeRef', 'niveauRef']);

        $codeNiveau = strtoupper((string) ($inscription->niveauRef?->code ?? $inscription->niveau ?? ''));
        $niveauLibelle = match ($codeNiveau) {
            'L1', 'M1', 'D1', '1' => 'Première année',
            'L2', 'M2', 'D2', '2' => 'Deuxième année',
            'L3', '3' => 'Troisième année',
            default => (string) ($inscription->niveauRef?->nom ?? $inscription->niveau ?? 'Année en cours'),
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
            'inscription' => (string) ($etudiant->num_inscription ?? ''),
            'naissance' => $etudiant->date_naissance?->format('d/m/Y'),
            'lieuNaissance' => $lieuRaw,
            'lieuNaissanceArabe' => $lieuNaissanceArabe,
            'faculte' => $faculteNom,
            'faculteArabe' => $faculteArabe,
            'programme' => $filiereNom,
            'filiere' => $filiereNom,
            'filiereArabe' => $filiereArabe,
            'specialite' => $specialiteNom,
            'specialiteArabe' => $specialiteArabe,
            'cycleDiplome' => (string) ($inscription->programmeRef?->typeDiplome?->nom ?? 'Mastère Professionnel'),
            'niveau' => (string) ($inscription->niveauRef?->code ?? $inscription->niveau),
            'niveauLibelle' => $niveauLibelle,
            'annee' => (string) ($inscription->anneeUniversitaire?->libelle ?? '—'),
            'inscriptionId' => $inscription->id,
            'locale' => $contexte['locale'] ?? 'fr',
            'langue' => $contexte['locale'] ?? 'fr',
            'ville' => $ville,
            'villeArabe' => $villeArabe,
            'secretaireGeneral' => 'Ben Abdallah Fethi',
            'secretaireGeneralArabe' => 'فتحي عبد الله',
            'tutelle' => 'DIR. GEN. DES ETUDES TECHNOLOGIQUES',
            'tutelleArabe' => 'الإدارة العامة للدراسات التكنولوجية',
            'niveauArabe' => match ($codeNiveau) {
                'L1', 'M1', 'D1', '1' => 'الأولى',
                'L2', 'M2', 'D2', '2' => 'الثانية',
                'L3', '3' => 'الثالثة',
                default => (string) ($inscription->niveauRef?->code ?? $inscription->niveau ?? 'الحالية'),
            },
            'cycleArabe' => 'الماجستير المهني',
            'sessionArabe' => 'الدورة الرئيسية',
            'mentionArabe' => 'متوسط',
            'duplicata' => isset($contexte['duplicata_de']),
        ];
    }
}
