@extends('layouts.app')

@section('title', 'Contraintes d’affectation des surveillances')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 py-8">
        <x-page-header
            titre="Contraintes d’affectation"
            description="Règles SOFT configurables du moteur d’équilibrage. Les contraintes HARD de sécurité ne sont jamais modifiables."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Surveillance' => route('admin.evaluations.surveillances.index'), 'Contraintes' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.affectations') }}">Affectation enseignants</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('succes'))
            <div class="mt-4"><x-alert type="success" :message="session('succes')" /></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <!-- Contraintes système (HARD, non modifiables) -->
        <div class="mt-6 ds-card p-5 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Contraintes système — obligatoires, non modifiables</h3>
            <ul class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-xs text-slate-600 dark:text-slate-400">
                @foreach(['Enseignant actif', 'Aucun chevauchement', 'Conflits horaires (épreuves + cours)', 'Isolation faculté', 'Capacité des salles', 'Principal + adjoint + garde requis', 'Unicité par épreuve', 'Épreuve non verrouillée'] as $dure)
                    <li class="flex items-center gap-1.5"><span class="text-emerald-600 font-black">✓</span> {{ $dure }}</li>
                @endforeach
            </ul>
        </div>

        <!-- Configurations par portée -->
        <div class="mt-4 ds-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100 dark:border-slate-800">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Configurations (priorité : session → faculté+année → année → système)</h3>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse($configs as $config)
                    <details class="p-4">
                        <summary class="cursor-pointer text-sm font-bold text-slate-900 dark:text-slate-100 list-none flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-indigo-100 text-indigo-800 dark:bg-indigo-900/60 dark:text-indigo-200">
                                {{ $config->portee() }}
                            </span>
                            <span>v{{ $config->version }}</span>
                            <span class="text-xs font-normal text-slate-500">
                                min {{ $config->min_surveillants }} · min/salle {{ $config->min_par_salle ?? 1 }} · ratio {{ $config->ratio_etudiants }}@if($config->seuil_grande_salle && $config->min_surveillants_grande_salle) · gde salle ≥{{ $config->seuil_grande_salle }} (min {{ $config->min_surveillants_grande_salle }})@endif · gardes {{ $config->nb_gardes }} · {{ $config->max_jours }} j {{ $config->mode_limite }}
                            </span>
                            <span class="text-[11px] font-normal text-slate-400">{{ $config->revisions->count() }} révision(s)</span>
                        </summary>
                        <form method="post" action="{{ route('admin.evaluations.surveillances.contraintes.update', $config) }}" class="mt-3 space-y-3">
                            @csrf
                            @method('PUT')
                            @include('modules.evaluation.surveillances-contraintes-champs', ['valeurs' => $config->toArray()])
                            <div class="flex items-center gap-2">
                                <x-btn type="submit" variant="primary" class="text-xs py-1.5 px-4">Enregistrer (v{{ $config->version + 1 }})</x-btn>
                                @if($config->portee() !== 'systeme')
                                    <button type="submit" formaction="{{ route('admin.evaluations.surveillances.contraintes.reset', $config) }}" formmethod="post"
                                            class="text-xs font-semibold text-slate-500 hover:text-rose-600 underline"
                                            onclick="return confirm('Restaurer les valeurs par défaut pour cette portée ?');">
                                        Restaurer les défauts
                                    </button>
                                @endif
                            </div>
                        </form>
                    </details>
                @empty
                    <p class="px-4 py-6 text-center text-sm text-slate-500"><x-empty-state message="Aucune configuration : le moteur utilise ses valeurs historiques." /></p>
                @endforelse
            </div>
        </div>

        <!-- Nouvelle portée -->
        <div class="mt-4 ds-card p-5 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Nouvelle configuration de portée</h3>
            <form method="post" action="{{ route('admin.evaluations.surveillances.contraintes.store') }}" class="mt-3 space-y-3">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                    <div>
                        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Faculté (vide = système/année)</label>
                        <select name="faculte_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5">
                            <option value="">—</option>
                            @foreach($facultes as $f)
                                <option value="{{ $f->id }}">{{ $f->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Année (vide = système/session)</label>
                        <select name="annee_universitaire_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5">
                            <option value="">—</option>
                            @foreach($annees as $a)
                                <option value="{{ $a->id }}">{{ $a->libelle }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Session (vide = non-session)</label>
                        <select name="session_examen_id" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5">
                            <option value="">—</option>
                            @foreach($sessions as $s)
                                <option value="{{ $s->id }}">{{ $s->nom }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <p class="text-[11px] text-slate-500">Formes valides : session seule · faculté + année · année seule · tout vide (= système).</p>
                @include('modules.evaluation.surveillances-contraintes-champs', ['valeurs' => []])
                <x-btn type="submit" variant="primary" class="text-xs py-1.5 px-4">Créer la configuration</x-btn>
            </form>
        </div>

        <!-- Simulation sans écriture -->
        <div class="mt-4 ds-card p-5 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
            <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Simuler avec ces valeurs (sans enregistrer, sans affecter)</h3>
            <form method="post" action="{{ route('admin.evaluations.surveillances.contraintes.simuler') }}" class="mt-3 space-y-3">
                @csrf
                <div class="max-w-xs text-xs">
                    <label class="block font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Session à simuler *</label>
                    <select name="session_id" required class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5">
                        <option value="">Sélectionner…</option>
                        @foreach($sessions as $s)
                            <option value="{{ $s->id }}">{{ $s->nom }}</option>
                        @endforeach
                    </select>
                </div>
                @include('modules.evaluation.surveillances-contraintes-champs', ['valeurs' => $effective->versParams()])
                <x-btn type="submit" variant="primary" class="text-xs py-1.5 px-4">Simuler</x-btn>
            </form>
        </div>
    </div>
@endsection
