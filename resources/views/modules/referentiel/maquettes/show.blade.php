@extends('layouts.app')

@section('title', 'Maquette v' . $maquette->version)

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Maquette v' . $maquette->version . ' — ' . $maquette->programme->code" :description="$maquette->anneeUniversitaire->libelle . ' · ' . $maquette->statut"
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'Maquettes' => route('admin.referentiel.maquettes.index'), 'v' . $maquette->version => '#']">
            <x-slot:actions>
                <x-btn variant="ghost" href="{{ route('admin.referentiel.maquettes.comparer', $maquette) }}">Comparer</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Opération refusée.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        {{-- Transitions de statut --}}
        @can('referentiel.manage')
            <div class="mt-4 ds-card p-4 flex flex-wrap items-center gap-2">
                <span class="text-[11px] font-black uppercase tracking-wider text-slate-500">Statut : {{ $maquette->statut }}</span>
                @foreach(\App\Modules\Referentiel\Models\Maquette::TRANSITIONS[$maquette->statut] ?? [] as $cible)
                    <form method="POST" action="{{ route('admin.referentiel.maquettes.transition', $maquette) }}" class="inline">
                        @csrf @method('PATCH')
                        <input type="hidden" name="statut" value="{{ $cible }}">
                        <x-btn variant="secondary" type="submit">→ {{ $cible }}</x-btn>
                    </form>
                @endforeach
                @if(empty(\App\Modules\Referentiel\Models\Maquette::TRANSITIONS[$maquette->statut] ?? []))
                    <span class="text-xs text-slate-500">Statut final — créez une nouvelle version pour évoluer.</span>
                @endif
            </div>
        @endcan

        {{-- Résumé du plan (LOOP 03) : totaux serveur, jamais recalculés navigateur --}}
        <section aria-label="Résumé du plan" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Résumé du plan</h2>
            <div class="mt-3 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                @foreach([
                    ['UE', $totaux['global']['ues']],
                    ['ECUE (contextes)', $totaux['global']['ecues']],
                    ['Crédits', $totaux['global']['credits']],
                    ['Heures CM', $totaux['global']['cm']],
                    ['Heures TD', $totaux['global']['td']],
                    ['Heures TP', $totaux['global']['tp']],
                ] as [$libelle, $valeur])
                    <div class="rounded-xl border border-slate-100 px-3 py-2.5">
                        <p class="text-xl font-black tabular-nums text-slate-900">{{ $valeur }}</p>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">{{ $libelle }}</p>
                    </div>
                @endforeach
            </div>
            @if($totaux['semestres'] !== [])
                <ul class="mt-3 flex flex-wrap gap-2 text-xs text-slate-600">
                    @foreach($totaux['semestres'] as $resume)
                        <li class="rounded-full bg-slate-100 px-3 py-1.5 tabular-nums"><strong>{{ $resume['code'] }}</strong> : {{ $resume['ues'] }} UE · {{ $resume['ecues'] }} ECUE · {{ $resume['credits'] }} crédits</li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- Contenu : UE regroupées par semestre (LOOP 03) --}}
        <section aria-label="Contenu de la maquette" class="mt-4 ds-card p-5" x-data="{ rechercheUe: '' }">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-black text-slate-900">Contenu ({{ $maquette->unitesEnseignement->count() }} UE)</h2>
                <input type="search" x-model="rechercheUe" placeholder="Filtrer les UE…" aria-label="Filtrer les UE affichées"
                       class="rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[40px]">
            </div>
            @if($lignesUe->isNotEmpty())
                @foreach($lignesUe->groupBy(fn ($ligne) => $ligne->semestre?->code ?? $ligne->ue?->semestre?->code ?? '?') as $codeSemestre => $lignes)
                    <h3 class="mt-4 text-sm font-black uppercase tracking-wider text-slate-500">Semestre {{ $codeSemestre }} ({{ $lignes->count() }} UE)</h3>
                    <ul class="mt-2 space-y-1.5 text-sm">
                        @foreach($lignes as $ligne)
                            @php($ue = $ligne->ue)
                            @php($sommeEcue = (int) ($ue?->ecues->sum('credits') ?? 0))
                            @php($nbEcue = (int) ($ue?->ecues->count() ?? 0))
                            <li x-show="$el.dataset.recherche.includes(rechercheUe.toLowerCase())" data-recherche="{{ mb_strtolower(($ue?->code ?? '').' '.($ue?->nom ?? '')) }}"
                                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                                <span><strong class="font-mono">{{ $ue?->code ?? 'UE supprimée' }}</strong> — {{ $ue?->nom ?? '' }}
                                    <span class="text-slate-500 tabular-nums">· {{ $ue?->type ?? '—' }} · {{ $ue?->credits ?? 0 }} crédits · coef {{ $ue?->coefficient ?? '—' }}</span>
                                    <span class="text-slate-500 tabular-nums">· ECUE : {{ $nbEcue }} ({{ $sommeEcue }} crédits)</span>
                                    @if($ue === null)
                                        <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-black text-red-700">UE retirée du référentiel</span>
                                    @elseif($nbEcue === 0)
                                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-black text-amber-700">Sans ECUE</span>
                                    @elseif($sommeEcue !== (int) $ue->credits)
                                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-black text-amber-700">Écart crédits</span>
                                    @else
                                        <span class="ml-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-black text-emerald-700">Cohérente</span>
                                    @endif
                                </span>
                                @if($ue !== null)
                                    <a href="{{ route('admin.referentiel.entites.show', ['ues', $ue]) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Fiche UE</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endforeach
            @else
                <div class="mt-3"><x-empty-state message="Maquette vide : définissez son contenu." /></div>
            @endif

            @can('referentiel.manage')
                @if(!$maquette->estVerrouillee())
                    <form method="POST" action="{{ route('admin.referentiel.maquettes.contenu', $maquette) }}" class="mt-4 space-y-2">
                        @csrf @method('PUT')
                        <p class="text-[11px] font-black uppercase tracking-wider text-slate-500">Définir le contenu (UE du programme, semestre de rattachement)</p>
                        <div id="contenu-ues" class="space-y-2">
                            @foreach($maquette->programme->niveaux->flatMap->semestres->flatMap->unitesEnseignement->unique('id')->sortBy('code') as $ue)
                                @php($rattachee = $maquette->unitesEnseignement->firstWhere('id', $ue->id))
                                <label class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 px-3.5 py-2.5 text-xs cursor-pointer">
                                    <input type="checkbox" name="ues[{{ $ue->id }}][ue_id]" value="{{ $ue->id }}" @checked($rattachee !== null) class="h-5 w-5 rounded border-slate-300">
                                    <span class="font-mono font-bold">{{ $ue->code }}</span>
                                    <select name="ues[{{ $ue->id }}][semestre_id]" aria-label="Semestre de {{ $ue->code }}" class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs min-h-[36px]">
                                        @foreach($maquette->programme->niveaux->flatMap->semestres as $semestre)
                                            <option value="{{ $semestre->id }}" @selected(($rattachee?->pivot->semestre_id ?? $ue->semestre_id) == $semestre->id)>{{ $semestre->code }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                        </div>
                        <x-btn type="submit" variant="secondary">Enregistrer le contenu</x-btn>
                    </form>
                @else
                    <p class="mt-3 text-xs font-bold text-slate-500">🔒 Maquette verrouillée ({{ $maquette->statut }}) — créez une nouvelle version pour évoluer.</p>
                @endif
            @endcan
        </section>

        {{-- Vague 3 : contextes ECUE du plan (valeurs annuelles + Master) --}}
        <section aria-label="Contextes ECUE du plan" class="mt-4 ds-card p-5" x-data="{ rechercheEcue: '' }">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-black text-slate-900">ECUE du plan ({{ $lignesUe->sum(fn ($l) => $l->ecues->count()) }} contextes)</h2>
                    <p class="mt-1 text-xs text-slate-500">Valeurs propres à ce plan (ordre, coefficient, crédits, volumes) — la matière canonique n'est jamais modifiée. Règles Master : régime, groupe de choix (K parmi N). La recherche filtre l'affichage uniquement : les lignes masquées restent enregistrées.</p>
                </div>
                <input type="search" x-model="rechercheEcue" placeholder="Filtrer les ECUE…" aria-label="Filtrer les ECUE affichées"
                       class="rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[40px]">
            </div>

            @if($lignesUe->isNotEmpty())
                @php($editable = auth()->user()->can('referentiel.manage') && !$maquette->estVerrouillee())
                @if($editable)<form method="POST" action="{{ route('admin.referentiel.maquettes.ecues', $maquette) }}" data-surveiller x-data="{ modifie: false }" @change="modifie = true" @submit="modifie = false">@csrf @method('PUT')@endif
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[1020px] text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                                <th scope="col" class="px-2 py-3 font-bold">{{ $editable ? 'Incluse' : '' }}</th>
                                <th scope="col" class="px-2 py-3 font-bold">UE</th>
                                <th scope="col" class="px-2 py-3 font-bold">ECUE</th>
                                <th scope="col" class="px-2 py-3 font-bold">Ordre</th>
                                <th scope="col" class="px-2 py-3 font-bold">Coef.</th>
                                <th scope="col" class="px-2 py-3 font-bold">Crédits</th>
                                <th scope="col" class="px-2 py-3 font-bold">CM/TD/TP</th>
                                <th scope="col" class="px-2 py-3 font-bold">Prérequis</th>
                                <th scope="col" class="px-2 py-3 font-bold">Régime</th>
                                <th scope="col" class="px-2 py-3 font-bold">Groupe de choix</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($lignesUe as $ligne)
                                @php($contextes = $ligne->ecues->keyBy('matiere_id'))
                                @forelse($ligne->ue?->ecues->sortBy([['ordre', 'asc'], ['code', 'asc']]) ?? collect() as $ecue)
                                    @php($cle = $ligne->id.'-'.$ecue->id)
                                    @php($contexte = $contextes->get($ecue->id))
                                    <tr x-show="$el.dataset.recherche.includes(rechercheEcue.toLowerCase())" data-recherche="{{ mb_strtolower($ecue->code.' '.$ecue->nom) }}">
                                        @if($editable)
                                            <td class="px-2 py-2">
                                                <input type="hidden" name="ecues[{{ $cle }}][maquette_ue_id]" value="{{ $ligne->id }}">
                                                <input type="checkbox" name="ecues[{{ $cle }}][matiere_id]" value="{{ $ecue->id }}" @checked($contexte !== null) class="h-5 w-5 rounded border-slate-300" aria-label="Inclure {{ $ecue->code }}">
                                            </td>
                                        @endif
                                        <td class="px-2 py-2 font-mono text-slate-600">{{ $ligne->ue?->code }}</td>
                                        <td class="px-2 py-2"><strong class="font-mono">{{ $ecue->code }}</strong> — {{ $ecue->nom }}</td>
                                        <td class="px-2 py-2"><input type="number" min="0" max="65000" name="ecues[{{ $cle }}][ordre]" value="{{ $contexte->ordre ?? $ecue->ordre ?? 0 }}" @disabled(!$editable) aria-label="Ordre de {{ $ecue->code }}" class="w-16 rounded-lg border border-slate-300 px-2 py-1.5 tabular-nums"></td>
                                        <td class="px-2 py-2"><input type="number" step="0.01" min="0" max="99" name="ecues[{{ $cle }}][coefficient]" value="{{ $contexte->coefficient ?? $ecue->coefficient }}" @disabled(!$editable) class="w-20 rounded-lg border border-slate-300 px-2 py-1.5"></td>
                                        <td class="px-2 py-2"><input type="number" min="0" max="60" name="ecues[{{ $cle }}][credits]" value="{{ $contexte->credits ?? $ecue->credits }}" @disabled(!$editable) class="w-20 rounded-lg border border-slate-300 px-2 py-1.5"></td>
                                        <td class="px-2 py-2 tabular-nums whitespace-nowrap">
                                            <input type="number" min="0" max="999" name="ecues[{{ $cle }}][volume_cm]" value="{{ $contexte->volume_cm ?? $ecue->volume_cm }}" @disabled(!$editable) aria-label="CM {{ $ecue->code }}" class="w-16 rounded-lg border border-slate-300 px-2 py-1.5">/<input type="number" min="0" max="999" name="ecues[{{ $cle }}][volume_td]" value="{{ $contexte->volume_td ?? $ecue->volume_td }}" @disabled(!$editable) aria-label="TD {{ $ecue->code }}" class="w-16 rounded-lg border border-slate-300 px-2 py-1.5">/<input type="number" min="0" max="999" name="ecues[{{ $cle }}][volume_tp]" value="{{ $contexte->volume_tp ?? $ecue->volume_tp }}" @disabled(!$editable) aria-label="TP {{ $ecue->code }}" class="w-16 rounded-lg border border-slate-300 px-2 py-1.5">
                                        </td>
                                        <td class="px-2 py-2 font-mono text-slate-600">{{ $ecue->prerequis->pluck('code')->implode(', ') ?: '—' }}</td>
                                        <td class="px-2 py-2">
                                            <select name="ecues[{{ $cle }}][regime]" @disabled(!$editable) class="rounded-lg border border-slate-300 px-2 py-1.5">
                                                @foreach(['obligatoire', 'optionnelle', 'conditionnelle'] as $regime)
                                                    <option value="{{ $regime }}" @selected(($contexte->regime ?? 'obligatoire') === $regime)>{{ $regime }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-2 py-2"><input type="text" maxlength="30" name="ecues[{{ $cle }}][groupe_choix]" value="{{ $contexte->groupe_choix ?? '' }}" placeholder="OPT-S3" @disabled(!$editable) class="w-32 rounded-lg border border-slate-300 px-2 py-1.5"></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="10" class="px-2 py-3 text-slate-500">UE sans ECUE rattachée.</td></tr>
                                @endforelse
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($editable)
                    <p x-show="modifie" class="mt-2 text-xs font-bold text-amber-700" role="status">⚠ Modifications non enregistrées.</p>
                    <x-btn type="submit" variant="secondary">Enregistrer les contextes ECUE (remplace le contenu ECUE du plan)</x-btn>
                    </form>
                    <script>
                        // LOOP 03 : avertit avant de quitter avec des contextes
                        // non enregistrés (la recherche filtre l'affichage
                        // uniquement — les lignes masquées sont conservées).
                        document.querySelectorAll('form[data-surveiller]').forEach(function (form) {
                            form.addEventListener('change', function () { form.dataset.sale = '1'; });
                            form.addEventListener('submit', function () { form.dataset.sale = ''; });
                        });
                        window.addEventListener('beforeunload', function (e) {
                            if (document.querySelector('form[data-sale="1"]')) e.preventDefault();
                        });
                    </script>
                @else
                    <p class="mt-3 text-xs font-bold text-slate-500">🔒 Contextes figés ({{ $maquette->statut }}) — créez une nouvelle version pour évoluer.</p>
                @endif
            @else
                <div class="mt-3"><x-empty-state message="Définissez d'abord le contenu (UE) du plan." /></div>
            @endif
        </section>

        {{-- Nouvelle version + suppression brouillon --}}
        @can('referentiel.manage')
            <div class="mt-4 flex flex-col sm:flex-row gap-2">
                <form method="POST" action="{{ route('admin.referentiel.maquettes.nouvelle-version', $maquette) }}" class="flex flex-col sm:flex-row gap-2 flex-1">
                    @csrf
                    <select name="annee_universitaire_id" required aria-label="Année de la nouvelle version" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                        @foreach(\App\Models\AnneeUniversitaire::orderByDesc('date_debut')->get() as $annee)
                            <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                        @endforeach
                    </select>
                    <x-btn type="submit" variant="secondary">Dupliquer en nouvelle version</x-btn>
                </form>
                @if($maquette->statut === 'draft')
                    <form method="POST" action="{{ route('admin.referentiel.maquettes.destroy', $maquette) }}" onsubmit="return confirm('Supprimer ce brouillon ?')">
                        @csrf @method('DELETE')
                        <x-btn variant="danger" type="submit">Supprimer le brouillon</x-btn>
                    </form>
                @endif
            </div>
        @endcan

        {{-- Lignage des versions du programme (LOOP C1) --}}
        <section aria-label="Versions du programme" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Versions — {{ $maquette->programme->code }} ({{ $versions->count() }})</h2>
            <ul class="mt-3 space-y-1.5 text-sm">
                @foreach($versions as $version)
                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border {{ $version->id === $maquette->id ? 'border-primary-300 bg-primary-50/50' : 'border-slate-100' }} px-3.5 py-2.5">
                        <span><strong>v{{ $version->version }}</strong> · <span class="font-bold uppercase">{{ $version->statut }}</span>
                            <span class="text-slate-500 tabular-nums">· {{ $version->anneeUniversitaire?->libelle ?? '—' }} · MAJ {{ $version->updated_at?->format('d/m/Y H:i') ?? '—' }}</span></span>
                        <span class="flex gap-3">
                            @if($version->id !== $maquette->id)
                                <a href="{{ route('admin.referentiel.maquettes.show', $version) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir</a>
                                <a href="{{ route('admin.referentiel.maquettes.comparer', $maquette) }}?autre={{ $version->id }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Comparer</a>
                            @else
                                <span class="text-xs font-bold text-slate-400">version affichée</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
@endsection
