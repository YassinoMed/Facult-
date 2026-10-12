@extends('layouts.app')

@section('title', 'GED — Recherche documentaire')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="GED — Recherche documentaire" description="Les 3 silos en lecture seule, téléchargement via routes autorisées."
            :fil="['GED' => '#']" />

        <form method="get" action="{{ route('admin.ged.index') }}" class="mt-4 flex gap-2" role="search">
            <label for="q" class="sr-only">Rechercher un document (2 lettres minimum)</label>
            <input id="q" type="search" name="q" value="{{ $q }}" placeholder="Nom, type, catégorie, référence…"
                class="w-full max-w-md rounded-xl border border-slate-200 px-4 py-2 text-sm" />
            <x-btn size="sm" type="submit">Rechercher</x-btn>
        </form>

        @foreach($sections as $titre => $documents)
            <section class="mt-4 ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">{{ $titre }} ({{ $documents->count() }})</h2>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($documents as $document)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <p class="font-bold text-slate-900">{{ $document['titre'] }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $document['detail'] }}</p>
                            </div>
                            <a href="{{ $document['lien'] }}" class="shrink-0 text-xs font-bold text-primary-800 underline underline-offset-2">Télécharger</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if($q !== '' && empty($sections))
            <div class="mt-4"><x-empty-state message="Aucun document trouvé." /></div>
        @endif
    </div>
@endsection
