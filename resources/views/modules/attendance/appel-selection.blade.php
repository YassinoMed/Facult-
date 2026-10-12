@extends('layouts.app')

@section('title', 'Faire l’appel — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Faire l’appel" description="Vos séances appelables : aujourd’hui et jours précédents, jamais de futur."
            :fil="['Présences' => route('enseignant.presences.index'), 'Appel' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <form method="GET" action="{{ route('enseignant.appel.selection') }}" class="mt-4 flex flex-wrap gap-2 items-end">
            <div>
                <label for="date" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Date de séance</label>
                <input type="date" id="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}"
                       class="rounded-xl border border-slate-300 dark:border-slate-700 px-3 py-2 text-sm bg-white dark:bg-slate-900 min-h-[44px]">
            </div>
            <x-btn type="submit" variant="secondary">Afficher</x-btn>
        </form>

        <section aria-label="Séances appelables" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
            @forelse($seances as $ligne)
                <article class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-5">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-black text-slate-900 dark:text-slate-100">{{ $ligne['slot']->matiere?->nom ?? '—' }}</h3>
                            <p class="text-xs text-slate-500">{{ $ligne['slot']->schedule?->groupe?->nom ?? '—' }} · Salle {{ $ligne['slot']->room?->code ?? '—' }} · {{ strtoupper($ligne['slot']->type) }}</p>
                            <p class="text-xs tabular-nums text-slate-500">{{ substr((string) $ligne['slot']->start_time, 0, 5) }} → {{ substr((string) $ligne['slot']->end_time, 0, 5) }} · {{ $ligne['effectif'] }} étudiant(s) attendu(s)</p>
                        </div>
                        <x-status-badge :statut="$ligne['appel']?->statut ?? 'non_commence'" />
                    </div>
                    <form method="POST" action="{{ route('enseignant.appel.ouvrir', ['slot' => $ligne['slot']->id, 'date' => $date]) }}" class="mt-3">
                        @csrf
                        <x-btn type="submit">{{ $ligne['appel'] ? ($ligne['appel']->estCloture() ? 'Revoir l’appel clôturé' : 'Reprendre l’appel') : 'Faire l’appel' }}</x-btn>
                    </form>
                </article>
            @empty
                <div class="md:col-span-2"><x-empty-state message="Aucune séance à appeler à cette date." /></div>
            @endforelse
        </section>
    </div>
@endsection
