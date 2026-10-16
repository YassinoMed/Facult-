@extends('layouts.app')

@section('title', 'Studio e-mail — bibliothèque')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <x-page-header titre="Studio e-mail — bibliothèque" description="Modèles personnalisés versionnés. Les 7 modèles du catalogue restent verrouillés (lecture seule ci-dessous)."
        :fil="['Studio' => '#']" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if(session('error'))
        <div class="mt-4"><x-alert type="error">{{ session('error') }}</x-alert></div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        @can('mail.modeles.themes')
        <a href="{{ route('admin.studio.themes') }}" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700">Thèmes</a>
        @endcan
    </div>

    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-black text-slate-900">Modèles système protégés (lecture seule)</h2>
        <ul class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-600">
            @foreach($systeme as $identifiant => $modele)
                <li class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">
                    <span class="font-mono font-bold text-slate-900">{{ $identifiant }}</span>
                    <span class="text-slate-400">v{{ $modele['version'] }}</span> — {{ $modele['sujet'] }}
                </li>
            @endforeach
        </ul>
    </div>

    @can('mail.modeles.gerer')
    <form method="GET" action="{{ route('admin.studio.index') }}" class="mt-4 flex flex-col sm:flex-row gap-3">
        <label class="sr-only" for="recherche-studio">Recherche</label>
        <input id="recherche-studio" name="q" value="{{ request('q') }}" placeholder="Nom ou code…"
               class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <label class="sr-only" for="statut-studio">Statut</label>
        <select id="statut-studio" name="statut" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
            <option value="">Tous statuts</option>
            @foreach(['brouillon', 'a_valider', 'publie', 'archive'] as $statut)
                <option value="{{ $statut }}" @selected(request('statut') === $statut)>{{ $statut }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700">Filtrer</button>
    </form>
    @endcan

    <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 text-left text-[11px] uppercase tracking-wider text-slate-400">
                    <th scope="col" class="px-4 py-3">Code</th>
                    <th scope="col" class="px-4 py-3">Nom</th>
                    <th scope="col" class="px-4 py-3">Statut</th>
                    <th scope="col" class="px-4 py-3">Version publiée</th>
                    <th scope="col" class="px-4 py-3">Modifié le</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse($modeles as $modele)
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="px-4 py-3 font-mono text-xs">{{ $modele->code }}</td>
                        <td class="px-4 py-3 font-bold">{{ $modele->nom }}</td>
                        <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold text-slate-700">{{ $modele->statut }}</span></td>
                        <td class="px-4 py-3 tabular-nums">{{ $modele->version_courante ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs tabular-nums">{{ $modele->updated_at->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <a href="{{ route('admin.studio.voir', $modele) }}" class="font-bold text-primary-800 underline underline-offset-2">Détail</a>
                            @can('mail.modeles.gerer')
                            @if($modele->estModifiable())
                                <a href="{{ route('admin.studio.editer', $modele) }}" class="ml-2 font-bold text-primary-800 underline underline-offset-2">Éditer</a>
                            @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">Aucun modèle. Créez votre premier brouillon via l’API du studio.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $modeles->links() }}</div>
</div>
@endsection
