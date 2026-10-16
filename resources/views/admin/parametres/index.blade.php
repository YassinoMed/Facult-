@extends('layouts.app')

@section('title', 'Paramètres de la Plateforme')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8" x-data="{ recherche: '', categorieActive: 'toutes' }">
        {{-- En-tête de page --}}
        <x-page-header titre="Paramètres de la Plateforme" description="Configuration centralisée, sécurité, verrouillage et audits de modifications."
            :fil="['Paramètres' => '#']">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.verrouillage.code') }}"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                        <span>🔒</span> Mon code PIN
                    </a>
                    <a href="{{ route('admin.mfa.configurer') }}"
                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition">
                        <span>🛡️</span> Sécurité 2FA
                    </a>
                    <a href="{{ route('admin.sentinel.index') }}"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-bold text-white shadow-sm hover:bg-slate-800 transition">
                        <span>🚨</span> Sentinel
                    </a>
                </div>
            </x-slot:actions>
        </x-page-header>

        {{-- ── Raccourcis Rapides de Sécurité & Exploitation ── --}}
        <div class="mt-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-xs font-black uppercase tracking-wider text-slate-500 flex items-center gap-2">
                    <span>🛡️</span> Modules de Sécurité & Exploitation
                </h2>
                <span class="text-[11px] font-semibold text-slate-400">Accès rapide aux fonctionnalités clés</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">
                @foreach($securiteRaccourcis ?? [] as $raccourci)
                    <a href="{{ $raccourci['route'] }}"
                        class="group flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-primary-400 hover:shadow-md transition">
                        <div>
                            <div class="flex items-center justify-between">
                                <span class="text-2xl" aria-hidden="true">{{ $raccourci['icone'] }}</span>
                                <span class="text-[10px] font-bold text-primary-700 bg-primary-50 px-2 py-0.5 rounded-full group-hover:bg-primary-100 transition">Ouvrir →</span>
                            </div>
                            <h3 class="mt-2 text-xs font-black text-slate-900 group-hover:text-primary-800 transition">{{ $raccourci['titre'] }}</h3>
                            <p class="mt-1 text-[11px] text-slate-500 line-clamp-2 leading-relaxed">{{ $raccourci['description'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Barre d'outils : Recherche instantanée et filtre de catégories --}}
        <div class="mt-6 space-y-3">
            <div class="ds-card p-4 flex flex-col sm:flex-row items-center gap-3" role="search" aria-label="Recherche de paramètres">
                <div class="relative flex-1 w-full">
                    <span class="absolute inset-y-0 left-3 flex items-center text-slate-400 pointer-events-none text-sm">🔍</span>
                    <label class="sr-only" for="recherche-parametres">Rechercher un paramètre</label>
                    <input id="recherche-parametres" type="text" x-model="recherche" autocomplete="off"
                        placeholder="Rechercher par clé, catégorie ou description (ex. verrouillage, session, mfa, purge)…"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-4 py-2.5 text-sm focus:border-primary-500 focus:bg-white focus:outline-none transition" />
                </div>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <button type="button" x-show="recherche" @click="recherche = ''"
                        class="text-xs font-bold text-slate-500 hover:text-slate-700 px-2 py-1 rounded-lg border border-slate-200 hover:bg-slate-100">
                        Effacer
                    </button>
                </div>
            </div>

            {{-- Filtres par onglets de catégorie --}}
            <div class="flex flex-wrap items-center gap-1.5 p-1 rounded-2xl bg-slate-100 border border-slate-200/80 text-xs font-bold">
                <button type="button" @click="categorieActive = 'toutes'"
                    :class="categorieActive === 'toutes' ? 'bg-white text-slate-900 shadow-sm border border-slate-200' : 'text-slate-600 hover:text-slate-900 hover:bg-white/60'"
                    class="rounded-xl px-3 py-1.5 transition">
                    Toutes ({{ $groupes->flatten()->count() }})
                </button>
                @foreach($groupes as $cat => $params)
                    @php($meta = $categoriesMeta[$cat] ?? null)
                    <button type="button" @click="categorieActive = '{{ $cat }}'"
                        :class="categorieActive === '{{ $cat }}' ? 'bg-white text-slate-900 shadow-sm border border-slate-200' : 'text-slate-600 hover:text-slate-900 hover:bg-white/60'"
                        class="rounded-xl px-3 py-1.5 transition flex items-center gap-1.5">
                        <span>{{ $meta['icone'] ?? '⚙️' }}</span>
                        <span>{{ $meta['nom'] ?? ucfirst($cat) }}</span>
                        <span class="rounded-full bg-slate-200/70 text-[10px] px-1.5 py-0.2">{{ $params->count() }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Diagnostic de configuration (valeurs non sensibles uniquement) --}}
        <div class="mt-4 ds-card p-4 bg-slate-50/70 border-slate-200" role="status" aria-label="État de configuration">
            <div class="flex items-center gap-2 mb-1">
                <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
                <span class="text-xs font-bold text-slate-700">État de configuration système</span>
            </div>
            <p class="text-[11px] text-slate-500 leading-relaxed">
                Environnement : <strong class="text-slate-700">{{ $diagnostic['environnement'] }}</strong> ·
                Langue : <strong class="text-slate-700">{{ $diagnostic['langue'] }}</strong> ·
                Fuseau PHP : <strong class="text-slate-700">{{ $diagnostic['fuseau_php'] }}</strong> ·
                Débogage : <strong class="text-slate-700">{{ $diagnostic['debug'] ? 'activé (développement uniquement)' : 'désactivé' }}</strong> ·
                Courriel : <strong class="text-slate-700">{{ $diagnostic['courriel']['pilote'] }} ({{ $diagnostic['courriel']['expediteur'] }}{{ $diagnostic['courriel']['hote'] ? ' via '.$diagnostic['courriel']['hote'] : '' }})</strong>
            </p>
        </div>

        {{-- ── Sections par Catégorie ── --}}
        <div class="mt-6 space-y-6">
            @foreach($groupes as $categorie => $parametres)
                @php($meta = $categoriesMeta[$categorie] ?? null)
                <section class="ds-card p-6 border border-slate-200 shadow-sm"
                    x-show="(categorieActive === 'toutes' || categorieActive === '{{ $categorie }}')"
                    x-transition>
                    {{-- En-tête de catégorie --}}
                    <div class="flex flex-wrap items-center justify-between border-b border-slate-100 pb-4 mb-4 gap-2">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-xl" aria-hidden="true">
                                {{ $meta['icone'] ?? '⚙️' }}
                            </span>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h2 class="text-base font-black text-slate-900">{{ $meta['nom'] ?? ucfirst($categorie) }}</h2>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-mono text-slate-500 uppercase">{{ $categorie }}</span>
                                </div>
                                <p class="text-xs text-slate-500 mt-0.5">{{ $meta['description'] ?? 'Paramètres de configuration du groupe.' }}</p>
                            </div>
                        </div>
                        <span class="rounded-full bg-slate-100 border border-slate-200 px-2.5 py-1 text-xs font-bold text-slate-600">
                            {{ $parametres->count() }} paramètre(s)
                        </span>
                    </div>

                    {{-- Liste des paramètres de la catégorie --}}
                    <div class="divide-y divide-slate-100">
                        @foreach($parametres as $parametre)
                            @php($definition = $definitions[$parametre->cle] ?? null)
                            <div class="py-4 first:pt-0 last:pb-0"
                                data-recherche="{{ mb_strtolower($parametre->cle.' '.($parametre->description ?? '').' '.$categorie) }}"
                                x-show="$el.dataset.recherche.includes(recherche.toLowerCase())"
                                data-initiale="{{ old('valeur', $parametre->valeur) }}"
                                x-data="{ initiale: $el.dataset.initiale, courante: $el.dataset.initiale }">
                                <form method="post" action="{{ route('admin.parametres.update', $parametre) }}"
                                    class="flex flex-col sm:flex-row sm:items-start gap-4"
                                    x-data="{ envoi: false }" @submit="envoi = true"
                                    @if(($definition['impact'] ?? 'normal') === 'fort') onsubmit="return confirm('Paramètre à fort impact : confirmer la modification ?')" @endif>
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="attendu" value="{{ $parametre->updated_at?->toIso8601String() }}">

                                    <div class="flex-1 min-w-0">
                                        {{-- Libellé et description --}}
                                        <div class="flex flex-wrap items-center gap-2">
                                            <label class="text-xs font-black font-mono text-slate-800" for="p-{{ $parametre->id }}">
                                                {{ $parametre->cle }}
                                            </label>

                                            @if(($definition['impact'] ?? 'normal') === 'fort')
                                                <span class="rounded-full bg-rose-100 text-rose-800 border border-rose-200 px-2 py-0.5 text-[10px] font-bold">Fort impact</span>
                                            @endif

                                            @if($definition !== null && (string) $parametre->valeur !== (string) $definition['defaut'])
                                                <span class="rounded-full bg-amber-100 text-amber-800 border border-amber-200 px-2 py-0.5 text-[10px] font-bold">Personnalisé</span>
                                            @endif

                                            @if($definition['proprietaire'] ?? null)
                                                <span class="text-[10px] text-slate-400">· Resp : {{ $definition['proprietaire'] }}</span>
                                            @endif
                                        </div>

                                        @if($parametre->description)
                                            <p class="mt-1 text-xs text-slate-600 font-medium leading-relaxed">{{ $parametre->description }}</p>
                                        @endif

                                        <p class="mt-1 text-[11px] text-slate-400">
                                            @if($definition !== null)
                                                Défaut : <span class="font-mono text-slate-500 font-semibold">{{ (string) $definition['defaut'] }}</span> ·
                                            @endif
                                            @if($parametre->auteur)
                                                Modifié par {{ $parametre->auteur->name }} le {{ $parametre->updated_at?->format('d/m/Y H:i') }}
                                            @else
                                                Valeur initiale du système
                                            @endif
                                        </p>

                                        @error('valeur', 'p'.$parametre->id)
                                            <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1" role="alert">
                                                <span>⚠</span> {{ $message }}
                                            </p>
                                        @enderror
                                        @error('concurrence', 'p'.$parametre->id)
                                            <p class="mt-1.5 text-xs font-bold text-rose-600 flex items-center gap-1" role="alert">
                                                <span>⚠</span> {{ $message }}
                                            </p>
                                        @enderror

                                        {{-- Volet d'historique des versions --}}
                                        @if(($historiques[$parametre->id] ?? collect())->isNotEmpty())
                                            <details class="mt-2.5 rounded-xl border border-slate-200 bg-slate-50/60 overflow-hidden">
                                                <summary class="cursor-pointer px-3 py-1.5 text-[11px] font-bold text-slate-600 hover:text-slate-900 focus:outline-none flex items-center gap-1.5 select-none">
                                                    <span>📜</span> Historique ({{ ($historiques[$parametre->id] ?? collect())->count() }})
                                                </summary>
                                                <ul class="divide-y divide-slate-100 bg-white px-3 pb-2 pt-1">
                                                    @foreach(($historiques[$parametre->id] ?? collect())->take(10) as $version)
                                                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1 py-1.5 text-[11px] text-slate-500">
                                                            <span class="font-mono text-slate-400 font-bold">#{{ $version->id }}</span>
                                                            <span class="font-mono">
                                                                <span class="text-rose-500 line-through">{{ $version->valeur_precedente ?? '—' }}</span>
                                                                <span class="text-slate-400">→</span>
                                                                <span class="text-emerald-700 font-bold">{{ $version->valeur }}</span>
                                                            </span>
                                                            <span class="text-slate-400">· {{ $version->acteur?->name ?? 'Système' }} le {{ $version->created_at?->format('d/m/Y H:i') }}</span>
                                                            @if($version->motif)
                                                                <span class="italic text-slate-600">({{ $version->motif }})</span>
                                                            @endif
                                                            <form method="post" action="{{ route('admin.parametres.restaurer', $parametre) }}" class="ml-auto inline"
                                                                onsubmit="return confirm('Restaurer cette version ? Une nouvelle version sera créée.')">
                                                                @csrf
                                                                <input type="hidden" name="version_id" value="{{ $version->id }}">
                                                                <button type="submit" class="rounded-lg border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-bold text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition">
                                                                    Restaurer
                                                                </button>
                                                            </form>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </div>

                                    {{-- Champ de saisie et bouton d'action --}}
                                    <div class="w-full sm:w-72 flex flex-col items-end gap-1.5 flex-shrink-0">
                                        <div class="w-full flex items-center gap-2">
                                            @if($parametre->type === 'entier')
                                                <div class="relative w-full">
                                                    <input id="p-{{ $parametre->id }}" name="valeur" type="number" step="1"
                                                        x-model="courante" value="{{ old('valeur', $parametre->valeur) }}"
                                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold focus:border-primary-500 focus:outline-none transition @error('valeur', 'p'.$parametre->id) border-rose-300 bg-rose-50/50 @enderror" />
                                                </div>
                                            @elseif($parametre->type === 'booleen')
                                                <select id="p-{{ $parametre->id }}" name="valeur" x-model="courante"
                                                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold focus:border-primary-500 focus:outline-none transition">
                                                    @foreach(['1' => 'Activé (Oui)', '0' => 'Désactivé (Non)'] as $option => $libelle)
                                                        <option value="{{ $option }}" {{ old('valeur', $parametre->valeur) == $option ? 'selected' : '' }}>
                                                            {{ $libelle }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <input id="p-{{ $parametre->id }}" name="valeur" type="text"
                                                    x-model="courante" value="{{ old('valeur', $parametre->valeur) }}"
                                                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold focus:border-primary-500 focus:outline-none transition @error('valeur', 'p'.$parametre->id) border-rose-300 bg-rose-50/50 @enderror" />
                                            @endif

                                            <x-btn size="sm" type="submit" x-bind:disabled="envoi || courante === initiale"
                                                x-text="envoi ? 'Enregistrement…' : 'Enregistrer'"
                                                title="Enregistrer (actif si modifié)">Enregistrer</x-btn>
                                        </div>

                                        {{-- Prévisualisation de changement --}}
                                        <p x-show="courante !== initiale" x-cloak class="w-full text-right text-[11px] text-slate-500 font-medium">
                                            Aperçu : <span class="font-mono line-through text-rose-500" x-text="initiale"></span> →
                                            <span class="font-mono font-bold text-emerald-700" x-text="courante"></span>
                                        </p>
                                    </div>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>
@endsection
