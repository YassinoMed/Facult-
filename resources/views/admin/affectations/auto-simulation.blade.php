@extends('layouts.app')

@section('title', 'Simulation automatique')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header titre="Proposition équilibrée (simulation)" description="Aucune écriture : vérifiez puis confirmez explicitement."
            :fil="['Auto' => route('admin.affectations.auto'), 'Simulation' => '#']" />

        @isset($trancheMessage)
            <x-alert type="info" class="mt-4">{{ $trancheMessage }}</x-alert>
        @endisset

        @isset($precheck)
            <div class="mt-4 ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider">Pré-check</h2>
                <dl class="mt-2 grid sm:grid-cols-3 gap-1 text-sm">
                    <div>Étudiants sélectionnés : <strong>{{ $precheck['selectionnes'] }}</strong></div>
                    <div>Inscrits {{ $annee->libelle }} : <strong>{{ $precheck['inscrits'] }}</strong></div>
                    <div>Sans inscription : <strong>{{ $precheck['sans_inscription'] }}</strong></div>
                    <div>Formation compatible : <strong>{{ $precheck['prog_compatibles'] }}</strong></div>
                    <div>Niveau compatible : <strong>{{ $precheck['niveau_compatibles'] }}</strong></div>
                    <div>Déjà affectés : <strong>{{ $precheck['deja_affectes'] }}</strong></div>
                    <div>Verrouillés : <strong>{{ $precheck['verrouilles'] }}</strong></div>
                    <div>Suspendus : <strong>{{ $precheck['suspendus'] }}</strong></div>
                    <div>Éligibles à l'affectation : <strong>{{ $precheck['eligibles'] }}</strong></div>
                    <div>Groupes candidats : <strong>{{ $precheck['groupes_compatibles'] }}</strong></div>
                    <div>Capacité totale : <strong>{{ $precheck['capacite_totale'] }}</strong></div>
                    <div>Capacité disponible : <strong>{{ $precheck['capacite_disponible'] }}</strong></div>
                    <div>Besoin : <strong>{{ $precheck['besoin'] }}</strong> · Manque : <strong>{{ $precheck['manque'] }}</strong></div>
                    @if($precheck['groupes_supplementaires'] > 0)
                        <div class="sm:col-span-3 font-bold text-amber-700">→ {{ $precheck['groupes_supplementaires'] }} groupe(s) supplémentaire(s) recommandé(s) (capacité {{ $precheck['capacite_recommandee'] }}@if($precheck['code_groupe_suggere']), ex. {{ $precheck['code_groupe_suggere'] }}@endif)</div>
                    @endif
                </dl>
            </div>
        @endisset

        @foreach($groupes as $groupe)
            @php $ids = $proposition['repartition'][$groupe->id] ?? []; @endphp
            <section class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
                <h2 class="font-mono font-black">{{ $groupe->code }} — {{ count($ids) }} proposé(s)</h2>
                <p class="text-xs text-slate-500">Effectif : {{ $proposition['capacites'][$groupe->id]['effectif'] ?? '?' }}/{{ $groupe->capacite ?? '∞' }}</p>
                @if($ids !== [])
                    <ul class="mt-2 grid gap-1 sm:grid-cols-2 text-sm">
                        @foreach($ids as $id)
                            <li class="rounded-xl border border-slate-100 px-3 py-1.5">
                                <span class="font-bold">{{ $etudiants[$id]?->nom_complet ?? "#{$id}" }}</span>
                                <span class="block font-mono text-[11px] text-slate-500">{{ $etudiants[$id]?->num_inscription }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-2 text-xs text-slate-400">Aucun étudiant proposé pour ce groupe.</p>
                @endif
            </section>
        @endforeach

        @if($proposition['non_affectes'] !== [])
            <x-alert type="warning" titre="{{ count($proposition['non_affectes']) }} non affecté(s)" class="mt-4">
                <ul class="list-disc list-inside text-xs">
                    @foreach($proposition['non_affectes'] as $id => $motifs)
                        <li><strong>{{ $etudiants[$id]?->nom_complet ?? "#{$id}" }}</strong>
                            <span class="font-mono text-[11px] text-slate-500">{{ $etudiants[$id]?->num_inscription }} · {{ $etudiants[$id]?->niveau }}</span> : {{ implode(' ', $motifs) }}
                            @php $pisteCourante = isset($pistes[$id]) ? $pistes[$id] : null; @endphp
                            @include('admin.affectations._piste', ['piste' => $pisteCourante, 'etudiantId' => $id])</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        <form method="post" action="{{ route('admin.affectations.auto.appliquer') }}" class="mt-4 ds-card p-5">
            @csrf
            <input type="hidden" name="annee_universitaire_id" value="{{ $annee->id }}">
            {{-- UN SEUL champ JSON (pas N champs cachés) : au-delà de
                max_input_vars (1000 par défaut), PHP tronque la requête
                en silence et l'application devient partielle. --}}
            <input type="hidden" name="repartition_json" value="{{ json_encode($proposition['repartition']) }}">
            <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="motif">Motif</label>
            <input id="motif" type="text" name="motif" required maxlength="255" placeholder="Équilibrage rentrée" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <label class="mt-2 inline-flex items-center gap-1.5 text-xs"><input type="checkbox" name="confirmer" value="1" required /> Je confirme l'application (transaction + audit + notification).</label>
            <div class="mt-2 flex gap-2">
                <x-btn variant="secondary" href="{{ route('admin.affectations.auto') }}">Annuler</x-btn>
                <x-btn type="submit">Appliquer la répartition</x-btn>
            </div>
            @php
                $totalRepartition = (int) array_sum(array_map('count', $proposition['repartition']));
                $seuilSync = max(1, (int) config('affectations.lots.seuil_sync', 1000));
            @endphp
            @if($totalRepartition > $seuilSync)
                <p class="mt-2 text-xs text-slate-500">Volume important ({{ $totalRepartition }} affectation(s) &gt; {{ $seuilSync }}) : l'exécution passera en arrière-plan, avec progression et reprise sur la page de suivi du lot.</p>
            @endif
        </form>
    </div>
@endsection
