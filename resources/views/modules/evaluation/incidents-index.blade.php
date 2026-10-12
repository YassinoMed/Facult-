@extends('layouts.app')

@section('title', 'Incidents — ' . $epreuve->matiere->code)

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Incidents — ' . $epreuve->matiere->libelle_complet" description="Constats post-hoc : le verrou de l’épreuve ne bloque jamais le signalement."
            :fil="['Épreuve' => route('admin.evaluations.epreuves.show', $epreuve), 'Incidents' => '#']" />

        @if(session('succes'))
            <x-alert type="success" class="mt-4">{{ session('succes') }}</x-alert>
        @endif

        @if($errors->any())
            <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
        @endif

        @can('update', $epreuve)
            <form method="post" action="{{ route('admin.evaluations.epreuves.incidents.store', $epreuve) }}" class="mt-4 ds-card p-4 space-y-3">
                @csrf
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs font-bold text-slate-600">Type
                        <select name="type" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="retard">Retard</option>
                            <option value="fraude">Fraude</option>
                            <option value="materiel">Matériel</option>
                            <option value="copie_manquante">Copie manquante</option>
                            <option value="probleme_salle">Problème de salle</option>
                            <option value="autre">Autre</option>
                        </select>
                    </label>
                    <label class="text-xs font-bold text-slate-600">Candidat concerné (optionnel)
                        <select name="etudiant_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="">— Aucun (incident général) —</option>
                            @foreach($epreuve->candidats ?? [] as $candidat)
                                <option value="{{ $candidat->etudiant_id }}">{{ $candidat->etudiant?->num_inscription ?? $candidat->etudiant_id }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <label class="block text-xs font-bold text-slate-600">Description
                    <textarea name="description" required maxlength="2000" rows="3" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Constat factuel, sans données sensibles inutiles."></textarea>
                </label>
                <div class="flex justify-end">
                    <x-btn type="submit">Signaler l’incident</x-btn>
                </div>
            </form>
        @endcan

        <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($incidents->isNotEmpty())
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            <th scope="col" class="px-4 py-3 font-bold">Date</th>
                            <th scope="col" class="px-4 py-3 font-bold">Type</th>
                            <th scope="col" class="px-4 py-3 font-bold">Description</th>
                            <th scope="col" class="px-4 py-3 font-bold">Statut</th>
                            <th scope="col" class="px-4 py-3 font-bold"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($incidents as $incident)
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 tabular-nums">{{ $incident->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs">{{ $incident->type }}</td>
                                <td class="px-4 py-3 text-xs">{{ $incident->description }}</td>
                                <td class="px-4 py-3"><x-status-badge :statut="$incident->statut" /></td>
                                <td class="px-4 py-3 text-right">
                                    @can('update', $epreuve)
                                        @if($incident->statut === 'signale')
                                            <form method="POST" action="{{ route('admin.evaluations.incidents.transiter', [$incident, 'en_cours']) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="font-bold text-primary-800 underline underline-offset-2 hover:text-primary-600">Prendre en charge</button>
                                            </form>
                                        @endif
                                        @if(! $incident->estClos())
                                            <form method="POST" action="{{ route('admin.evaluations.incidents.transiter', [$incident, 'resolu']) }}" class="inline ml-2">
                                                @csrf
                                                <button type="submit" class="font-bold text-emerald-700 underline underline-offset-2 hover:text-emerald-500">Résoudre</button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-5"><x-empty-state message="Aucun incident signalé pour cette épreuve." /></div>
            @endif
        </div>
    </div>
@endsection
