<?php

namespace App\Services;

use App\Models\RegleAcademiqueVersion;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * GOUVERNANCE VERSIONNÉE DES RÈGLES ACADÉMIQUES (LOOP 44).
 *
 * Principes :
 * - aucune règle n'est créée par défaut : le socle ne contient aucun seuil ;
 * - une règle suit BROUILLON → EN_REVUE → APPROUVEE → PUBLIEE → RETIREE ;
 * - quatre yeux : l'auteur ne peut ni approuver ni publier sa propre règle ;
 * - une référence officielle et une date d'effet sont obligatoires pour publier ;
 * - une version approuvée ou publiée est immuable (garde de modèle + empreinte) ;
 * - deux versions publiées du même code ne peuvent pas se chevaucher ;
 * - `regleApplicable()` est fail-closed : aucune version, ou ambiguïté ⇒ null/exception.
 *
 * Le service ne décide pas qu'une règle est juste : il garantit qu'elle est
 * traçable, approuvée par un tiers et applicable à une date donnée.
 */
class GouvernanceReglesService
{
    private const LONGUEUR_MAX_CODE = 120;

    private const LONGUEUR_MAX_REFERENCE = 255;

    private const LONGUEUR_MAX_CONTENU = 65535;

    private const TENTATIVES_VERSION = 3;

    /**
     * Crée un brouillon de règle. Aucune règle métier n'est fournie par le socle.
     *
     * @param  array{code: string, contenu: array<string, mixed>, reference_officielle?: string|null, date_effet?: string|null, date_fin?: string|null}  $donnees
     */
    public function creerBrouillon(array $donnees, User $auteur): RegleAcademiqueVersion
    {
        $code = $this->normaliserCode($donnees['code'] ?? '');
        $contenu = $this->validerContenu($donnees['contenu'] ?? null);
        $reference = $this->normaliserReference($donnees['reference_officielle'] ?? null);
        [$dateEffet, $dateFin] = $this->validerPeriode($donnees['date_effet'] ?? null, $donnees['date_fin'] ?? null);

        for ($tentative = 1; $tentative <= self::TENTATIVES_VERSION; $tentative++) {
            try {
                return DB::transaction(function () use ($code, $contenu, $reference, $dateEffet, $dateFin, $auteur) {
                    $version = (int) RegleAcademiqueVersion::pourCode($code)->lockForUpdate()->max('version') + 1;

                    return RegleAcademiqueVersion::create([
                        'code' => $code,
                        'version' => $version,
                        'statut' => RegleAcademiqueVersion::STATUT_BROUILLON,
                        'contenu' => $contenu,
                        'reference_officielle' => $reference,
                        'date_effet' => $dateEffet,
                        'date_fin' => $dateFin,
                        'checksum' => $this->checksum($code, $version, $contenu),
                        'cree_par_id' => $auteur->id,
                    ]);
                });
            } catch (QueryException $e) {
                // Collision de version sous concurrence : on retente.
                if ($tentative === self::TENTATIVES_VERSION || ! $this->estViolationUnicite($e)) {
                    throw $e;
                }
            }
        }

        throw new DomainException('Impossible de créer la règle après plusieurs tentatives.');
    }

