@extends('layouts.app')

@section('title', $groupe->code . ' — Groupe')

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8">
        <x-page-header :titre="$groupe->code . ' — ' . $groupe->nom" :description="$groupe->programme?->nom ?? $groupe->filiere ?? ''"
            :fil="['Groupes' => route('admin.groupes.index'), $groupe->code => '#']">
            <x-slot:actions>
                <x-quick-actions contexte="groupe.show" :sujet="$groupe" />
                <x-btn variant="secondary" href="{{ route('admin.listes.preview', ['groupe_id' => $groupe->id]) }}">Liste / Export</x-btn>
                <x-btn variant="secondary" href="{{ route('admin.groupes.etudiants', $groupe) }}">Étudiants</x-btn>
                @can('data.export')
                    <x-btn variant="ghost" href="{{ route('admin.listes.imprimer', ['groupe_id' => $groupe->id]) }}">Imprimer</x-btn>
                @endcan
                @can('archives.update')
                    <x-btn variant="ghost" href="{{ route('admin.affectations.masse') }}">Affecter en masse</x-btn>
                @endcan
                @can('data.import')
                    <x-btn variant="ghost" href="{{ route('admin.donnees.import.form', 'affectations-groupes') }}">Importer</x-btn>
                @endcan
                @can('groupes.manage')
                    <x-btn variant="secondary" href="{{ route('admin.groupes.edit', $groupe) }}">Modifier</x-btn>
                @endcan
            </x-slot:actions>
        </x-page-header>

        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3">
            <x-stat-card libelle="Effectif / Capacité" :valeur="$groupe->affectations_actives . '/' . ($groupe->capacite ?? '∞')" />
            <x-stat-card libelle="Hommes / Femmes" :valeur="$repartition['hommes'] . ' / ' . $repartition['femmes']" />
            <x-stat-card libelle="Programme" :valeur="$groupe->programme?->code ?? '—'" />
            <x-stat-card libelle="Niveau / Année" :valeur="($groupe->niveauRef?->code ?? $groupe->niveau) . ' · ' . ($groupe->anneeUniversitaire?->libelle ?? '—')" />
        </div>

        @if($alertes !== [])
            <div class="mt-4"><x-alert type="warning" titre="Points de vigilance ({{ count($alertes) }})">
                <ul class="list-disc list-inside mt-1">@foreach($alertes as $alerte)<li>{{ $alerte }}</li>@endforeach</ul>
            </x-alert></div>
        @endif

        <x-tabs :onglets="['etudiants' => 'Étudiants (' . $groupe->etudiants->count() . ')', 'planning' => 'Planning (' . $groupe->schedules->count() . ')', 'enseignement' => 'Enseignement (' . $enseignements->count() . ')', 'sous-groupes' => 'Sous-groupes (' . $sousGroupes->count() . ')']" class="mt-4">
            <x-slot:panneau_etudiants>
                @if($groupe->etudiants->isNotEmpty())
                    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                        @foreach($groupe->etudiants as $etudiant)
                            <li class="rounded-xl border border-slate-100 px-3.5 py-2.5">
                                <a href="{{ route('admin.etudiants.show', $etudiant) }}" class="font-bold text-primary-800 underline underline-offset-2">{{ $etudiant->nom_complet }}</a>
                                <span class="block font-mono text-[11px] text-slate-500">{{ $etudiant->num_inscription }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty-state message="Aucun étudiant affecté." />
                @endif
            </x-slot:panneau_etudiants>
            <x-slot:panneau_planning>
                @if($groupe->schedules->isNotEmpty())
                    <ul class="space-y-1.5 text-sm">
                        @foreach($groupe->schedules as $planning)
                            <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                                <span><strong>{{ $planning->nom }}</strong> <span class="text-slate-500">· {{ $planning->statut ?? 'brouillon' }}</span></span>
                                <a href="{{ route('admin.emplois-du-temps.show', $planning) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Ouvrir →</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty-state message="Aucun planning rattaché." />
                @endif
            </x-slot:panneau_planning>
            <x-slot:panneau_enseignement>
                @if($enseignements->isNotEmpty())
                    <ul class="space-y-1.5 text-sm">
                        @foreach($enseignements as $ens)
                            <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                                <span><strong class="font-mono">{{ $ens->matiere->code }}</strong> — {{ $ens->matiere->nom }}
                                    <span class="text-slate-500">· {{ $ens->type?->code }} · {{ $ens->volumeTotal() }}h</span></span>
                                <a href="{{ route('admin.enseignements.show', $ens) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Fiche →</a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-empty-state message="Aucun enseignement rattaché." />
                @endif
            </x-slot:panneau_enseignement>
            <x-slot:panneau_sous-groupes>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm text-slate-500">{{ $sousGroupes->count() }} sous-groupe(s) TD/TP.</p>
                    @can('groupes.manage')
                        <a href="{{ route('admin.sous-groupes.index', $groupe) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Gérer →</a>
                    @endcan
                </div>
                @if($sousGroupes->isNotEmpty())
                    <ul class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
                        @foreach($sousGroupes as $sg)
                            <li class="rounded-xl border border-slate-100 px-3.5 py-2.5">
                                <strong class="font-mono">{{ $sg->code }}</strong> — {{ $sg->nom }}
                                <span class="text-slate-500">· {{ $sg->type }} · {{ $sg->capacite ?? '—' }} places</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 text-xs text-slate-500">Aucun sous-groupe (TD/TP).</p>
                @endif
            </x-slot:panneau_sous-groupes>
        </x-tabs>
    </div>
@endsection
