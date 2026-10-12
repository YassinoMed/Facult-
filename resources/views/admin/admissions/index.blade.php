@extends('layouts.app')

@section('title', 'Admissions')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Admissions" description="Actes d’admission externes — consommés par la progression LMD (fin de cycle, Master, réorientation entrante)."
            :fil="['Scolarité' => '#', 'Admissions' => '#']" />

        <div class="mt-4 flex flex-wrap gap-2">
            @can('admissions.manage')
                <a href="{{ route('admin.admissions.create') }}"
                   class="inline-flex items-center gap-2 rounded-xl bg-primary-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-900">
                    + Nouvelle admission
                </a>
            @endcan
        </div>

        <div class="mt-4 ds-card overflow-hidden">
            @if($admissions->isEmpty())
                <x-empty-state :message="'Aucune admission enregistrée — un acte d’admission actif permet la progression vers un programme cible explicite.'" />
            @else
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-2">Étudiant</th>
                            <th class="px-4 py-2">Programme cible</th>
                            <th class="px-4 py-2">Niveau</th>
                            <th class="px-4 py-2">Année</th>
                            <th class="px-4 py-2">Décision</th>
                            <th class="px-4 py-2">Statut</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($admissions as $admission)
                            <tr>
                                <td class="px-4 py-2 font-bold">{{ $admission->etudiant->nom }} {{ $admission->etudiant->prenom }}</td>
                                <td class="px-4 py-2">{{ $admission->programme->nom }}</td>
                                <td class="px-4 py-2">{{ $admission->niveau?->code ?? '—' }}</td>
                                <td class="px-4 py-2 tabular-nums">{{ $admission->anneeUniversitaire->libelle }}</td>
                                <td class="px-4 py-2">{{ $admission->decision === 'refuse' ? 'Refusée' : ($admission->decision === 'admis_conditionnel' ? 'Admis (conditionnel)' : 'Admis') }}</td>
                                <td class="px-4 py-2">
                                    @if($admission->consumed_at !== null)
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>Consommée
                                        </span>
                                    @elseif($admission->decision === 'refuse')
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-rose-200 bg-rose-50 px-2.5 py-0.5 text-[11px] font-bold text-rose-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-rose-500" aria-hidden="true"></span>Refusée
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-0.5 text-[11px] font-bold text-sky-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-sky-500" aria-hidden="true"></span>Active
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right">
                                    @can('admissions.manage')
                                        @if($admission->consumed_at === null)
                                            <form method="post" action="{{ route('admin.admissions.destroy', $admission) }}" class="inline"
                                                  onsubmit="return confirm('Supprimer cette admission (non consommée) ?');">
                                                @method('delete')
                                                @csrf
                                                <button type="submit" class="text-xs font-bold text-rose-600 hover:underline">Supprimer</button>
                                            </form>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $admissions->links() }}</div>
            @endif
        </div>
    </div>
@endsection
