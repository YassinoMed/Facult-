@extends('layouts.app')

@section('title', 'Conflits détectés — Scolarité')

@section('content')
    @php($joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'])
    @php($typesFr = ['tous' => 'Tous', 'salle' => 'Salle', 'enseignant' => 'Enseignant', 'groupe' => 'Groupe'])
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'⚠ Conflits (' . count($conflits) . ')'" description="Chevauchements détectés par balayage des créneaux actifs — résolvez depuis le tableau du planning."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Conflits' => '#']" />

        <form method="GET" action="{{ route('admin.emplois-du-temps.conflits') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <label for="c-type" class="sr-only">Filtrer par type</label>
            <select id="c-type" name="type" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                @foreach($typesFr as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($type === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        @if($conflits !== [])
            <ol class="mt-4 space-y-3">
                @foreach($conflits as $conflit)
                    <li class="ds-card p-4 sm:p-5">
                        <p class="text-sm font-black text-slate-900">
                            Conflit #{{ str_pad($conflit['id'], 3, '0', STR_PAD_LEFT) }}
                            <span class="ml-1 rounded-full border border-amber-300 bg-amber-50 px-2 py-0.5 text-[10px] uppercase">{{ $typesFr[$conflit['type']] }}</span>
                        </p>
                        <p class="mt-1 text-xs font-bold text-slate-600 tabular-nums">{{ $joursFr[$conflit['jour']] }} {{ $conflit['debut'] }} → {{ $conflit['fin'] }}</p>
                        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                                <p class="font-black text-slate-900">{{ $conflit['a']['matiere'] }}</p>
                                <p class="text-slate-600">{{ $conflit['a']['groupe'] }} · {{ $conflit['a']['enseignant'] }} · {{ $conflit['a']['salle'] }}</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3">
                                <p class="font-black text-slate-900">{{ $conflit['b']['matiere'] }}</p>
                                <p class="text-slate-600">{{ $conflit['b']['groupe'] }} · {{ $conflit['b']['enseignant'] }} · {{ $conflit['b']['salle'] }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-btn variant="secondary" href="{{ route('admin.emplois-du-temps.tableau', $conflit['a']['planning_id']) }}">Résoudre (tableau)</x-btn>
                        </div>
                    </li>
                @endforeach
            </ol>
        @else
            <div class="mt-4"><x-empty-state message="Aucun conflit détecté sur votre périmètre. ✓" /></div>
        @endif
    </div>
@endsection
