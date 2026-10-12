@extends('layouts.app')

@section('title', 'Mes finances')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Mes finances" :description="'Solde : ' . \App\Modules\Finances\Models\Facture::formater($solde['reste']) . ' restant(s).'" :fil="['Finances' => '#']" />

        <div class="mt-4 grid grid-cols-3 gap-3">
            <x-stat-card libelle="Total" :valeur="\App\Modules\Finances\Models\Facture::formater($solde['total'])" />
            <x-stat-card libelle="Payé" :valeur="\App\Modules\Finances\Models\Facture::formater($solde['paye'])" couleur="emerald" />
            <x-stat-card libelle="En retard" :valeur="\App\Modules\Finances\Models\Facture::formater($solde['en_retard'])" couleur="rose" />
        </div>

        <div class="mt-4 space-y-3">
            @forelse($factures as $facture)
                <div class="ds-card p-4">
                    <p class="font-bold">{{ $facture->reference }} · {{ \App\Modules\Finances\Models\Facture::formater($facture->netCentimes()) }}</p>
                    <p class="mt-1"><x-status-badge :statut="$facture->statut" /></p>
                    <ul class="mt-2 text-sm">
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
