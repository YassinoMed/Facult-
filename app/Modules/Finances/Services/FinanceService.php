<?php

namespace App\Modules\Finances\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\Finances\Events\FactureEmise;
use App\Modules\Finances\Events\PaiementConfirme;
use App\Modules\Finances\Events\RemboursementTraite;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\Paiement;
use App\Modules\Finances\Models\Remboursement;
use App\Modules\Finances\Models\Tarif;
use App\Services\NotificationService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Services financiers (§23) : tarifs configurables, facturation,
 * paiements confirmés (idempotents), remboursements contrôlés.
 * Montants TOUJOURS en centimes INTEGER. Aucune donnée bancaire.
 */
class FinanceService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Tarifs applicables : année + programme/niveau les plus spécifiques.
     *
     * @return Collection<int, Tarif>
     */
    public function tarifsApplicables(InscriptionAcademique $inscription)
    {
        $inscription->loadMissing('etudiant');

        return Tarif::where('annee_universitaire_id', $inscription->annee_universitaire_id)
            ->where('actif', true)
            ->where(fn ($q) => $q
                ->whereNull('programme_id')
                ->orWhere('programme_id', $inscription->etudiant->programme_id))
            ->orderBy('code')
            ->get();
    }

    /**
     * Émet une facture depuis une inscription (idempotente par
     * référence métier : une facture ouverte par inscription).
     */
    public function emettreFacture(InscriptionAcademique $inscription, int $auteurId, ?string $typeFrais = null): Facture
    {
        return DB::transaction(function () use ($inscription, $auteurId, $typeFrais) {
            $existante = Facture::where('inscription_academique_id', $inscription->id)
                ->whereNotIn('statut', ['cancelled'])
                ->first();

            if ($existante !== null) {
                return $existante;
            }

            $tarifs = $this->tarifsApplicables($inscription)
                ->when($typeFrais !== null, fn ($c) => $c->where('type_frais', $typeFrais));

            if ($tarifs->isEmpty()) {
                throw new DomainException('Aucun tarif applicable à cette inscription.');
            }

            $facture = Facture::create([
                'reference' => $this->reference('FAC'),
                'etudiant_id' => $inscription->etudiant_id,
                'annee_universitaire_id' => $inscription->annee_universitaire_id,
                'inscription_academique_id' => $inscription->id,
                'statut' => 'issued',
                'total_centimes' => $tarifs->sum('montant_centimes'),
                'date_echeance' => now()->addDays(30)->format('Y-m-d'),
                'created_by' => $auteurId,
            ]);

            foreach ($tarifs as $tarif) {
                $facture->lignes()->create([
                    'tarif_id' => $tarif->id,
                    'libelle' => $tarif->libelle,
                    'montant_centimes' => $tarif->montant_centimes,
                ]);
            }

            FactureEmise::dispatch($facture->id);

            return $facture->fresh();
        });
    }

    /**
     * Enregistre un paiement guichet (pending), puis confirmation
     * séparée — jamais de reçu avant confirmation réelle.
     */
    public function enregistrerPaiement(Facture $facture, int $montantCentimes, string $methode, int $auteurId, ?string $referenceExterne = null): Paiement
    {
        return DB::transaction(function () use ($facture, $montantCentimes, $methode, $auteurId, $referenceExterne) {
            if (in_array($facture->statut, ['cancelled', 'refunded'], true)) {
                throw new DomainException('Facture clôturée : paiement refusé.');
            }

            if ($montantCentimes <= 0 || $montantCentimes > $facture->resteCentimes()) {
                throw new DomainException('Montant invalide (trop-perçu interdit).');
            }

            if ($referenceExterne !== null && Paiement::where('reference_externe', $referenceExterne)->exists()) {
                throw new DomainException('Référence externe déjà utilisée (idempotence).');
            }

            return Paiement::create([
                'reference' => $this->reference('PAY'),
                'facture_id' => $facture->id,
                'montant_centimes' => $montantCentimes,
                'methode' => $methode,
                'statut' => 'pending',
                'reference_externe' => $referenceExterne,
                'enregistre_par' => $auteurId,
            ]);
        });
    }

    public function confirmerPaiement(Paiement $paiement, int $auteurId): Paiement
    {
        return DB::transaction(function () use ($paiement, $auteurId) {
            $paiement->refresh();

            if ($paiement->statut !== 'pending') {
                throw new DomainException("Confirmation interdite depuis « {$paiement->statut} ».");
            }

            $paiement->update(['statut' => 'confirmed', 'confirme_par' => $auteurId, 'confirme_a' => now()]);

            $facture = $paiement->facture()->first();
            $reste = $facture->resteCentimes();
            $facture->update(['statut' => $reste === 0 ? 'paid' : 'partially_paid']);

            $this->notifications->notifierEtudiant(
                $facture->etudiant,
                'finance.paiement',
                'Paiement confirmé',
                'Paiement de '.Facture::formater($paiement->montant_centimes).' confirmé (reçu disponible).',
                route('etudiant.finances.index'),
            );

            PaiementConfirme::dispatch($paiement->id);

            return $paiement->fresh();
        });
    }

    public function demanderRemboursement(Paiement $paiement, int $montantCentimes, string $motif, int $auteurId): Remboursement
    {
        return DB::transaction(function () use ($paiement, $montantCentimes, $motif, $auteurId) {
            if (! $paiement->estConfirme()) {
                throw new DomainException('Seul un paiement confirmé peut être remboursé.');
            }

            $deja = $paiement->remboursements()->whereNotIn('statut', ['rejected'])->sum('montant_centimes');

            if ($montantCentimes <= 0 || $deja + $montantCentimes > $paiement->montant_centimes) {
                throw new DomainException('Montant de remboursement invalide.');
            }

            return Remboursement::create([
                'paiement_id' => $paiement->id,
                'montant_centimes' => $montantCentimes,
                'statut' => 'requested',
                'motif' => $motif,
                'demande_par' => $auteurId,
            ]);
        });
    }

    public function traiterRemboursement(Remboursement $remboursement, string $cible, int $auteurId): Remboursement
    {
        return DB::transaction(function () use ($remboursement, $cible, $auteurId) {
            $remboursement->refresh();

            if (! $remboursement->peutTransiterVers($cible)) {
                throw new DomainException("Transition {$remboursement->statut} → {$cible} interdite.");
            }

            $remboursement->update(['statut' => $cible, 'traite_par' => $auteurId, 'traite_a' => now()]);

            if ($cible === 'completed') {
                $remboursement->paiement->facture()->update(['statut' => 'refunded']);
            }

            RemboursementTraite::dispatch($remboursement->id, $cible);

            return $remboursement->fresh();
        });
    }

    public function annulerFacture(Facture $facture, int $auteurId, string $motif): Facture
    {
        return DB::transaction(function () use ($facture, $auteurId, $motif) {
            if (! $facture->peutTransiterVers('cancelled')) {
                throw new DomainException("Annulation interdite depuis « {$facture->statut} ».");
            }

            $facture->update(['statut' => 'cancelled', 'annulee_par' => $auteurId, 'motif_annulation' => $motif]);

            return $facture->fresh();
        });
    }

    /**
     * @return array{total: int, paye: int, reste: int, en_retard: int}
     */
    public function soldeEtudiant(Etudiant $etudiant, ?int $anneeId = null): array
    {
        $factures = Facture::where('etudiant_id', $etudiant->id)
            ->whereNotIn('statut', ['cancelled', 'draft'])
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->with('paiements')
            ->get();

        $total = 0;
        $paye = 0;
        $retard = 0;

        foreach ($factures as $facture) {
            $total += $facture->netCentimes();
            $paye += $facture->payeCentimes();

            if ($facture->resteCentimes() > 0 && $facture->date_echeance !== null && $facture->date_echeance->isPast()) {
                $retard += $facture->resteCentimes();
            }
        }

        return ['total' => $total, 'paye' => $paye, 'reste' => max(0, $total - $paye), 'en_retard' => $retard];
    }

    private function reference(string $prefixe): string
    {
        for ($i = 0; $i < 3; $i++) {
            $reference = $prefixe.'-'.now()->format('Y').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            $existe = $prefixe === 'FAC'
                ? Facture::where('reference', $reference)->exists()
                : Paiement::where('reference', $reference)->exists();

            if (! $existe) {
                return $reference;
            }
        }

        throw new \RuntimeException('Génération de référence impossible.');
    }
}