    /**
     * Modifie un brouillon. Toute modification d'une version en revue annule
     * la revue en cours : le statut revient à BROUILLON.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function mettreAJourBrouillon(RegleAcademiqueVersion $regle, array $donnees, User $acteur): RegleAcademiqueVersion
    {
        if (! $regle->estModifiable()) {
            throw new DomainException(
                "La règle {$regle->code} v{$regle->version} est au statut {$regle->statut} : elle est immuable."
            );
        }

        $contenu = array_key_exists('contenu', $donnees)
            ? $this->validerContenu($donnees['contenu'])
            : $regle->contenu;

        $reference = array_key_exists('reference_officielle', $donnees)
            ? $this->normaliserReference($donnees['reference_officielle'])
            : $regle->reference_officielle;

        [$dateEffet, $dateFin] = $this->validerPeriode(
            array_key_exists('date_effet', $donnees) ? $donnees['date_effet'] : $regle->date_effet?->toDateString(),
            array_key_exists('date_fin', $donnees) ? $donnees['date_fin'] : $regle->date_fin?->toDateString()
        );

        DB::transaction(function () use ($regle, $contenu, $reference, $dateEffet, $dateFin) {
            $regle->contenu = $contenu;
            $regle->reference_officielle = $reference;
            $regle->date_effet = $dateEffet;
            $regle->date_fin = $dateFin;
            $regle->checksum = $this->checksum($regle->code, $regle->version, $contenu);

            if ($regle->statut === RegleAcademiqueVersion::STATUT_EN_REVUE) {
                $regle->statut = RegleAcademiqueVersion::STATUT_BROUILLON;
                $regle->approuve_par_id = null;
                $regle->approuve_le = null;
            }

            $regle->save();
        });

        return $regle->refresh();
    }

    public function soumettre(RegleAcademiqueVersion $regle, User $acteur): RegleAcademiqueVersion
    {
        if ($regle->statut !== RegleAcademiqueVersion::STATUT_BROUILLON) {
            throw new DomainException(
                "Seul un brouillon peut être soumis (statut actuel : {$regle->statut})."
            );
        }

        $regle->statut = RegleAcademiqueVersion::STATUT_EN_REVUE;
        $regle->save();

        return $regle->refresh();
    }

    /**
     * APPROBATION — quatre yeux obligatoires : l'approbateur doit différer
     * de l'auteur. La référence officielle et la date d'effet sont exigées.
     */
    public function approuver(RegleAcademiqueVersion $regle, User $approbateur): RegleAcademiqueVersion
    {
        if ($regle->statut !== RegleAcademiqueVersion::STATUT_EN_REVUE) {
            throw new DomainException(
                "Seule une règle en revue peut être approuvée (statut actuel : {$regle->statut})."
            );
        }

        if ((int) $regle->cree_par_id === (int) $approbateur->id) {
            throw new DomainException(
                'Quatre yeux : l\'auteur d\'une règle ne peut pas approuver sa propre règle.'
            );
        }

        if (! $regle->referenceOfficielleComplete()) {
            throw new DomainException(
                'Une référence officielle est obligatoire avant approbation.'
            );
        }

        if ($regle->date_effet === null) {
            throw new DomainException(
                'Une date d\'effet est obligatoire avant approbation.'
            );
        }

        $regle->statut = RegleAcademiqueVersion::STATUT_APPROUVEE;
        $regle->approuve_par_id = $approbateur->id;
        $regle->approuve_le = now();
        $regle->save();

        return $regle->refresh();
    }

    /**
     * PUBLICATION — l'auteur ne peut pas publier sa propre règle. Deux
     * versions publiées du même code ne peuvent pas se chevaucher.
     */
    public function publier(RegleAcademiqueVersion $regle, User $acteur): RegleAcademiqueVersion
    {
        if ($regle->statut !== RegleAcademiqueVersion::STATUT_APPROUVEE) {
            throw new DomainException(
                "Seule une règle approuvée peut être publiée (statut actuel : {$regle->statut})."
            );
        }

        if ((int) $regle->cree_par_id === (int) $acteur->id) {
            throw new DomainException(
                'Séparation des fonctions : l\'auteur d\'une règle ne peut pas la publier lui-même.'
            );
        }

        if (! $regle->referenceOfficielleComplete()) {
            throw new DomainException('Une référence officielle est obligatoire avant publication.');
        }

        if ($regle->date_effet === null) {
            throw new DomainException('Une date d\'effet est obligatoire avant publication.');
        }

        $this->verifierAbsenceChevauchement($regle);

        $regle->statut = RegleAcademiqueVersion::STATUT_PUBLIEE;
        $regle->publie_par_id = $acteur->id;
        $regle->publie_le = now();
        $regle->save();

        return $regle->refresh();
    }

