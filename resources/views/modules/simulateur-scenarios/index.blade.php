@extends('layouts.app')

@section('title', 'Simulateur de scénarios')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Simulateur de scénarios" description="Hypothèses institutionnelles en lecture seule : effectifs, capacité, charges. Aucune donnée officielle n’est modifiée."
            :fil="['Statistiques' => route('admin.bi.index'), 'Simulateur' => '#']" />

        @if($erreur)
            <div class="mt-4"><x-alert type="error">{{ $erreur }}</x-alert></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error">{{ $errors->first() }}</x-alert></div>
        @endif

        <form method="get" action="{{ route('admin.simulateur-scenarios.index') }}" class="mt-4 ds-card p-5">
            <input type="hidden" name="executer" value="1">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <label for="faculte_id" class="text-xs font-bold text-slate-600">Faculté</label>
                    <select id="faculte_id" name="faculte_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                            @if($faculteRestreinte !== null) disabled @endif>
                        @if($faculteRestreinte === null)
                            <option value="">Toutes les facultés</option>
                        @endif
                        @foreach($facultes as $faculte)
                            <option value="{{ $faculte->id }}" @selected((int) request('faculte_id') === $faculte->id)>{{ $faculte->code }} — {{ $faculte->nom }}</option>
                        @endforeach
                    </select>
                    @if($faculteRestreinte !== null)
                        <p class="mt-1 text-xs text-slate-500">Périmètre verrouillé sur votre faculté.</p>
                    @endif
                </div>
                <div>
                    <label for="annee_id" class="text-xs font-bold text-slate-600">Année universitaire</label>
                    <select id="annee_id" name="annee_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        <option value="">Toutes les années</option>
                        @foreach($annees as $annee)
                            <option value="{{ $annee->id }}" @selected((int) request('annee_id') === $annee->id)>{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="delta_effectifs" class="text-xs font-bold text-slate-600">Δ effectifs (−0,5 à +2,0)</label>
                    <input id="delta_effectifs" name="delta_effectifs" type="number" step="0.01" min="-0.5" max="2" value="{{ request('delta_effectifs', '0') }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label for="delta_salles" class="text-xs font-bold text-slate-600">Δ salles (−20 à +20)</label>
                    <input id="delta_salles" name="delta_salles" type="number" step="1" min="-20" max="20" value="{{ request('delta_salles', '0') }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label for="delta_charges" class="text-xs font-bold text-slate-600">Δ charges (−0,5 à +1,0)</label>
                    <input id="delta_charges" name="delta_charges" type="number" step="0.01" min="-0.5" max="1" value="{{ request('delta_charges', '0') }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                </div>
            </div>
            <div class="mt-4 flex items-center gap-3">
                <x-btn type="submit">Calculer le scénario</x-btn>
                <span class="text-xs text-slate-500">Résultat indicatif — jamais une décision institutionnelle officielle.</span>
            </div>
        </form>

        @if($resultat)
            @php($axes = $resultat['axes'])
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-stat-card libelle="Effectifs (base)" :valeur="number_format($axes['effectifs']['base'], 0, ',', ' ')" />
                <x-stat-card libelle="Effectifs (projetés)" :valeur="number_format($axes['effectifs']['projete'], 0, ',', ' ')" couleur="emerald" />
                <x-stat-card libelle="Capacité (places)" :valeur="number_format($axes['capacite']['base'], 0, ',', ' ')" />
                <x-stat-card libelle="Capacité (projetée)" :valeur="number_format($axes['capacite']['projete'], 0, ',', ' ')" couleur="emerald" />
                <x-stat-card libelle="Saturation" :valeur="$axes['saturation']['valeur'] !== null ? $axes['saturation']['valeur'] : 'N/A'" couleur="amber" />
                <x-stat-card libelle="Charges (heures)" :valeur="number_format($axes['charges']['base'], 1, ',', ' ')" />
                <x-stat-card libelle="Charges (projetées)" :valeur="number_format($axes['charges']['projete'], 1, ',', ' ')" couleur="amber" />
                <x-stat-card libelle="Programmes recensés" :valeur="number_format($axes['programmes']['recense'], 0, ',', ' ')" />
            </div>

            <div class="mt-4 ds-card p-5 text-sm">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Lecture des axes</h2>
                <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="font-bold text-slate-700">Effectifs — base</dt>
                        <dd class="text-slate-600">{{ $axes['effectifs']['base_definie_comme'] }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate-700">Hors chaîne programme : {{ $axes['effectifs']['hors_chaine_programme'] }}</dt>
                        <dd class="text-slate-600">{{ $axes['effectifs']['hors_chaine_programme_definie_comme'] }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate-700">Saturation</dt>
                        <dd class="text-slate-600">{{ $axes['saturation']['raison'] ?? 'Calculée sur les effectifs et la capacité projetés.' }}</dd>
                    </div>
                    <div>
                        <dt class="font-bold text-slate-700">Charges</dt>
                        <dd class="text-slate-600">{{ $axes['charges']['regroupement'] }}</dd>
                    </div>
                </dl>

                @if(collect($axes)->contains(fn ($axe) => ($axe['plafond_atteint'] ?? false) === true))
                    <div class="mt-3"><x-alert type="warning">Un ou plusieurs axes dépassent {{ number_format($axes['effectifs']['plafond_lignes'] ?? 5000, 0, ',', ' ') }} lignes : les agrégats restent exacts, mais la volumétrie doit être revue avant exploitation.</x-alert></div>
                @endif

                <details class="mt-4">
                    <summary class="cursor-pointer text-xs font-bold text-slate-500">Détail technique (JSON)</summary>
                    <pre class="mt-2 overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-slate-100">{{ json_encode($resultat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            </div>
        @endif
    </div>
@endsection
