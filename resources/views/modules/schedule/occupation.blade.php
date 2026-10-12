@extends('layouts.app')

@section('title', 'Occupation des salles — Scolarité')

@section('content')
    @php($joursFr = ['monday' => 'Lundi', 'tuesday' => 'Mardi', 'wednesday' => 'Mercredi', 'thursday' => 'Jeudi', 'friday' => 'Vendredi', 'saturday' => 'Samedi', 'sunday' => 'Dimanche'])
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8" x-data="{ detailSalle: null }">
        <x-page-header titre="Occupation des salles" description="Matrice salle × heure — cliquez sur un créneau occupé pour le détail."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Occupation' => '#']" />

        <form method="GET" action="{{ route('admin.emplois-du-temps.occupation') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <label for="o-jour" class="sr-only">Jour</label>
            <select id="o-jour" name="jour" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                @foreach($jours as $j)
                    <option value="{{ $j }}" @selected($jour === $j)>{{ $joursFr[$j] }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Afficher</x-btn>
        </form>

        <section aria-label="Matrice d'occupation du {{ strtolower($joursFr[$jour]) }}" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[720px] text-center text-xs">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th scope="col" class="px-4 py-3 text-left font-bold">Salle</th>
                        @foreach($heures as $heure)
                            <th scope="col" class="px-2 py-3 font-bold tabular-nums">{{ substr($heure, 0, 2) }}h</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($salles as $salle)
                        <tr class="hover:bg-slate-50/60">
                            <th scope="row" class="px-4 py-2.5 text-left font-mono font-bold text-slate-800">{{ $salle->code }}</th>
                            @foreach($heures as $heure)
                                @php($cellule = $occupation[$salle->id][$heure] ?? null)
                                <td class="px-1 py-1">
                                    @if($cellule === null)
                                        <span class="block rounded-lg bg-emerald-50 border border-emerald-200 px-1 py-2 text-[10px] font-bold text-emerald-700" title="Libre">✓</span>
                                    @else
                                        <button type="button"
                                                @click="detailSalle = @js($cellule)"
                                                class="block w-full rounded-lg bg-rose-100 border border-rose-300 px-1 py-2 text-[10px] font-black text-rose-900 min-h-[36px]"
                                                aria-label="Occupé : {{ $cellule['matiere'] }}, {{ $cellule['groupe'] }} — voir le détail">█</button>
                                    @endif
                                </td>
                            @endforeach
                    @endforeach
                </tbody>
            </table>
        </section>

        <x-modal titre="Créneau occupant" nom="detailSalle">
            <template x-if="detailSalle">
                <dl class="space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Cours</dt><dd class="font-black" x-text="detailSalle.matiere"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Groupe</dt><dd class="font-bold" x-text="detailSalle.groupe"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Enseignant</dt><dd class="font-bold" x-text="detailSalle.enseignant"></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Horaire</dt><dd class="font-bold tabular-nums" x-text="detailSalle.debut + ' → ' + detailSalle.fin"></dd></div>
                </dl>
            </template>
            <x-slot:actions>
                <template x-if="detailSalle">
                    <a :href="'{{ url('admin/emplois-du-temps') }}/' + detailSalle.planning_id" class="rounded-xl bg-primary-800 px-4 py-2.5 text-xs font-bold text-white min-h-[44px] inline-flex items-center">Voir le planning</a>
                </template>
            </x-slot:actions>
        </x-modal>
    </div>
@endsection
