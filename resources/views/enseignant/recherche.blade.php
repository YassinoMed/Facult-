@extends('layouts.app')

@section('title', 'Recherche — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Recherche" description="Vos étudiants, séances, épreuves et supports uniquement."
            :fil="['Tableau de bord' => route('enseignant.dashboard'), 'Recherche' => '#']" />

        <form method="GET" action="{{ route('enseignant.recherche') }}" role="search" class="mt-4">
            <label for="q" class="sr-only">Rechercher (2 caractères minimum)</label>
            <div class="flex gap-2">
                <input type="search" id="q" name="q" value="{{ $motif }}" minlength="2" maxlength="100" required
                    placeholder="Nom d'étudiant, n° inscription, code matière, épreuve…"
                    class="flex-1 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                <x-btn type="submit">Rechercher</x-btn>
            </div>
        </form>

        @if($motif !== '' && mb_strlen($motif) < 2)
            <div class="mt-4"><x-alert type="error" message="Saisissez au moins 2 caractères." /></div>
        @elseif($motif !== '')
            @forelse($sections as $titre => $resultats)
                <section aria-label="{{ $titre }}" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800">
                        <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">{{ $titre }} ({{ count($resultats) }})</h3>
                    </div>
                    @if($resultats !== [])
                        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach($resultats as $item)
                                <li class="px-5 py-3 text-sm">
                                    @if($item['lien'])
                                        <a href="{{ $item['lien'] }}" class="font-bold text-indigo-700 dark:text-indigo-300 underline underline-offset-2">{{ $item['titre'] }}</a>
                                    @else
                                        <p class="font-bold text-slate-800 dark:text-slate-100">{{ $item['titre'] }}</p>
                                    @endif
                                    @if($item['detail'] !== '')<p class="text-slate-500">{{ $item['detail'] }}</p>@endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <div class="p-5"><x-empty-state message="Aucun résultat dans cette rubrique." /></div>
                    @endif
                </section>
            @empty
                <div class="mt-4"><x-empty-state message="Aucun résultat." /></div>
            @endforelse
        @else
            <div class="mt-4"><x-empty-state message="Lancez une recherche pour voir vos résultats." /></div>
        @endif
    </div>
@endsection
