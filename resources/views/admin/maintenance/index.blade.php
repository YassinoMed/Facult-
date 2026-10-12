@extends('layouts.app')

@section('title', 'Maintenance — Scolarité')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Maintenance système" description="Réservé aux super-administrateurs — aucune donnée métier ici."
            :fil="['Tableau de bord' => route('admin.dashboard'), 'Maintenance' => '#']" />

        @if(session('success'))
            <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
        @endif

        <section aria-label="Santé système" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach($sante as $controle)
                <div class="ds-card flex items-center gap-3 p-4">
                    <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl text-lg font-black {{ $controle['statut'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}" aria-hidden="true">
                        {{ $controle['statut'] ? '✓' : '✕' }}
                    </span>
                    <span>
                        <span class="block text-sm font-black text-slate-900">{{ $controle['nom'] }}</span>
                        <span class="block text-[11px] text-slate-500">{{ $controle['detail'] }} · {{ $controle['statut'] ? 'opérationnel' : 'en panne' }}</span>
                    </span>
                </div>
            @endforeach
        </section>

        <section aria-label="Jobs en échec" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Jobs en échec (10 derniers — sans payload)</h2>
            @php $echecs = $supervision->echecsJobs(10); @endphp
            @if($echecs === [])
                <p class="mt-2 text-sm text-slate-500">Aucun échec enregistré.</p>
            @else
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($echecs as $echec)
                        <li class="py-1.5"><strong>#{{ $echec['id'] }}</strong> · {{ $echec['queue'] }} · {{ $echec['failed_at'] }}<br /><span class="text-xs text-slate-500">{{ $echec['erreur'] }}</span></li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-2 text-[11px] text-slate-400">Erreurs API (1 h) : {{ $supervision->erreursApiHeure() }} · Rejeu : <code>php artisan queue:retry all</code> (serveur).</p>
        </section>

        <section aria-label="Actions techniques" class="mt-4 ds-card p-5">
            <h2 class="text-base font-black text-slate-900">Actions techniques</h2>
            <form method="POST" action="{{ route('admin.maintenance.purger-cache') }}" class="mt-3"
                  x-data="{ confirmer: false }" @submit.prevent="confirmer = true">
                @csrf
                <x-btn variant="secondary" type="submit">Purger les caches applicatifs</x-btn>
                <x-modal titre="Purger les caches ?" nom="confirmer">
                    <p>Les caches applicatifs seront reconstruits à la prochaine requête (léger ralentissement ponctuel).</p>
                    <x-slot:actions>
                        <x-btn variant="secondary" @click="confirmer = false">Annuler</x-btn>
                        <x-btn type="submit" @click="$root.requestSubmit()">Purger</x-btn>
                    </x-slot:actions>
                </x-modal>
            </form>
        </section>
    </div>
@endsection
