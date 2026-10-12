@extends('layouts.app')

@section('title', 'Tâches')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Centre de tâches" description="Ce qui doit être fait, avec l’action directe."
            :fil="['Tâches' => '#']" />

        <form method="get" action="{{ route('admin.taches.index') }}" class="mt-4 flex flex-wrap items-center gap-2">
            <select name="statut" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                <option value="">Tous statuts</option>
                @foreach(\App\Modules\Tasks\Models\Task::STATUTS as $statut)
                    <option value="{{ $statut }}" @selected(request('statut') === $statut)>{{ $statut }}</option>
                @endforeach
            </select>
            <select name="priorite" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                <option value="">Toutes priorités</option>
                @foreach(\App\Modules\Tasks\Models\Task::PRIORITES as $priorite)
                    <option value="{{ $priorite }}" @selected(request('priorite') === $priorite)>{{ $priorite }}</option>
                @endforeach
            </select>
            <label class="inline-flex items-center gap-1 text-sm"><input type="checkbox" name="miennes" value="1" @checked(request()->boolean('miennes')) /> Mes tâches</label>
            <x-btn size="sm" type="submit">Filtrer</x-btn>
        </form>

        @if($vues->isNotEmpty())
            <div class="mt-2 flex flex-wrap gap-1.5">
                @foreach($vues as $vue)
                    <a href="{{ route('admin.taches.index', $vue->filtres) }}" class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-bold text-slate-600">{{ $vue->nom }}</a>
                @endforeach
            </div>
        @endif

        <form method="post" action="{{ route('admin.taches.bulk') }}" class="mt-4">
            @csrf
            <x-task-list :taches="$taches" />
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <select name="action" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="demarrer">Marquer en cours</option>
                    <option value="terminer">Terminer</option>
                </select>
                <x-btn size="sm" variant="secondary" type="submit">Appliquer à la sélection</x-btn>
            </div>
        </form>
    </div>
@endsection
