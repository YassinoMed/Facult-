@extends('layouts.app')

@section('title', 'Table de correspondance des compostages — ' . $epreuve->matiere->code)

@section('content')
    <div class="mx-auto max-w-5xl px-4 sm:px-6 py-8 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <x-page-header :titre="'Correspondance — ' . $epreuve->matiere->libelle_complet"
                description="Levée d'anonymat réservée à l'administration des examens · Consultation tracée sans altération"
                :fil="['Épreuve' => route('admin.evaluations.epreuves.show', $epreuve), 'Saisie des notes' => route('admin.evaluations.notes.saisie', $epreuve), 'Correspondance' => '#']" />

            <a href="{{ route('admin.evaluations.notes.saisie', $epreuve) }}"
               class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                ← Retour à la saisie anonyme
            </a>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-amber-50/80 p-4 text-xs text-amber-900 shadow-sm flex items-start gap-3">
            <span class="font-bold text-amber-600 text-base" aria-hidden="true">🔒</span>
            <div>
                <p class="font-bold">Registre confidentiel de levée d'anonymat (§13/§18)</p>
                <p class="mt-0.5 text-amber-800">
                    Cette vue associe les numéros de compostage aux identités réelles des étudiants.
                    Chaque consultation est enregistrée dans le journal d'audit de sécurité avec l'identifiant de votre compte, votre adresse IP et l'horodatage.
                </p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-200 bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                        <th scope="col" class="px-4 py-3 font-bold">N° Compostage</th>
                        <th scope="col" class="px-4 py-3 font-bold">Matricule</th>
                        <th scope="col" class="px-4 py-3 font-bold">CIN</th>
                        <th scope="col" class="px-4 py-3 font-bold">Nom et Prénom</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($compostages as $compostage)
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="px-4 py-3 font-mono font-bold text-slate-900 tracking-wide">
                                {{ $compostage->numero }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">
                                {{ $compostage->etudiant?->num_inscription ?? '—' }}
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">
                                {{ $compostage->etudiant?->cin ?? '—' }}
                            </td>
                            <td class="px-4 py-3 font-medium text-slate-900">
                                {{ $compostage->etudiant?->nom_complet ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">
                                Aucun compostage enregistré pour cette épreuve.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
