@extends('layouts.app')

@section('title', 'Incident Sentinel')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$incident->titre" :description="$incident->code . ' · détecté le ' . $incident->detecte_le"
            :fil="['Sentinelle' => route('admin.sentinel.index'), 'Incident' => '#']" />

        <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p><x-status-badge :statut="$incident->gravite" /></p>
                <p class="mt-1 text-[11px] font-bold uppercase text-slate-500">Gravité</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p><x-status-badge :statut="$incident->statut" /></p>
                <p class="mt-1 text-[11px] font-bold uppercase text-slate-500">Statut</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <p class="text-2xl font-black tabular-nums">{{ $incident->compteur }}</p>
                <p class="text-[11px] font-bold uppercase text-slate-500">Détections</p>
            </div>
        </div>

        <section aria-label="Diagnostic" class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Diagnostic</h2>
            <p class="mt-2 text-sm">{{ $incident->diagnostic }}</p>
            @if($incident->recommandation)
                <h2 class="mt-4 text-sm font-black uppercase tracking-wider text-slate-500">Action recommandée</h2>
                <p class="mt-2 text-sm">{{ $incident->recommandation }}</p>
            @endif
            <dl class="mt-4 grid grid-cols-2 gap-2 text-xs text-slate-500">
                <div><dt class="font-bold">Module</dt><dd>{{ $incident->module ?? '—' }}</dd></div>
                <div><dt class="font-bold">Catégorie</dt><dd>{{ $incident->categorie }}</dd></div>
                <div><dt class="font-bold">Dernière détection</dt><dd>{{ $incident->derniere_detection_le }}</dd></div>
                <div><dt class="font-bold">Entité</dt><dd>{{ $incident->entite_libelle ?? $incident->entite_type ?? '—' }}</dd></div>
            </dl>
        </section>

        @can('sentinel.manage')
            <form method="POST" action="{{ route('admin.sentinel.incidents.transiter', $incident) }}" class="mt-4 flex flex-wrap gap-2">
                @csrf
                <select name="cible" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" aria-label="Transition">
                    @foreach(\App\Modules\Sentinel\Models\SentinelIncident::TRANSITIONS[$incident->statut] ?? [] as $cible)
                        <option value="{{ $cible }}">{{ $cible }}</option>
                    @endforeach
                </select>
                <x-btn size="sm" type="submit">Appliquer</x-btn>
            </form>
        @endcan
    </div>
@endsection
