@extends('layouts.app')

@section('title', 'Disponibilités — Scolarité')

@section('content')
    @php($joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'])
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Qui est disponible ?" description="Enseignants et salles libres sur un intervalle — calculés côté serveur sur les plannings réels."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Disponibilités' => '#']" />

        <form method="GET" action="{{ route('admin.emplois-du-temps.disponibilites') }}" class="mt-4 ds-card p-4 sm:p-5">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div>
                    <label for="d-jour" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Jour</label>
                    <select id="d-jour" name="jour" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        @foreach($jours as $j)
                            <option value="{{ $j }}" @selected($jour === $j)>{{ $joursFr[$j] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="d-debut" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Début</label>
                    <input id="d-debut" type="time" name="debut" value="{{ $debut }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                </div>
                <div>
                    <label for="d-fin" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Fin</label>
                    <input id="d-fin" type="time" name="fin" value="{{ $fin }}" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                </div>
                <div>
                    <label for="d-cap" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Capacité min. (salles)</label>
                    <input id="d-cap" type="number" name="capacite_min" value="{{ $capaciteMin }}" min="1" max="10000" placeholder="Ex. 30" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                </div>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-btn type="submit">Rechercher</x-btn>
            </div>
        </form>

        <p class="mt-3 text-xs text-slate-500" role="status">{{ $joursFr[$jour] }} {{ $debut }} → {{ $fin }} :
            <strong class="tabular-nums">{{ $enseignants->where('libre', true)->count() }}/{{ $enseignants->count() }}</strong> enseignants libres,
            <strong class="tabular-nums">{{ $salles->where('libre', true)->count() }}/{{ $salles->count() }}</strong> salles libres.</p>

        <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
            <section aria-label="Enseignants disponibles" class="ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Enseignants</h2>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($enseignants as $enseignant)
                        <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                            <span class="font-bold text-slate-800">{{ $enseignant['nom'] }}</span>
                            @if($enseignant['libre'])
                                <span class="text-xs font-bold text-emerald-700">✓ Disponible</span>
                            @elseif($enseignant['indisponible'] ?? false)
                                <span class="text-xs font-bold text-amber-700" title="Indisponibilité déclarée">✕ Indisponible <span class="font-normal text-slate-500">({{ $enseignant['indisponible_par'] }})</span></span>
                            @else
                                <span class="text-xs font-bold text-rose-700" title="{{ $enseignant['occupe_par'] }}">✕ Occupé <span class="font-normal text-slate-500">({{ $enseignant['occupe_par'] }})</span></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
            <section aria-label="Salles disponibles" class="ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Salles</h2>
                <ul class="mt-3 space-y-1.5 text-sm">
                    @foreach($salles as $salle)
                        <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                            <span class="font-bold text-slate-800">{{ $salle['code'] }}
                                <span class="font-normal text-slate-500 tabular-nums">· {{ $salle['capacite'] ?? '—' }} places</span></span>
                            @if($salle['libre'])
                                <span class="text-xs font-bold text-emerald-700">✓ Libre</span>
                            @elseif($salle['indisponible'] ?? false)
                                <span class="text-xs font-bold text-amber-700" title="Indisponibilité déclarée">✕ Indisponible <span class="font-normal text-slate-500">({{ $salle['indisponible_par'] }})</span></span>
                            @else
                                <span class="text-xs font-bold text-rose-700" title="{{ $salle['occupe_par'] }}">✕ Occupée</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>

        @if($capaciteMin !== null)
            <section aria-label="Salles correspondant à la capacité" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">{{ $sallesLibres->count() }} salle(s) ≥ {{ $capaciteMin }} places</h2>
                @if($sallesLibres->isNotEmpty())
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        @foreach($sallesLibres as $salle)
                            <div class="rounded-2xl border border-slate-200 p-4">
                                <p class="text-base font-black text-slate-900">{{ $salle->code }}</p>
                                <p class="text-xs text-slate-500 tabular-nums">{{ $salle->capacite }} places</p>
                                <p class="mt-1 text-xs font-bold text-emerald-700">✓ Libre {{ $joursFr[$jour] }} {{ $debut }} → {{ $fin }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-3"><x-empty-state message="Aucune salle libre avec cette capacité sur cet intervalle." /></div>
                @endif
            </section>
        @endif
    </div>
@endsection
