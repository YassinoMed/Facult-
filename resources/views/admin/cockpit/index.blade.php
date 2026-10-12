@extends('layouts.app')

@section('title', 'Cockpit')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Cockpit" description="Ce qui nécessite une intervention humaine maintenant."
            :fil="['Cockpit' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.taches.index') }}">Centre de tâches ({{ $aTraiter }})</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.statistiques.index') }}">Statistiques</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if($urgentes->isNotEmpty())
            <section aria-label="Urgent" class="mt-4 ds-card border-rose-200 p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-rose-700">Urgent ({{ $urgentes->count() }})</h2>
                <x-task-list :taches="$urgentes" />
            </section>
        @endif

        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Mes tâches ({{ $miennes->count() }})</h2>
                <x-task-list :taches="$miennes" />
            </section>
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Aujourd’hui</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($aujourdhui as $element)
                        <li class="py-2"><strong>{{ $element->titre }}</strong> <span class="text-slate-400">· {{ $element->type }}</span></li>
                    @empty
                        <li class="py-2 text-slate-500">Rien de prévu aujourd’hui.</li>
                    @endforelse
                </ul>
                @if($demandesEcheance->isNotEmpty())
                    <h3 class="mt-3 text-xs font-black uppercase tracking-wider text-amber-600">Demandes hors délai</h3>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach($demandesEcheance as $demande)
                            <li class="py-1.5"><a href="{{ route('admin.demandes.show', $demande) }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $demande->reference }}</a> <span class="text-slate-400">· {{ $demande->objet }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-2">
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Activité récente</h2>
                <x-timeline :elements="$activite->map(fn ($a) => ['titre' => $a->action, 'detail' => ($a->etudiant?->nom_complet ?? '') . ' — ' . ($a->utilisateur?->name ?? 'système'), 'date' => $a->created_at?->format('d/m H:i')])->all()" />
            </section>
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Récemment consultés</h2>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($recents as $recent)
                        <li class="py-1.5"><a href="{{ $recent->lien }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $recent->titre }}</a> <span class="text-slate-400">· {{ $recent->type }}</span></li>
                    @empty
                        <li class="py-2 text-slate-500">Aucun élément récent.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
@endsection
