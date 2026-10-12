@extends('layouts.app')

@section('title', 'Évaluation ECUE — ' . $matiere->code)

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Évaluation : ' . $matiere->code" :description="$matiere->nom"
            :fil="['Enseignements' => route('admin.enseignements.index'), 'Évaluation' => '#']" />

        @if($errors->any())
            <div class="mt-4"><x-alert type="error" titre="Vérifiez la saisie.">
                <ul class="list-disc list-inside mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        {{-- Responsable pédagogique --}}
        <form method="POST" action="{{ route('admin.enseignements.evaluation.responsable', $matiere) }}" class="mt-4 ds-card p-4 space-y-2">
            @csrf @method('PATCH')
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Responsable pédagogique</h2>
            <select name="responsable_teacher_id" aria-label="Responsable" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">— Aucun —</option>
                @foreach($enseignants as $ens)
                    <option value="{{ $ens->id }}" @selected($matiere->responsable_teacher_id == $ens->id)>{{ $ens->nomComplet() }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Enregistrer</x-btn>
        </form>

        {{-- Composantes (somme = 100 %) --}}
        <form method="POST" action="{{ route('admin.enseignements.evaluation.composantes', $matiere) }}" class="mt-4 ds-card p-4 space-y-3">
            @csrf @method('PUT')
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Composantes (§24) — total 100 %</h2>
            <p class="text-xs text-slate-500">Répartition par type : CC, Examen, Projet, Oral, TP, Mixte…</p>

            <div id="composantes" class="space-y-2">
                @forelse($matiere->composantes as $comp)
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <input name="composantes[{{ $loop->index }}][code]" value="{{ $comp->code }}" required maxlength="20" class="col-span-3 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Code">
                        <input name="composantes[{{ $loop->index }}][libelle]" value="{{ $comp->libelle }}" required maxlength="100" class="col-span-6 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Libellé">
                        <input name="composantes[{{ $loop->index }}][pourcentage]" type="number" step="0.5" min="0" max="100" value="{{ (float) $comp->pourcentage }}" required class="col-span-3 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Pourcentage">
                    </div>
                @empty
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <input name="composantes[0][code]" value="CC" required maxlength="20" class="col-span-3 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Code">
                        <input name="composantes[0][libelle]" value="Contrôle continu" required maxlength="100" class="col-span-6 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Libellé">
                        <input name="composantes[0][pourcentage]" type="number" step="0.5" min="0" max="100" value="40" required class="col-span-3 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Pourcentage">
                    </div>
                    <div class="grid grid-cols-12 gap-2 items-center">
                        <input name="composantes[1][code]" value="EXAM" required maxlength="20" class="col-span-3 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Code">
                        <input name="composantes[1][libelle]" value="Examen" required maxlength="100" class="col-span-6 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Libellé">
                        <input name="composantes[1][pourcentage]" type="number" step="0.5" min="0" max="100" value="60" required class="col-span-3 rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]" aria-label="Pourcentage">
                    </div>
                @endforelse
            </div>

            <p class="text-xs text-slate-500">Total saisi : <strong class="tabular-nums" x-data="{ }" x-on:input cible="sum">—</strong>
                <span class="tabular-nums text-slate-700">{{ $matiere->composantes->sum('pourcentage') ?: 0 }} %</span>
                @if($matiere->composantes->isNotEmpty() && abs($matiere->composantes->sum('pourcentage') - 100) > 0.01)
                    <span class="font-bold text-amber-700">⚠ À corriger à 100 %</span>
                @endif
            </p>

            <div class="flex flex-col sm:flex-row gap-2">
                <x-btn type="submit">Valider la répartition</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.enseignements.index') }}">Retour</x-btn>
            </div>
        </form>
    </div>
@endsection
