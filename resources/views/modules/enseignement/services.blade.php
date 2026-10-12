@extends('layouts.app')

@section('title', 'Services enseignants')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Services enseignants" description="Charges par type + seuils (min/cible/max) — calculés serveur."
            :fil="['Enseignements' => route('admin.enseignements.tableau-bord'), 'Services' => '#']" />

        <form method="GET" action="{{ route('admin.enseignements.services') }}" class="mt-4 flex flex-col sm:flex-row gap-2">
            <select name="annee_universitaire_id" aria-label="Année" class="rounded-xl border border-slate-300 px-3 py-2.5 text-sm min-h-[44px]">
                <option value="">Toutes années</option>
                @foreach($annees as $annee)
                    <option value="{{ $annee->id }}" @selected($anneeId == $annee->id)>{{ $annee->libelle }}</option>
                @endforeach
            </select>
            <x-btn type="submit" variant="secondary">Filtrer</x-btn>
        </form>

        <section aria-label="Charge par enseignant" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th scope="col" class="px-5 py-3 font-bold">Enseignant</th>
                        <th scope="col" class="px-5 py-3 font-bold">CM</th>
                        <th scope="col" class="px-5 py-3 font-bold">TD</th>
                        <th scope="col" class="px-5 py-3 font-bold">TP</th>
                        <th scope="col" class="px-5 py-3 font-bold">Total</th>
                        <th scope="col" class="px-5 py-3 font-bold">Seuils</th>
                        <th scope="col" class="px-5 py-3 font-bold">Statut</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($tableau as $ligne)
                        <tr class="hover:bg-slate-50/60">
                            <td class="px-5 py-3 font-bold">{{ $ligne['teacher']->nomComplet() }}</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['charge']['cm'] }}h</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['charge']['td'] }}h</td>
                            <td class="px-5 py-3 tabular-nums">{{ $ligne['charge']['tp'] }}h</td>
                            <td class="px-5 py-3 tabular-nums font-black">{{ $ligne['charge']['total'] }}h</td>
                            <td class="px-5 py-3 text-[11px] text-slate-500">{{ $ligne['seuils']['min'] }}/{{ $ligne['seuils']['cible'] }}/{{ $ligne['seuils']['max'] }}</td>
                            <td class="px-5 py-3 text-xs font-black {{ $ligne['statut'] === 'surcharge' ? 'text-rose-700' : ($ligne['statut'] === 'sous-charge' ? 'text-amber-700' : 'text-emerald-700') }}">
                                {{ $ligne['statut'] === 'surcharge' ? '⚠ Surcharge' : ($ligne['statut'] === 'sous-charge' ? '▼ Sous-charge' : '✓') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        @can('enseignements.manage')
            <section aria-label="Seuils de service" class="mt-4 ds-card p-5">
                <h2 class="text-base font-black text-slate-900">Seuils (§20)</h2>
                <form method="POST" action="{{ route('admin.enseignements.seuils.store') }}" class="mt-3 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-2">
                    @csrf
                    <select name="grade_id" class="rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                        <option value="">Global (grade)</option>
                        @foreach($grades as $grade)
                            <option value="{{ $grade->id }}">{{ $grade->nom }}</option>
                        @endforeach
                    </select>
                    <input type="number" name="service_min" step="0.5" min="0" required placeholder="Min (h)" class="rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                    <input type="number" name="service_cible" step="0.5" min="0" required placeholder="Cible (h)" class="rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                    <input type="number" name="service_max" step="0.5" min="0" required placeholder="Max (h)" class="rounded-xl border border-slate-300 px-3 py-2 text-xs min-h-[44px]">
                    <x-btn type="submit" variant="secondary">Ajouter</x-btn>
                </form>
                <ul class="mt-3 space-y-1.5 text-xs">
                    @foreach($seuils as $seuil)
                        <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3 py-2">
                            <span>{{ $seuil->grade?->nom ?? 'Global' }} · {{ $seuil->faculte?->code ?? 'global' }} · {{ $seuil->anneeUniversitaire?->libelle ?? 'toutes' }} → min {{ $seuil->service_min }} / max {{ $seuil->service_max }}</span>
                            <form method="POST" action="{{ route('admin.enseignements.seuils.destroy', $seuil) }}" class="inline">
                                @csrf @method('DELETE')
                                <button type="submit" aria-label="Supprimer" class="text-rose-700 font-bold min-h-[32px] min-w-[32px]">✕</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endcan
    </div>
@endsection
