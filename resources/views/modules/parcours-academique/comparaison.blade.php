@extends('layouts.app')

@section('title', 'Comparaison — Parcours')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header titre="Comparaison de parcours" description="Progression, crédits, moyennes, dettes, risques. Données scopées faculté." :fil="['Parcours' => route('admin.parcours.index'), 'Comparaison' => url()->current()]" />
        @if($lignes === null)
            <x-card titre="Choisir 2 à 4 étudiants" class="mt-6">
                <form method="GET" action="{{ route('admin.parcours.comparer') }}">
                    <div class="grid gap-2 sm:grid-cols-2">
                        @for($i = 0; $i < 4; $i++)
                            <div>
                                <label for="etudiant-{{ $i }}" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">Étudiant {{ $i + 1 }}@if($i < 2) <span class="text-rose-600" aria-hidden="true">*</span>@endif</label>
                                <input type="number" id="etudiant-{{ $i }}" name="etudiants[]" list="candidats" placeholder="ID étudiant" @if($i < 2) required @endif
                                       class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm min-h-[44px]">
                            </div>
                        @endfor
                    </div>
                    <datalist id="candidats">
                        @foreach($candidats as $candidat)
                            <option value="{{ $candidat->id }}">{{ $candidat->nom }} {{ $candidat->prenom }}</option>
                        @endforeach
                    </datalist>
                    <x-btn type="submit" class="mt-3">Comparer</x-btn>
                </form>
            </x-card>
        @else
        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                <thead><tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                    <th class="px-5 py-3 font-bold">Étudiant</th><th class="px-5 py-3 font-bold">Niveau</th><th class="px-5 py-3 font-bold">Crédits</th><th class="px-5 py-3 font-bold">Moyenne</th><th class="px-5 py-3 font-bold">Dettes</th><th class="px-5 py-3 font-bold">Risque</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($lignes as $ligne)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3 font-bold">{{ $ligne['nom_complet'] }}</td>
                            <td class="px-5 py-3">{{ $ligne['niveau_actuel'] ?? '—' }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['credits_obtenus'] }}/{{ $ligne['credits_requis'] }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['moyenne_generale'] ?? '—' }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['dettes'] }}</td>
                            <td class="px-5 py-3"><x-status-badge :statut="$ligne['risque']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
@endsection
