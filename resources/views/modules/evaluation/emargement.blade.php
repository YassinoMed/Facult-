@extends('layouts.app')

@section('title', 'Feuille d\'émargement — ' . $epreuve->matiere->code)

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header :titre="'Émargement — ' . $epreuve->matiere->libelle_complet"
            :description="'Le ' . $epreuve->date->format('d/m/Y') . ' · ' . substr($epreuve->heure_debut, 0, 5) . ' → ' . substr($epreuve->heure_fin, 0, 5) . ' · Salle : ' . ($epreuve->salle?->code ?? '—')"
            :fil="['Épreuves' => route('admin.evaluations.epreuves.index'), $epreuve->matiere->code => route('admin.evaluations.epreuves.show', $epreuve), 'Émargement' => '#']">
            <x-slot:actions>
                <x-btn variant="secondary" href="{{ route('admin.evaluations.epreuves.convocation', $epreuve) }}">
                    Imprimer PDF
                </x-btn>
                <x-btn variant="ghost" href="{{ route('admin.evaluations.epreuves.show', $epreuve) }}">
                    Retour à l'épreuve
                </x-btn>
            </x-slot:actions>
        </x-page-header>

        @if($epreuve->estVerrouillee())
            <x-alert type="warning" class="mt-4">
                Cette épreuve est verrouillée ({{ $epreuve->statut }}). L'émargement ne peut plus être modifié.
            </x-alert>
        @endif

        <form method="post" action="{{ route('admin.evaluations.epreuves.emargement.store', $epreuve) }}" class="mt-6">
            @csrf

            <div class="ds-card overflow-hidden bg-white rounded-xl border border-slate-200">
                <div class="p-4 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-3 text-xs">
                    <div class="flex items-center gap-4 text-slate-600">
                        <span>Total candidats : <strong class="text-slate-900">{{ $candidats->count() }}</strong></span>
                        <span>Présents : <strong class="text-emerald-700">{{ $candidats->where('statut', 'present')->count() }}</strong></span>
                        <span>Absents : <strong class="text-rose-700">{{ $candidats->whereIn('statut', ['absent', 'absent_justifie', 'absent_injustifie'])->count() }}</strong></span>
                        <span>Retards : <strong class="text-amber-700">{{ $candidats->where('statut', 'retard')->count() }}</strong></span>
                    </div>

                    @unless($epreuve->estVerrouillee())
                        <x-btn type="submit">Enregistrer l'émargement</x-btn>
                    @endunless
                </div>

                @if($candidats->isEmpty())
                    <div class="p-8">
                        <x-empty-state message="Aucun candidat n'a encore été convoqué pour cette épreuve." />
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50/75 text-left text-[11px] uppercase tracking-wider text-slate-500 border-b border-slate-200">
                                <tr>
                                    <th class="px-4 py-2.5">#</th>
                                    <th class="px-4 py-2.5">Matricule</th>
                                    <th class="px-4 py-2.5">Nom & Prénom</th>
                                    <th class="px-4 py-2.5">Salle / Place</th>
                                    <th class="px-4 py-2.5">Statut de présence</th>
                                    <th class="px-4 py-2.5">Justification / Observation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($candidats as $index => $candidat)
                                    <tr class="hover:bg-slate-50/50">
                                        <td class="px-4 py-2.5 text-xs text-slate-400 tabular-nums">{{ $index + 1 }}</td>
                                        <td class="px-4 py-2.5 text-xs font-mono font-bold text-slate-700">{{ $candidat->etudiant->num_inscription ?? '—' }}</td>
                                        <td class="px-4 py-2.5 font-medium text-slate-900">
                                            {{ $candidat->etudiant->nom_complet }}
                                            <input type="hidden" name="presences[{{ $index }}][candidat_id]" value="{{ $candidat->id }}" />
                                        </td>
                                        <td class="px-4 py-2.5 text-xs text-slate-600">
                                            <span class="font-bold text-slate-800">{{ $candidat->salleEffective()?->code ?? $epreuve->salle?->code ?? '—' }}</span>
                                            @if($candidat->place)
                                                <span class="text-slate-400">·</span>
                                                <span class="text-indigo-600 font-semibold">{{ $candidat->place }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <select name="presences[{{ $index }}][statut]"
                                                @disabled($epreuve->estVerrouillee())
                                                class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs bg-white font-medium focus:border-indigo-500">
                                                <option value="present" @selected($candidat->statut === 'present')>Présent</option>
                                                <option value="absent" @selected($candidat->statut === 'absent')>Absent</option>
                                                <option value="retard" @selected($candidat->statut === 'retard')>En retard</option>
                                                <option value="excuse" @selected($candidat->statut === 'excuse')>Excusé</option>
                                                <option value="absent_justifie" @selected($candidat->statut === 'absent_justifie')>Absent justifié</option>
                                                <option value="absent_injustifie" @selected($candidat->statut === 'absent_injustifie')>Absent injustifié</option>
                                            </select>
                                        </td>
                                        <td class="px-4 py-2.5">
                                            <input type="text"
                                                name="presences[{{ $index }}][justification]"
                                                value="{{ $candidat->justification }}"
                                                placeholder="Optionnel..."
                                                @disabled($epreuve->estVerrouillee())
                                                class="w-full rounded-lg border border-slate-200 px-2.5 py-1 text-xs bg-white focus:border-indigo-500" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @unless($epreuve->estVerrouillee())
                        <div class="p-4 bg-slate-50 border-t border-slate-200 flex justify-end">
                            <x-btn type="submit">Enregistrer l'émargement</x-btn>
                        </div>
                    @endunless
                @endif
            </div>
        </form>
    </div>
@endsection
