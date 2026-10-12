@extends('layouts.app')

@section('title', 'Charge enseignants — Scolarité')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header titre="Charge des enseignants" description="Heures, cours et jours par enseignant — calculés sur les créneaux actifs de votre périmètre."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), 'Charge' => '#']" />

        <section aria-label="Charge enseignants" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($charges->isNotEmpty())
                <table class="w-full min-w-[560px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Enseignant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Heures</th>
                            <th scope="col" class="px-5 py-3 font-bold">Cours</th>
                            <th scope="col" class="px-5 py-3 font-bold">Jours</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Planning</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($charges as $charge)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $charge['nom'] }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $charge['heures'] }}h</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $charge['cours'] }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $charge['jours'] }}</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.emplois-du-temps.explorer', ['enseignant_id' => $charge['id']]) }}"
                                       class="font-bold text-primary-800 underline underline-offset-2">Voir le planning</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-5"><x-empty-state message="Aucune charge calculable sur votre périmètre." /></div>
            @endif
        </section>
    </div>
@endsection
