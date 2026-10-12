@extends('layouts.app')

@section('title', 'Simulation d’affectation')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Simulation → ' . $groupe->code" :description="'Année ' . $annee->libelle . ' — rien n’est écrit.'"
            :fil="['Masse' => route('admin.affectations.masse'), 'Simulation' => '#']" />

        <div class="mt-4 grid grid-cols-3 gap-3">
            <x-stat-card libelle="Proposées" :valeur="count($simulation['proposees'])" couleur="emerald" />
            <x-stat-card libelle="Bloquées" :valeur="count($simulation['bloquees'])" couleur="rose" />
            <x-stat-card libelle="Warnings" :valeur="count($simulation['warnings'])" couleur="amber" />
        </div>

        @if($simulation['bloquees'] !== [])
            @php
                $countSansInscription = 0;
                $countNiveauIncompatible = 0;
                $countProgrammeIncompatible = 0;
                foreach ($simulation['bloquees'] as $motifsList) {
                    $motifsStr = implode(' ', $motifsList);
                    if (str_contains($motifsStr, 'aucune inscription')) $countSansInscription++;
                    if (str_contains($motifsStr, 'Niveau incompatible')) $countNiveauIncompatible++;
                    if (str_contains($motifsStr, 'Programme incompatible')) $countProgrammeIncompatible++;
                }
            @endphp

            <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50/70 p-4 sm:p-5">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-black uppercase tracking-wider text-rose-800">Diagnostic des blocages académiques</h2>
                        <p class="mt-0.5 text-xs text-rose-700">Le groupe <strong>{{ $groupe->code }}</strong> ({{ $groupe->niveau ?? 'Niveau' }}) applique strictement les règles d'intégrité de l'année <strong>{{ $annee->libelle }}</strong>.</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <a href="{{ route('admin.affectations.masse') }}" class="inline-flex items-center rounded-lg border border-rose-300 bg-white px-3 py-1.5 font-bold text-rose-700 hover:bg-rose-50">
                            ← Réajuster la sélection
                        </a>
                        <a href="{{ route('admin.etudiants.index', array_filter(['niveau' => $groupe->niveau, 'programme_id' => $groupe->programme_id, 'sans_groupe' => 1])) }}" class="inline-flex items-center rounded-lg bg-rose-700 px-3 py-1.5 font-bold text-white hover:bg-rose-800">
                            Filtrer le registre ({{ $groupe->niveau ?? 'L2' }})
                        </a>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    @if($countSansInscription > 0)
                        <span class="inline-flex items-center gap-1 rounded-lg bg-white/90 border border-rose-200 px-3 py-1 font-semibold text-rose-900 shadow-sm">
                            <span class="inline-block w-2 h-2 rounded-full bg-rose-500"></span>
                            <strong>{{ $countSansInscription }}</strong> sans inscription {{ $annee->libelle }}
                        </span>
                    @endif
                    @if($countNiveauIncompatible > 0)
                        <span class="inline-flex items-center gap-1 rounded-lg bg-white/90 border border-amber-200 px-3 py-1 font-semibold text-amber-900 shadow-sm">
                            <span class="inline-block w-2 h-2 rounded-full bg-amber-500"></span>
                            <strong>{{ $countNiveauIncompatible }}</strong> niveau différent de {{ $groupe->niveau ?? 'L2' }}
                        </span>
                    @endif
                    @if($countProgrammeIncompatible > 0)
                        <span class="inline-flex items-center gap-1 rounded-lg bg-white/90 border border-slate-200 px-3 py-1 font-semibold text-slate-800 shadow-sm">
                            <span class="inline-block w-2 h-2 rounded-full bg-slate-500"></span>
                            <strong>{{ $countProgrammeIncompatible }}</strong> filière/programme incompatible
                        </span>
                    @endif
                </div>
            </div>

            <section class="mt-4 ds-card p-5" x-data="{ limite: 50, total: {{ count($simulation['bloquees']) }}, filtre: '' }">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-100 pb-4">
                    <div>
                        <h2 class="text-sm font-black uppercase tracking-wider text-rose-700">Détail des étudiants bloqués ({{ count($simulation['bloquees']) }})</h2>
                        <p class="text-xs text-slate-500">Motifs détaillés par étudiant et pistes de résolution.</p>
                    </div>
                    <div class="w-full sm:w-64">
                        <input type="text" x-model="filtre" placeholder="Rechercher (nom, id)..." class="w-full rounded-xl border border-slate-200 px-3 py-1.5 text-xs">
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    @php $compteur = 0; @endphp
                    @foreach($simulation['bloquees'] as $etudiantId => $motifs)
                        @php
                            $compteur++;
                            $etudiant = $etudiants[$etudiantId] ?? $etudiants->get($etudiantId);
                            $nomComplet = $etudiant?->nom_complet ?? '#'.$etudiantId;
                            $pisteCourante = ($pistes ?? [])[$etudiantId] ?? null;
                        @endphp
                        <div x-show="filtre === '' ? ({{ $compteur }} <= limite) : ('{{ strtolower(addslashes($nomComplet . ' ' . $etudiantId)) }}'.includes(filtre.toLowerCase()))"
                             class="rounded-xl border border-slate-200/90 bg-white p-4 shadow-sm hover:border-slate-300 transition-colors">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2">
                                <div>
                                    <strong class="text-sm font-bold text-slate-900">{{ $nomComplet }}</strong>
                                    <span class="ml-2 font-mono text-xs text-slate-400">#{{ $etudiantId }} @if($etudiant?->num_inscription) · {{ $etudiant->num_inscription }} @endif</span>
                                </div>
                                @if($etudiant)
                                    <div class="flex items-center gap-1.5">
                                        <span class="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">
                                            {{ $etudiant->niveau ?? 'Sans niveau' }}
                                        </span>
                                        @if($etudiant->programme)
                                            <span class="rounded-md bg-blue-50 text-blue-800 px-2 py-0.5 text-[11px] font-semibold">
                                                {{ $etudiant->programme->code }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <div class="mt-2.5 space-y-1.5">
                                <ul class="space-y-1 text-xs text-rose-700">
                                    @foreach($motifs as $motif)
                                        <li class="flex items-start gap-1.5">
                                            <span class="text-rose-500 font-bold">•</span>
                                            <span>{{ $motif }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @include('admin.affectations._piste', ['piste' => $pisteCourante, 'etudiantId' => $etudiantId])
                            </div>
                        </div>
                    @endforeach
                </div>

                @if(count($simulation['bloquees']) > 50)
                    <div x-show="filtre === '' && limite < total" class="mt-5 text-center">
                        <button type="button" @click="limite += 50" class="rounded-xl border border-slate-300 bg-white px-5 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50">
                            Afficher 50 étudiants de plus (actuellement <span x-text="Math.min(limite, total)"></span> sur {{ count($simulation['bloquees']) }})
                        </button>
                    </div>
                @endif
            </section>
        @endif

        @if($simulation['warnings'] !== [])
            <section class="mt-4 ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-amber-700">Avertissements ({{ count($simulation['warnings']) }})</h2>
                <ul class="mt-2 space-y-2 text-sm">
                    @foreach($simulation['warnings'] as $etudiantId => $motifs)
                        <li class="rounded-lg border border-amber-200 bg-amber-50/50 p-3 text-xs text-amber-900">
                            <strong>{{ ($etudiants[$etudiantId] ?? $etudiants->get($etudiantId))?->nom_complet ?? '#'.$etudiantId }}</strong> : {{ implode(' ', $motifs) }}
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if($simulation['proposees'] !== [])
            <form method="post" action="{{ route('admin.affectations.appliquer') }}" class="mt-4 ds-card space-y-3 p-5">
                @csrf
                <input type="hidden" name="groupe_id" value="{{ $groupe->id }}" />
                <input type="hidden" name="annee_universitaire_id" value="{{ $annee->id }}" />
                @foreach($simulation['proposees'] as $id)
                    <input type="hidden" name="etudiant_ids[]" value="{{ $id }}" />
                @endforeach
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="motif">Motif (obligatoire, audité)</label>
                    <input id="motif" name="motif" maxlength="255" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <label class="inline-flex items-center gap-2 text-sm">
                    <input type="checkbox" name="confirmer" value="1" required />
                    Je confirme l’application transactionnelle à {{ count($simulation['proposees']) }} étudiant(s).
                </label>
                <div><x-btn type="submit">Appliquer la proposition</x-btn></div>
            </form>
        @endif
    </div>
@endsection
