@extends('layouts.app')

@section('title', 'Comparer — Maquette v' . $maquette->version)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Comparer — Maquette v' . $maquette->version . ' (' . $maquette->programme->code . ')'" description="Comparaison en lecture seule entre deux versions du même programme : ajouts, retraits, modifications, écarts de totaux. Les versions ne sont jamais modifiées."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Maquettes' => route('admin.referentiel.maquettes.index'), 'v' . $maquette->version => route('admin.referentiel.maquettes.show', $maquette), 'Comparer' => '#']">
        </x-page-header>

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Comparaison impossible.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <form method="GET" action="{{ route('admin.referentiel.maquettes.comparer', $maquette) }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <select name="autre" required aria-label="Version à comparer" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px] flex-1">
                <option value="">Choisir une autre version du même programme…</option>
                @foreach($candidats as $candidat)
                    <option value="{{ $candidat->id }}" @selected((string) $autreId === (string) $candidat->id)>v{{ $candidat->version }} · {{ $candidat->statut }} · {{ $candidat->anneeUniversitaire?->libelle ?? '—' }}</option>
                @endforeach
            </select>
            <select name="filtre" aria-label="Filtrer par type de changement" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                @foreach(['tous' => 'Tous changements', 'ajouts' => 'Ajouts', 'retraits' => 'Retraits', 'modifications' => 'Modifications'] as $valeur => $libelle)
                    <option value="{{ $valeur }}" @selected($filtre === $valeur)>{{ $libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Comparer</x-btn>
        </form>

        @if($comparaison !== null)
            <section aria-label="Synthèse" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Synthèse : v{{ $comparaison['reference']['version'] }} ({{ $comparaison['reference']['statut'] }}) ↔ v{{ $comparaison['comparee']['version'] }} ({{ $comparaison['comparee']['statut'] }})</h2>
                @if($comparaison['identiques'])
                    <p class="mt-2 text-sm font-bold text-emerald-700">Versions identiques sur le contenu comparé.</p>
                @endif
                <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    @foreach([
                        ['UE ajoutées', count($comparaison['ue_ajoutees'])],
                        ['UE retirées', count($comparaison['ue_retirees'])],
                        ['ECUE ajoutées', count($comparaison['ecue_ajoutees'])],
                        ['ECUE retirées', count($comparaison['ecue_retirees'])],
                        ['ECUE modifiées', count($comparaison['ecue_modifiees'])],
                        ['Écart crédits', ($comparaison['totaux']['ecarts']['credits'] >= 0 ? '+' : '').$comparaison['totaux']['ecarts']['credits']],
                    ] as [$libelle, $valeur])
                        <div class="rounded-xl border border-slate-100 px-3 py-2.5">
                            <p class="text-xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs tabular-nums text-slate-600">Totaux : {{ $comparaison['totaux']['avant']['credits'] }} → {{ $comparaison['totaux']['apres']['credits'] }} crédits ·
                    CM {{ $comparaison['totaux']['avant']['cm'] }} → {{ $comparaison['totaux']['apres']['cm'] }} ·
                    TD {{ $comparaison['totaux']['avant']['td'] }} → {{ $comparaison['totaux']['apres']['td'] }} ·
                    TP {{ $comparaison['totaux']['avant']['tp'] }} → {{ $comparaison['totaux']['apres']['tp'] }}.</p>
            </section>

            @if(in_array($filtre, ['tous', 'ajouts'], true) && ($comparaison['ue_ajoutees'] !== [] || $comparaison['ecue_ajoutees'] !== []))
                <section aria-label="Ajouts" class="mt-4 ds-card p-5">
                    <h2 class="text-base font-black text-emerald-700">Ajouts</h2>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach($comparaison['ue_ajoutees'] as $ue)
                            <li><span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-black text-emerald-800">UE</span> <strong class="font-mono">{{ $ue['code'] }}</strong> — {{ $ue['nom'] }} ({{ $ue['semestre'] ?? '?' }})</li>
                        @endforeach
                        @foreach($comparaison['ecue_ajoutees'] as $ecue)
                            <li><span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-black text-emerald-800">ECUE</span> <strong class="font-mono">{{ $ecue['code'] }}</strong> — {{ $ecue['nom'] }} ({{ $ecue['ue'] ?? '?' }}, {{ $ecue['credits'] }} crédits)</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if(in_array($filtre, ['tous', 'retraits'], true) && ($comparaison['ue_retirees'] !== [] || $comparaison['ecue_retirees'] !== []))
                <section aria-label="Retraits" class="mt-4 ds-card p-5">
                    <h2 class="text-base font-black text-red-700">Retraits</h2>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach($comparaison['ue_retirees'] as $ue)
                            <li><span class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-black text-red-800">UE</span> <strong class="font-mono">{{ $ue['code'] }}</strong> — {{ $ue['nom'] }} ({{ $ue['semestre'] ?? '?' }})</li>
                        @endforeach
                        @foreach($comparaison['ecue_retirees'] as $ecue)
                            <li><span class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-black text-red-800">ECUE</span> <strong class="font-mono">{{ $ecue['code'] }}</strong> — {{ $ecue['nom'] }} ({{ $ecue['ue'] ?? '?' }})</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if(in_array($filtre, ['tous', 'modifications'], true) && ($comparaison['ue_modifiees'] !== [] || $comparaison['ecue_modifiees'] !== [] || $comparaison['prerequis'] !== []))
                <section aria-label="Modifications" class="mt-4 ds-card p-5">
                    <h2 class="text-base font-black text-amber-700">Modifications</h2>
                    <ul class="mt-2 space-y-2 text-sm">
                        @foreach($comparaison['ue_modifiees'] as $ue)
                            <li><strong class="font-mono">{{ $ue['ue'] }}</strong>
                                <ul class="ml-4 list-disc">
                                    @foreach($ue['changements'] as $changement)
                                        <li><span class="font-mono text-[12px]">{{ $changement['champ'] }}</span> : {{ $changement['avant'] ?? '—' }} → {{ $changement['apres'] ?? '—' }}</li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                        @foreach($comparaison['ecue_modifiees'] as $ecue)
                            <li><strong class="font-mono">{{ $ecue['matiere'] }}</strong> <span class="text-slate-500">({{ $ecue['ue'] ?? '?' }})</span>
                                <ul class="ml-4 list-disc">
                                    @foreach($ecue['changements'] as $changement)
                                        <li><span class="font-mono text-[12px]">{{ $changement['champ'] }}</span> : {{ is_array($changement['avant']) ? json_encode($changement['avant']) : ($changement['avant'] ?? '—') }} → {{ is_array($changement['apres']) ? json_encode($changement['apres']) : ($changement['apres'] ?? '—') }}</li>
                                    @endforeach
                                </ul>
                            </li>
                        @endforeach
                        @foreach($comparaison['prerequis'] as $prerequis)
                            <li><strong class="font-mono">{{ $prerequis['matiere'] }}</strong> <span class="text-slate-500">(prérequis canoniques, état actuel)</span> :
                                @if($prerequis['ajoutes'] !== []) +{{ implode(', ', $prerequis['ajoutes']) }} @endif
                                @if($prerequis['retires'] !== []) −{{ implode(', ', $prerequis['retires']) }} @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif
    </div>
@endsection
