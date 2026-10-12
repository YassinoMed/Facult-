@extends('layouts.app')

@section('title', 'Mon épreuve — Espace Enseignant')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Épreuve ' . $epreuve->code" :description="($epreuve->matiere?->nom ?? '—') . ' · ' . ($epreuve->session?->nom ?? '—')"
            :fil="['Surveillances' => route('enseignant.surveillances.index'), 'Épreuve' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('enseignant.epreuves.emargement', $epreuve) }}">Liste d'émargement (PDF)</x-btn>
            </x-slot:actions>
        </x-page-header>

        @if(session('success'))
            <div class="mt-4"><x-alert type="success" :message="session('success')" /></div>
        @endif
        @if($errors->any())
            <div class="mt-4"><x-alert type="error" :message="$errors->first()" /></div>
        @endif

        <section aria-label="Mon affectation" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Mon affectation</h3>
            </div>
            <dl class="divide-y divide-slate-100 dark:divide-slate-800 text-sm">
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Date · Horaire</dt><dd class="font-semibold tabular-nums">{{ $epreuve->date->format('d/m/Y') }} · {{ substr((string) $epreuve->heure_debut, 0, 5) }} → {{ substr((string) $epreuve->heure_fin, 0, 5) }}</dd></div>
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Ma salle</dt><dd class="font-semibold font-mono">{{ $affectation->salle?->code ?? $epreuve->salle?->code ?? '—' }}</dd></div>
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Mon rôle</dt><dd><x-status-badge :statut="$affectation->role" /></dd></div>
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Statut</dt><dd><x-status-badge :statut="$affectation->statut" /></dd></div>
                <div class="px-5 py-3 flex justify-between gap-4"><dt class="text-slate-500">Surveillants actifs / Gardes</dt><dd class="font-semibold tabular-nums">{{ $nbActifs }} / {{ $nbGardes }}</dd></div>
            </dl>
        </section>

        <section aria-label="Incidents de l'épreuve" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-800">
                <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Incidents signalés ({{ $epreuve->incidents->count() }})</h3>
            </div>
            @if($epreuve->incidents->isNotEmpty())
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($epreuve->incidents as $incident)
                        <li class="px-5 py-3 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-bold text-slate-800 dark:text-slate-100">{{ ucfirst(str_replace('_', ' ', $incident->type)) }}</p>
                                <x-status-badge :statut="$incident->statut" />
                            </div>
                            <p class="mt-0.5 text-slate-600 dark:text-slate-300">{{ $incident->description }}</p>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="p-5"><x-empty-state message="Aucun incident signalé sur cette épreuve." /></div>
            @endif
        </section>

        <form method="POST" action="{{ route('enseignant.epreuves.incidents.signaler', $epreuve) }}" class="mt-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 space-y-4 shadow-sm">
            @csrf
            <h3 class="text-sm font-black text-slate-900 dark:text-slate-100">Signaler un incident</h3>
            <div>
                <label for="type" class="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300 mb-1">Type *</label>
                <select id="type" name="type" required class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 px-2.5 py-1.5 text-sm">
                    <option value="">Sélectionner…</option>
                    @foreach(['retard' => 'Retard', 'fraude' => 'Fraude', 'materiel' => 'Matériel', 'copie_manquante' => 'Copie manquante', 'probleme_salle' => 'Problème de salle', 'autre' => 'Autre'] as $code => $libelle)
                        <option value="{{ $code }}" @selected(old('type') === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
            </div>
            <x-textarea id="description" name="description" label="Description (2000 caractères max)" :rows="4">{{ old('description') }}</x-textarea>
            <div>
                <x-btn type="submit">Signaler</x-btn>
            </div>
        </form>
    </div>
@endsection
