@extends('layouts.app')

@section('title', 'Aménagements — Parcours')

@section('content')
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <x-page-header :titre="ucfirst($categorie)" description="Workflow proposé → décidé, audité, historique préservé." :fil="['Parcours' => route('admin.parcours.index'), ucfirst($categorie) => url()->current()]" />
        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                <thead><tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                    <th class="px-5 py-3 font-bold">Étudiant</th><th class="px-5 py-3 font-bold">Objet</th><th class="px-5 py-3 font-bold">Statut</th><th class="px-5 py-3 font-bold">Décision</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($elements as $element)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3 font-bold">{{ $element->etudiant->nom }} {{ $element->etudiant->prenom }}</td>
                            <td class="px-5 py-3">{{ $element->cible_libelle ?? $element->nouveau_libelle ?? $element->origine_libelle ?? '—' }}</td>
                            <td class="px-5 py-3"><x-status-badge :statut="$element->statut" /></td>
                            <td class="px-5 py-3">{{ $element->decision ?? $element->decision_admission ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $elements->links() }}</div>
    </div>
@endsection
