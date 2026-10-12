@extends('layouts.app')

@section('title', 'Préparer — ' . $session->nom)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Préparer — ' . $session->nom" :description="'Session ' . $session->type . ' · ' . $session->date_debut->format('d/m/Y') . ' → ' . $session->date_fin->format('d/m/Y')"
            :fil="['Sessions' => route('admin.evaluations.sessions.index'), 'Préparation' => '#']">
            <x-slot:actions>
                <x-status-badge :statut="$session->statut" />
            </x-slot:actions>
        </x-page-header>

        @if($errors->any())
            <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
        @endif

        <x-stepper :etapes="['Épreuves', 'Candidats', 'Salles', 'Surveillants', 'Contrôles', 'Publication']" :courant="1" class="mt-4" />

        <div class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">1 · Générer les épreuves (maquette active)</h2>
            <form method="post" action="{{ route('admin.evaluations.sessions.generer', $session) }}" class="mt-2 flex flex-wrap items-end gap-2">
                @csrf
                <div>
                    <label class="text-xs font-bold text-slate-600" for="prog">Programme</label>
                    <select id="prog" name="programme_id" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm" required>
                        @foreach($programmes as $programme)
                            <option value="{{ $programme->id }}">{{ $programme->code }} — {{ $programme->nom }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600" for="niv">Niveau</label>
                    <select id="niv" name="niveau" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        <option value="">Tous</option>
                        @foreach(['L1','L2','L3','M1','M2'] as $niveau)
                            <option value="{{ $niveau }}">{{ $niveau }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600" for="deb">Début</label>
                    <input id="deb" type="date" name="date_debut" required class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                </div>
                <x-btn size="sm" type="submit">Générer</x-btn>
            </form>
        </div>

        @if(session('simulation_equilibrage'))
            @php $sim = session('simulation_equilibrage'); @endphp
            <div class="mt-4 ds-card p-5 border-2 {{ $sim['statut'] === 'OPTIMALE' ? 'border-emerald-500 bg-emerald-50/30' : ($sim['statut'] === 'REALISABLE' ? 'border-indigo-500 bg-indigo-50/30' : 'border-rose-500 bg-rose-50/30') }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Résultat de la simulation</span>
                        <h3 class="text-base font-black text-slate-900 mt-0.5">
                            Statut : <span class="{{ $sim['statut'] === 'OPTIMALE' ? 'text-emerald-700' : ($sim['statut'] === 'REALISABLE' ? 'text-indigo-700' : 'text-rose-700') }}">{{ $sim['statut'] }}</span>
                        </h3>
                        <p class="text-xs text-slate-600 mt-1">{{ $sim['message_statut'] }}</p>
                    </div>
                    @if($sim['statut'] !== 'IMPOSSIBLE')
                        <form method="post" action="{{ route('admin.evaluations.sessions.equilibrage.appliquer', $session) }}">
                            @csrf
                            <x-btn type="submit" variant="primary">Confirmer et appliquer l'affectation</x-btn>
                        </form>
                    @endif
                </div>

                <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                    <div class="p-3 bg-white rounded-lg border border-slate-200">
                        <span class="text-slate-500 block">Surveillants actifs</span>
                        <span class="font-bold text-slate-900 text-sm">{{ $sim['total_surveillants_actifs'] }} ({{ $sim['epreuves_couvertes_pct'] }} %)</span>
                    </div>
                    <div class="p-3 bg-white rounded-lg border border-slate-200">
                        <span class="text-slate-500 block">Gardes de réserve</span>
                        <span class="font-bold text-slate-900 text-sm">{{ $sim['total_gardes_reserve'] }} ({{ $sim['gardes_couvertes_pct'] }} %)</span>
                    </div>
                    <div class="p-3 bg-white rounded-lg border border-slate-200">
                        <span class="text-slate-500 block">Charge moyenne · Écart</span>
                        <span class="font-bold text-slate-900 text-sm">{{ $sim['indicateurs_charge']['moyenne'] }} · Écart : {{ $sim['indicateurs_charge']['ecart'] }}</span>
                    </div>
                    <div class="p-3 bg-white rounded-lg border border-slate-200">
                        <span class="text-slate-500 block">Présence 3 jours</span>
                        <span class="font-bold text-slate-900 text-sm">{{ $sim['repartition_jours']['cible_3_jours'] }} enseignant(s)</span>
                    </div>
                </div>
            </div>
        @endif

        <div class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">5 · Contrôles ({{ $readiness['epreuves'] }} épreuve(s))</h2>
            @if($readiness['bloquants'] === [])
                <p class="mt-2 text-sm font-bold text-emerald-700">✓ Prête pour ouverture.</p>
            @else
                <ul class="mt-2 list-disc pl-5 text-sm text-rose-700">
                    @foreach($readiness['bloquants'] as $bloquant)
                        <li>{{ $bloquant }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                <form method="post" action="{{ route('admin.evaluations.sessions.surveillants-auto', $session) }}" class="inline-flex items-end gap-2">
                    @csrf
                    <input type="number" name="nombre" value="2" min="1" max="20" aria-label="Surveillants par épreuve" class="w-20 rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                    <x-btn size="sm" variant="secondary" type="submit">Affecter surveillants auto</x-btn>
                </form>
                <form method="post" action="{{ route('admin.evaluations.sessions.equilibrage.simuler', $session) }}" class="inline-flex items-center">
                    @csrf
                    <x-btn size="sm" variant="secondary" type="submit">🔍 Simuler équilibrage</x-btn>
                </form>
                <form method="post" action="{{ route('admin.evaluations.sessions.equilibrage.appliquer', $session) }}" class="inline-flex items-center" onsubmit="return confirm('Lancer l\'équilibrage intelligent (>= 2 surveillants, 1 garde de réserve, présence ciblée sur 3 jours) ?');">
                    @csrf
                    <x-btn size="sm" variant="primary" type="submit">⚖️ Équilibrage intelligent</x-btn>
                </form>
                @foreach(\App\Modules\Evaluation\Models\SessionExamen::TRANSITIONS[$session->statut] ?? [] as $cible)
                    @if($cible !== 'locked' && $cible !== 'archived')
                        <form method="post" action="{{ route('admin.evaluations.sessions.transiter', [$session, $cible]) }}">
                            @csrf
                            <x-btn size="sm" type="submit">→ {{ $cible }}</x-btn>
                        </form>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">ECUE</th><th class="px-4 py-2">Groupe</th><th class="px-4 py-2">Salle</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($session->epreuves as $epreuve)
                        <tr>
                            <td class="px-4 py-2 tabular-nums">{{ $epreuve->date->format('d/m/Y') }}</td>
                            <td class="px-4 py-2 font-bold">{{ $epreuve->matiere->code }}</td>
                            <td class="px-4 py-2">{{ $epreuve->groupe?->code ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $epreuve->salle?->code ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <a href="{{ route('admin.evaluations.epreuves.show', $epreuve) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Détail</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
