@extends('layouts.app')

@section('title', 'Pilotage des maquettes — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header titre="Pilotage des maquettes" description="Un programme = une ligne : versions, maquette active, crédits attendus vs observés, progression, qualité et disponibilité opérationnelle (seul un plan actif rend le programme utilisable par les inscriptions et les examens)."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Pilotage' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.referentiel.prevalidation.index') }}">Prévalidation</x-btn>
                <x-btn variant="ghost" href="{{ route('admin.referentiel.maquettes.index') }}">Maquettes</x-btn>
            </x-slot:actions>
        </x-page-header>

        <form method="GET" action="{{ route('admin.referentiel.pilotage') }}" role="search" class="mt-4 flex flex-col lg:flex-row gap-2">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Code, sigle, nom…"
                   aria-label="Rechercher un programme" class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
            <select name="statut" aria-label="Filtrer par statut de programme" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous statuts</option>
                @foreach(['brouillon' => 'Brouillon', 'actif' => 'Actif', 'inactif' => 'Inactif', 'archive' => 'Archivé'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected(request('statut') === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <select name="type_diplome_id" aria-label="Filtrer par diplôme" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Tous diplômes</option>
                @foreach($typesDiplomes as $type)
                    <option value="{{ $type->id }}" @selected((string) request('type_diplome_id') === (string) $type->id)>{{ $type->code }} — {{ $type->nom }}</option>
                @endforeach
            </select>
            <select name="maquette" aria-label="Filtrer par état de maquette" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes maquettes</option>
                <option value="active" @selected(request('maquette') === 'active')>Maquette active</option>
                <option value="attente" @selected(request('maquette') === 'attente')>En attente d'activation</option>
                <option value="aucune" @selected(request('maquette') === 'aucune')>Sans maquette</option>
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
            @if(request()->hasAny(['search', 'statut', 'type_diplome_id', 'maquette']))
                <a href="{{ route('admin.referentiel.pilotage') }}" class="inline-flex items-center text-xs font-bold text-slate-500 underline underline-offset-2 min-h-[44px]">Réinitialiser</a>
            @endif
        </form>

        <section aria-label="Programmes et maquettes" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($programmes->isNotEmpty())
                <table class="w-full min-w-[1080px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Programme</th>
                            <th scope="col" class="px-5 py-3 font-bold">Versions</th>
                            <th scope="col" class="px-5 py-3 font-bold">Crédits</th>
                            <th scope="col" class="px-5 py-3 font-bold">Contenu</th>
                            <th scope="col" class="px-5 py-3 font-bold">Préparation</th>
                            <th scope="col" class="px-5 py-3 font-bold">Qualité</th>
                            <th scope="col" class="px-5 py-3 font-bold">Opérationnel</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($programmes as $programme)
                            @php($ligne = $lignes[$programme->id])
                            @php($courante = $ligne['maquette_courante'])
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3">
                                    <p class="font-mono font-bold text-slate-800">{{ $programme->code }}</p>
                                    <p class="font-bold text-slate-900">{{ $programme->nom }}</p>
                                    <p class="text-[11px] text-slate-500">{{ $programme->faculte->nom ?? '—' }} · {{ $programme->typeDiplome->code ?? '—' }} · {{ $programme->niveaux->count() }} niveaux · <span class="uppercase">{{ $programme->statut }}</span></p>
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">
                                    <p class="font-black">{{ $ligne['versions'] }} version(s)</p>
                                    @if($courante)
                                        <p class="text-[11px]">courante : v{{ $courante->version }} · <span class="font-bold uppercase">{{ $courante->statut }}</span>{{ $courante->anneeUniversitaire ? ' · '.$courante->anneeUniversitaire->libelle : '' }}</p>
                                        <p class="text-[11px] text-slate-500">MAJ {{ $courante->updated_at?->format('d/m/Y H:i') ?? '—' }}{{ $courante->date_validation ? ' · validée le '.$courante->date_validation->format('d/m/Y') : '' }}</p>
                                    @else
                                        <p class="text-[11px] text-slate-500">aucune maquette</p>
                                    @endif
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">
                                    <p>attendus : <strong>{{ $ligne['credits_attendus'] ?? '—' }}</strong></p>
                                    <p>observés : <strong>{{ $ligne['credits_observes'] }}</strong></p>
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">
                                    <p>{{ $ligne['ues'] }} UE · {{ $ligne['ecues'] }} ECUE</p>
                                </td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">
                                    <p class="font-black">{{ $ligne['progression'] }} %</p>
                                    <p class="text-[11px] text-slate-500">{{ $ligne['semestres_couverts'] }}/{{ $ligne['semestres_total'] }} semestres couverts</p>
                                </td>
                                <td class="px-5 py-3 text-slate-700">
                                    <p class="text-[12px]">🔴 {{ count($ligne['rapport']['blocages']) }} bloquant(s) · 🟡 {{ count($ligne['rapport']['avertissements']) }} avertissement(s)</p>
                                    <p class="text-[11px] font-black uppercase">{{ $ligne['rapport']['recommandation'] === 'ACTIVE_DEJA' ? ' active' : ($ligne['rapport']['recommandation'] === 'ACTIVABLE' ? ' activable' : ' en attente') }}</p>
                                </td>
                                <td class="px-5 py-3">
                                    @if($ligne['operationnel'])
                                        <span class="inline-block rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-black text-emerald-800">Opérationnel</span>
                                    @else
                                        <span class="inline-block rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-black text-slate-600" title="inscrireEcue et genererEpreuves exigent une maquette active">Non utilisable</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <x-dropdown :etiquette="'Actions pour ' . $programme->code">
                                        <x-slot:liens>
                                            <a href="{{ route('admin.referentiel.entites.show', ['programmes', $programme->id]) }}" role="menuitem" class="block px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Voir le programme</a>
                                            <a href="{{ route('admin.referentiel.prevalidation.show', $programme) }}" role="menuitem" class="block px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Prévalidation</a>
                                            @if($courante)
                                                <a href="{{ route('admin.referentiel.maquettes.show', $courante) }}" role="menuitem" class="block px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 min-h-[44px]">Ouvrir la maquette v{{ $courante->version }}</a>
                                            @endif
                                        </x-slot:liens>
                                    </x-dropdown>
                                    @if($courante)
                                        <div class="mt-1 flex flex-col gap-1">
                                            @if($courante->statut === 'draft')
                                                @can('update', $courante)
                                                    <form method="POST" action="{{ route('admin.referentiel.maquettes.transition', $courante) }}">
                                                        @csrf @method('PATCH')
                                                        <input type="hidden" name="statut" value="review">
                                                        <button type="submit" class="text-[11px] font-bold text-primary-800 underline underline-offset-2">Soumettre en revue</button>
                                                    </form>
                                                @endcan
                                            @elseif($courante->statut === 'review')
                                                @can('approuver', $courante)
                                                    <form method="POST" action="{{ route('admin.referentiel.maquettes.transition', $courante) }}">
                                                        @csrf @method('PATCH')
                                                        <input type="hidden" name="statut" value="approved">
                                                        <button type="submit" class="text-[11px] font-bold text-primary-800 underline underline-offset-2">Approuver</button>
                                                    </form>
                                                @endcan
                                                @can('update', $courante)
                                                    <form method="POST" action="{{ route('admin.referentiel.maquettes.transition', $courante) }}">
                                                        @csrf @method('PATCH')
                                                        <input type="hidden" name="statut" value="draft">
                                                        <button type="submit" class="text-[11px] font-bold text-slate-500 underline underline-offset-2">Renvoyer en brouillon</button>
                                                    </form>
                                                @endcan
                                            @elseif($courante->statut === 'approved')
                                                @can('approuver', $courante)
                                                    <form method="POST" action="{{ route('admin.referentiel.maquettes.transition', $courante) }}">
                                                        @csrf @method('PATCH')
                                                        <input type="hidden" name="statut" value="active">
                                                        <button type="submit" class="text-[11px] font-bold text-emerald-700 underline underline-offset-2">Activer (bascule atomique)</button>
                                                    </form>
                                                @endcan
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-5 py-3 border-t border-slate-100">{{ $programmes->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucun programme dans ce périmètre." /></div>
            @endif
        </section>
    </div>
@endsection
