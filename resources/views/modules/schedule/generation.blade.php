@extends('layouts.app')

@section('title', 'Génération automatique — ' . $planning->nom)

@php($joursFr = \App\Modules\Schedule\Models\ScheduleAvailability::JOURS_FR)
@php($statutsFr = ['succes' => 'Succès', 'partiel' => 'Partielle', 'echec' => 'Échec', 'en_cours' => 'En cours', 'annule' => 'Annulée'])

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header titre="Génération automatique"
            description="Le moteur place les séances dans le brouillon en respectant les contraintes dures (conflits, indisponibilités) et en optimisant les pondérations déclarées. Aucune publication automatique."
            :fil="['Emplois du temps' => route('admin.emplois-du-temps.index'), $planning->nom => route('admin.emplois-du-temps.show', $planning), 'Génération' => '#']">
            <x-slot:actions>
                <x-btn href="{{ route('admin.emplois-du-temps.show', $planning) }}" variant="secondary">Retour au planning</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-900" role="status">✓ {{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-xs text-rose-800" role="alert">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section aria-label="État du planning" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-black text-slate-900">État actuel — statut : {{ $planning->statut ?? 'brouillon' }}</h2>
            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 text-xs">
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="font-bold uppercase tracking-wider text-slate-400 text-[10px]">Créneaux</dt>
                    <dd class="mt-1 text-lg font-black text-slate-900 tabular-nums">{{ $controle['creneaux'] }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="font-bold uppercase tracking-wider text-slate-400 text-[10px]">Conflits</dt>
                    <dd class="mt-1 text-lg font-black tabular-nums {{ count($controle['conflits']) > 0 ? 'text-rose-700' : 'text-emerald-700' }}">{{ count($controle['conflits']) }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="font-bold uppercase tracking-wider text-slate-400 text-[10px]">Sans enseignant</dt>
                    <dd class="mt-1 text-lg font-black text-slate-900 tabular-nums">{{ $controle['sans_enseignant'] }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-3">
                    <dt class="font-bold uppercase tracking-wider text-slate-400 text-[10px]">Score qualité</dt>
                    <dd class="mt-1 text-lg font-black text-slate-900 tabular-nums">{{ $controle['score'] }}/100</dd>
                </div>
            </dl>

            @if(! $generationPossible)
                <p class="mt-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-xs font-bold text-amber-900" role="status">
                    Planning en statut « {{ $planning->statut }} » : la génération est bloquée (lecture seule). Dupliquez le planning pour repartir d'un brouillon — la simulation reste disponible.
                </p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                @can('schedules.manage')
                    <form method="POST" action="{{ route('admin.emplois-du-temps.generation.generer', $planning) }}" class="flex flex-wrap gap-2">
                        @csrf
                        <x-btn type="submit" variant="secondary"
                            formaction="{{ route('admin.emplois-du-temps.generation.simuler', $planning) }}">Simuler sans écrire</x-btn>
                        <x-btn type="submit" :disabled="! $generationPossible">Générer le brouillon</x-btn>
                    </form>
                @endcan
            </div>
        </section>

        @can('schedules.manage')
            <section aria-label="Options de génération" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-black text-slate-900">Pondérations appliquées et options</h2>
                <p class="mt-1 text-xs text-slate-500">
                    Ces pondérations viennent des paramètres « Emplois du temps &amp; Génération » (bornées) et du registre de contraintes de la faculté.
                </p>
                <dl class="mt-3 flex flex-wrap gap-2 text-[11px] font-bold text-slate-600">
                    <span class="rounded-full bg-slate-100 px-3 py-1 ring-1 ring-inset ring-slate-200">Trous : {{ $ponderations['poids_trous'] }}/10</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 ring-1 ring-inset ring-slate-200">Équilibre des jours : {{ $ponderations['poids_equilibre_jours'] }}/10</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 ring-1 ring-inset ring-slate-200">Continuité : {{ $ponderations['poids_continuite'] }}/10</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 ring-1 ring-inset ring-slate-200">Séquence max : {{ $ponderations['duree_max_sequence_minutes'] }} min</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 ring-1 ring-inset ring-slate-200">Itérations max : {{ number_format($ponderations['iteration_max'], 0, ',', ' ') }}</span>
                </dl>

                <form method="POST" action="{{ route('admin.emplois-du-temps.generation.generer', $planning) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                    @csrf
                    <fieldset class="sm:col-span-2">
                        <legend class="text-xs font-bold uppercase tracking-wider text-slate-400">Jours autorisés</legend>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach($jours as $jour)
                                <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="jours_autorises[]" value="{{ $jour }}" checked
                                        class="h-4 w-4 rounded border-slate-300 text-primary-800 focus:ring-primary-700">
                                    {{ $joursFr[$jour] }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <label class="block text-sm">
                        <span class="font-bold text-slate-700">Itérations maximales (garde-fou anti-boucle)</span>
                        <input type="number" name="iteration_max" min="100" max="{{ $ponderations['iteration_max'] }}"
                            value="{{ $ponderations['iteration_max'] }}"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                    </label>

                    <label class="block text-sm">
                        <span class="font-bold text-slate-700">Budget temps (secondes, 1–60)</span>
                        <input type="number" name="timeout_secondes" min="1" max="60" value="15"
                            class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                    </label>

                    <label class="sm:col-span-2 inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="ecraser_brouillon" value="1"
                            class="h-4 w-4 rounded border-slate-300 text-primary-800 focus:ring-primary-700">
                        Écraser les créneaux existants (sinon génération incrémentale)
                    </label>

                    <div class="sm:col-span-2 flex flex-wrap gap-2">
                        <x-btn type="submit" :disabled="! $generationPossible">Générer le brouillon</x-btn>
                    </div>
                </form>
            </section>
        @endcan

        <section aria-label="Historique des générations" class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <h2 class="px-5 pt-5 text-base font-black text-slate-900">Historique des exécutions</h2>
            @if($historique->isNotEmpty())
                <table class="mt-3 w-full min-w-[860px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-5 py-3 font-bold">Date</th>
                            <th scope="col" class="px-5 py-3 font-bold">Mode</th>
                            <th scope="col" class="px-5 py-3 font-bold">Résultat</th>
                            <th scope="col" class="px-5 py-3 font-bold">Placées</th>
                            <th scope="col" class="px-5 py-3 font-bold">Score</th>
                            <th scope="col" class="px-5 py-3 font-bold">Itérations</th>
                            <th scope="col" class="px-5 py-3 font-bold">Durée</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($historique as $execution)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-5 py-3 text-slate-600 tabular-nums">{{ $execution->created_at?->format('d/m/Y H:i') }}</td>
                                <td class="px-5 py-3">
                                    @if($execution->parametres['dry_run'] ?? false)
                                        <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-1 text-[11px] font-bold text-sky-700 ring-1 ring-inset ring-sky-200">Simulation</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-bold text-emerald-700 ring-1 ring-inset ring-emerald-200">Brouillon</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $statutsFr[$execution->statut] ?? $execution->statut }}</td>
                                <td class="px-5 py-3 text-slate-700 tabular-nums">{{ $execution->nombre_seances_placees }}/{{ $execution->nombre_seances_a_placer }}</td>
                                <td class="px-5 py-3 text-slate-700 tabular-nums">{{ $execution->score_qualite ?? '—' }}</td>
                                <td class="px-5 py-3 text-slate-600 tabular-nums">{{ number_format((int) $execution->nombre_iterations, 0, ',', ' ') }}</td>
                                <td class="px-5 py-3 text-slate-600 tabular-nums">{{ $execution->duree_ms }} ms</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-5">
                    <x-empty-state message="Aucune génération n'a encore été exécutée pour ce planning." />
                </div>
            @endif
        </section>

        <p class="mt-4 text-xs text-slate-500">
            Rappel : la génération n'écrit jamais le statut du planning. La publication reste une action humaine explicite (contrôle pré-publication obligatoire).
        </p>
    </div>
@endsection
