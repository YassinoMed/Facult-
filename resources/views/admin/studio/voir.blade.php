@extends('layouts.app')

@section('title', 'Modèle — détail')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <x-page-header titre="Modèle {{ $modele->code }}" description="{{ $modele->nom }} — cycle et historique."
        :fil="['Studio' => route('admin.studio.index')]" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if(session('error'))
        <div class="mt-4"><x-alert type="error">{{ session('error') }}</x-alert></div>
    @endif

    <dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        @foreach(['Statut' => $modele->statut, 'Catégorie' => $modele->categorie, 'Version publiée' => $modele->version_courante ?? '—', 'Verrou système' => $modele->verrou_systeme ? 'oui' : 'non'] as $libelle => $valeur)
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ $libelle }}</dt>
                <dd class="mt-1 font-mono text-sm">{{ $valeur }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-4 flex flex-wrap gap-2">
        @can('mail.modeles.gerer')
        @if($modele->estModifiable())
            <a href="{{ route('admin.studio.editer', $modele) }}" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white">Éditer</a>
            <form method="POST" action="{{ route('admin.studio.dupliquer', $modele) }}" class="inline">@csrf<button type="submit" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700">Dupliquer</button></form>
        @endif
        @endcan
        @can('mail.modeles.publier')
        @if($modele->statut === 'brouillon')
            <form method="POST" action="{{ route('admin.studio.soumettre', $modele) }}" class="inline">@csrf<button type="submit" class="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700">Soumettre à validation</button></form>
        @endif
        @if(in_array($modele->statut, ['brouillon', 'a_valider'], true) && $modele->estModifiable())
            <form method="POST" action="{{ route('admin.studio.publier', $modele) }}" class="inline">@csrf<button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white">Publier</button></form>
        @endif
        @if($modele->estModifiable())
            <form method="POST" action="{{ route('admin.studio.archiver', $modele) }}" class="inline" onsubmit="return confirm('Archiver ce modèle ?')">@csrf<button type="submit" class="rounded-xl border border-rose-300 px-4 py-2 text-xs font-bold text-rose-700">Archiver</button></form>
        @endif
        @endcan
        @can('mail.modeles.gerer')
        @if($modele->version_courante === null && !$modele->verrou_systeme)
            <form method="POST" action="{{ route('admin.studio.supprimer', $modele) }}" class="inline" onsubmit="return confirm('Supprimer définitivement ce brouillon jamais publié ?')">@csrf @method('DELETE')<button type="submit" class="rounded-xl border border-rose-300 px-4 py-2 text-xs font-bold text-rose-700">Supprimer</button></form>
        @endif
        @endcan
    </div>

    <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-black text-slate-900">Envoi de test (données fictives)</h2>
            @can('mail.test.modele')
            @if(!$modele->verrou_systeme && $modele->statut !== 'archive' && $versions->isNotEmpty())
                <form method="POST" action="{{ route('admin.studio.envoi-test', $modele) }}" class="mt-2 flex flex-col sm:flex-row gap-2 text-sm">
                    @csrf
                    <label class="sr-only" for="test-version">Version</label>
                    <select id="test-version" name="version" class="rounded-xl border border-slate-200 px-2 py-1.5">
                        @foreach($versions as $v)<option value="{{ $v->numero }}">v{{ $v->numero }}</option>@endforeach
                    </select>
                    <label class="sr-only" for="test-destinataire">Destinataire autorisé</label>
                    <input id="test-destinataire" name="destinataire" type="email" required maxlength="254" placeholder="adresse de test autorisée" class="flex-1 rounded-xl border border-slate-200 px-2 py-1.5">
                    <button type="submit" class="rounded-xl bg-primary-600 px-3 py-1.5 text-xs font-bold text-white">Aperçu avant envoi</button>
                </form>
            @else
                <p class="mt-2 text-xs text-slate-500">Envoi de test indisponible (modèle verrouillé, archivé ou sans version).</p>
            @endif
            @endcan
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-black text-slate-900">Versions immuables</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @foreach($versions as $version)
                    <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2">
                        <span class="font-mono font-bold">v{{ $version->numero }}</span>
                        <span class="text-xs text-slate-500">{{ $version->created_at->format('d/m/Y H:i') }}{{ $version->note ? ' — '.$version->note : '' }}</span>
                        <span class="flex gap-2">
                            @can('mail.modeles.publier')
                            @if($modele->estModifiable())
                            <form method="POST" action="{{ route('admin.studio.restaurer', $modele) }}" class="inline" onsubmit="return confirm('Republier la version {{ $version->numero }} comme nouvelle version ?')">@csrf<input type="hidden" name="version" value="{{ $version->numero }}"><button type="submit" class="text-xs font-bold text-primary-800 underline underline-offset-2">Restaurer</button></form>
                            @endif
                            @endcan
                            @can('mail.test.modele')
                            <a href="{{ route('admin.studio.editer', $modele) }}" class="text-xs font-bold text-slate-500">Tester depuis l’éditeur</a>
                            @endcan
                        </span>
                    </li>
                @endforeach
            </ul>

            <h3 class="mt-5 text-sm font-black text-slate-900">Comparer deux versions</h3>
            <form method="GET" action="{{ route('admin.studio.voir', $modele) }}" class="mt-2 flex items-center gap-2 text-sm">
                <label class="sr-only" for="cmp-a">Version A</label>
                <select id="cmp-a" name="v1" class="rounded-xl border border-slate-200 px-2 py-1.5">@foreach($versions as $v)<option value="{{ $v->numero }}" @selected($numeroA === $v->numero)>v{{ $v->numero }}</option>@endforeach</select>
                <label class="sr-only" for="cmp-b">Version B</label>
                <select id="cmp-b" name="v2" class="rounded-xl border border-slate-200 px-2 py-1.5">@foreach($versions as $v)<option value="{{ $v->numero }}" @selected($numeroB === $v->numero)>v{{ $v->numero }}</option>@endforeach</select>
                <button type="submit" class="rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-bold">Comparer</button>
            </form>
            @if($comparatif)
                <dl class="mt-3 space-y-1 text-xs">
                    <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">Méta modifiées : <span class="font-mono">{{ implode(', ', $comparatif['meta_modifiees']) ?: '—' }}</span></div>
                    <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">Thème modifié : <span class="font-mono">{{ $comparatif['theme_modifie'] ? 'oui' : 'non' }}</span></div>
                    @foreach($comparatif['blocs'] as $ligne)
                        <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">Bloc {{ $ligne['bloc'] }} ({{ $ligne['type'] }}) : <strong>{{ $ligne['changement'] }}</strong></div>
                    @endforeach
                    @if($comparatif['blocs'] === [] && $comparatif['meta_modifiees'] === [] && !$comparatif['theme_modifie'])
                        <div class="rounded-xl bg-emerald-50 border border-emerald-200 px-3 py-2">Versions identiques.</div>
                    @endif
                </dl>
            @endif
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-black text-slate-900">Journal de cycle de vie</h2>
            <ul class="mt-3 space-y-2 text-xs text-slate-600">
                @forelse($journal as $entree)
                    <li class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2">
                        <strong class="font-mono">{{ $entree->action }}</strong>
                        @if($entree->version_numero) — v{{ $entree->version_numero }}@endif
                        <span class="text-slate-400">· {{ $entree->created_at->format('d/m/Y H:i') }} · acteur {{ $entree->acteur_id ?? '—' }}</span>
                    </li>
                @empty
                    <li class="text-slate-500">Aucune entrée.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
