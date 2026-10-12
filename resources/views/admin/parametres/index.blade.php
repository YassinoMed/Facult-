@extends('layouts.app')

@section('title', 'Paramètres')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8" x-data="{ recherche: '' }">
        <x-page-header titre="Paramètres" description="Valeurs centralisées, toute modification est auditée."
            :fil="['Paramètres' => '#']" />

        {{-- Recherche instantanée (filtrage local, sans requête) --}}
        <div class="mt-4 ds-card p-4" role="search" aria-label="Recherche de paramètres">
            <label class="sr-only" for="recherche-parametres">Rechercher un paramètre</label>
            <input id="recherche-parametres" type="text" x-model="recherche" autocomplete="off"
                placeholder="Rechercher par clé ou description…"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
        </div>

        @foreach($groupes as $categorie => $parametres)
            <section class="mt-4 ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">{{ $categorie }}</h2>
                <div class="mt-3 space-y-3">
                    @foreach($parametres as $parametre)
                        @php($definition = $definitions[$parametre->cle] ?? null)
                        <div data-recherche="{{ mb_strtolower($parametre->cle.' '.($parametre->description ?? '')) }}"
                            x-show="$el.dataset.recherche.includes(recherche.toLowerCase())"
                            data-initiale="{{ old('valeur', $parametre->valeur) }}"
                            x-data="{ initiale: $el.dataset.initiale, courante: $el.dataset.initiale }">
                            <form method="post" action="{{ route('admin.parametres.update', $parametre) }}" class="flex flex-wrap items-end gap-2">
                                @csrf @method('PATCH')
                                <div class="min-w-52 flex-1">
                                    <label class="text-xs font-bold text-slate-600" for="p-{{ $parametre->id }}">{{ $parametre->cle }}</label>
                                    @if($parametre->type === 'entier')
                                        <input id="p-{{ $parametre->id }}" name="valeur" type="number" step="1"
                                            x-model="courante" value="{{ old('valeur', $parametre->valeur) }}"
                                            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm @error('valeur', 'p'.$parametre->id) border-rose-300 bg-rose-50/50 @enderror" />
                                    @elseif($parametre->type === 'booleen')
                                        <select id="p-{{ $parametre->id }}" name="valeur" x-model="courante"
                                            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                            @foreach(['1' => 'Oui', '0' => 'Non'] as $option => $libelle)
                                                <option value="{{ $option }}" {{ old('valeur', $parametre->valeur) == $option ? 'selected' : '' }}>{{ $libelle }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input id="p-{{ $parametre->id }}" name="valeur" type="text"
                                            x-model="courante" value="{{ old('valeur', $parametre->valeur) }}"
                                            class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm @error('valeur', 'p'.$parametre->id) border-rose-300 bg-rose-50/50 @enderror" />
                                    @endif
                                    @if($parametre->description)
                                        <p class="mt-1 text-xs text-slate-400">{{ $parametre->description }}</p>
                                    @endif
                                    <p class="mt-1 text-[11px] text-slate-400">
                                        @if($definition !== null)
                                            Défaut : <span class="font-mono">{{ (string) $definition['defaut'] }}</span> ·
                                        @endif
                                        @if($parametre->auteur)
                                            Modifié par {{ $parametre->auteur->name }} le {{ $parametre->updated_at?->format('d/m/Y H:i') }}
                                        @endif
                                    </p>
                                    @error('valeur', 'p'.$parametre->id)
                                        <p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>
                                    @enderror
                                    @error('concurrence', 'p'.$parametre->id)
                                        <p class="mt-1 text-xs text-rose-600" role="alert">{{ $message }}</p>
                                    @enderror
                                    @if(($historiques[$parametre->id] ?? collect())->isNotEmpty())
                                        <details class="mt-2 rounded-xl border border-slate-200 bg-white">
                                            <summary class="cursor-pointer px-3 py-2 text-[11px] font-bold text-slate-600 focus:outline-none">
                                                Historique ({{ ($historiques[$parametre->id] ?? collect())->count() }})
                                            </summary>
                                            <ul class="divide-y divide-slate-100 px-3 pb-2">
                                                @foreach(($historiques[$parametre->id] ?? collect())->take(10) as $version)
                                                    <li class="flex flex-wrap items-center gap-x-2 gap-y-1 py-1.5 text-[11px] text-slate-500">
                                                        <span class="font-mono text-slate-400">#{{ $version->id }}</span>
                                                        <span class="font-mono"><span class="text-rose-400">{{ $version->valeur_precedente }}</span> → <span class="text-emerald-600">{{ $version->valeur }}</span></span>
                                                        <span>{{ $version->acteur?->name ?? 'Système' }} · {{ $version->created_at?->format('d/m/Y H:i') }}</span>
                                                        @if($version->motif)
                                                            <span class="italic">{{ $version->motif }}</span>
                                                        @endif
                                                        <form method="post" action="{{ route('admin.parametres.restaurer', $parametre) }}" class="ml-auto inline"
                                                            onsubmit="return confirm('Restaurer cette version ? Une nouvelle version sera créée.')">
                                                            @csrf
                                                            <input type="hidden" name="version_id" value="{{ $version->id }}">
                                                            <button type="submit" class="rounded-lg border border-slate-200 px-2 py-0.5 font-bold text-slate-600 hover:bg-slate-50">Restaurer</button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </details>
                                    @endif
                                </div>
                                <x-btn size="sm" type="submit" x-bind:disabled="courante === initiale"
                                    title="Enregistrer (actif si modifié)">Enregistrer</x-btn>
                            </form>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection
