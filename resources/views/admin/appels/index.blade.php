@extends('layouts.app')

@section('title', 'Appels de présence — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Appels de présence" description="Relevés enregistrés par les enseignants : en cours, clôturés, incomplets."
            :fil="['Présences' => route('admin.presences.index'), 'Appels' => '#']" />

        <form method="GET" action="{{ route('admin.appels.index') }}" class="mt-4 flex flex-wrap gap-2 items-end">
            <div>
                <label for="date" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Date</label>
                <input type="date" id="date" name="date" value="{{ request('date') }}" class="rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
            </div>
            <div>
                <label for="statut" class="block text-[11px] font-bold uppercase tracking-wider text-slate-500">Statut</label>
                <select id="statut" name="statut" class="rounded-xl border border-slate-300 px-3 py-2 text-sm min-h-[44px]">
                    <option value="">Tous</option>
                    <option value="en_cours" @selected(request('statut') === 'en_cours')>En cours</option>
                    <option value="cloture" @selected(request('statut') === 'cloture')>Clôturés</option>
                </select>
            </div>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <section aria-label="Appels enregistrés" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($appels->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Date · Séance</th>
                            <th scope="col" class="px-5 py-3 font-bold">Groupe</th>
                            <th scope="col" class="px-5 py-3 font-bold">Enseignant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Compteurs</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($appels as $appel)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.appels.show', $appel) }}" class="font-bold text-indigo-700 underline underline-offset-2">{{ $appel->date->format('d/m/Y') }} · {{ $appel->slot?->matiere?->nom ?? '—' }}</a>
                                </td>
                                <td class="px-5 py-3">{{ $appel->slot?->schedule?->groupe?->nom ?? '—' }}</td>
                                <td class="px-5 py-3">{{ $appel->enseignant?->nomComplet() ?? '—' }}</td>
                                <td class="px-5 py-3 tabular-nums">{{ $appel->presents }}P / {{ $appel->absents }}A / {{ $appel->retards }}R / {{ $appel->non_renseignes }}NR</td>
                                <td class="px-5 py-3"><x-status-badge :statut="$appel->statut" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $appels->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun appel enregistré." /></div>
            @endif
        </section>
    </div>
@endsection
