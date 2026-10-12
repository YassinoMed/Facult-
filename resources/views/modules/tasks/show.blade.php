@extends('layouts.app')

@section('title', 'Tâche')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$tache->titre" :description="$tache->source . ' · ' . $tache->statut"
            :fil="['Tâches' => route('admin.taches.index'), 'Détail' => '#']">
            <x-slot:actions>
                @can('update', $tache)
                    @foreach(\App\Modules\Tasks\Models\Task::TRANSITIONS[$tache->statut] ?? [] as $cible)
                        <form method="post" action="{{ route('admin.taches.transiter', [$tache, $cible]) }}" class="inline">
                            @csrf
                            <x-btn size="sm" variant="secondary" type="submit">→ {{ $cible }}</x-btn>
                        </form>
                    @endforeach
                @endcan
                @if($tache->lien)
                    <x-btn size="sm" href="{{ $tache->lien }}">Ouvrir l’objet métier</x-btn>
                @endif
            </x-slot:actions>
        </x-page-header>

        @if($tache->description)
            <div class="mt-4 ds-card p-5 text-sm">{{ $tache->description }}</div>
        @endif

        <section class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Historique</h2>
            <x-timeline :elements="$tache->evenements->map(fn ($e) => ['titre' => $e->action, 'detail' => $e->commentaire . ($e->auteur ? ' — ' . $e->auteur->name : ''), 'date' => $e->created_at?->format('d/m H:i')])->all()" />
        </section>
    </div>
@endsection
