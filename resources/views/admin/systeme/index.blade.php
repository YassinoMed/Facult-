@extends('layouts.app')

@section('title', 'Système — Exploitation')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Système — Exploitation" description="Santé, activité, données, release. Façade : aucune donnée dupliquée."
            :fil="['Système' => '#']" />

        <section aria-label="Santé" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach($sondes as $sonde)
                <div class="ds-card flex items-center gap-3 p-4">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl text-lg font-black {{ $sonde['ok'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}" aria-hidden="true">{{ $sonde['ok'] ? '✓' : '✕' }}</span>
                    <span>
                        <span class="block text-sm font-black text-slate-900">{{ $sonde['nom'] }}</span>
                        <span class="block text-[11px] text-slate-500">{{ $sonde['detail'] }}</span>
                    </span>
                </div>
            @endforeach
        </section>

        <div class="mt-4 grid gap-3 lg:grid-cols-3">
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Activité</h2>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($activite as $libelle => $valeur)
                        <li class="flex justify-between py-1.5"><span>{{ $libelle }}</span><strong class="tabular-nums">{{ $valeur }}</strong></li>
                    @endforeach
                </ul>
            </section>
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Données & erreurs</h2>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($donnees as $libelle => $valeur)
                        <li class="flex justify-between py-1.5"><span>{{ $libelle }}</span><strong class="tabular-nums">{{ $valeur }}</strong></li>
                    @endforeach
                    <li class="flex justify-between py-1.5"><span>Erreurs API (1 h)</span><strong class="tabular-nums">{{ $erreurs_api }}</strong></li>
                    <li class="flex justify-between py-1.5"><span>Scheduler</span><strong>{{ $dernier_scheduler ?? 'jamais vu' }}</strong></li>
                </ul>
                <p class="mt-2 text-xs"><a href="{{ route('admin.qualite.index') }}" class="font-bold text-primary-800 underline underline-offset-2">Qualité</a> · <a href="{{ route('admin.audit.index') }}" class="font-bold text-primary-800 underline underline-offset-2">Audit</a> · <a href="{{ route('admin.taches.index') }}" class="font-bold text-primary-800 underline underline-offset-2">Tâches</a></p>
            </section>
            <section class="ds-card p-5">
                <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Release</h2>
                <ul class="mt-2 divide-y divide-slate-100 text-sm">
                    @foreach($release as $libelle => $valeur)
                        <li class="flex justify-between py-1.5"><span>{{ $libelle }}</span><strong>{{ $valeur }}</strong></li>
                    @endforeach
                </ul>
            </section>
        </div>

        <section aria-label="Jobs en échec" class="mt-4 ds-card p-5">
            <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Jobs en échec (5 derniers, sans payload)</h2>
            <ul class="mt-2 divide-y divide-slate-100 text-sm">
                @forelse($echecs as $echec)
                    <li class="py-1.5"><strong>#{{ $echec['id'] }}</strong> · {{ $echec['queue'] }} · {{ $echec['failed_at'] }}<br /><span class="text-xs text-slate-500">{{ $echec['erreur'] }}</span></li>
                @empty
                    <li class="py-1.5 text-slate-500">Aucun échec.</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
