@extends('layouts.app')

@section('title', 'Dérogations et Échéances — Gouvernance Scolarité')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8 space-y-6">

    <div class="relative overflow-hidden rounded-3xl border border-slate-200/90 bg-white p-6 sm:p-8 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold border border-blue-200 bg-blue-50 text-blue-900">
                        <span class="h-2 w-2 rounded-full bg-blue-500" aria-hidden="true"></span>
                        Gouvernance Académique (LOOP 146)
                    </span>
                    <span class="font-mono text-xs font-bold text-slate-500">
                        Total dossiers instruits : {{ $totalDossiers }}
                    </span>
                </div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-slate-900">
                    Dérogations et Échéances Réglementaires
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-slate-500 max-w-3xl">
                    Instruction collégiale et déterministe : respect strict des compétences statutaires, scellage des dates initiales, figeage neutre des ambiguïtés et rejet de toute mutation automatique.
                </p>
            </div>

            <div>
                <a href="{{ route('admin.dashboard') }}"
                   class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-smooth min-h-[44px]">
                    ← Cockpit principal
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-900">
            ✓ {{ session('success') }}
        </div>
    @endif

    {{-- Filtre et recherche --}}
    <div class="rounded-3xl border border-slate-200/90 bg-white p-5 shadow-sm">
        <form method="GET" action="{{ route('admin.derogations.index') }}" class="grid grid-cols-1 sm:grid-cols-3 gap-3" role="search">
            <div>
                <label for="filtre-q" class="block text-xs font-bold text-slate-700 mb-1">Recherche (Nom, Prénom, CIN)</label>
                <input type="text"
                       id="filtre-q"
                       name="q"
                       value="{{ $recherche }}"
                       placeholder="Ex. Benali ou 71460021"
                       class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            </div>

            <div>
                <label for="filtre-faculte" class="block text-xs font-bold text-slate-700 mb-1">Faculté</label>
                <select id="filtre-faculte"
                        name="faculte_id"
                        class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="">Toutes les facultés</option>
                    @foreach($facultes as $fac)
                        <option value="{{ $fac->id }}" @selected($faculteId == $fac->id)>
                            {{ $fac->nom }} ({{ $fac->code }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end gap-2">
                <button type="submit"
                        class="w-full rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-blue-700 transition-smooth min-h-[38px]">
                    Filtrer les dossiers
                </button>
                @if($recherche || $faculteId)
                    <a href="{{ route('admin.derogations.index') }}"
                       class="rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 transition-smooth min-h-[38px] flex items-center">
                        Réinitialiser
                    </a>
                @endif
            </div>
        </form>
    </div>

    {{-- Tableau des dossiers --}}
    <div class="overflow-hidden rounded-3xl border border-slate-200/90 bg-white shadow-sm">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-sm font-black text-slate-900 uppercase tracking-wider">
                Dossiers académiques sous instruction ou signalement
            </h2>
            <span class="text-xs font-bold text-slate-500">
                Page {{ $etudiants->currentPage() }} sur {{ $etudiants->lastPage() }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="border-b border-slate-100 bg-slate-50/70 text-[11px] uppercase tracking-wider text-slate-500 font-bold">
                        <th scope="col" class="px-6 py-3">Étudiant</th>
                        <th scope="col" class="px-6 py-3">CIN</th>
                        <th scope="col" class="px-6 py-3">Faculté & Niveau</th>
                        <th scope="col" class="px-6 py-3">Statut dossier</th>
                        <th scope="col" class="px-6 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($etudiants as $etudiant)
                        <tr class="hover:bg-slate-50/80 transition-smooth">
                            <td class="px-6 py-4 font-bold text-slate-900">
                                {{ $etudiant->nom }} {{ $etudiant->prenom }}
                            </td>
                            <td class="px-6 py-4 font-mono text-slate-600">
                                {{ $etudiant->cin }}
                            </td>
                            <td class="px-6 py-4 text-slate-600">
                                {{ $etudiant->faculte?->nom ?? '—' }} ({{ $etudiant->niveau ?? 'L1' }})
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold bg-slate-100 text-slate-800">
                                    {{ $etudiant->statut }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('admin.derogations.show', $etudiant) }}"
                                   class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-slate-800 transition-smooth min-h-[34px]">
                                    Consulter & Instruire →
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-xs text-slate-500">
                                Aucun dossier de dérogation ou constat d’échéance ne correspond aux critères.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($etudiants->hasPages())
            <div class="px-6 py-4 border-t border-slate-100">
                {{ $etudiants->links() }}
            </div>
        @endif
    </div>

</div>
@endsection

