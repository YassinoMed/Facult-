@extends('layouts.app')

@section('title', 'Rôles et autorisations')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Rôles et autorisations"
        description="Matrice en lecture seule ({{ $totalPermissions }} permissions). L'attribution se fait par compte ; la matrice vit en code versionné."
        :fil="['Rôles' => '#']" />

    @if(session('success'))
        <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 text-sm font-semibold text-emerald-800">
            {{ session('success') }}
        </div>
    @endif

    <div @role-attribue.window="
            ['effectif-' + $event.detail.resultat.ancien_role, 'effectif-' + $event.detail.resultat.nouveau_role].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.textContent = parseInt(el.textContent, 10) + (id.endsWith($event.detail.resultat.nouveau_role) ? 1 : -1);
            });
            document.getElementById('banner-role').classList.remove('hidden');
            document.getElementById('banner-role-texte').textContent = $event.detail.resultat.message;
         ">
    <p id="banner-role" class="hidden mt-6 rounded-2xl border border-emerald-200 bg-emerald-50/80 px-5 py-3.5 text-sm font-semibold text-emerald-800" role="status">
        <span id="banner-role-texte"></span>
    </p>

    <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        @foreach($roles as $role)
            <section class="ds-card p-5" aria-label="Rôle {{ $role['code'] }}">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-black text-slate-900">{{ $role['code'] }}</h2>
                    <span class="text-[11px] text-slate-500">{{ count($role['permissions']) }} permissions · <span id="effectif-{{ $role['code'] }}">{{ $role['effectif'] }}</span> compte(s)</span>
                </div>
                @if($peutAttribuer)
                    <button type="button" @click="$dispatch('ouvrir-attribution', { roleCode: '{{ $role['code'] }}' })"
                            class="mt-3 rounded-xl bg-primary-800 px-4 py-2 text-xs font-bold text-white hover:bg-primary-700">
                        Attribuer ce rôle
                    </button>
                @endif
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

    @include('admin._modal-attribution-role', ['mode' => 'role', 'config' => [
        'mode' => 'role',
        'urls' => [
            'attribuer' => route('admin.roles.attribuer'),
            'recherche' => route('admin.administrateurs.recherche'),
        ],
        'roles' => $rolesAttribuables,
        'registre' => $registrePermissions,
        'facultes' => $facultes->map(fn ($f) => ['id' => $f->id, 'nom' => $f->nom])->all(),
    ]])
    </div>
</div>
@endsection
