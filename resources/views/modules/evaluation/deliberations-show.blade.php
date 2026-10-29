@extends('layouts.app')

@section('title', 'Tableau de délibération')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Tableau de délibération" :description="'Statut : ' . $deliberation->statut"
            :fil="['Délibérations' => route('admin.evaluations.deliberations.index'), 'Tableau' => '#']">
            <x-slot:actions>
                <x-btn variant="ghost" href="{{ route('admin.listes.preview', array_filter(['groupe_id' => $deliberation->groupe_id, 'programme_id' => $deliberation->programme_id, 'annee_universitaire_id' => $deliberation->annee_universitaire_id])) }}">Population convoquée</x-btn>
                @can('deliberations.prepare')
                    <form method="post" action="{{ route('admin.evaluations.deliberations.preparer', $deliberation) }}" class="inline">
                        @csrf
                        <x-btn variant="secondary" type="submit">Préparer</x-btn>
                    </form>
                @endcan
                @can('deliberations.validate')
                    <form method="post" action="{{ route('admin.evaluations.deliberations.valider', $deliberation) }}" class="inline">
                        @csrf
                        <x-btn variant="secondary" type="submit">Valider</x-btn>
                    </form>
                @endcan
                @can('deliberations.publish')
                    <form method="post" action="{{ route('admin.evaluations.deliberations.publier', $deliberation) }}" class="inline"
                        onsubmit="return confirm('La publication rend les décisions visibles par les étudiants concernés. Confirmer ?')">
                        @csrf
                        <x-btn type="submit">Publier</x-btn>
                    </form>
                @endcan
                @can('deliberations.prepare')
                    <x-btn variant="ghost" href="{{ route('admin.evaluations.deliberations.simuler', $deliberation) }}">Simuler</x-btn>
                @endcan
                @if(in_array($deliberation->statut, ['validated', 'published', 'locked', 'archived'], true))
                    <x-btn variant="ghost" href="{{ route('admin.evaluations.deliberations.pv', $deliberation) }}">PV (PDF)</x-btn>
                @endif
                @if($deliberation->statut === 'published')
                    @can('deliberations.publish')
                        <form method="post" action="{{ route('admin.evaluations.deliberations.releves', $deliberation) }}" class="inline">
                            @csrf
                            <x-btn type="submit" variant="secondary">Générer les relevés</x-btn>
                        </form>
                    @endcan
                @endif
            </x-slot:actions>
        </x-page-header>

        <section class="mt-4 ds-card p-5">
            @php
                $presents = $deliberation->membres->where('present', true);
                $presidentPresent = $presents->contains(fn ($membre) => $membre->fonction === 'president');
                $quorumAtteint = $presidentPresent && $presents->count() >= 3;
            @endphp
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Jury (quorum : président + 2 présents)</h2>
            <p class="mt-1 text-sm">
                <span class="font-bold tabular-nums">{{ $presents->count() }}/{{ $deliberation->membres->count() }} membre(s) présent(s)</span>
                <span class="mx-1 text-slate-300" aria-hidden="true">·</span>
                <span class="font-semibold {{ $quorumAtteint ? 'text-emerald-700' : 'text-amber-700' }}">
                    Quorum {{ $quorumAtteint ? 'atteint' : 'non atteint' }}
                </span>
                @unless($quorumAtteint)
                    <span class="text-slate-400">— la validation exige un président présent et au moins 3 membres présents.</span>
                @endunless
            </p>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @foreach($deliberation->membres as $membre)
                    <li class="flex items-center justify-between py-1.5">
                        <span>{{ $membre->utilisateur?->name ?? $membre->enseignant?->nomComplet() ?? '—' }} · {{ $membre->fonction }} · {{ $membre->present ? 'présent' : 'absent' }}</span>
                        @can('deliberations.manage')
                            <form method="post" action="{{ route('admin.evaluations.membres.pointer', $membre) }}" class="inline">
                                @csrf
                                <x-btn size="sm" variant="secondary" type="submit">Pointer</x-btn>
                            </form>
                        @endcan
                    </li>
                @endforeach
            </ul>
        </section>

        @if($deliberation->resultats_invalides_le !== null)
            <x-alert type="error" class="mt-4">
                Résultats invalidés par une correction de note le {{ $deliberation->resultats_invalides_le->format('d/m/Y H:i') }} : relancez la préparation avant validation.
            </x-alert>
        @endif

        @if(!empty($deliberation->anomalies))
            <x-alert type="warning" class="mt-4">
                <ul class="list-disc pl-5">
                    @foreach($deliberation->anomalies as $anomalie)
                        <li>{{ $anomalie['message'] }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif

        @if($errors->any())
            <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
        @endif

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Étudiant</th><th class="px-4 py-2 text-right">Moy.</th><th class="px-4 py-2 text-right">Crédits</th><th class="px-4 py-2 text-right">UE</th><th class="px-4 py-2">Décision</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($deliberation->decisions as $decision)
                        <tr>
                            <td class="px-4 py-2 font-bold">{{ $decision->etudiant->nom_complet }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $decision->moyenne ?? '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $decision->credits_obtenus }}/{{ $decision->credits_total }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $decision->ues_validees }}/{{ $decision->ues_total }}</td>
                            <td class="px-4 py-2"><x-status-badge :statut="$decision->decision" /></td>
                            <td class="px-4 py-2 text-right">
                                @can('deliberations.manage')
                                    <form method="post" action="{{ route('admin.evaluations.decisions.decider', $decision) }}" class="inline-flex gap-1">
                                        @csrf
                                        <input type="hidden" name="updated_at" value="{{ $decision->updated_at->toJSON() }}" />
                                        <select name="decision" class="rounded-xl border border-slate-200 px-2 py-1 text-xs">
                                            @foreach(\App\Modules\Evaluation\Models\DecisionDeliberation::DECISIONS as $option)
                                                <option value="{{ $option }}" @selected($decision->decision === $option)>{{ $option }}</option>
                                            @endforeach
                                        </select>
                                        <x-btn size="sm" type="submit">OK</x-btn>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
