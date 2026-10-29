@extends('layouts.app')

@section('title', 'Compétences — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Compétences pédagogiques" description="Catalogue saisi, approuvé puis relié aux programmes, UE et ECUE. Aucun référentiel n’est inventé par le système."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Compétences' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.referentiel.competences.coherence') }}">Contrôle de cohérence</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error">{{ $errors->first() }}</x-alert></div>
        @endif

        @can('referentiel.manage')
            <section aria-label="Créer une compétence" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Nouveau brouillon</h2>
                <p class="mt-1 text-xs text-slate-500">Le code est unique et définitif. Un brouillon est révisable ; l’approbation le fige.</p>
                <form method="post" action="{{ route('admin.referentiel.competences.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label for="competence-code" class="text-xs font-bold text-slate-600">Code</label>
                        <input id="competence-code" name="code" type="text" maxlength="30" required value="{{ old('code') }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label for="competence-libelle" class="text-xs font-bold text-slate-600">Libellé</label>
                        <input id="competence-libelle" name="libelle" type="text" maxlength="200" required value="{{ old('libelle') }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label for="competence-faculte" class="text-xs font-bold text-slate-600">Portée</label>
                        <select id="competence-faculte" name="faculte_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="">Établissement (toutes facultés)</option>
                            @foreach($facultes as $faculte)
                                <option value="{{ $faculte->id }}" @selected((string) old('faculte_id') === (string) $faculte->id)>{{ $faculte->code }} — {{ $faculte->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="competence-description" class="text-xs font-bold text-slate-600">Description (facultative)</label>
                        <textarea id="competence-description" name="description" rows="2" maxlength="4000"
                                  class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">{{ old('description') }}</textarea>
                    </div>
                    <div class="sm:col-span-2">
                        <x-btn type="submit">Créer le brouillon</x-btn>
                    </div>
                </form>
            </section>
        @endcan

        <section aria-label="Catalogue des compétences" class="mt-4 ds-card overflow-x-auto">
            <table class="w-full min-w-[680px] text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-2">Code</th>
                        <th scope="col" class="px-4 py-2">Libellé</th>
                        <th scope="col" class="px-4 py-2">Portée</th>
                        <th scope="col" class="px-4 py-2">Statut</th>
                        <th scope="col" class="px-4 py-2 text-right">Associations</th>
                        <th scope="col" class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($competences as $competence)
                        <tr>
                            <td class="px-4 py-2 font-mono font-bold">{{ $competence->code }}</td>
                            <td class="px-4 py-2">
                                <p class="font-semibold text-slate-900">{{ $competence->libelle }}</p>
                                @if($competence->description)
                                    <p class="mt-0.5 text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($competence->description, 140) }}</p>
                                @endif
                                <p class="mt-0.5 text-xs text-slate-400">v{{ $competence->version }}</p>
                            </td>
                            <td class="px-4 py-2 text-xs text-slate-600">{{ $competence->faculte?->code ?? 'Établissement' }}</td>
                            <td class="px-4 py-2"><x-status-badge :statut="$competence->statut" /></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $competence->objectifs_count }}</td>
                            <td class="px-4 py-2 text-right">
                                <span class="flex flex-wrap justify-end gap-1.5">
                                    @can('maquettes.approve')
                                        @if($competence->statut === 'brouillon')
                                            <form method="post" action="{{ route('admin.referentiel.competences.approuver', $competence->id) }}" class="inline">
                                                @csrf
                                                <x-btn size="sm" type="submit">Approuver</x-btn>
                                            </form>
                                        @endif
                                    @endcan
                                    @can('referentiel.manage')
                                        @if($competence->statut === 'approuvee')
                                            <form method="post" action="{{ route('admin.referentiel.competences.archiver', $competence->id) }}" class="inline">
                                                @csrf
                                                <x-btn size="sm" variant="secondary" type="submit">Archiver</x-btn>
                                            </form>
                                        @endif
                                    @endcan
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-slate-500">Catalogue vide : la saisie est une décision de l’administration.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section aria-label="Couverture par maquette" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Couverture d’un plan d’études</h2>
            <p class="mt-1 text-xs text-slate-500">Le rapport distingue l’absence de donnée (UE/ECUE non reliée) de l’absence de couverture (compétence sans cible).</p>
            @if($maquettes->isNotEmpty())
                <ul class="mt-3 divide-y divide-slate-100">
                    @foreach($maquettes as $maquette)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span class="text-sm">
                                <strong class="font-mono">{{ $maquette->programme?->code }}</strong>
                                · {{ $maquette->anneeUniversitaire?->libelle }}
                                · v{{ $maquette->version }}
                            </span>
                            <a href="{{ route('admin.referentiel.maquettes.couverture', $maquette) }}"
                               class="text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir la couverture →</a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-3 text-sm text-slate-500">Aucune maquette dans votre périmètre.</p>
            @endif
            <p class="mt-4 text-xs text-slate-400">L’import par codes reste disponible via l’API dédiée (`competences/import`), avec détection des ambiguïtés et `cible_contexte`.</p>
        </section>
    </div>
@endsection
