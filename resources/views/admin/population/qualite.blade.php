@extends('layouts.app')

@section('title', 'Qualité des données population')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header titre="Qualité des données — Population" description="Anomalies calculées côté serveur : gravité, entité, action corrective."
            :fil="['Population' => route('admin.population.stats'), 'Qualité' => '#']" />

        @if($anomalies !== [])
            <section aria-label="Anomalies" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[680px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Gravité</th>
                            <th scope="col" class="px-5 py-3 font-bold">Entité</th>
                            <th scope="col" class="px-5 py-3 font-bold">Problème</th>
                            <th scope="col" class="px-5 py-3 font-bold">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($anomalies as $anomalie)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 text-xs font-black {{ $anomalie['gravite'] === 'erreur' ? 'text-rose-700' : ($anomalie['gravite'] === 'avertissement' ? 'text-amber-700' : 'text-sky-700') }}">
                                    {{ $anomalie['gravite'] === 'erreur' ? '🔴 Erreur' : ($anomalie['gravite'] === 'avertissement' ? '🟡 Avertissement' : '🔵 Info') }}
                                </td>
                                <td class="px-5 py-3 font-bold"><a href="{{ $anomalie['lien'] }}" class="text-primary-800 underline underline-offset-2">{{ $anomalie['entite'] }}</a></td>
                                <td class="px-5 py-3 text-slate-700">{{ $anomalie['probleme'] }}</td>
                                <td class="px-5 py-3 text-slate-600">{{ $anomalie['action'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @else
            <div class="mt-4"><x-empty-state message="Aucune anomalie. ✓ Population cohérente." /></div>
        @endif
    </div>
@endsection
