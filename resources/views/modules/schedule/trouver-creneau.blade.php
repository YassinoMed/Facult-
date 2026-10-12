@extends('layouts.app')

@section('title', 'Trouver un créneau — Scolarité')

@section('content')
    @php($joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'])
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Trouver un créneau disponible" description="Groupe + enseignant + durée (+ salle optionnelle) : le système vérifie conflits, capacité et contraintes."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Créneau' => '#']" />

        <form method="GET" action="{{ route('admin.emplois-du-temps.trouver-creneau') }}" class="mt-4 ds-card p-4 sm:p-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label for="c-groupe" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Groupe <span class="text-rose-600">*</span></label>
                    <select id="c-groupe" name="groupe_id" required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">— Choisir —</option>
                        @foreach($groupes as $groupe)
                            <option value="{{ $groupe->id }}" @selected(($choix['groupe_id'] ?? null) == $groupe->id)>{{ $groupe->code }} — {{ $groupe->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="c-enseignant" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Enseignant</label>
                    <select id="c-enseignant" name="enseignant_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Indifférent</option>
                        @foreach($enseignants as $enseignant)
                            <option value="{{ $enseignant->id }}" @selected(($choix['enseignant_id'] ?? null) == $enseignant->id)>{{ $enseignant->nomComplet() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="c-duree" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Durée</label>
                    <select id="c-duree" name="duree" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        @foreach([60 => '1h', 90 => '1h30', 120 => '2h', 180 => '3h', 240 => '4h'] as $minutes => $libelle)
                            <option value="{{ $minutes }}" @selected(($choix['duree'] ?? 120) == $minutes)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="c-salle" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Salle</label>
                    <select id="c-salle" name="salle_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        <option value="">Indifférente</option>
                        @foreach($salles as $salle)
                            <option value="{{ $salle->id }}" @selected(($choix['salle_id'] ?? null) == $salle->id)>{{ $salle->code }} ({{ $salle->capacite ?? '—' }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-3"><x-btn type="submit">Rechercher</x-btn></div>
        </form>

        @if(!empty($choix['groupe_id']))
            <section aria-label="Créneaux disponibles" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">{{ count($libres) }} créneau(x) disponible(s)</h2>
                @if($libres !== [])
                    <ul class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                        @foreach(array_slice($libres, 0, 24) as $creneau)
                            <li class="flex items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-3.5 py-2.5 font-bold text-emerald-900">
                                <span aria-hidden="true">✓</span> {{ $joursFr[$creneau['jour']] }} {{ $creneau['debut'] }} → {{ $creneau['fin'] }}
                            </li>
                        @endforeach
                    </ul>
                    @if(count($libres) > 24)
                        <p class="mt-2 text-xs text-slate-500">+ {{ count($libres) - 24 }} autres créneaux possibles.</p>
                    @endif
                @else
                    <div class="mt-3"><x-empty-state message="Aucun créneau compatible : contraintes ou conflits sur toute la semaine." /></div>
                @endif
            </section>
        @endif
    </div>
@endsection
