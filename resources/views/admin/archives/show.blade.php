@extends('layouts.app')

@section('title', 'Dossier Académique — ' . $etudiant->nom_complet)
@section('meta_description', 'Dossier académique historique complet de l\'étudiant.')

@section('content')
<div class="pt-3 sm:pt-6">

    <div class="w-full overflow-hidden border-b border-slate-200/90 bg-white shadow-2xl shadow-slate-400/25">

        {{-- ═══ EN-TÊTE : identité du dossier ═══ --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b-2 border-slate-800/90 px-6 py-5 sm:px-10 lg:px-16 xl:px-20">
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl border-2 border-slate-800/80 text-slate-900">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6m-1.5 12V10.332A48.36 48.36 0 0 0 12 9.75c-2.551 0-5.056.2-7.5.582V21M3 21h18M12 6.75h.008v.008H12V6.75Z" />
                    </svg>
                </div>
                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Archives Académiques — Service de la Scolarité</p>
                    <h1 class="font-serif text-xl sm:text-2xl font-bold text-slate-900">Dossier Académique</h1>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <a href="{{ route('admin.archives.index') }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
                    Registre
                </a>
                <a href="{{ route('admin.archives.export', $dossier) }}"
                   class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3.5 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 transition-smooth">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    Export CSV
                </a>
                @can('update', $dossier)
                    <a href="{{ route('admin.archives.edit', $dossier) }}"
                       class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" /></svg>
                        Gérer le dossier
                    </a>
                @endcan
            </div>
        </div>
        <div class="h-px bg-gradient-to-r from-slate-300 via-slate-800/60 to-slate-300"></div>

        {{-- ═══ Identité étudiant ═══ --}}
        <div class="px-6 py-7 sm:px-10 lg:px-16 xl:px-20">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-5">
                    <div class="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-xl border-2 border-slate-800/80 bg-slate-50 text-xl font-black text-slate-800">
                        {{ mb_strtoupper(mb_substr($etudiant->prenom, 0, 1)) }}{{ mb_strtoupper(mb_substr($etudiant->nom, 0, 1)) }}
                    </div>
                    <div>
                        <h2 class="font-serif text-2xl font-bold text-slate-900">{{ $etudiant->nom_complet }}</h2>
                        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span><span class="font-mono font-bold text-slate-700">{{ $dossier->matricule }}</span> · Matricule</span>
                            <span><span class="font-mono font-bold text-slate-700">{{ $etudiant->cin }}</span> · CIN</span>
                            <span><span class="font-bold text-slate-700">{{ $etudiant->num_inscription }}</span> · Inscription</span>
                            <span class="font-semibold">{{ $etudiant->faculte->nom }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col items-start gap-2 lg:items-end">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Statut du dossier · archivé le {{ $dossier->date_archivage->format('d/m/Y') }}</p>
                    @if($dossier->statut === 'actif')
                        <div class="inline-flex rotate-2 items-center rounded-md border-[3px] border-emerald-600/80 bg-emerald-50/40 px-4 py-1.5">
                            <span class="text-sm font-black uppercase tracking-[0.25em] text-emerald-700/90">Actif</span>
                        </div>
                    @else
                        <div class="inline-flex -rotate-2 items-center rounded-md border-[3px] border-slate-600/80 bg-slate-50 px-4 py-1.5">
                            <span class="text-sm font-black uppercase tracking-[0.25em] text-slate-600/90">Clos</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ═══ SYNTHÈSE ═══ --}}
        <div class="grid grid-cols-2 divide-y divide-slate-200 border-y border-slate-200 bg-slate-50/70 sm:grid-cols-4 sm:divide-x sm:divide-y-0">
            <div class="px-6 py-4 sm:px-10 lg:px-16 xl:px-20">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Moyenne générale</p>
                <p class="mt-1 text-2xl font-black {{ ($synthese['moyenne_generale'] ?? 0) >= 10 ? 'text-emerald-600' : 'text-slate-900' }}">{{ $synthese['moyenne_generale'] !== null ? number_format($synthese['moyenne_generale'], 2) : '—' }}<span class="text-sm text-slate-400">/20</span></p>
            </div>
            <div class="px-6 py-4 sm:px-10 lg:px-16 xl:px-20">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Crédits obtenus</p>
                <p class="mt-1 text-2xl font-black text-slate-900">{{ $synthese['credits_obtenus'] }}<span class="text-sm text-slate-400">/ {{ $synthese['credits_total'] }}</span></p>
            </div>
            <div class="px-6 py-4 sm:px-10 lg:px-16 xl:px-20">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Années validées</p>
                <p class="mt-1 text-2xl font-black text-slate-900">{{ $synthese['annees_validees'] }}<span class="text-sm text-slate-400">/ {{ $synthese['annees_total'] }}</span></p>
            </div>
            <div class="px-6 py-4 sm:px-10 lg:px-16 xl:px-20">
                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Dernière décision</p>
                <p class="mt-1 text-sm font-bold text-slate-700">{{ $inscriptions->first()?->decision ?? 'En cours' }}</p>
            </div>
        </div>

        {{-- ═══ PARCOURS / NOTES / MOYENNES ═══ --}}
        <div class="px-6 py-8 sm:px-10 sm:py-10 lg:px-16 xl:px-20 space-y-6" x-data="{ inscriptionForm: false }">

            <div class="flex items-center gap-3 border-b border-slate-800/80 pb-2">
                <span class="font-serif text-sm font-bold text-slate-900">I.</span>
                <h3 class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">Parcours universitaire & notes</h3>
                @can('archives.create')
                    <button type="button" @click="inscriptionForm = !inscriptionForm"
                            class="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-600 transition-smooth hover:border-primary-600 hover:text-primary-700">
                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Ajouter une inscription
                    </button>
                @endcan
            </div>

            {{-- Formulaire d'ajout d'inscription académique (Alpine toggle + état période serveur) --}}
            @can('archives.create')
                <div x-show="inscriptionForm" x-cloak x-transition
                     class="rounded-2xl border border-primary-200 bg-primary-50/40 p-5"
                     x-data="{ anneeChoisie: '', etatInsc: {statut: '', interactif: true, titre: '', message: ''} }">
                        <div x-show="etatInsc.statut !== ''" x-cloak role="status"
                             :aria-label="'État des inscriptions : ' + etatInsc.titre"
                             class="mb-4 rounded-xl border px-4 py-3 text-xs sm:text-sm"
                             :class="etatInsc.interactif ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-rose-300 bg-rose-50 text-rose-900'">
                            <p class="font-bold" x-text="(etatInsc.interactif ? '✓ ' : '🔒 ') + etatInsc.titre"></p>
                            <p class="mt-1" x-text="etatInsc.message"></p>
                        </div>
                        <form method="POST" action="{{ route('admin.archives.inscriptions.store', $dossier) }}" class="space-y-4">
                            @csrf
                            <fieldset :disabled="! etatInsc.interactif" class="space-y-4 disabled:opacity-60" aria-label="Formulaire d'inscription académique">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Année universitaire *</label>
                                    <select name="annee_universitaire_id" required x-model="anneeChoisie"
                                            @change="if (anneeChoisie === '') { etatInsc = {statut: '', interactif: true, titre: '', message: ''}; } else { fetch('{{ route('admin.periodes.etat') }}?etudiant_id={{ $dossier->etudiant_id }}&annee_universitaire_id=' + anneeChoisie + '&type_inscription=premiere').then(r => r.json()).then(j => { etatInsc = j; }).catch(() => { etatInsc = {statut: '', interactif: true, titre: '', message: ''}; }); }"
                                            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none disabled:cursor-not-allowed">
                                        <option value="">— Année —</option>
                                        @foreach($annees as $annee)
                                            <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                                        @endforeach
                                    </select>
                                    @error('annee_universitaire_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Niveau *</label>
                                    <select name="niveau" required
                                            class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                                        <option value="">— Niveau —</option>
                                        @foreach(['L1','L2','L3','M1','M2','D1','D2','D3'] as $n)
                                            <option value="{{ $n }}">{{ $n }}</option>
                                        @endforeach
                                    </select>
                                    @error('niveau')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                </div>
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Filière</label>
                                    <input type="text" name="filiere" placeholder="ex : Sciences & Techniques"
                                           class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                                </div>
                                <div>
                                    <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Groupe</label>
                                    <input type="text" name="groupe" placeholder="ex : G1"
                                           class="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                                </div>
                            </div>
                            </fieldset>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="inscriptionForm = false" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-600 hover:bg-white transition-smooth">Annuler</button>
                                <button type="submit" :disabled="! etatInsc.interactif" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white hover:bg-primary-700 transition-smooth disabled:cursor-not-allowed disabled:opacity-60" x-text="etatInsc.interactif ? 'Créer l\'inscription (S1 + S2)' : '🔒 Inscriptions fermées'"></button>
                            </div>
                        </form>
                    </div>
            @endcan

            @forelse($inscriptions as $inscription)
                {{-- ── Une année universitaire = un accordéon Alpine ── --}}
                <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden"
                     x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }">
                    <button type="button" @click="open = !open"
                            class="w-full flex flex-col sm:flex-row sm:items-center gap-3 px-5 py-4 text-left hover:bg-slate-50/70 transition-smooth">
                        <div class="flex items-center gap-4 flex-1 min-w-0">
                            <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl font-black text-xs
                                {{ $inscription->statut === 'valide' ? 'bg-emerald-100 text-emerald-700' : ($inscription->statut === 'ajourne' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700') }}">
                                {{ $inscription->niveau }}
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-900">{{ $inscription->anneeUniversitaire->libelle }} — {{ $inscription->niveau }}</p>
                                <p class="text-[11px] text-slate-400 truncate">{{ $inscription->filiere ?? 'Filière non précisée' }}{{ $inscription->groupe ? ' · Groupe '.$inscription->groupe : '' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-xs flex-shrink-0">
                            <div class="text-right">
                                <p class="font-black text-slate-900">{{ $inscription->moyenne_annuelle !== null ? number_format((float) $inscription->moyenne_annuelle, 2) : '—' }}<span class="text-slate-400">/20</span></p>
                                <p class="text-[10px] text-slate-400">Moyenne annuelle</p>
                            </div>
                            <div class="text-right hidden sm:block">
                                <p class="font-black text-slate-900">{{ $inscription->credits_obtenus }}/{{ $inscription->credits_total }}</p>
                                <p class="text-[10px] text-slate-400">Crédits</p>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-[10px] font-bold
                                {{ $inscription->statut === 'valide' ? 'bg-emerald-100 text-emerald-800' : ($inscription->statut === 'ajourne' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">
                                {{ $inscription->decision ?? 'En cours' }}
                            </span>
                            <svg class="h-4 w-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                        </div>
                    </button>

                    <div x-show="open" x-cloak x-transition class="border-t border-slate-100 bg-slate-50/50 px-5 py-5 space-y-5">
                        @can('archives.update')
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('admin.inscriptions.pedagogique', $inscription) }}" class="rounded-xl border border-slate-300 px-3 py-1.5 text-[11px] font-bold text-primary-800 hover:bg-white min-h-[36px] inline-flex items-center">Pédagogique ({{ $inscription->pedagogiques_count }})</a>
                                @if($inscription->statut === 'en_cours')
                                    <form method="POST" action="{{ route('admin.inscriptions.valider', $inscription) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="rounded-xl bg-emerald-600 px-3 py-1.5 text-[11px] font-bold text-white hover:bg-emerald-700 min-h-[36px]">Valider</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.inscriptions.rejeter', $inscription) }}" class="inline-flex items-center gap-1.5">
                                        @csrf
                                        <label for="motif-{{ $inscription->id }}" class="sr-only">Motif d'ajournement</label>
                                        <input id="motif-{{ $inscription->id }}" name="motif" required maxlength="255" placeholder="Motif d'ajournement" class="rounded-xl border border-slate-300 px-3 py-1.5 text-[11px] min-h-[36px]">
                                        <button type="submit" class="rounded-xl border border-rose-300 px-3 py-1.5 text-[11px] font-bold text-rose-700 hover:bg-rose-50 min-h-[36px]">Ajourner</button>
                                    </form>
                                @endif
                            </div>
                        @endcan
                        @foreach($inscription->semestres as $semestre)
                            <div class="rounded-xl border border-slate-200 bg-white overflow-hidden" x-data="{ noteForm: false }">
                                <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-slate-100 bg-white">
                                    <div class="flex items-center gap-3">
                                        <span class="rounded-lg bg-slate-900 px-2.5 py-1 text-[11px] font-black text-white">{{ $semestre->libelle }}</span>
                                        <span class="text-xs text-slate-500">Moyenne : <strong class="text-slate-800">{{ $semestre->moyenne !== null ? number_format((float) $semestre->moyenne, 2) : '—' }}</strong> · Crédits : <strong class="text-slate-800">{{ $semestre->credits_obtenus }}/{{ $semestre->credits_total }}</strong> · {{ $semestre->decision ?? '—' }}</span>
                                    </div>
                                    @can('notes.create')
                                        <button type="button" @click="noteForm = !noteForm"
                                                class="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-600 hover:border-primary-600 hover:text-primary-700 transition-smooth">
                                            + Saisir une note
                                        </button>
                                    @endcan
                                </div>

                                {{-- Saisie de note --}}
                                @can('notes.create')
                                    <div x-show="noteForm" x-cloak class="border-b border-slate-100 bg-primary-50/30 px-4 py-4">
                                        <form method="POST" action="{{ route('admin.archives.notes.store', $inscription) }}" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                                            @csrf
                                            <input type="hidden" name="semestre_numero" value="{{ $semestre->numero }}">
                                            <div class="lg:col-span-2">
                                                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Matière *</label>
                                                <select name="matiere_id" required
                                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                                                    <option value="">— Matière ({{ $inscription->niveau }} · S{{ $semestre->numero }}) —</option>
                                                    @foreach($matieres->where('niveau', $inscription->niveau)->where('semestre', $semestre->numero) as $matiere)
                                                        <option value="{{ $matiere->id }}">{{ $matiere->libelle_complet }} (coef. {{ $matiere->coefficient }}, {{ $matiere->credits }} cr.)</option>
                                                    @endforeach
                                                </select>
                                                @error('matiere_id')<p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>@enderror
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Note / 20 *</label>
                                                <input type="number" name="note" step="0.25" min="0" max="20" required
                                                       class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                                                @error('note')<p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>@enderror
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Session</label>
                                                <select name="session"
                                                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                                                    <option value="normale">Normale</option>
                                                    <option value="rattrapage">Rattrapage</option>
                                                </select>
                                                @error('session')<p class="mt-1 text-[11px] text-rose-600">{{ $message }}</p>@enderror
                                            </div>
                                            <button type="submit"
                                                    class="rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">
                                                Enregistrer
                                            </button>
                                        </form>
                                    </div>
                                @endcan

                                {{-- Notes du semestre --}}
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left text-xs">
                                        <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-100">
                                            <tr>
                                                <th class="px-4 py-2.5">Matière</th>
                                                <th class="px-4 py-2.5">Coef.</th>
                                                <th class="px-4 py-2.5">Crédits</th>
                                                <th class="px-4 py-2.5">Session</th>
                                                <th class="px-4 py-2.5">Note</th>
                                                <th class="px-4 py-2.5 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-50">
                                            @forelse($semestre->notes as $note)
                                                <tr class="{{ $note->estVerrouillee() ? 'bg-slate-50/60' : '' }}">
                                                    <td class="px-4 py-2.5 font-bold text-slate-800">
                                                        {{ $note->matiere->code }}
                                                        @if($note->estVerrouillee())
                                                            <span title="Note verrouillée" class="ml-1 text-amber-500">🔒</span>
                                                        @endif
                                                    </td>
                                                    <td class="px-4 py-2.5 text-slate-500">{{ number_format((float) $note->coefficient, 1) }}</td>
                                                    <td class="px-4 py-2.5 text-slate-500">{{ $note->credits }}</td>
                                                    <td class="px-4 py-2.5">
                                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-bold {{ $note->session === 'normale' ? 'bg-slate-100 text-slate-600' : 'bg-amber-100 text-amber-800' }}">{{ $note->session === 'normale' ? 'Normale' : 'Rattrapage' }}</span>
                                                    </td>
                                                    <td class="px-4 py-2.5">
                                                        <span class="font-black {{ (float) $note->note >= 10 ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format((float) $note->note, 2) }}</span>
                                                    </td>
                                                    <td class="px-4 py-2.5 text-right">
                                                        <div class="flex items-center justify-end gap-1.5">
                                                            @can('notes.unlock')
                                                                <form method="POST" action="{{ route('admin.archives.notes.lock', $note) }}">
                                                                    @csrf @method('PATCH')
                                                                    <button type="submit" title="{{ $note->estVerrouillee() ? 'Déverrouiller' : 'Verrouiller la note' }}"
                                                                            class="rounded-lg px-2 py-1 text-[11px] {{ $note->estVerrouillee() ? 'bg-amber-100 text-amber-700 hover:bg-amber-200' : 'bg-slate-100 text-slate-500 hover:bg-slate-200' }} transition-smooth">
                                                                        {{ $note->estVerrouillee() ? 'Déverrouiller' : 'Verrouiller' }}
                                                                    </button>
                                                                </form>
                                                            @endcan
                                                            @can('update', $note)
                                                                <div x-data="{ open: false }" class="inline-flex items-center gap-1.5">
                                                                    <form method="POST" action="{{ route('admin.archives.notes.update', $note) }}" class="inline-flex items-center gap-1">
                                                                        @csrf @method('PATCH')
                                                                        <input x-show="open" x-cloak type="number" name="note" step="0.25" min="0" max="20" value="{{ $note->note }}"
                                                                               class="w-20 rounded-lg border border-slate-300 px-2 py-1 text-xs">
                                                                        <input x-show="open" x-cloak type="text" name="raison" placeholder="Motif de correction"
                                                                               class="w-36 rounded-lg border border-slate-300 px-2 py-1 text-xs">
                                                                        <button x-show="open" x-cloak type="submit"
                                                                                class="rounded-lg bg-slate-900 px-2 py-1 text-[11px] font-bold text-white hover:bg-slate-800 transition-smooth">OK</button>
                                                                    </form>
                                                                    <button type="button" @click="open = !open"
                                                                            class="rounded-lg px-2 py-1 text-[11px] font-semibold text-primary-600 hover:bg-primary-50 transition-smooth">
                                                                        <span x-text="open ? 'Annuler' : 'Corriger'"></span>
                                                                    </button>
                                                                </div>
                                                            @endcan
                                                            @can('delete', $note)
                                                                <form method="POST" action="{{ route('admin.archives.notes.destroy', $note) }}"
                                                                      onsubmit="return confirm('Supprimer définitivement cette note ?')">
                                                                    @csrf @method('DELETE')
                                                                    <button type="submit" class="rounded-lg px-2 py-1 text-[11px] text-rose-500 hover:bg-rose-50 transition-smooth">Suppr.</button>
                                                                </form>
                                                            @endcan
                                                        </div>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="6" class="px-4 py-6 text-center text-slate-400">Aucune note saisie pour ce semestre.</td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach

                        {{-- Suppression de l'inscription --}}
                        @can('archives.delete')
                            <form method="POST" action="{{ route('admin.archives.inscriptions.destroy', $inscription) }}"
                                  onsubmit="return confirm('Supprimer cette inscription académique avec tous ses semestres et notes ?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-[11px] font-semibold text-rose-500 hover:text-rose-700 hover:underline transition-smooth">
                                    Supprimer l'inscription {{ $inscription->anneeUniversitaire->libelle }}…
                                </button>
                            </form>
                        @endcan
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-400">
                    Aucune inscription académique enregistrée pour ce dossier.
                </div>
            @endforelse
        </div>

        {{-- ═══ DOCUMENTS ═══ --}}
        <div class="border-t border-slate-200 px-6 py-8 sm:px-10 sm:py-10 lg:px-16 xl:px-20 space-y-5">
            <div class="flex items-center gap-3 border-b border-slate-800/80 pb-2">
                <span class="font-serif text-sm font-bold text-slate-900">II.</span>
                <h3 class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">Documents archivés</h3>
                <span class="ml-auto text-[10px] text-slate-400">{{ $documents->count() }} fichier(s) · stockage sécurisé</span>
            </div>

            {{-- Checklist documentaire (LOOP 4) : informative uniquement —
                 signale les pièces manquantes sans modifier aucune décision. --}}
            @if(! empty($piecesDossier['pieces']))
                <div class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4" role="status" aria-label="Complétude documentaire">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-600">Pièces obligatoires — {{ $piecesDossier['progression'] }}</p>
                        @if($piecesDossier['complet'])
                            <span class="rounded-full bg-emerald-100 text-emerald-800 border border-emerald-200 px-2.5 py-0.5 text-[10px] font-bold">Dossier complet</span>
                        @else
                            <span class="rounded-full bg-amber-100 text-amber-800 border border-amber-200 px-2.5 py-0.5 text-[10px] font-bold">Dossier incomplet</span>
                        @endif
                    </div>
                    <ul class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                        @foreach($piecesDossier['pieces'] as $piece)
                            <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                <span class="text-xs font-semibold text-slate-700">{{ $piece['libelle'] }}{{ $piece['obligatoire'] ? ' *' : '' }}</span>
                                <span class="flex-shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold
                                    {{ $piece['statut'] === 'valide' ? 'bg-emerald-100 text-emerald-800' : ($piece['statut'] === 'non_fourni' ? 'bg-slate-100 text-slate-500' : ($piece['statut'] === 'rejete' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800')) }}">{{ $piece['statut_libelle'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-[10px] text-slate-400">Indicateur informatif — sans effet automatique sur les décisions d'inscription.</p>
                </div>
            @endif

            {{-- Upload --}}
            @can('documents.upload')
                <form method="POST" action="{{ route('admin.archives.documents.store', $dossier) }}"
                      enctype="multipart/form-data"
                      class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                    @csrf
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Type de document *</label>
                        <select name="type_document" required
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            @foreach(\App\Services\DocumentService::TYPES_DOCUMENTS as $cle => $libelle)
                                <option value="{{ $cle }}">{{ $libelle }}</option>
                            @endforeach
                        </select>
                        @error('type_document')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Année (optionnelle)</label>
                        <select name="annee_universitaire_id"
                                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                            <option value="">— Année concernée —</option>
                            @foreach($annees as $annee)
                                <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                            @endforeach
                        </select>
                        @error('annee_universitaire_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-[10px] font-bold uppercase tracking-wider text-slate-500">Fichier (max 10 Mo) *</label>
                        <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt"
                               class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 file:mr-2 file:rounded-lg file:border-0 file:bg-slate-900 file:px-2.5 file:py-1.5 file:text-[11px] file:font-bold file:text-white focus:border-primary-600 focus:ring-4 focus:ring-primary-500/15 focus:outline-none">
                        @error('document')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit"
                            class="rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white hover:bg-slate-800 transition-smooth">
                        Archiver le document
                    </button>
                </form>
            @endcan

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                @forelse($documents as $document)
                    <div class="flex items-center gap-3.5 rounded-2xl border border-slate-200 bg-white p-4">
                        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 border border-primary-100 font-black text-[10px] uppercase">
                            {{ \Illuminate\Support\Str::afterLast($document->nom_original, '.') }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-bold text-slate-900">{{ $document->nom_original }}</p>
                            <p class="text-[10px] text-slate-400">
                                {{ \App\Services\DocumentService::TYPES_DOCUMENTS[$document->type_document] ?? $document->type_document }} · {{ $document->taille_lisible }} · {{ $document->anneeUniversitaire?->libelle ?? 'Sans année' }} · {{ $document->created_at->format('d/m/Y') }}
                            </p>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            @can('documents.download')
                                <a href="{{ route('admin.archives.documents.download', $document) }}"
                                   class="rounded-lg bg-slate-900 px-2.5 py-1.5 text-[10px] font-bold text-white hover:bg-slate-800 transition-smooth">Télécharger</a>
                            @endcan
                            @can('documents.delete')
                                <form method="POST" action="{{ route('admin.archives.documents.destroy', $document) }}"
                                      onsubmit="return confirm('Supprimer définitivement ce document ?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="rounded-lg px-2 py-1.5 text-[11px] text-rose-500 hover:bg-rose-50 transition-smooth">✕</button>
                                </form>
                            @endcan
                        </div>
                    </div>
                @empty
                    <div class="md:col-span-2 rounded-2xl border border-dashed border-slate-300 p-8 text-center text-xs text-slate-400">
                        Aucun document archivé pour cet étudiant.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ═══ HISTORIQUE / AUDIT ═══ --}}
        {{-- Restreint (LOOP 3) : acteur, IP et valeurs = données sensibles ;
             l'affichage suit la même garde que le chargement contrôleur. --}}
        @can('update', $dossier)
        <div class="border-t border-slate-200 bg-slate-50/60 px-6 py-8 sm:px-10 sm:py-10 lg:px-16 xl:px-20">
            <div class="mb-4 flex items-center gap-3 border-b border-slate-800/80 pb-2">
                <span class="font-serif text-sm font-bold text-slate-900">III.</span>
                <h3 class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-700">Historique des actions (audit)</h3>
            </div>

            <div class="max-h-96 overflow-y-auto rounded-2xl border border-slate-200 bg-white divide-y divide-slate-50">
                @forelse($journal as $entree)
                    <div class="flex items-start gap-3.5 px-4 py-3">
                        <div class="mt-1 h-2 w-2 flex-shrink-0 rounded-full
                            {{ str_contains($entree->action, 'suppression') ? 'bg-rose-500' : (str_contains($entree->action, 'modification') || str_contains($entree->action, 'verrouillage') ? 'bg-amber-500' : (str_contains($entree->action, 'consultation') ? 'bg-slate-300' : 'bg-emerald-500')) }}"></div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-slate-800">{{ $entree->action_libelle }}</p>
                            @if($entree->ancienne_valeur || $entree->nouvelle_valeur)
                                <p class="mt-0.5 truncate text-[10px] font-mono text-slate-400">
                                    @if($entree->ancienne_valeur)<span class="text-rose-400">{{ $entree->ancienne_valeur }}</span> → @endif
                                    @if($entree->nouvelle_valeur)<span class="text-emerald-500">{{ $entree->nouvelle_valeur }}</span>@endif
                                </p>
                            @endif
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-[10px] font-semibold text-slate-500">{{ $entree->utilisateur?->name ?? 'Étudiant / Système' }}</p>
                            <p class="text-[10px] text-slate-400">{{ $entree->created_at->format('d/m/Y H:i') }} · {{ $entree->adresse_ip }}</p>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center text-xs text-slate-400">Aucune action enregistrée.</div>
                @endforelse
            </div>
        </div>
        @endcan
    </div>
</div>
@endsection
