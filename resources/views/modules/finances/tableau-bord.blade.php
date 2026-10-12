@extends('layouts.app')

@section('title', 'Finances')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Finances universitaires" description="Facturation, encaissements, impayés, remboursements."
            :fil="['Finances' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.finances.factures.index') }}">Factures</x-btn>
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

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Factures impayées</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $impayees }}</p>
            </div>
            <div class="ds-card p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Remboursements en attente</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $remboursements }}</p>
            </div>
        </div>
    </div>
@endsection
