@extends('layouts.app')

@section('title', 'Recherche globale — Scolarité')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Recherche globale" description="Étudiants, enseignants, groupes, matières, salles, plannings et demandes — dans votre périmètre."
            :fil="['Tableau de bord' => route('admin.dashboard'), 'Recherche' => '#']" />

        <form method="GET" action="{{ route('admin.recherche') }}" role="search" class="mt-4">
            <label for="recherche-q" class="sr-only">Rechercher (2 caractères minimum)</label>
            <div class="flex gap-2">
                <input type="search" id="recherche-q" name="q" value="{{ $q }}" minlength="2" required
                       placeholder="CIN, nom, matière, salle, référence…"
                       class="flex-1 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm focus:border-primary-700 focus:outline-none min-h-[48px]">
                <x-btn type="submit">Chercher</x-btn>
            </div>
        </form>

        @if($q !== '')
            @php $total = collect($sections)->sum(fn ($s) => $s['resultats']->count()); @endphp
            @if($total > 0)
                @foreach($sections as $titre => $section)
                    @if($section['resultats']->isNotEmpty())
                        <section aria-label="Résultats {{ $titre }}" class="mt-6 ds-card overflow-hidden">
                            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                                <h2 class="text-sm font-black text-slate-900">{{ $titre }} ({{ $section['resultats']->count() }})</h2>
                            </div>
                            <ul class="divide-y divide-slate-100">
                                @foreach($section['resultats'] as $resultat)
                                    <li class="px-5 py-3 text-sm">
                                        @if($resultat['lien'] !== null)
                                            <a href="{{ $resultat['lien'] }}" class="block font-bold text-slate-900 hover:text-primary-700 hover:underline underline-offset-2">{{ $resultat['titre'] }}</a>
                                        @else
                                            <p class="font-bold text-slate-900">{{ $resultat['titre'] }}</p>
                                        @endif
                                        <p class="text-[11px] text-slate-500">{{ $resultat['detail'] }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif
                @endforeach
            @else
                <div class="mt-6">
                    <x-empty-state message="Aucun résultat pour « {{ $q }} » dans votre périmètre." />
                </div>
            @endif
        @endif
    </div>
@endsection