    /**
     * PROPOSITION — passage de BROUILLON à PROPOSEE (alias EN_REVUE).
     */
    public function proposer(RegleAcademiqueVersion $regle, User $auteur): RegleAcademiqueVersion
    {
        return $this->soumettre($regle, $auteur);
    }

    /**
     * SUSPENSION — suspend une règle publiée avec motif obligatoire (PHASE 3 - LOOP 44).
     */
    public function suspendre(RegleAcademiqueVersion $regle, User $acteur, string $motif): RegleAcademiqueVersion
    {
        if ($regle->statut !== RegleAcademiqueVersion::STATUT_PUBLIEE) {
            throw new DomainException(
                "Seule une règle publiée peut être suspendue (statut actuel : {$regle->statut})."
            );
        }

        $motif = trim($motif);
        if ($motif === '') {
            throw new InvalidArgumentException('Un motif de suspension est obligatoire.');
        }

        $regle->statut = RegleAcademiqueVersion::STATUT_SUSPENDUE;
        $regle->motif_retrait = mb_substr("SUSPENSION: {$motif}", 0, 2000);
        $regle->save();

        return $regle->refresh();
    }

    /**
     * REMPLACEMENT — remplace une ancienne règle par une nouvelle version approuvée (PHASE 4 - LOOP 44).
     */
    public function remplacer(RegleAcademiqueVersion $ancienne, RegleAcademiqueVersion $nouvelle, User $acteur): RegleAcademiqueVersion
    {
        if ($ancienne->statut !== RegleAcademiqueVersion::STATUT_PUBLIEE) {
            throw new DomainException(
                "Seule une règle publiée peut être remplacée (statut actuel : {$ancienne->statut})."
            );
        }

        if ($ancienne->code !== $nouvelle->code) {
            throw new DomainException(
                "Les deux règles doivent partager le même code fonctionnel ({$ancienne->code} vs {$nouvelle->code})."
            );
        }

        if ($nouvelle->statut !== RegleAcademiqueVersion::STATUT_APPROUVEE) {
            throw new DomainException(
                "La règle remplaçante doit être approuvée avant de remplacer l'ancienne."
            );
        }

        return DB::transaction(function () use ($ancienne, $nouvelle, $acteur) {
            $ancienne->statut = RegleAcademiqueVersion::STATUT_REMPLACEE;
            if ($nouvelle->date_effet !== null) {
                $ancienne->date_fin = $nouvelle->date_effet->copy()->subDay();
            }
            $ancienne->retire_par_id = $acteur->id;
            $ancienne->retire_le = now();
            $ancienne->motif_retrait = "Remplacée par la version {$nouvelle->version}";
            $ancienne->save();

            return $this->publier($nouvelle, $acteur);
        });
    }

    /**
     * RETRAIT — la version reste consultable, son contenu reste figé.
     */
    public function retirer(RegleAcademiqueVersion $regle, User $acteur, string $motif): RegleAcademiqueVersion
    {
        if ($regle->statut !== RegleAcademiqueVersion::STATUT_PUBLIEE) {
            throw new DomainException(
                "Seule une règle publiée peut être retirée (statut actuel : {$regle->statut})."
            );
        }

        $motif = trim($motif);

        if ($motif === '') {
            throw new InvalidArgumentException('Un motif de retrait est obligatoire.');
        }

        $regle->statut = RegleAcademiqueVersion::STATUT_RETIREE;
        $regle->retire_par_id = $acteur->id;
        $regle->retire_le = now();
        $regle->motif_retrait = mb_substr($motif, 0, 2000);
        $regle->save();

        return $regle->refresh();
    }

