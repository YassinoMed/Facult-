@extends('layouts.app')

@section('title', 'Rôles et autorisations')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Rôles et autorisations"
        description="Matrice en lecture seule ({{ $totalPermissions }} permissions). L'attribution se fait par compte ; la matrice vit en code versionné."
        :fil="['Rôles' => '#']" />

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach($roles as $role)
            <section class="ds-card p-5" aria-label="Rôle {{ $role['code'] }}">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-black text-slate-900">{{ $role['code'] }}</h2>
                    <span class="text-[11px] text-slate-500">{{ count($role['permissions']) }} permissions · {{ $role['effectif'] }} compte(s)</span>
                </div>
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs font-bold text-primary-800">Voir les permissions</summary>
                    <ul class="mt-2 flex flex-wrap gap-1.5">
                        @foreach($role['permissions'] as $permission)
                            <li><code class="rounded-lg bg-slate-100 px-2 py-0.5 font-mono text-[11px] text-slate-700">{{ $permission }}</code></li>
                        @endforeach
                    </ul>
                </details>
                <p class="mt-3 text-[11px] text-slate-400">
                    Attribuer : <a class="font-bold text-primary-800 underline" href="{{ route('admin.administrateurs.index') }}">comptes administrateurs</a>
                    (gardes anti-escalade actives).
                </p>
            </section>
        @endforeach
    </div>
</div>
@endsection
