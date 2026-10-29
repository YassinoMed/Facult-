@extends('layouts.app')

@section('title', 'Mes finances')

@section('content')
    <div class="finances-page mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes finances" :description="'Solde : ' . \App\Modules\Finances\Models\Facture::formater($solde['reste']) . ' restant(s).'" :fil="['Finances' => '#']" />

        {{-- LOT 3 : ne pas confondre la facturation historique avec le
             parcours canonique des frais d'inscription (dossier/reçu). --}}
        <div class="finances-notice mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3">
            <p class="text-sm font-black text-amber-900">Facturation historique</p>
            <p class="mt-0.5 text-xs text-amber-900/80">
                Cette page présente les factures et paiements historiques. Pour les frais d’inscription annuels,
                le dépôt et la validation des reçus se font dans <strong>Mes paiements d’inscription</strong>.
            </p>
            <a href="{{ route('etudiant.paiements.index') }}" class="mt-2 inline-flex items-center gap-1.5 text-xs font-bold text-amber-900 underline underline-offset-2 hover:text-amber-700">
                Ouvrir Mes paiements d’inscription <span aria-hidden="true">→</span>
            </a>
        </div>

        <div class="finances-kpis mt-4 grid grid-cols-3 gap-3">
            <x-stat-card libelle="Total" :valeur="\App\Modules\Finances\Models\Facture::formater($solde['total'])" />
            <x-stat-card libelle="Payé" :valeur="\App\Modules\Finances\Models\Facture::formater($solde['paye'])" couleur="emerald" />
            <x-stat-card libelle="En retard" :valeur="\App\Modules\Finances\Models\Facture::formater($solde['en_retard'])" couleur="rose" />
        </div>

        <div class="mt-4 space-y-3">
            @forelse($factures as $facture)
                <div class="facture-card ds-card p-4">
                    <p class="font-bold">{{ $facture->reference }} · {{ \App\Modules\Finances\Models\Facture::formater($facture->netCentimes()) }}</p>
                    <p class="mt-1"><x-status-badge :statut="$facture->statut" /></p>
                    <ul class="facture-lignes mt-2 text-sm">
                        @foreach($facture->lignes as $ligne)
                            <li class="flex justify-between py-0.5"><span>{{ $ligne->libelle }}</span><span class="tabular-nums">{{ \App\Modules\Finances\Models\Facture::formater($ligne->montant_centimes) }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <x-empty-state message="Aucune facture." />
            @endforelse
        </div>
        <div class="mt-4">{{ $factures->links() }}</div>
    </div>
@endsection
