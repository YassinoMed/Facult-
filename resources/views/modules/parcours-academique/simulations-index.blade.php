@extends('layouts.app')

@section('title', 'Simulations — Parcours')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header titre="Simulations" description="Hypothèses réversibles, sans effet officiel." :fil="['Parcours' => route('admin.parcours.index'), 'Simulations' => route('admin.parcours.simulations.index')]" />
        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                <thead><tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                    <th class="px-5 py-3 font-bold">Étudiant</th><th class="px-5 py-3 font-bold">Scénario</th><th class="px-5 py-3 font-bold">Résultat</th><th class="px-5 py-3 font-bold">Règles</th><th class="px-5 py-3 font-bold"><span class="sr-only">Voir</span></th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($simulations as $simulation)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3 font-bold">{{ $simulation->etudiant->nom }} {{ $simulation->etudiant->prenom }}</td>
                            <td class="px-5 py-3">{{ $simulation->scenario }}</td>
                            <td class="px-5 py-3">{{ $simulation->resultat['resultat_final'] ?? '—' }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ $simulation->regle_reference ?? '—' }}</td>
                            <td class="px-5 py-3"><a href="{{ route('admin.parcours.simulations.show', $simulation) }}" class="text-primary-700 hover:underline">Détail</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $simulations->links() }}</div>
    </div>
@endsection
