@extends('layouts.app')

@section('title', 'Régularisations de présence — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Régularisations de présence" description="Demandes des enseignants (catégorie présence) en attente de traitement."
            :fil="['Présences' => route('admin.presences.index'), 'Régularisations' => '#']" />

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <section aria-label="File des régularisations" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($reclamations->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Référence</th>
                            <th scope="col" class="px-5 py-3 font-bold">Enseignant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Séance contestée</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Traiter</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($reclamations as $reclamation)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold">{{ $reclamation->reference }}</td>
                                <td class="px-5 py-3">{{ $reclamation->teacher?->nomComplet() ?? '—' }}</td>
                                <td class="px-5 py-3">
                                    @if($reclamation->presence)
                                        {{ $reclamation->presence->date->format('d/m/Y') }} · {{ $reclamation->presence->slot?->matiere?->nom ?? '—' }}
                                    @else
                                        <span class="text-slate-400">Sans présence liée</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3"><x-status-badge :statut="$reclamation->statut" /></td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.presences.regularisations.show', $reclamation) }}" class="font-bold text-indigo-700 underline underline-offset-2">Traiter</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $reclamations->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune demande de régularisation en attente." /></div>
            @endif
        </section>
    </div>
@endsection