    /**
     * Version applicable à une date. Fail-closed : aucune version applicable
     * ⇒ null ; plusieurs versions applicables ⇒ exception.
     *
     * Une version retirée reste applicable pour les dates STRICTEMENT
     * antérieures à son retrait : un audit passé doit retrouver la règle qui
     * était réellement en vigueur, sans réécrire l'histoire.
     */
    public function regleApplicable(string $code, CarbonInterface|string|null $date = null): ?RegleAcademiqueVersion
    {
        $code = $this->normaliserCode($code);
        $reference = $this->normaliserDate($date ?? now());

        $candidates = RegleAcademiqueVersion::query()
            ->pourCode($code)
            ->where(function ($query) use ($reference) {
                $query->where('statut', RegleAcademiqueVersion::STATUT_PUBLIEE)
                    ->orWhere(function ($sousRequete) use ($reference) {
                        $sousRequete->where('statut', RegleAcademiqueVersion::STATUT_RETIREE)
                            ->whereNotNull('retire_le')
                            ->whereDate('retire_le', '>', $reference->toDateString());
                    });
            })
            ->whereDate('date_effet', '<=', $reference->toDateString())
            ->where(function ($query) use ($reference) {
                $query->whereNull('date_fin')
                    ->orWhereDate('date_fin', '>=', $reference->toDateString());
            })
            ->orderByDesc('version')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() > 1) {
            throw new DomainException(
                "Ambiguïté : {$candidates->count()} versions publiées de la règle {$code} sont applicables au {$reference->toDateString()}."
            );
        }

