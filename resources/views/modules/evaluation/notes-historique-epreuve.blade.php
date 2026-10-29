@extends('layouts.app')

@section('title', 'Audit & Historique — ' . ($epreuve->matiere?->libelle_complet ?? 'Épreuve #' . $epreuve->id))

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <x-page-header :titre="'Audit de l\'épreuve #' . $epreuve->id"
        :description="($epreuve->matiere?->libelle_complet ?? '—') . ' · Session : ' . ($epreuve->session?->libelle ?? '—') . ' · Groupe : ' . ($epreuve->groupe?->code ?? '—')"
        :fil="['Évaluations' => route('admin.evaluations.index'), 'Épreuves' => route('admin.evaluations.epreuves.index'), 'Audit épreuve' => '#']">
        <x-slot:actions>
            <x-btn variant="secondary" href="{{ route('admin.evaluations.notes.saisie', $epreuve->id) }}">Accéder à la saisie</x-btn>
            <x-btn variant="secondary" href="{{ route('admin.evaluations.historique-notes.index') }}">Journal global</x-btn>
        </x-slot:actions>
    </x-page-header>

    <!-- Cartes KPI Audit -->
    <div class="mt-6 grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Modifications totales</p>
            <p class="text-2xl font-black text-slate-900 mt-1">{{ $stats['total_modifications'] }}</p>
        </div>
        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Notes révisées</p>
            <p class="text-2xl font-black text-indigo-700 mt-1">{{ $stats['notes_modifiees_count'] }}</p>
        </div>
        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Auteurs distincts</p>
            <p class="text-2xl font-black text-slate-900 mt-1">{{ $stats['auteurs_distincts'] }}</p>
        </div>
        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Statut épreuve</p>
            <p class="mt-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold uppercase
                    {{ $epreuve->statut === 'validated' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-800' }}">
                    {{ $epreuve->statut }}
                </span>
            </p>
        </div>
    </div>

    <!-- Journal des modifications de l'épreuve -->
    <div class="mt-8 bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
            <h2 class="text-xs font-bold text-slate-700 uppercase tracking-wider">Journal des révisions de cette épreuve</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                <thead class="bg-slate-100 text-slate-700 uppercase font-semibold">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Étudiant</th>
                        <th class="px-4 py-3">Avant</th>
                        <th class="px-4 py-3">Après</th>
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
                        <tr class="hover:bg-slate-50 transition">
                            <td class="px-4 py-3 font-mono text-slate-500 whitespace-nowrap">
                                {{ $entree->created_at?->format('d/m/Y H:i') ?? '—' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($entree->note?->etudiant)
                                    <span class="font-semibold text-slate-900">{{ $entree->note->etudiant->nom }} {{ $entree->note->etudiant->prenom }}</span>
                                    <span class="text-[11px] font-mono text-slate-500 block">{{ $entree->note->etudiant->num_inscription }}</span>
                                @else
                                    <span class="italic text-slate-400">Non renseigné</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-slate-600">
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
                                <div class="text-slate-800 font-medium">{{ $entree->motif }}</div>
                                <div class="text-[11px] text-slate-500">Par {{ $entree->auteur?->name ?? 'Système' }}</div>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($entree->note)
                                    <a href="{{ route('admin.evaluations.notes.historique', $entree->note->id) }}"
                                       class="px-2.5 py-1 text-xs rounded bg-slate-100 hover:bg-slate-200 text-indigo-700 font-semibold transition">
                                        Timeline
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500 italic">
                                Aucune révision de note pour cette épreuve.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
