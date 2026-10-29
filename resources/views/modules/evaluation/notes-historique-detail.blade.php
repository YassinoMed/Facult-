@extends('layouts.app')

@section('title', 'Timeline & Diff Note — Évaluations')

@section('content')
<div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
    <x-page-header :titre="'Historique de la note #' . $note->id"
        :description="'Étudiant : ' . ($note->etudiant ? $note->etudiant->nom . ' ' . $note->etudiant->prenom : '—') . ' · Matière : ' . ($note->epreuve?->matiere?->libelle_complet ?? '—')"
        :fil="['Évaluations' => route('admin.evaluations.index'), 'Historique des notes' => route('admin.evaluations.historique-notes.index'), 'Détail #' . $note->id => '#']">
        <x-slot:actions>
            <x-btn variant="secondary" href="{{ route('admin.evaluations.historique-notes.index') }}">Retour à l'audit</x-btn>
            @if($note->epreuve)
                <x-btn href="{{ route('admin.evaluations.notes.saisie', $note->epreuve->id) }}">Voir la saisie</x-btn>
            @endif
        </x-slot:actions>
    </x-page-header>

    <!-- Résumé de la note et état de synchronisation -->
    <div class="mt-6 grid grid-cols-1 sm:grid-cols-4 gap-4">
        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Valeur actuelle</p>
            <p class="text-2xl font-black text-slate-900 mt-1">
                {{ $note->valeur !== null ? number_format((float) $note->valeur, 2) : '—' }}
                <span class="text-sm font-normal text-slate-500">/ {{ (float) $note->note_max }}</span>
            </p>
        </div>

        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Statut épreuve</p>
            <p class="mt-1">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold uppercase
                    {{ $note->statut === 'validated' ? 'bg-emerald-100 text-emerald-800' : ($note->statut === 'locked' ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-800') }}">
                    {{ $note->statut }}
                </span>
            </p>
        </div>

        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Note officielle (centrale)</p>
            <p class="text-lg font-bold text-slate-800 mt-1">
                @if($note_officielle)
                    {{ number_format((float) $note_officielle->valeur, 2) }}
                    <span class="text-xs font-normal text-slate-500">(#{{ $note_officielle->id }})</span>
                @else
                    <span class="text-sm text-slate-400 italic">Aucune note liée</span>
                @endif
            </p>
        </div>

        <div class="bg-white p-4 rounded-lg border border-slate-200 shadow-sm">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Synchronisation</p>
            <p class="mt-1">
                @if($statut_synchronisation === 'synchronisee')
                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Synchronisée
                    </span>
                @elseif($statut_synchronisation === 'desynchronisee')
                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-rose-700 bg-rose-50 px-2 py-0.5 rounded border border-rose-200">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Désynchronisée
                    </span>
                @else
                    <span class="text-xs text-slate-400 font-medium">Non applicable</span>
                @endif
            </p>
        </div>
    </div>

    <!-- Timeline chronologique -->
    <div class="mt-8 bg-white rounded-lg shadow-sm border border-slate-200 p-6">
        <h2 class="text-base font-semibold text-slate-900 border-b pb-3 mb-6">
            Timeline différentielle ({{ $total_modifications }} révision(s))
        </h2>

        @if($historique->isEmpty())
            <p class="text-sm text-slate-500 italic">Aucune trace de modification historique enregistrée pour cette note.</p>
        @else
            <div class="relative border-l-2 border-slate-200 ml-4 space-y-8">
                @foreach($historique as $revision)
                    <div class="relative pl-6">
                        <span class="absolute -left-2.5 top-1.5 w-5 h-5 rounded-full bg-indigo-600 border-4 border-white shadow-sm"></span>

                        <div class="p-4 rounded-lg bg-slate-50 border border-slate-200">
                            <div class="flex items-center justify-between">
                                <span class="font-mono text-xs text-slate-500 font-medium">
                                    {{ $revision['created_at']?->format('d/m/Y H:i:s') ?? '—' }}
                                </span>
                                <span class="text-xs text-slate-600 font-semibold">
                                    Par {{ $revision['auteur'] }}
                                </span>
                            </div>

                            <div class="mt-2 flex items-center gap-3">
                                <span class="text-sm font-mono text-slate-500">
                                    {{ $revision['ancienne_valeur'] !== null ? number_format($revision['ancienne_valeur'], 2) : '—' }}
                                </span>
                                <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
                                </svg>
                                <span class="text-base font-mono font-bold text-slate-900">
                                    {{ $revision['nouvelle_valeur'] !== null ? number_format($revision['nouvelle_valeur'], 2) : '—' }}
                                </span>

                                @if($revision['ecart'] !== null)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold font-mono
                                        {{ $revision['ecart'] > 0 ? 'bg-emerald-100 text-emerald-800' : ($revision['ecart'] < 0 ? 'bg-rose-100 text-rose-800' : 'bg-slate-100 text-slate-700') }}">
                                        {{ $revision['ecart'] > 0 ? '+'.$revision['ecart'] : $revision['ecart'] }}
                                    </span>
                                @endif
                            </div>

                            <p class="mt-2 text-xs text-slate-700 font-medium bg-white p-2.5 rounded border border-slate-200">
                                <span class="font-bold text-slate-500 uppercase tracking-wider text-[10px] block">Motif :</span>
                                {{ $revision['motif'] }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
