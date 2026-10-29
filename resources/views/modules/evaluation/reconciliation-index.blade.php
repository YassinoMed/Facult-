@extends('layouts.app')

@section('title', 'Examens — Réconciliation tri-sources')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6">
        <x-page-header
            titre="Réconciliation tri-sources"
            description="Chaque divergence papier / numérique devient un dossier persistant, instruit puis validé par deux personnes distinctes. Aucun écart n’est supprimé."
            :fil="[
                'Évaluation' => route('admin.evaluations.index'),
                'Examens' => route('admin.evaluation.examens.tableau-de-bord'),
                'Réconciliation' => '#',
            ]"
        />

        @if(session('succes'))
            <x-alert type="success">{{ session('succes') }}</x-alert>
        @endif

        @if($errors->any())
            <x-alert type="error">{{ $errors->first() }}</x-alert>
        @endif

        {{-- KPI du périmètre visible --}}
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach(\App\Modules\Evaluation\Models\EvaluationReconciliationEcart::LIBELLES_STATUTS as $code => $libelle)
                @php $total = (int) ($compteurs[$code] ?? 0); @endphp
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                    <p class="mt-2 text-3xl font-black tracking-tight text-slate-900">{{ $total }}</p>
                </div>
            @endforeach
        </div>

        {{-- Filtres --}}
        <form method="get" action="{{ route('admin.evaluations.reconciliation.index') }}"
              class="ds-card flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
            <label class="flex-1 text-xs font-bold text-slate-600">
                Épreuve
                <select name="epreuve_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Toutes les épreuves</option>
                    @foreach($epreuves as $option)
                        <option value="{{ $option->id }}" @selected((int) $filtres['epreuve_id'] === (int) $option->id)>
                            {{ $option->code }} — {{ $option->matiere?->nom ?? $option->matiere?->code ?? 'Épreuve' }} ({{ $option->date?->format('d/m/Y') }})
                        </option>
                    @endforeach
                </select>
            </label>

            <label class="flex-1 text-xs font-bold text-slate-600">
                Statut
                <select name="statut" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Tous les statuts</option>
                    @foreach($statuts as $code => $libelle)
                        <option value="{{ $code }}" @selected($filtres['statut'] === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </label>

            <div class="flex gap-2">
                <x-btn type="submit" variant="secondary" size="sm">Filtrer</x-btn>
                @if($filtres['epreuve_id'] !== null || $filtres['statut'] !== null)
                    <x-btn :href="route('admin.evaluations.reconciliation.index')" variant="ghost" size="sm">Réinitialiser</x-btn>
                @endif
            </div>
        </form>

        {{-- Analyse papier → serveur --}}
        @if($epreuve !== null)
            <div class="ds-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Analyse papier → serveur</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ $epreuve->matiere?->libelle_complet ?? $epreuve->matiere?->nom ?? $epreuve->code }} —
                            {{ $epreuve->date?->format('d/m/Y') }}. Renseignez les pointages papier puis lancez le rapprochement.
                        </p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-bold text-slate-600">
                        {{ $epreuve->candidats->count() }} candidat(s)
                    </span>
                </div>

                @can('evaluations.update')
                    @if($epreuve->candidats->isEmpty())
                        <div class="mt-4">
                            <x-empty-state message="Aucun candidat convoqué : le rapprochement ne peut pas être lancé." />
                        </div>
                    @else
                        <form method="post" action="{{ route('admin.evaluations.reconciliation.analyser') }}" class="mt-4 space-y-3">
                            @csrf
                            <input type="hidden" name="epreuve_id" value="{{ $epreuve->id }}">

                            <div class="overflow-x-auto rounded-xl border border-slate-200">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50 text-left text-[11px] font-bold uppercase tracking-wider text-slate-500">
                                        <tr>
                                            <th class="px-3 py-2">Candidat</th>
                                            <th class="px-3 py-2">Statut serveur</th>
                                            <th class="px-3 py-2">Statut papier</th>
                                            <th class="px-3 py-2">Signature</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach($epreuve->candidats as $candidat)
                                            <tr>
                                                <td class="px-3 py-2">
                                                    <p class="font-semibold text-slate-800">
                                                        {{ trim(($candidat->etudiant?->nom ?? '') . ' ' . ($candidat->etudiant?->prenom ?? '')) ?: 'Candidat #'.$candidat->id }}
                                                    </p>
                                                    <p class="text-[11px] text-slate-500">{{ $candidat->numero_anonyme }}</p>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <code class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-700">{{ $candidat->statut }}</code>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <select name="statut_papier[{{ $candidat->id }}]"
                                                            class="w-full min-w-[11rem] rounded-lg border border-slate-200 px-2 py-1.5 text-xs">
                                                        <option value="">Non consigné</option>
                                                        @foreach(\App\Modules\Evaluation\Models\Candidat::STATUTS as $statut)
                                                            <option value="{{ $statut }}" @selected(old('statut_papier.'.$candidat->id) === $statut)>{{ $statut }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <label class="inline-flex items-center gap-2 text-xs text-slate-600">
                                                        <input type="checkbox" name="signatures[{{ $candidat->id }}]" value="1"
                                                               @checked(old('signatures.'.$candidat->id))>
                                                        Présente
                                                    </label>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="text-[11px] text-slate-500">
                                    Une source papier muette reste une réserve, jamais une divergence inventée.
                                </p>
                                <x-btn type="submit" size="sm">Lancer l’analyse et persister les écarts</x-btn>
                            </div>
                        </form>
                    @endif
                @endcan
            </div>
        @endif

        {{-- Dossiers d'écart --}}
        <div class="ds-card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <h2 class="text-base font-bold text-slate-900">Dossiers d’écart</h2>
                <span class="text-[11px] font-semibold text-slate-500">{{ $ecarts->total() }} dossier(s)</span>
            </div>

            @if($ecarts->isEmpty())
                <div class="p-5">
                    <x-empty-state message="Aucun écart pour ces filtres. Lancez une analyse sur une épreuve pour en créer." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Candidat</th>
                                <th class="px-4 py-3">Épreuve</th>
                                <th class="px-4 py-3">Divergence</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Détecté le</th>
                                <th class="px-4 py-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($ecarts as $ecart)
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800">
                                            {{ trim(($ecart->candidat?->etudiant?->nom ?? '') . ' ' . ($ecart->candidat?->etudiant?->prenom ?? '')) ?: 'Candidat #'.$ecart->candidat_id }}
                                        </p>
                                        <p class="text-[11px] text-slate-500">{{ $ecart->candidat?->numero_anonyme }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-slate-700">{{ $ecart->epreuve?->code }}</p>
                                        <p class="text-[11px] text-slate-500">{{ $ecart->epreuve?->matiere?->nom }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-xs text-slate-600">
                                            <code class="rounded bg-slate-100 px-1.5 py-0.5">{{ $ecart->statut_papier }}</code>
                                            →
                                            <code class="rounded bg-slate-100 px-1.5 py-0.5">{{ $ecart->statut_numerique }}</code>
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold',
                                            'bg-amber-100 text-amber-900' => $ecart->estOuvert(),
                                            'bg-sky-100 text-sky-900' => $ecart->estInstruit(),
                                            'bg-emerald-100 text-emerald-900' => $ecart->estValide(),
                                        ])>{{ $ecart->libelleStatut() }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">
                                        {{ $ecart->detecte_at?->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <x-btn :href="route('admin.evaluations.reconciliation.show', $ecart)" variant="secondary" size="sm">
                                            Ouvrir
                                        </x-btn>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-200 px-4 py-3">
                    {{ $ecarts->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
