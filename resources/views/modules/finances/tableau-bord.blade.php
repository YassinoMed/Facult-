@extends('layouts.app')

@section('title', 'Finances')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Finances universitaires" description="Facturation, encaissements, impayés, remboursements."
            :fil="['Finances' => '#']">
            <x-slot:actions>
                @if(config('inscriptions-paiements.actif', true))
                    <x-btn variant="primary" href="{{ route('admin.finances.paiements.index') }}">Reçus d’inscription</x-btn>
                @endif
                <x-btn variant="secondary" href="{{ route('admin.finances.factures.index') }}">Factures</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.finances.remboursements.index') }}">Remboursements</x-btn>
                @can('finances.manage')
                    <x-btn variant="secondary" href="{{ route('admin.finances.tarifs.index') }}">Tarifs</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <x-stat-card libelle="Facturé" :valeur="\App\Modules\Finances\Models\Facture::formater($total)" />
            <x-stat-card libelle="Encaissé" :valeur="\App\Modules\Finances\Models\Facture::formater($encaisse)" couleur="emerald" />
            <x-stat-card libelle="Reste dû" :valeur="\App\Modules\Finances\Models\Facture::formater($reste)" couleur="amber" />
            <x-stat-card libelle="En retard" :valeur="\App\Modules\Finances\Models\Facture::formater($en_retard)" couleur="rose" />
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Factures impayées</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $impayees }}</p>
            </div>
            <a href="{{ route('admin.finances.remboursements.index') }}"
               class="ds-card p-4 transition hover:border-primary-300 hover:bg-primary-50/40 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary-500/20">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Remboursements en attente</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $remboursements }}</p>
                <p class="mt-1 text-xs font-semibold text-primary-700">Ouvrir les remboursements →</p>
            </a>
            @if(config('inscriptions-paiements.actif', true))
                <a href="{{ route('admin.finances.paiements.index') }}"
                   class="ds-card p-4 transition hover:border-primary-300 hover:bg-primary-50/40 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary-500/20">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Reçus d’inscription à vérifier</p>
                    <p class="mt-1 text-2xl font-black tabular-nums text-primary-800">{{ $recus_a_verifier }}</p>
                    <p class="mt-1 text-xs font-semibold text-primary-700">Ouvrir la file de vérification →</p>
                </a>
            @endif
        </div>
    </div>
@endsection
