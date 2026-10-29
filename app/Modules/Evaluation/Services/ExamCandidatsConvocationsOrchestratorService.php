<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;

/**
 * Service d'orchestration Enterprise pour les candidats, l'émargement et les convocations (Menus 4, 9 & 11).
 *
 * Responsabilités :
 * - Audit unifié de l'état de validation de la population et des numéros anonymes.
 * - Détection et qualification de la dérive de population depuis le snapshot officiel.
 * - Consolidation des métriques et indicateurs de couverture des convocations d'examen.
 * - Génération des payloads de signature et QR codes de sécurité anti-fraude pour l'accès aux salles.
 */
class ExamCandidatsConvocationsOrchestratorService
{
    public function __construct(
        private PreparationExamenService $preparationService,
    ) {}

    /**
     * Audit complet de la population candidate et de l'anonymat pour une épreuve.
     *
     * @return array{
     *     total_candidats: int,
     *     candidats_avec_anonymat: int,
     *     candidats_sans_anonymat: int,
     *     est_validee: bool,
     *     date_validation: string|null,
     *     effectif_valide: int,
     *     est_derivee: bool,
     *     ecart_derive: int,
     *     statut_code: string,
     *     statut_libelle: string,
     *     blocage_ouverture: bool
     * }
     */
    public function auditerEligibiliteEpreuve(Epreuve $epreuve): array
    {
        $epreuve->loadCount(['candidats' => function ($q) {
            $q->whereNull('numero_anonyme');
        }]);

        $totalCandidats = $epreuve->candidats()->count();
        $sansAnonymat = $epreuve->candidats_count;
        $avecAnonymat = $totalCandidats - $sansAnonymat;

        $estValidee = $epreuve->population_validated_at !== null;
        $effectifValide = (int) ($epreuve->population_effectif ?? 0);
        $estDerivee = $this->preparationService->populationModifiee($epreuve);
        $ecartDerive = $estDerivee ? ($totalCandidats - $effectifValide) : 0;

        $correspondance = app(CorrespondanceExamenService::class)->analyserEpreuve($epreuve);
        $conformeCorrespondance = (bool) ($correspondance['conforme'] ?? true);

        if ($estDerivee) {
            $statutCode = 'derivee';
            $statutLibelle = "Dérive de population détectée ({$effectifValide} → {$totalCandidats}) : Revalidation requise";
            $blocageOuverture = true;
        } elseif (! $conformeCorrespondance) {
            $statutCode = 'discordance_correspondance';
            $statutLibelle = $correspondance['bloquants'][0] ?? CorrespondanceExamenService::ALERTE_PUBLICATION_BLOQUEE;
            $blocageOuverture = true;
        } elseif ($estValidee) {
            $statutCode = 'validee';
            $statutLibelle = "Population validée et figée ({$totalCandidats} candidats)";
            $blocageOuverture = false;
        } else {
            $statutCode = 'en_attente';
            $statutLibelle = 'Population non validée : En attente de snapshot';
            $blocageOuverture = true;
        }

        return [
            'total_candidats' => $totalCandidats,
            'candidats_avec_anonymat' => $avecAnonymat,
            'candidats_sans_anonymat' => $sansAnonymat,
            'est_validee' => $estValidee,
            'date_validation' => $epreuve->population_validated_at?->format('d/m/Y H:i'),
            'effectif_valide' => $effectifValide,
            'est_derivee' => $estDerivee,
            'ecart_derive' => $ecartDerive,
            'statut_code' => $statutCode,
            'statut_libelle' => $statutLibelle,
            'blocage_ouverture' => $blocageOuverture,
            'correspondance' => $correspondance,
        ];
    }

    /**
     * Consolidation des KPI de diffusion et de couverture des convocations d'examen.
     *
     * @return array{
     *     epreuves_publiees: int,
     *     candidats_convoques: int,
     *     convocations_generees: int,
     *     salles_mobilisees: int,
     *     taux_couverture: float
     * }
     */
    public function auditerConvocations(?int $sessionId = null, ?int $faculteId = null): array
    {
        $epreuvesQuery = Epreuve::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->whereIn('statut', ['published', 'ongoing', 'completed'])
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId));

        $epreuvesPubliees = (clone $epreuvesQuery)->count();

        $candidatsConvoques = Candidat::query()
            ->whereHas('epreuve', fn ($q) => $q
                ->when($faculteId !== null, fn ($f) => $f->pourFaculteId($faculteId))
                ->whereIn('statut', ['published', 'ongoing', 'completed'])
                ->when($sessionId !== null, fn ($s) => $s->where('session_id', $sessionId))
            )->count();

        $convocationsGenerees = DocumentExamenGenere::query()
            ->where('type', 'convocation')
            ->count();

        $sallesMobilisees = (clone $epreuvesQuery)
            ->whereNotNull('salle_id')
            ->distinct('salle_id')
            ->count('salle_id');

        $tauxCouverture = $candidatsConvoques > 0
            ? round(($convocationsGenerees / $candidatsConvoques) * 100, 1)
            : 0.0;

        return [
            'epreuves_publiees' => $epreuvesPubliees,
            'candidats_convoques' => $candidatsConvoques,
            'convocations_generees' => $convocationsGenerees,
            'salles_mobilisees' => $sallesMobilisees,
            'taux_couverture' => min(100.0, $tauxCouverture),
        ];
    }

    /**
     * Génère la chaîne de signature sécurisée pour le QR Code anti-fraude d'une convocation (format compact ≤ 120 octets).
     */
    public function genererPayloadQrCode(Candidat $candidat): string
    {
        $etudiant = $candidat->etudiant;
        $epreuve = $candidat->epreuve;

        $donnees = [
            'app' => 'UNIR',
            'cid' => (int) $candidat->id,
            'ano' => (string) ($candidat->numero_anonyme ?? '—'),
            'mat' => (string) ($etudiant?->num_inscription ?? ''),
            'epr' => (string) ($epreuve?->code ?? ''),
            'sal' => (string) ($candidat->salleEffective()?->code ?? $epreuve?->salle?->code ?? '—'),
        ];

        $cle = (string) (config('app.key') ?: 'secret-exam-uniregistre');
        $signature = substr(hash_hmac('sha256', (string) json_encode($donnees), $cle), 0, 16);
        $donnees['sig'] = $signature;

        return (string) json_encode($donnees, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Vérifie l'authenticité et l'intégrité cryptographique d'un QR code de convocation.
     *
     * @return array{valide: bool, message: string, donnees: array|null}
     */
    public function verifierPayloadQrCode(string $payload): array
    {
        $json = json_decode($payload, true);
        if (! is_array($json) || empty($json['sig']) || empty($json['cid'])) {
            return [
                'valide' => false,
                'message' => 'Format de QR code invalide ou illisible.',
                'donnees' => null,
            ];
        }

        $signatureRecue = (string) $json['sig'];
        $donneesSignees = $json;
        unset($donneesSignees['sig']);

        $cle = (string) (config('app.key') ?: 'secret-exam-uniregistre');
        $signatureAttendue = substr(hash_hmac('sha256', (string) json_encode($donneesSignees), $cle), 0, 16);

        if (! hash_equals($signatureAttendue, $signatureRecue)) {
            return [
                'valide' => false,
                'message' => 'Signature cryptographique invalide : risque de contrefaçon.',
                'donnees' => $json,
            ];
        }

        return [
            'valide' => true,
            'message' => 'Convocation authentique et certifiée par UniRegistre.',
            'donnees' => $json,
        ];
    }
}
