@extends('layouts.app')

@section('title', 'Dossiers étudiants — Scolarité')
@section('meta_description', 'Registre des dossiers administratifs permanents des étudiants.')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Module dossiers étudiants</p>
                <h1 class="mt-1 text-2xl sm:text-3xl font-black tracking-tight text-slate-900">Dossiers étudiants</h1>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.student-dossiers.index') }}" class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                    <label for="recherche" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Recherche</label>
                    <input type="search" id="recherche" name="search" value="{{ request('search') }}" placeholder="CIN, nom, email, référence…"
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:ring-4 focus:ring-primary-500/20 focus:outline-none">
                </div>
                <div>
                    <label for="filtre-statut" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Statut</label>
                    <select id="filtre-statut" name="statut" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                        <option value="">Tous</option>
                        @foreach(['open' => 'Ouvert', 'complete' => 'Complet', 'archived' => 'Archivé'] as $valeur => $libelle)
                            <option value="{{ $valeur }}" @selected(request('statut') === $valeur)>{{ $libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="filtre-niveau" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Niveau</label>
                    <select id="filtre-niveau" name="niveau" class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none">
                        <option value="">Tous</option>
                        @foreach(['L1', 'L2', 'L3', 'M1', 'M2', 'D1', 'D2', 'D3'] as $niveau)
                            <option value="{{ $niveau }}" @selected(request('niveau') === $niveau)>{{ $niveau }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full rounded-xl bg-primary-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-700 min-h-[44px]">Filtrer</button>
                </div>
            </div>
        </form>

        @can('student_dossiers.create')
            <form method="POST" action="{{ route('admin.student-dossiers.store') }}" class="mt-4 flex flex-col sm:flex-row gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                @csrf
                <div class="flex-1">
                    <label for="nouveau-etudiant" class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-slate-500">Ouvrir un dossier (ID étudiant)</label>
                    <input type="number" id="nouveau-etudiant" name="etudiant_id" required min="1" placeholder="Ex : 42"
                           class="w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm focus:border-primary-700 focus:outline-none @error('etudiant_id') border-rose-400 @enderror">
                    @error('etudiant_id')
                        <p class="mt-1 text-xs font-semibold text-rose-700">⚠ {{ $message }}</p>
                    @enderror
                </div>
                <div class="flex items-end">
                    <button type="submit" class="rounded-xl border-2 border-primary-200 px-5 py-2.5 text-sm font-bold text-primary-800 hover:border-primary-400 min-h-[44px]">Ouvrir le dossier</button>
                </div>
            </form>
        @endcan

        <section aria-label="Registre des dossiers" class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($dossiers->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Référence</th>
                            <th scope="col" class="px-5 py-3 font-bold">Étudiant</th>
                            <th scope="col" class="px-5 py-3 font-bold">Faculté</th>
                            <th scope="col" class="px-5 py-3 font-bold">Documents</th>
                            <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($dossiers as $dossier)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ $dossier->reference }}</td>
                                <td class="px-5 py-3">
                                    <span class="block font-bold text-slate-900">{{ $dossier->etudiant->nom_complet }}</span>
                                    <span class="block font-mono text-[11px] text-slate-500">{{ $dossier->etudiant->cin }}</span>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ $dossier->etudiant->faculte?->code ?? '—' }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $dossier->documents_count }}</td>
                                <td class="px-5 py-3"><x-status-badge :statut="$dossier->status" /></td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.student-dossiers.show', $dossier) }}" class="font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Voir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $dossiers->links() }}</div>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucun dossier dans ce périmètre." />
                </div>
            @endif
        </section>
    </div>
@endsection
