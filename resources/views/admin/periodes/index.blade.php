@extends('layouts.app')

@section('title', 'Périodes d’inscription')

@section('content')
<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-page-header titre="Périodes d’inscription" description="Ouvertures planifiées (normales) et exceptionnelles — toute inscription est refusée hors période (REGISTRATION_PERIOD_CLOSED)." />

    @if($errors->any())
        <div class="mt-4"><x-alert type="error" titre="Période refusée">{{ $errors->first() }}</x-alert></div>
    @endif
    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif

    {{-- Rappels (§22) : compteurs calculés à l'affichage, sans tâche planifiée. --}}
    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4" role="status" aria-label="État des périodes">
        <x-stat-card libelle="Ouvertes" :valeur="$resume['ouvertes']" couleur="emerald" />
        <x-stat-card libelle="Fermeture < 48 h" :valeur="$resume['bientot']" couleur="amber" />
        <x-stat-card libelle="Planifiées" :valeur="$resume['planifiees']" couleur="primary" />
        <x-stat-card libelle="Exceptions actives" :valeur="$resume['exceptions']" />
    </div>

    <div class="mt-4"><x-card titre="Nouvelle période / exception">
        <form method="post" action="{{ route('admin.periodes.store') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @csrf
            <div>
                <label for="annee_universitaire_id" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Année *</label>
                <select name="annee_universitaire_id" id="annee_universitaire_id" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}" @selected((int) old('annee_universitaire_id') === $annee->id)>{{ $annee->libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type_periode" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Type *</label>
                <select name="type_periode" id="type_periode" required class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="normale">Normale (ouverture planifiée)</option>
                    <option value="exception">Exception (temporaire, motivée)</option>
                </select>
            </div>
            <div>
                <label for="nom" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Libellé</label>
                <input type="text" name="nom" id="nom" value="{{ old('nom') }}" maxlength="150" placeholder="Ex. Rentrée principale" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label for="faculte_id" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Faculté (vide = toutes)</label>
                <select name="faculte_id" id="faculte_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Toutes</option>
                    @foreach($facultes as $faculte)
                        <option value="{{ $faculte->id }}">{{ $faculte->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type_inscription" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Type d’inscription (vide = tous)</label>
                <select name="type_inscription" id="type_inscription" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    <option value="premiere">Première inscription</option>
                    <option value="reinscription">Réinscription</option>
                </select>
            </div>
            <div>
                <label for="programme_id" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Programme (vide = tous)</label>
                <select name="programme_id" id="programme_id" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}" @selected((int) old('programme_id') === $programme->id)>{{ $programme->code }} — {{ $programme->nom }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="niveau" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Niveau (vide = tous)</label>
                <input type="text" name="niveau" id="niveau" value="{{ old('niveau') }}" maxlength="10" placeholder="Ex. L1" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label for="etudiant_id" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Étudiant (exception individuelle)</label>
                <input type="number" name="etudiant_id" id="etudiant_id" value="{{ old('etudiant_id') }}" min="1" placeholder="ID étudiant (vide = non individuelle)" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div>
                <label for="opened_at" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Ouverture *</label>
                <input type="datetime-local" name="opened_at" id="opened_at" required value="{{ old('opened_at') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                <p class="mt-1 text-[11px] text-slate-400">Heure de Tunis (stockage UTC) — bornes inclusives.</p>
                @error('opened_at')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="closed_at" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Fermeture *</label>
                <input type="datetime-local" name="closed_at" id="closed_at" required value="{{ old('closed_at') }}" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" />
                @error('closed_at')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-3">
                <label for="motif" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Motif (obligatoire pour une exception)</label>
                <textarea name="motif" id="motif" rows="2" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm" placeholder="Justification officielle de l’ouverture exceptionnelle…">{{ old('motif') }}</textarea>
                @error('motif')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-3">
                <x-btn type="submit">Créer</x-btn>
            </div>
        </form>
    </x-card></div>

    <div class="mt-4"><x-card titre="Périodes configurées">
        <form method="get" action="{{ route('admin.periodes.index') }}" class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
            <div>
                <label for="filtre-annee" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Année</label>
                <select name="annee_universitaire_id" id="filtre-annee" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Toutes</option>
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}" @selected((int) ($filtres['annee_universitaire_id'] ?? 0) === $annee->id)>{{ $annee->libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filtre-type" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Type</label>
                <select name="type_periode" id="filtre-type" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    <option value="normale" @selected(($filtres['type_periode'] ?? null) === 'normale')>Normale</option>
                    <option value="exception" @selected(($filtres['type_periode'] ?? null) === 'exception')>Exception</option>
                </select>
            </div>
            <div>
                <label for="filtre-statut" class="mb-1 block text-xs font-bold uppercase tracking-wider text-slate-500">Statut</label>
                <select name="statut" id="filtre-statut" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm">
                    <option value="">Tous</option>
                    @foreach(['active', 'revoquee', 'utilisee'] as $statut)
                        <option value="{{ $statut }}" @selected(($filtres['statut'] ?? null) === $statut)>{{ $statut }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <x-btn type="submit" variant="secondary">Filtrer</x-btn>
            </div>
        </form>
        @if($periodes->isEmpty())
            <x-empty-state message="Aucune période configurée : les inscriptions restent autorisées (mode transition)." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                            <th class="px-3 py-2">Année</th>
                            <th class="px-3 py-2">Libellé</th>
                            <th class="px-3 py-2">Type</th>
                            <th class="px-3 py-2">Portée</th>
                            <th class="px-3 py-2">Ouverture</th>
                            <th class="px-3 py-2">Fermeture</th>
                            <th class="px-3 py-2">État</th>
                            <th class="px-3 py-2">Motif</th>
                            <th class="px-3 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($periodes as $periode)
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 font-semibold">{{ $periode->anneeUniversitaire?->libelle }}</td>
                                <td class="px-3 py-2">{{ $periode->nom ?? '—' }}</td>
                                <td class="px-3 py-2"><x-status-badge :statut="$periode->type_periode" /></td>
                                <td class="px-3 py-2 text-xs">{{ $periode->faculte?->nom ?? 'Toutes facultés' }}{{ $periode->niveau ? ' · '.$periode->niveau : '' }}{{ $periode->etudiant ? ' · '.$periode->etudiant->num_inscription : '' }}</td>
                                <td class="px-3 py-2">{{ $periode->opened_at->timezone(config('inscriptions.fuseau'))->format('d/m/Y H:i') }}</td>
                                <td class="px-3 py-2">{{ $periode->closed_at->timezone(config('inscriptions.fuseau'))->format('d/m/Y H:i') }}</td>
                                <td class="px-3 py-2"><x-status-badge :statut="$periode->statutCalcule()" /></td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ \Illuminate\Support\Str::limit((string) $periode->motif, 60) }}</td>
                                <td class="px-3 py-2">
                                    @if($periode->statut === 'active')
                                        <form method="post" action="{{ route('admin.periodes.revoquer', $periode) }}" class="inline">
                                            @csrf
                                            <input type="hidden" name="motif" value="Révocation administrative depuis le centre." />
                                            <button type="submit" class="text-xs font-bold text-rose-700 hover:underline">Révoquer</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $periodes->links() }}
        @endif
    </x-card></div>
</div>
@endsection