        return $candidates->first();
    }

    /**
     * DÉTECTION DES CONFLITS (PHASE 6 - LOOP 44).
     *
     * Détecte les conflits entre :
     * - Texte national
     * - Règlement d'établissement
     * - Maquette pédagogique
     * - Décision de jury
     * - Versions successives
     *
     * Si un conflit n'est pas résolu par une règle documentée, retourne
     * impérativement le statut REVUE_JURIDIQUE_REQUISE sans jamais choisir
     * arbitrairement la règle la plus récente ou la plus restrictive.
     *
     * @return array{
     *     statut: 'CONFORME'|'REVUE_JURIDIQUE_REQUISE'|'AUCUNE_REGLE',
     *     motif: string,
     *     regles_en_conflit: array<int, array<string, mixed>>,
     *     regle_applicable?: ?RegleAcademiqueVersion
     * }
     */
    public function detecterConflits(string $code, CarbonInterface|string|null $date = null): array
    {
        $code = $this->normaliserCode($code);
        $reference = $this->normaliserDate($date ?? now());

        $versions = RegleAcademiqueVersion::query()
            ->pourCode($code)
            ->whereIn('statut', [
                RegleAcademiqueVersion::STATUT_PUBLIEE,
                RegleAcademiqueVersion::STATUT_RETIREE,
                RegleAcademiqueVersion::STATUT_SUSPENDUE,
                RegleAcademiqueVersion::STATUT_REMPLACEE,
            ])
            ->get();

        if ($versions->isEmpty()) {
            return [
                'statut' => 'AUCUNE_REGLE',
                'motif' => "Aucune règle publiée ou archivée trouvée pour le code {$code}.",
                'regles_en_conflit' => [],
                'regle_applicable' => null,
            ];
        }

        // 1. Détection d'ambiguïté temporelle directe
        try {
            $regle = $this->regleApplicable($code, $reference);
        } catch (DomainException $e) {
            return [
                'statut' => 'REVUE_JURIDIQUE_REQUISE',
                'motif' => "Conflit d'ambiguïté temporelle : {$e->getMessage()}",
                'regles_en_conflit' => $versions->toArray(),
                'regle_applicable' => null,
            ];
        }

        if ($regle === null) {
            return [
                'statut' => 'AUCUNE_REGLE',
                'motif' => "Aucune version active de la règle {$code} au {$reference->toDateString()}.",
                'regles_en_conflit' => [],
                'regle_applicable' => null,
            ];
        }

        // 2. Détection de conflits entre sources / catégories au sein du périmètre
        $contenu = $regle->contenu ?? [];
        if (! empty($contenu['sources_concurrentes'])) {
            return [
                'statut' => 'REVUE_JURIDIQUE_REQUISE',
                'motif' => "Conflit entre sources concurrentes non arbitrées documenté pour la règle {$code} v{$regle->version}.",
                'regles_en_conflit' => [$regle->toArray()],
                'regle_applicable' => null,
            ];
        }

        return [
            'statut' => 'CONFORME',
            'motif' => "Règle v{$regle->version} active et conforme au {$reference->toDateString()}.",
            'regles_en_conflit' => [],
            'regle_applicable' => $regle,
        ];
    }

    /**
     * @return Collection<int, RegleAcademiqueVersion>
     */
    public function historique(string $code): Collection
    {
        return RegleAcademiqueVersion::query()
            ->pourCode($this->normaliserCode($code))
            ->orderBy('version')
            ->get();
    }

    /**
     * Contrôle de cohérence global, sans modifier aucune donnée.
     *
     * @return array{total: int, anomalies: list<array{code: string, version: int, anomalie: string}>}
     */
    public function verifierCoherence(): array
    {
        $anomalies = [];
        $regles = RegleAcademiqueVersion::query()->orderBy('code')->orderBy('version')->get();

        foreach ($regles as $regle) {
            if ($regle->statut === RegleAcademiqueVersion::STATUT_PUBLIEE) {
                if (! $regle->referenceOfficielleComplete()) {
                    $anomalies[] = $this->anomalie($regle, 'PUBLIEE_SANS_REFERENCE');
                }

                if ($regle->date_effet === null) {
                    $anomalies[] = $this->anomalie($regle, 'PUBLIEE_SANS_DATE_EFFET');
                }

                if ($regle->approuve_par_id === null) {
                    $anomalies[] = $this->anomalie($regle, 'PUBLIEE_SANS_APPROBATION');
                }
            }

            if ($regle->statut === RegleAcademiqueVersion::STATUT_RETIREE) {
                if ($regle->retire_le === null) {
                    $anomalies[] = $this->anomalie($regle, 'RETIREE_SANS_DATE');
                }

                if ($regle->motif_retrait === null || trim($regle->motif_retrait) === '') {
                    $anomalies[] = $this->anomalie($regle, 'RETIREE_SANS_MOTIF');
                }
            }

            if ($regle->date_effet !== null && $regle->date_fin !== null
                && $regle->date_fin->toDateString() < $regle->date_effet->toDateString()) {
                $anomalies[] = $this->anomalie($regle, 'DATE_FIN_AVANT_EFFET');
            }

            if ($regle->approuve_par_id !== null && (int) $regle->approuve_par_id === (int) $regle->cree_par_id) {
                $anomalies[] = $this->anomalie($regle, 'AUTO_APPROBATION');
            }

            if ($regle->publie_par_id !== null && (int) $regle->publie_par_id === (int) $regle->cree_par_id) {
                $anomalies[] = $this->anomalie($regle, 'AUTO_PUBLICATION');
            }

            if ($regle->checksum !== $this->checksum($regle->code, (int) $regle->version, $regle->contenu ?? [])) {
                $anomalies[] = $this->anomalie($regle, 'CHECKSUM_INVALIDE');
            }
        }

        $publiees = $regles->where('statut', RegleAcademiqueVersion::STATUT_PUBLIEE);

        foreach ($publiees->groupBy('code') as $code => $versions) {
            $liste = $versions->values();

            for ($i = 0; $i < $liste->count(); $i++) {
                for ($j = $i + 1; $j < $liste->count(); $j++) {
                    if ($this->intervallesSeChevauchent($liste[$i], $liste[$j])) {
                        $anomalies[] = $this->anomalie($liste[$j], 'CHEVAUCHEMENT_PUBLIE');
                    }
                }
            }
        }

        return [
            'total' => $regles->count(),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Empreinte canonique : clés triées récursivement, JSON stable.
     *
     * @param  array<string, mixed>  $contenu
     */
    public function checksum(string $code, int $version, array $contenu): string
    {
        $canonique = $this->canonicaliser([
            'code' => $code,
            'version' => $version,
            'contenu' => $contenu,
        ]);

        return hash('sha256', json_encode($canonique, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function verifierAbsenceChevauchement(RegleAcademiqueVersion $regle): void
    {
        $publiees = RegleAcademiqueVersion::query()
            ->pourCode($regle->code)
            ->publiee()
            ->where('id', '!=', $regle->id)
            ->get();

        foreach ($publiees as $existante) {
            if ($this->intervallesSeChevauchent($regle, $existante)) {
                throw new DomainException(
                    "Chevauchement : la version {$regle->version} de {$regle->code} chevauche la version publiée {$existante->version}."
                );
            }
        }
    }

    private function intervallesSeChevauchent(RegleAcademiqueVersion $a, RegleAcademiqueVersion $b): bool
    {
        if ($a->date_effet === null || $b->date_effet === null) {
            return false;
        }

        $finA = $a->date_fin?->toDateString() ?? '9999-12-31';
        $finB = $b->date_fin?->toDateString() ?? '9999-12-31';

        return $a->date_effet->toDateString() <= $finB
            && $b->date_effet->toDateString() <= $finA;
    }

    private function normaliserCode(string $code): string
    {
        $code = mb_strtoupper(trim($code));

        if ($code === '') {
            throw new InvalidArgumentException('Le code de la règle est obligatoire.');
        }

        if (mb_strlen($code) > self::LONGUEUR_MAX_CODE) {
            throw new InvalidArgumentException('Le code de la règle est trop long.');
        }

        if (! preg_match('/^[A-Z0-9_.-]+$/', $code)) {
            throw new InvalidArgumentException(
                'Le code de la règle ne peut contenir que des lettres majuscules, chiffres, points, tirets et underscores.'
            );
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function validerContenu(mixed $contenu): array
    {
        if (! is_array($contenu) || $contenu === []) {
            throw new InvalidArgumentException('Le contenu de la règle doit être un tableau non vide.');
        }

        $encode = json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encode === false) {
            throw new InvalidArgumentException('Le contenu de la règle n\'est pas sérialisable.');
        }

        if (strlen($encode) > self::LONGUEUR_MAX_CONTENU) {
            throw new InvalidArgumentException('Le contenu de la règle est trop volumineux.');
        }

        return $contenu;
    }

    private function normaliserReference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        if (mb_strlen($reference) > self::LONGUEUR_MAX_REFERENCE) {
            throw new InvalidArgumentException('La référence officielle est trop longue.');
        }

        return $reference;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function validerPeriode(mixed $dateEffet, mixed $dateFin): array
    {
        $debut = $dateEffet === null || $dateEffet === '' ? null : $this->normaliserDate($dateEffet)->toDateString();
        $fin = $dateFin === null || $dateFin === '' ? null : $this->normaliserDate($dateFin)->toDateString();

        if ($debut !== null && $fin !== null && $fin < $debut) {
            throw new InvalidArgumentException('La date de fin ne peut pas précéder la date d\'effet.');
        }

        return [$debut, $fin];
    }

    private function normaliserDate(CarbonInterface|string $date): Carbon
    {
        try {
            return $date instanceof CarbonInterface
                ? Carbon::parse($date->format('Y-m-d'))->startOfDay()
                : Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Date invalide.');
        }
    }

    /**
     * @param  array<string, mixed>  $valeur
     * @return array<string, mixed>
     */
    private function canonicaliser(array $valeur): array
    {
        ksort($valeur);

        foreach ($valeur as $cle => $element) {
            if (is_array($element)) {
                $valeur[$cle] = $this->canonicaliser($element);
            }
        }

        return $valeur;
    }

    /**
     * @return array{code: string, version: int, anomalie: string}
     */
    private function anomalie(RegleAcademiqueVersion $regle, string $anomalie): array
    {
        return [
            'code' => $regle->code,
            'version' => (int) $regle->version,
            'anomalie' => $anomalie,
        ];
    }

    private function estViolationUnicite(QueryException $e): bool
    {
        $message = mb_strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'constraint');
    }
}
