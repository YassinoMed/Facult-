@extends('layouts.app')

@section('title', 'Appel du ' . $appel->date->format('d/m/Y') . ' — Scolarité')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Appel : ' . ($appel->slot?->matiere?->nom ?? '—') . ' · ' . $appel->date->format('d/m/Y')" :description="'Enseignant : ' . ($appel->enseignant?->nomComplet() ?? '—') . (filled($appel->cloture_le) ? ' · Clôturé le ' . $appel->cloture_le->format('d/m/Y H:i') : ' · En cours')"
            :fil="['Appels' => route('admin.appels.index'), 'Détail' => '#']">
            <x-slot:actions>
                <x-status-badge :statut="$appel->statut" />
            </x-slot:actions>
        </x-page-header>

        <section aria-label="Relevé" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th scope="col" class="px-5 py-3 font-bold">Étudiant</th>
                        <th scope="col" class="px-5 py-3 font-bold">N° inscription</th>
                        <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                        <th scope="col" class="px-5 py-3 font-bold">Auteur</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($releves as $releve)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3 font-bold">{{ $releve->etudiant?->nom_complet ?? '#' . $releve->etudiant_id }}</td>
                            <td class="px-5 py-3 font-mono text-xs text-slate-500">{{ $releve->etudiant?->num_inscription ?? '—' }}</td>
                            <td class="px-5 py-3"><x-status-badge :statut="$releve->statut" /></td>
                            <td class="px-5 py-3 text-xs text-slate-500">{{ $releve->enseignantAuteur?->nomComplet() ?? $releve->enregistrePar?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-5"><x-empty-state message="Aucun relevé enregistré." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <p class="mt-3 text-xs text-slate-500">Corrections : circuit enseignant (motif + historique sur la feuille d'appel) ou ré-enregistrement back-office.</p>
    </div>
@endsection
