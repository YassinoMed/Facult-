@extends('layouts.app')

@section('title', 'Historique des notes — Audit & Traçabilité')

@section('content')
<div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
    <x-page-header titre="Historique & Traçabilité des notes"
        description="Audit complet des saisies, modifications, revalidations et corrections exceptionnelles de notes."
        :fil="['Évaluations' => route('admin.evaluations.index'), 'Historique des notes' => '#']">
        <x-slot:actions>
            <x-btn variant="secondary" href="{{ route('admin.evaluations.anomalies.index') }}">Centre des anomalies</x-btn>
            <x-btn variant="secondary" href="{{ route('admin.evaluations.index') }}">Retour tableau de bord</x-btn>
        </x-slot:actions>
    </x-page-header>

    <!-- Filtres -->
    <div class="mt-6 bg-white rounded-2xl shadow-sm border border-slate-200 p-4">
        <form method="GET" action="{{ route('admin.evaluations.historique-notes.index') }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Recherche</label>
                <input type="text" name="recherche" value="{{ $filtres['recherche'] ?? '' }}"
                       placeholder="Étudiant, motif..."
                       class="w-full rounded-xl border-slate-300 text-xs focus:border-primary-500 focus:ring-primary-500">
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Session</label>
                <select name="session_id" class="w-full rounded-xl border-slate-300 text-xs focus:border-primary-500 focus:ring-primary-500">
                    <option value="">Toutes les sessions</option>
                    @foreach($sessions as $session)
                        <option value="{{ $session->id }}" @selected(($filtres['session_id'] ?? '') == $session->id)>
                            {{ $session->libelle }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Date début</label>
                <input type="date" name="date_debut" value="{{ $filtres['date_debut'] ?? '' }}"
                       class="w-full rounded-xl border-slate-300 text-xs focus:border-primary-500 focus:ring-primary-500">
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-1">Date fin</label>
                <input type="date" name="date_fin" value="{{ $filtres['date_fin'] ?? '' }}"
                       class="w-full rounded-xl border-slate-300 text-xs focus:border-primary-500 focus:ring-primary-500">
            </div>

            <div class="flex items-end gap-2">
                <x-btn type="submit" size="sm" class="flex-1 justify-center">
                    Filtrer
                </x-btn>
                <x-btn href="{{ route('admin.evaluations.historique-notes.index') }}" size="sm" variant="secondary">
                    Réinitialiser
                </x-btn>
            </div>
        </form>
    </div>

    <!-- Tableau de l'historique -->
    <div class="mt-6 bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-200 flex justify-between items-center bg-slate-50/80">
            <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">
                Journal des révisions ({{ $journal->total() }} entrées)
            </span>
        </div>

        <div class="overflow-x-auto max-h-[700px]">
            <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                <thead class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur-xs text-slate-700 uppercase font-bold text-[11px] tracking-wider border-b border-slate-200 shadow-2xs">
                    <tr>
                        <th class="px-4 py-3">Date & Heure</th>
                        <th class="px-4 py-3">Étudiant</th>
                        <th class="px-4 py-3">Épreuve & Matière</th>
                        <th class="px-4 py-3">Ancienne valeur</th>
                        <th class="px-4 py-3">Nouvelle valeur</th>
                        <th class="px-4 py-3">Écart</th>
                        <th class="px-4 py-3">Motif & Auteur</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white">
                    @forelse($journal as $entree)
                        @php
                            $ancienne = $entree->ancienne_valeur !== null ? (float) $entree->ancienne_valeur : null;
                            $nouvelle = $entree->nouvelle_valeur !== null ? (float) $entree->nouvelle_valeur : null;
                            $ecart = ($ancienne !== null && $nouvelle !== null) ? round($nouvelle - $ancienne, 2) : null;
                        @endphp
                        <tr class="hover:bg-slate-50/70 transition-colors">
                            <td class="px-4 py-3 font-mono text-slate-500 whitespace-nowrap">
                                {{ $entree->created_at?->format('d/m/Y H:i:s') ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($entree->note?->etudiant)
                                    <div class="font-semibold text-slate-900">
                                        {{ $entree->note->etudiant->nom }} {{ $entree->note->etudiant->prenom }}
                                    </div>
                                    <div class="text-[11px] font-mono text-slate-500">
                                        {{ $entree->note->etudiant->num_inscription }}
                                    </div>
                                @else
                                    <span class="italic text-slate-400">Non renseigné</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-800">
                                    {{ $entree->note?->epreuve?->matiere?->libelle_complet ?? '—' }}
                                </div>
                                <div class="text-[11px] text-slate-500">
                                    {{ $entree->note?->epreuve?->session?->libelle ?? '—' }} · {{ $entree->note?->epreuve?->groupe?->code ?? '—' }}
                                </div>
                            </td>
                            <td class="px-4 py-3 font-mono font-medium text-slate-600">
                                {{ $ancienne !== null ? number_format($ancienne, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 font-mono font-bold text-slate-900">
                                {{ $nouvelle !== null ? number_format($nouvelle, 2) : '—' }}
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($ecart !== null)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold font-mono
                                        {{ $ecart > 0 ? 'bg-emerald-100 text-emerald-800' : ($ecart < 0 ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700') }}">
                                        {{ $ecart > 0 ? '+'.$ecart : $ecart }}
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-slate-800 max-w-xs truncate" title="{{ $entree->motif }}">{{ $entree->motif }}</div>
                                <div class="text-[11px] text-slate-500">Par {{ $entree->auteur?->name ?? 'Système' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($entree->note)
                                    <a href="{{ route('admin.evaluations.notes.historique', $entree->note->id) }}"
                                       class="px-2.5 py-1 text-xs rounded-lg border border-primary-200 bg-primary-50/70 hover:bg-primary-100/70 text-primary-700 font-semibold transition-colors">
                                        Détail note
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-6">
                                <x-empty-state message="Aucune révision de note enregistrée correspondant à ces critères." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($journal->hasPages())
            <div class="p-4 border-t border-slate-200">
                {{ $journal->withQueryString()->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
