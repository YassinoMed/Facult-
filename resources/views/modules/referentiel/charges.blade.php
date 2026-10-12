@extends('layouts.app')

@section('title', 'Charge pédagogique — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Charge pédagogique" description="Indicateurs descriptifs par semestre et niveau, calculés sur le canonique. Aucune norme de dépassement : les écarts sont présentés, au responsable de les qualifier."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Pilotage' => route('admin.referentiel.pilotage'), 'Charge' => '#']">
        </x-page-header>

        <form method="GET" action="{{ route('admin.referentiel.charges') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <select name="programme_id" aria-label="Choisir un programme" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] flex-1" onchange="this.form.submit()">
                @foreach($programmes as $option)
                    <option value="{{ $option->id }}" @selected($programme?->id === $option->id)>{{ $option->code }} — {{ $option->nom }}</option>
                @endforeach
            </select>
        </form>

        @if($analyse !== null)
            <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                @foreach([
                    ['Crédits', $analyse['totaux']['credits']],
                    ['Heures totales', $analyse['totaux']['heures']],
                    ['CM / TD / TP', $analyse['totaux']['cm'].' / '.$analyse['totaux']['td'].' / '.$analyse['totaux']['tp']],
                    ['UE / ECUE', $analyse['totaux']['ues'].' / '.$analyse['totaux']['ecues']],
                    ['Crédits min/max par semestre', ($analyse['ecarts']['credits_min'] ?? '—').' / '.($analyse['ecarts']['credits_max'] ?? '—')],
                    ['Liens prérequis', $analyse['liens_prerequis']],
                ] as [$libelle, $valeur])
                    <div class="ds-card p-4">
                        <p class="text-xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                    </div>
                @endforeach
            </div>

            @foreach($analyse['niveaux'] as $niveau)
                <section aria-label="Niveau {{ $niveau['code'] }}" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <table class="w-full min-w-[680px] text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                                <th scope="col" class="px-5 py-3 font-bold">{{ $niveau['code'] }}</th>
                                <th scope="col" class="px-5 py-3 font-bold">UE</th>
                                <th scope="col" class="px-5 py-3 font-bold">ECUE</th>
                                <th scope="col" class="px-5 py-3 font-bold">Crédits</th>
                                <th scope="col" class="px-5 py-3 font-bold">CM</th>
                                <th scope="col" class="px-5 py-3 font-bold">TD</th>
                                <th scope="col" class="px-5 py-3 font-bold">TP</th>
                                <th scope="col" class="px-5 py-3 font-bold">Heures</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($niveau['semestres'] as $semestre)
                                <tr class="hover:bg-slate-50/60">
                                    <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ $semestre['code'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $semestre['ues'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $semestre['ecues'] }}</td>
                                    <td class="px-5 py-3 tabular-nums font-black">{{ $semestre['credits'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $semestre['cm'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $semestre['td'] }}</td>
                                    <td class="px-5 py-3 tabular-nums">{{ $semestre['tp'] }}</td>
                                    <td class="px-5 py-3 tabular-nums font-bold">{{ $semestre['heures'] }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-slate-50/70 font-bold">
                                <td class="px-5 py-3">Total {{ $niveau['code'] }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $niveau['totaux']['ues'] }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $niveau['totaux']['ecues'] }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $niveau['totaux']['credits'] }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $niveau['totaux']['cm'] }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $niveau['totaux']['td'] }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $niveau['totaux']['tp'] }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $niveau['totaux']['cm'] + $niveau['totaux']['td'] + $niveau['totaux']['tp'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            @endforeach

            <section aria-label="Types d'UE" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Répartition des types d'UE</h2>
                <ul class="mt-2 flex flex-wrap gap-2 text-xs text-slate-600">
                    @forelse($analyse['types_ue'] as $type => $nombre)
                        <li class="rounded-full bg-slate-100 px-3 py-1.5 tabular-nums"><strong>{{ $type }}</strong> : {{ $nombre }}</li>
                    @empty
                        <li>Aucune UE.</li>
                    @endforelse
                </ul>
                <p class="mt-3 text-[11px] text-slate-500">Sources : {{ $analyse['sources'] }}.<br>Formules : {{ $analyse['formules'] }}</p>
            </section>
        @else
            <div class="mt-4"><x-empty-state message="Aucun programme dans ce périmètre." /></div>
        @endif
    </div>
@endsection
