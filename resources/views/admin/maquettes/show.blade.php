@extends('layouts.app')

@section('title', 'Maquette ' . $maquette->nom)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Maquette : ' . $maquette->nom" :description="$maquette->type . ' — ' . $maquette->statut"
            :fil="['Maquettes' => route('admin.maquettes.index'), $maquette->nom => '#']" />

        <div class="mt-4 ds-card p-5">
            <dl class="grid sm:grid-cols-2 gap-2 text-sm">
                <div><dt class="text-slate-500">Type</dt><dd class="font-mono">{{ $maquette->type }}</dd></div>
                <div><dt class="text-slate-500">Statut</dt><dd><x-status-badge :statut="$maquette->statut" /></dd></div>
                <div><dt class="text-slate-500">Langue / Format</dt><dd>{{ $maquette->locale }} — {{ $maquette->format }} {{ $maquette->orientation }}</dd></div>
                <div><dt class="text-slate-500">Version courante</dt><dd class="tabular-nums">v{{ $courante?->version ?? '—' }}</dd></div>
            </dl>

            <div class="mt-4 flex flex-wrap gap-2">
                @can('document_template.edit')
                    @if($maquette->statut === 'brouillon')
                        <x-btn href="{{ route('admin.maquettes.edit', $maquette) }}" variant="secondary">Modifier (brouillon)</x-btn>
                    @endif
                    <form method="POST" action="{{ route('admin.maquettes.dupliquer', $maquette) }}">
                        @csrf
                        <x-btn type="submit" variant="secondary">Dupliquer</x-btn>
                    </form>
                    @if($maquette->statut !== 'archive')
                        <form method="POST" action="{{ route('admin.maquettes.archiver', $maquette) }}">
                            @csrf
                            <x-btn type="submit" variant="ghost">Archiver</x-btn>
                        </form>
                    @endif
                @endcan
                @can('document_template.publish')
                    @if($maquette->statut !== 'actif')
                        <form method="POST" action="{{ route('admin.maquettes.publier', $maquette) }}">
                            @csrf
                            <x-btn type="submit">Publier</x-btn>
                        </form>
                    @endif
                @endcan
            </div>
            @error('maquette')
                <div class="mt-4"><x-alert type="error">{{ $message }}</x-alert></div>
            @enderror
        </div>

        <h2 class="mt-6 text-base font-black">Visualisation directe (rendu réel, sans écriture)</h2>
        <div class="mt-2 rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
            @if($rendu !== null)
                {!! $rendu !!}
                <div class="mt-4">
                    <a class="underline" target="_blank" rel="noopener" href="{{ route('admin.maquettes.apercu-pdf', $maquette) }}">Ouvrir l'aperçu PDF</a>
                </div>
            @else
                <x-empty-state message="Aucune version à visualiser." />
            @endif
        </div>

        <h2 class="mt-6 text-base font-black">Aperçu technique (variables d'exemple)</h2>
        <div class="mt-2 rounded-2xl border border-slate-200 bg-white p-5 text-sm shadow-sm">
            @if($apercu !== null)
                {!! $apercu !!}
            @else
                <x-empty-state message="Aucune version : ajoutez un contenu ci-dessous." />
            @endif
        </div>

        <h2 class="mt-6 text-base font-black">Historique des versions (immuables)</h2>
        <div class="mt-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($maquette->versions->isNotEmpty())
                <table class="w-full min-w-[560px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-4 py-3 font-bold">Version</th>
                            <th scope="col" class="px-4 py-3 font-bold">Variables</th>
                            <th scope="col" class="px-4 py-3 font-bold">Par</th>
                            <th scope="col" class="px-4 py-3 font-bold">Le</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($maquette->versions->sortByDesc('version') as $version)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 tabular-nums font-bold">v{{ $version->version }}{{ $version->id === $maquette->version_courante_id ? ' (courante)' : '' }}</td>
                                <td class="px-4 py-3 font-mono text-[11px]">{{ implode(', ', array_keys($version->schema_variables ?? [])) ?: '—' }}</td>
                                <td class="px-4 py-3">{{ $version->cree_par ?? '—' }}</td>
                                <td class="px-4 py-3 tabular-nums">{{ $version->created_at?->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-5"><x-empty-state message="Aucune version." /></div>
            @endif
        </div>

        @can('document_template.edit')
            <h2 class="mt-6 text-base font-black">Nouvelle version (l'actuelle reste figée)</h2>
            <form method="POST" action="{{ route('admin.maquettes.versionner', $maquette) }}" enctype="multipart/form-data" class="mt-2 ds-card p-5 space-y-4">
                @csrf
                <x-textarea id="contenu-version" label="Nouveau contenu" name="contenu" rows="8" placeholder="Modèle HTML avec variables…" />
                <div>
                    <label for="fichier-version" class="mb-1 block text-xs font-bold text-slate-700 uppercase tracking-wider">…ou fichier (.html, .txt, .md, .csv, .docx, .odt, .rtf)</label>
                    <input id="fichier-version" type="file" name="fichier" accept=".html,.txt,.md,.csv,.docx,.odt,.rtf"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-slate-900 file:px-2.5 file:py-1.5 file:text-[11px] file:font-bold file:text-white">
                    @error('fichier')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <x-textarea id="schema-version" label="Schéma des variables (JSON, optionnel)" name="schema" rows="2" placeholder='{"etudiant.nom": "Nom"}' />
                @error('contenu')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                <x-btn type="submit">Créer la version</x-btn>
            </form>
        @endcan
    </div>
@endsection
