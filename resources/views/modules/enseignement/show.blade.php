@extends('layouts.app')

@section('title', $enseignement->matiere->code . ' / ' . $enseignement->type->code . ' — Enseignement')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$enseignement->matiere->code . ' · ' . $enseignement->type->code" :description="$enseignement->matiere->nom . ' · ' . ($enseignement->anneeUniversitaire->libelle) . ' · ' . $enseignement->statut"
            :fil="['Enseignements' => route('admin.enseignements.index'), $enseignement->matiere->code => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.listes.preview', ['matiere_id' => $enseignement->matiere_id]) }}">Étudiants concernés</x-btn>
                @can('enseignements.manage')
                    <x-btn variant="secondary" href="{{ route('admin.enseignements.edit', $enseignement) }}">Modifier</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Opération refusée.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        {{-- Transitions (workflow §44) --}}
        @can('enseignements.manage')
            <div class="mt-4 ds-card p-4 flex flex-wrap items-center gap-2">
                <span class="text-[11px] font-black uppercase tracking-wider text-slate-500">Statut : {{ $enseignement->statut }}</span>
                @foreach(\App\Modules\Enseignement\Models\Enseignement::TRANSITIONS[$enseignement->statut] ?? [] as $cible)
                    <form method="POST" action="{{ route('admin.enseignements.transition', $enseignement) }}" class="inline">
                        @csrf @method('PATCH')
                        <input type="hidden" name="statut" value="{{ $cible }}">
                        <x-btn variant="secondary" type="submit">→ {{ $cible }}</x-btn>
                    </form>
                @endforeach
            </div>
        @endcan

        <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Identité + volumes --}}
            <div class="ds-card p-5 lg:col-span-1">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Identité</h2>
                <dl class="mt-2 space-y-1.5 text-sm">
                    <div class="flex justify-between py-1 border-b border-slate-100"><dt>UE</dt><dd class="font-bold">{{ $enseignement->matiere->ue?->code ?? '—' }}</dd></div>
                    <div class="flex justify-between py-1 border-b border-slate-100"><dt>Programme</dt><dd class="font-bold">{{ $enseignement->programme?->code ?? $enseignement->matiere->ue?->programme?->code ?? '—' }}</dd></div>
                    <div class="flex justify-between py-1 border-b border-slate-100"><dt>Semestre</dt><dd class="font-bold">{{ $enseignement->semestre?->code ?? '—' }}</dd></div>
                    <div class="flex justify-between py-1"><dt class="font-black">Volume prévu</dt><dd class="font-black tabular-nums">{{ $enseignement->volumeTotal() }}h</dd></div>
                    <div class="flex justify-between py-1 text-slate-500 text-xs"><dt>Affecté</dt><dd class="tabular-nums">{{ number_format($enseignement->volumeAffecte(), 1) }}h</dd></div>
                </dl>
                <h3 class="mt-3 text-xs font-black uppercase tracking-wider text-slate-500">Évaluation</h3>
                @if($enseignement->matiere->composantes->isNotEmpty())
                    <ul class="mt-1.5 space-y-1 text-xs">
                        @foreach($enseignement->matiere->composantes as $comp)
                            <li class="flex justify-between"><span>{{ $comp->libelle }}</span><span class="tabular-nums font-bold">{{ $comp->pourcentage }}%</span></li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-1.5 text-xs text-slate-500">{{ $enseignement->matiere->type_evaluation ?? '—' }} <a href="{{ route('admin.enseignements.evaluation.edit', $enseignement->matiere) }}" class="font-bold text-primary-800 underline underline-offset-2">Configurer</a></p>
                @endif
                @if($enseignement->matiere->responsable)
                    <p class="mt-2 text-xs text-slate-500">Responsable : {{ $enseignement->matiere->responsable->nomComplet() }}</p>
                @endif
                @if($enseignement->matiere->prerequis->isNotEmpty())
                    <p class="mt-1.5 text-xs text-slate-500">Prérequis : {{ $enseignement->matiere->prerequis->pluck('code')->implode(', ') }}</p>
                @endif
            </div>

            {{-- Enseignants --}}
            <div class="ds-card p-5 lg:col-span-1">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Enseignants ({{ $enseignement->affectations->where('statut', 'active')->count() }})</h2>
                @foreach($enseignement->affectations as $aff)
                    <div class="mt-2 rounded-xl border border-slate-100 px-3 py-2 text-xs flex items-center justify-between gap-2 {{ $aff->statut === 'cloturee' ? 'opacity-50' : '' }}">
                        <span><strong>{{ $aff->teacher->nomComplet() }}</strong><span class="text-slate-500"> · {{ $aff->role }} · {{ $aff->volume_heures }}h</span></span>
                        @if($aff->statut === 'active')
                            @can('enseignements.manage')
                                <form method="POST" action="{{ route('admin.affectations-enseignement.destroy', $aff) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" aria-label="Clôturer" class="text-rose-700 font-bold min-h-[32px] min-w-[32px]">✕</button>
                                </form>
                            @endcan
                        @endif
                    </div>
                @endforeach
                @can('enseignements.manage')
                    <form method="POST" action="{{ route('admin.enseignements.affectations.store', $enseignement) }}" class="mt-3 space-y-2">
                        @csrf
                        <select name="teacher_id" required aria-label="Enseignant" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                            <option value="">— Enseignant —</option>
                            @foreach($enseignants as $ens)
                                <option value="{{ $ens->id }}">{{ $ens->nomComplet() }}</option>
                            @endforeach
                        </select>
                        <div class="grid grid-cols-2 gap-2">
                            <select name="role" required aria-label="Rôle" class="rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                                @foreach(['responsable', 'principal', 'enseignant', 'intervenant', 'vacataire'] as $r)
                                    <option value="{{ $r }}">{{ $r }}</option>
                                @endforeach
                            </select>
                            <input type="number" name="volume_heures" step="0.5" min="0" max="999" required placeholder="Heures" class="rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                        </div>
                        <x-btn type="submit" variant="secondary" class="w-full justify-center">Affecter</x-btn>
                    </form>
                @endcan
            </div>

            {{-- Groupes --}}
            <div class="ds-card p-5 lg:col-span-1">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Groupes ({{ $enseignement->groupes->count() }})</h2>
                @foreach($enseignement->groupes as $groupe)
                    @php($lien = \App\Modules\Enseignement\Models\EnseignementGroupe::where('enseignement_id', $enseignement->id)->where('groupe_id', $groupe->id)->first())
                    <div class="mt-2 flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3 py-2 text-xs">
                        <span>
                            <a href="{{ route('admin.groupes.show', $groupe) }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $groupe->code }}</a>
                            @if($groupe->pivot->sous_groupe_id)
                                <span class="text-slate-500">→ {{ \App\Modules\Enseignement\Models\SousGroupe::find($groupe->pivot->sous_groupe_id)?->code }}</span>
                            @endif
                        </span>
                        @can('enseignements.manage')
                            @if($lien)
                                <form method="POST" action="{{ route('admin.enseignement-groupes.destroy', $lien) }}" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" aria-label="Détacher {{ $groupe->code }}" class="text-rose-700 font-bold min-h-[32px] min-w-[32px]">✕</button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @endforeach
                @can('enseignements.manage')
                    <form method="POST" action="{{ route('admin.enseignements.groupes.store', $enseignement) }}" class="mt-3 space-y-2">
                        @csrf
                        <select name="groupe_id" required aria-label="Groupe" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                            <option value="">— Groupe —</option>
                            @foreach($groupes as $groupe)
                                <option value="{{ $groupe->id }}">{{ $groupe->code }}{{ $groupe->programme?->code ? ' (' . $groupe->programme->code . ')' : '' }}</option>
                            @endforeach
                        </select>
                        <select name="sous_groupe_id" aria-label="Sous-groupe (optionnel)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                            <option value="">— Sous-groupe —</option>
                            @foreach($sousGroupes as $sg)
                                <option value="{{ $sg->id }}">{{ $sg->groupe->code }} · {{ $sg->code }}</option>
                            @endforeach
                        </select>
                        <x-btn type="submit" variant="secondary" class="w-full justify-center">Rattacher</x-btn>
                    </form>
                @endcan
            </div>
        </div>
    </div>
@endsection
