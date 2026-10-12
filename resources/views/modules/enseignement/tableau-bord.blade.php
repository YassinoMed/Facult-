@extends('layouts.app')

@section('title', 'Enseignements — Tableau de bord')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Enseignement" description="ECUE × type × année : volumes, affectations, statuts."
            :fil="['Enseignements' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.enseignements.index') }}">Tous les enseignements</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.enseignements.services') }}">Services enseignants</x-btn>
                <x-btn variant="ghost" href="{{ route('admin.enseignements.qualite') }}">Qualité</x-btn>
                @can('enseignements.manage')
                    <x-btn href="{{ route('admin.enseignements.create') }}">Nouvel enseignement</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            @foreach([
                ['Enseignements', $compteurs['enseignements']],
                ['Validés / actifs', $compteurs['valides']],
                ['Brouillons', $compteurs['brouillons']],
                ['Heures prévues', $compteurs['heures_prevues']],
                ['Affectations actives', $compteurs['affectations']],
            ] as [$libelle, $valeur])
                <div class="ds-card p-4">
                    <p class="text-2xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                </div>
            @endforeach
        </div>

        @if($sansEnseignant->isNotEmpty())
            <section aria-label="À corriger" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">À corriger : enseignements sans enseignant ({{ $sansEnseignant->count() }})</h2>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($sansEnseignant as $ens)
                        <li class="flex items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-2.5">
                            <span><strong>{{ $ens->matiere->code }}</strong> · {{ $ens->type->code }} · {{ $ens->anneeUniversitaire->libelle }} · {{ $ens->statut }}</span>
                            <a href="{{ route('admin.enseignements.show', $ens) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Affecter</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
@endsection
