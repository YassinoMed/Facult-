@extends('layouts.app')

@section('title', 'Modifier la période #'.$periode->id.' — Administration')

@section('content')
<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    {{-- Navigation retour & En-tête --}}
    <div class="flex items-center justify-between border-b border-slate-200 dark:border-slate-800 pb-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.periodes.show', $periode) }}" class="rounded-xl border border-slate-200 dark:border-slate-800 p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Modifier la période #{{ $periode->id }}</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400">Ajustez le calendrier ou les paramètres de ciblage de cette fenêtre.</p>
            </div>
        </div>
        <div>
            @php
                $etat = $periode->statutCalcule();
            @endphp
            @if($etat === 'OPEN')
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    Actuellement ouverte
                </span>
            @elseif($etat === 'SCHEDULED')
                <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">
                    <span class="h-2 w-2 rounded-full bg-sky-500"></span>
                    Planifiée
                </span>
            @endif
        </div>
    </div>

    {{-- Alertes d'erreur --}}
    @if($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50/80 p-4 dark:border-rose-900/50 dark:bg-rose-950/40">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div>
                    <h3 class="text-sm font-semibold text-rose-800 dark:text-rose-300">Des erreurs empêchent la modification :</h3>
                    <ul class="mt-1 list-disc list-inside text-xs text-rose-700 dark:text-rose-400 space-y-0.5">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- Formulaire moderne --}}
    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm p-6 sm:p-8">
        <form method="post" action="{{ route('admin.periodes.update', $periode) }}" class="space-y-6">
            @csrf
            @method('PUT')

            {{-- Section 1 : Cadre général --}}
            <div>
                <h2 class="text-sm font-bold uppercase tracking-wider text-primary-600 dark:text-primary-400 mb-3 flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-100 text-[10px] text-primary-700 font-extrabold">1</span>
                    Cadre général
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="annee_universitaire_id" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Année universitaire *</label>
                        <select name="annee_universitaire_id" id="annee_universitaire_id" required class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                            @foreach($annees as $annee)
                                <option value="{{ $annee->id }}" @selected((int) old('annee_universitaire_id', $periode->annee_universitaire_id) === $annee->id)>{{ $annee->libelle }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="type_periode" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Type de période *</label>
                        <select name="type_periode" id="type_periode" required class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                            <option value="normale" @selected(old('type_periode', $periode->type_periode) === 'normale')>Normale</option>
                            <option value="exception" @selected(old('type_periode', $periode->type_periode) === 'exception')>Exception</option>
                        </select>
                    </div>

                    <div>
                        <label for="nom" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Libellé</label>
                        <input type="text" name="nom" id="nom" value="{{ old('nom', $periode->nom) }}" maxlength="150" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500" />
                    </div>
                </div>
            </div>

            {{-- Section 2 : Portée et Ciblage --}}
            <div class="pt-4 border-t border-slate-100 dark:border-slate-800">
                <h2 class="text-sm font-bold uppercase tracking-wider text-primary-600 dark:text-primary-400 mb-3 flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-100 text-[10px] text-primary-700 font-extrabold">2</span>
                    Portée et critères de ciblage
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div>
                        <label for="faculte_id" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Faculté</label>
                        <select name="faculte_id" id="faculte_id" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                            <option value="">Toutes les facultés</option>
                            @foreach($facultes as $faculte)
                                <option value="{{ $faculte->id }}" @selected((int) old('faculte_id', $periode->faculte_id) === $faculte->id)>{{ $faculte->nom }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="programme_id" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Programme</label>
                        <select name="programme_id" id="programme_id" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                            <option value="">Tous les programmes</option>
                            @foreach($programmes as $programme)
                                <option value="{{ $programme->id }}" @selected((int) old('programme_id', $periode->programme_id) === $programme->id)>{{ $programme->code }} — {{ $programme->nom }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="niveau" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Niveau d'étude</label>
                        <input type="text" name="niveau" id="niveau" value="{{ old('niveau', $periode->niveau) }}" maxlength="10" placeholder="Ex. L1" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500" />
                    </div>

                    <div>
                        <label for="type_inscription" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Type d'inscription</label>
                        <select name="type_inscription" id="type_inscription" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                            <option value="">Tous</option>
                            <option value="premiere" @selected(old('type_inscription', $periode->type_inscription) === 'premiere')>Première inscription</option>
                            <option value="reinscription" @selected(old('type_inscription', $periode->type_inscription) === 'reinscription')>Réinscription</option>
                        </select>
                    </div>

                    <div class="sm:col-span-2">
                        <label for="etudiant_id" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Étudiant individuel</label>
                        <input type="number" name="etudiant_id" id="etudiant_id" value="{{ old('etudiant_id', $periode->etudiant_id) }}" min="1" placeholder="ID étudiant" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500" />
                    </div>
                </div>
            </div>

            {{-- Section 3 : Calendrier --}}
            <div class="pt-4 border-t border-slate-100 dark:border-slate-800">
                <h2 class="text-sm font-bold uppercase tracking-wider text-primary-600 dark:text-primary-400 mb-3 flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-100 text-[10px] text-primary-700 font-extrabold">3</span>
                    Calendrier d'ouverture
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="opened_at" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Ouverture *</label>
                        <input type="datetime-local" name="opened_at" id="opened_at" required value="{{ old('opened_at', $periode->opened_at->format('Y-m-d\TH:i')) }}" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500" />
                    </div>

                    <div>
                        <label for="closed_at" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Fermeture *</label>
                        <input type="datetime-local" name="closed_at" id="closed_at" required value="{{ old('closed_at', $periode->closed_at->format('Y-m-d\TH:i')) }}" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500" />
                    </div>
                </div>
            </div>

            {{-- Section 4 : Motif --}}
            <div class="pt-4 border-t border-slate-100 dark:border-slate-800">
                <h2 class="text-sm font-bold uppercase tracking-wider text-primary-600 dark:text-primary-400 mb-3 flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-100 text-[10px] text-primary-700 font-extrabold">4</span>
                    Motif et traçabilité
                </h2>
                <div>
                    <label for="motif" class="mb-1 block text-xs font-semibold uppercase text-slate-500">Motif administratif</label>
                    <textarea name="motif" id="motif" rows="3" class="w-full rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-3 py-2 text-sm text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500">{{ old('motif', $periode->motif) }}</textarea>
                </div>
            </div>

            {{-- Actions --}}
            <div class="pt-6 border-t border-slate-200 dark:border-slate-800 flex items-center justify-end gap-3">
                <a href="{{ route('admin.periodes.show', $periode) }}" class="rounded-xl border border-slate-300 dark:border-slate-700 px-4 py-2.5 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    Annuler
                </a>
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-500 shadow-sm transition">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

