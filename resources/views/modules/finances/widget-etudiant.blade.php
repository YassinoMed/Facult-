{{-- LOT 3/4 : frais d'inscription — source canonique = dossier/reçu validé. --}}
<section aria-label="Frais d’inscription" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h2 class="text-base font-black text-slate-900">Frais d’inscription</h2>
            @if(isset($paiementInscription))
                <p class="mt-1 text-xs text-slate-500">
                    {{ $paiementInscription->anneeUniversitaire?->libelle ?? 'Année non renseignée' }}
                    · {{ $paiementInscription->niveau ?? '—' }}
                </p>
            @endif
        </div>
        @if(isset($paiementInscription))
            @if($paiementInscription->paye || $paiementDossier?->statut === \App\Modules\Finances\Models\DossierPaiement::VALIDE)
                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-emerald-800">Validé</span>
            @elseif($paiementDossier?->statut === \App\Modules\Finances\Models\DossierPaiement::REFUSE)
                <span class="rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-rose-800">Refusé</span>
            @elseif($paiementDernierRecu?->estEnAttente())
                <span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-amber-800">En vérification</span>
            @else
                <span class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-slate-700">À effectuer</span>
            @endif
        @endif
    </div>

    @if(isset($paiementInscription))
        <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3">
            @if($paiementInscription->paye || $paiementDossier?->statut === \App\Modules\Finances\Models\DossierPaiement::VALIDE)
                <p class="text-sm font-black text-emerald-800">Paiement confirmé</p>
                <p class="mt-0.5 text-xs text-slate-600">Le reçu a été validé par la scolarité.</p>
            @elseif($paiementDossier?->statut === \App\Modules\Finances\Models\DossierPaiement::REFUSE)
                <p class="text-sm font-black text-rose-800">Reçu refusé</p>
                <p class="mt-0.5 text-xs text-slate-600">Consultez le motif et déposez un nouveau justificatif.</p>
            @elseif($paiementDernierRecu?->estEnAttente())
                <p class="text-sm font-black text-amber-800">Reçu en attente de vérification</p>
                <p class="mt-0.5 text-xs text-slate-600">Aucun paiement n’est confirmé avant la décision de la scolarité.</p>
            @else
                <p class="text-sm font-black text-slate-800">Paiement à effectuer</p>
                <p class="mt-0.5 text-xs text-slate-600">Déposez un reçu : il sera vérifié par la scolarité.</p>
            @endif

            <p class="mt-2 text-xs text-slate-500">
                Montant attendu :
                @if($paiementMontantCentimes !== null)
                    <strong class="tabular-nums text-slate-900">{{ \App\Modules\Finances\Models\Facture::formater($paiementMontantCentimes) }}</strong>
                    <span class="text-slate-400">· {{ $paiementMontantSource }}</span>
                @else
                    <strong class="text-rose-700">non configuré</strong>
                @endif
            </p>

            @if($paiementDernierRecu !== null)
                <p class="mt-1 text-xs text-slate-500">
                    Dernier reçu : {{ $paiementDernierRecu->montantDeclareFormate() }}
                    · {{ $paiementDernierRecu->libelleStatut() }}
                    @if($paiementDernierRecu->date_paiement !== null)
                        · {{ $paiementDernierRecu->date_paiement->format('d/m/Y') }}
                    @endif
                </p>
            @endif
        </div>
    @else
        <div class="mt-3">
            <x-empty-state message="Aucune inscription annuelle rattachée à votre dossier." />
        </div>
    @endif

    <a href="{{ route('etudiant.paiements.index') }}" class="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">
        Gérer mon paiement d’inscription <span aria-hidden="true">→</span>
    </a>
</section>
