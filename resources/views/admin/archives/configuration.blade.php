@extends('layouts.app')

@section('title', 'Configuration Académique — Scolarité')
@section('meta_description', 'Gestion des années universitaires et du catalogue des matières.')

@section('content')
<div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">

    <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-2.5">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary-100 text-primary-700">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.216 1.411l-1.001 1.001a1.125 1.125 0 0 0-.29 1.176c.04.077.078.155.113.234" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c.058.29.088.59.088.9 0 2.7-2.988 5.36-7.588 6.897-4.6-1.537-7.588-4.197-7.588-6.897 0-2.152 1.94-3.938 4.695-5.123" />
                </svg>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Configuration Académique</h1>
                <p class="text-xs sm:text-sm text-slate-500">Années universitaires et catalogue des matières alimentant les archives.</p>
            </div>
        </div>
        <a href="{{ route('admin.archives.index') }}"
           class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 shadow-xs transition-smooth">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
            Archives
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── Années universitaires ── --}}
        <div class="lg:col-span-1 rounded-3xl border border-slate-200/80 bg-white p-5 shadow-xs">
            <h3 class="mb-4 flex items-center gap-2 border-b border-slate-800/80 pb-2 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">
                <span class="font-serif text-sm font-bold text-slate-900">I.</span> Années universitaires
            </h3>

            @can('configuration.manage')
                <form method="POST" action="{{ route('admin.archives.annees.store') }}" class="mb-5 space-y-3 rounded-2xl border border-slate-200 bg-slate-50/60 p-3.5">
                    @csrf
                    <input type="text" name="libelle" value="{{ old('libelle') }}" placeholder="2027-2028"
                           class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none @error('libelle') border-rose-300 @enderror">
                    @error('libelle')<p class="text-[11px] text-rose-600">{{ $message }}</p>@enderror
                    <div class="grid grid-cols-2 gap-2">
                        <input type="date" name="date_debut" value="{{ old('date_debut') }}"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                        <input type="date" name="date_fin" value="{{ old('date_fin') }}"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    </div>
                    @error('date_fin')<p class="text-[11px] text-rose-600">{{ $message }}</p>@enderror
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                        <input type="checkbox" name="active" value="1" class="rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                        Année active (courante)
                    </label>
                    <button type="submit" class="w-full rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">Ajouter l'année</button>
                </form>
            @endcan

            <div class="space-y-2">
                @foreach($annees as $annee)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-3.5 py-2.5 {{ $annee->active ? 'bg-emerald-50/60 border-emerald-200' : '' }}">
                        <div>
                            <p class="text-sm font-bold text-slate-900">{{ $annee->libelle }} @if($annee->active)<span class="ml-1 rounded-full bg-emerald-100 px-1.5 py-0.5 text-[9px] font-black text-emerald-700">ACTIVE</span>@endif</p>
                            <p class="text-[10px] text-slate-400">{{ $annee->date_debut->format('d/m/Y') }} → {{ $annee->date_fin->format('d/m/Y') }} · {{ $annee->inscriptions_count }} inscription(s)</p>
                        </div>
                        @can('configuration.manage')
                            @if($annee->inscriptions_count === 0)
                                <form method="POST" action="{{ route('admin.archives.annees.destroy', $annee) }}" onsubmit="return confirm('Supprimer cette année universitaire ?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs text-rose-500 hover:text-rose-700 font-bold">✕</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ── Catalogue des matières ── --}}
        <div class="lg:col-span-2 rounded-3xl border border-slate-200/80 bg-white p-5 shadow-xs">
            <h3 class="mb-4 flex items-center gap-2 border-b border-slate-800/80 pb-2 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">
                <span class="font-serif text-sm font-bold text-slate-900">II.</span> Catalogue des matières
                <span class="ml-auto text-[10px] font-semibold text-slate-400">{{ $matieres->total() }} matière(s)</span>
            </h3>

            @can('configuration.manage')
                <form method="POST" action="{{ route('admin.archives.matieres.store') }}" class="mb-5 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 items-end rounded-2xl border border-slate-200 bg-slate-50/60 p-3.5">
                    @csrf
                    <div class="col-span-1">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Code *</label>
                        <input type="text" name="code" value="{{ old('code') }}" placeholder="MATH201" maxlength="20" required
                               class="w-full rounded-xl border border-slate-200 bg-white px-2.5 py-2 font-mono text-xs uppercase focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    </div>
                    <div class="col-span-1 lg:col-span-2">
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Nom *</label>
                        <input type="text" name="nom" value="{{ old('nom') }}" placeholder="Analyse III" required
                               class="w-full rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Niveau *</label>
                        <select name="niveau" class="w-full rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-primary-600 focus:outline-none">
                            @foreach(['L1','L2','L3','M1','M2'] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Sem. *</label>
                        <select name="semestre" class="w-full rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-primary-600 focus:outline-none">
                            <option value="1">S1</option><option value="2">S2</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Coef *</label>
                            <input type="number" name="coefficient" step="0.5" min="0.5" max="99" value="2"
                                   class="w-full rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-primary-600 focus:outline-none">
                        </div>
                        <div>
                            <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Créd. *</label>
                            <input type="number" name="credits" min="0" max="60" value="4"
                                   class="w-full rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-sm focus:border-primary-600 focus:outline-none">
                        </div>
                    </div>
                    @foreach(['code', 'nom', 'coefficient', 'credits'] as $champ)
                        @error($champ)<p class="col-span-2 lg:col-span-6 text-[11px] text-rose-600">{{ $message }}</p>@enderror
                    @endforeach
                    <button type="submit" class="col-span-2 lg:col-span-6 rounded-xl bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">Ajouter au catalogue</button>
                </form>
            @endcan

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-200">
                        <tr>
                            <th class="px-3 py-2.5">Code</th>
                            <th class="px-3 py-2.5">Matière</th>
                            <th class="px-3 py-2.5">Niveau</th>
                            <th class="px-3 py-2.5">Sem.</th>
                            <th class="px-3 py-2.5">Coef.</th>
                            <th class="px-3 py-2.5">Crédits</th>
                            <th class="px-3 py-2.5">Notes</th>
                            <th class="px-3 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        @foreach($matieres as $matiere)
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-3 py-2.5 font-mono font-bold text-slate-800">{{ $matiere->code }}</td>
                                <td class="px-3 py-2.5 font-semibold text-slate-700">{{ $matiere->nom }}</td>
                                <td class="px-3 py-2.5"><span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-bold text-slate-600">{{ $matiere->niveau }}</span></td>
                                <td class="px-3 py-2.5 text-slate-500">S{{ $matiere->semestre }}</td>
                                <td class="px-3 py-2.5 text-slate-500">{{ number_format((float) $matiere->coefficient, 1) }}</td>
                                <td class="px-3 py-2.5 text-slate-500">{{ $matiere->credits }}</td>
                                <td class="px-3 py-2.5 text-slate-500">{{ $matiere->notes_count }}</td>
                                <td class="px-3 py-2.5 text-right">
                                    @if($matiere->notes_count === 0)
                                        @can('configuration.manage')
                                            <form method="POST" action="{{ route('admin.archives.matieres.destroy', $matiere) }}" onsubmit="return confirm('Supprimer cette matière du catalogue ?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-rose-500 hover:text-rose-700 font-bold">✕</button>
                                            </form>
                                        @endcan
                                    @else
                                        <span class="text-[10px] text-slate-300" title="Suppression impossible : notes rattachées">verrouillée</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $matieres->links() }}</div>
        </div>
    </div>
</div>
@endsection
