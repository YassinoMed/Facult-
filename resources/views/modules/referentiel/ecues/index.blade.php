@extends('layouts.app')

@section('title', 'ECUE — Référentiel LMD')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="ECUE rattachées" description="Matières liées à une UE : volumes, évaluation, prérequis."
            :fil="['Référentiel' => route('admin.referentiel.tableau-bord'), 'ECUE' => '#']" />

        <form method="GET" action="{{ route('admin.referentiel.ecues.index') }}" role="search" class="mt-4 flex flex-col sm:flex-row gap-2">
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Code, nom…"
                   aria-label="Rechercher une ECUE" class="flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm min-h-[44px]">
            <x-btn type="submit" variant="secondary">Chercher</x-btn>
        </form>

        <section aria-label="Liste des ECUE" class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if($ecues->isNotEmpty())
                <div x-data="triEcues()" data-tri-url="{{ route('admin.referentiel.reordonner') }}" data-tri-csrf="{{ csrf_token() }}">
                <p x-show="message" x-text="message" role="status" class="px-5 py-2 text-xs font-bold text-emerald-700"></p>
                <table class="w-full min-w-[680px] text-left text-xs sm:text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                            @can('referentiel.manage')<th scope="col" class="px-2 py-3 font-bold"><span class="sr-only">Déplacer</span></th>@endcan
                            <th scope="col" class="px-5 py-3 font-bold">Code</th>
                            <th scope="col" class="px-5 py-3 font-bold">Nom</th>
                            <th scope="col" class="px-5 py-3 font-bold">UE</th>
                            <th scope="col" class="px-5 py-3 font-bold">Crédits</th>
                            <th scope="col" class="px-5 py-3 font-bold">Volume</th>
                            <th scope="col" class="px-5 py-3 font-bold"><span class="sr-only">Ouvrir</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($ecues as $ecue)
                            <tr class="hover:bg-slate-50/60" draggable="false" data-tri-id="{{ $ecue->id }}"
                                @can('referentiel.manage') x-on:dragstart="glisser($event)" x-on:dragover.prevent="survol($event)" x-on:drop="deposer($event)" x-on:dragend="fin()" @endcan>
                                @can('referentiel.manage')
                                    <td class="px-2 py-3 cursor-grab text-slate-400" draggable="true" aria-label="Déplacer {{ $ecue->code }}" title="Glisser pour réordonner">⋮⋮</td>
                                @endcan
                                <td class="px-5 py-3 font-mono font-bold text-slate-800">{{ $ecue->code }}</td>
                                <td class="px-5 py-3 font-bold text-slate-900">{{ $ecue->nom }}</td>
                                <td class="px-5 py-3 font-mono text-slate-600">{{ $ecue->ue->code }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $ecue->credits }}</td>
                                <td class="px-5 py-3 tabular-nums text-slate-700">{{ $ecue->volumeTotal() }}h</td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('admin.referentiel.ecues.show', $ecue) }}" class="font-bold text-primary-800 underline underline-offset-2">Ouvrir</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
                <div class="px-5 py-3 border-t border-slate-100">{{ $ecues->links() }}</div>
            @else
                <div class="p-5"><x-empty-state message="Aucune ECUE rattachée. Rattachez des matières aux UE." /></div>
            @endif
        </section>
        <script>
        function triEcues() {
            return {
                message: '',
                entraine: null,
                glisser(e) { this.entraine = e.target.closest('tr'); },
                survol(e) {
                    const ligne = e.target.closest('tr');
                    if (ligne !== null && ligne !== this.entraine) {
                        const corps = ligne.parentNode;
                        const lignes = [...corps.querySelectorAll('tr')];
                        const cible = lignes.indexOf(ligne) < lignes.indexOf(this.entraine)
                            ? ligne : ligne.nextSibling;
                        corps.insertBefore(this.entraine, cible);
                    }
                },
                deposer(e) {
                    e.preventDefault();
                    const conteneur = this.$el;
                    const lignes = [...conteneur.querySelectorAll('tbody tr[data-tri-id]')];
                    const base = Math.min(...lignes.map((l, i) => i));
                    const ordres = {};
                    lignes.forEach((l, i) => { ordres[l.dataset.triId] = base + i; });
                    fetch(conteneur.dataset.triUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': conteneur.dataset.triCsrf },
                        body: JSON.stringify({ type: 'matiere', ordres }),
                    }).then((r) => r.json()).then((d) => { this.message = d.message ?? 'Ordre enregistré.'; })
                      .catch(() => { this.message = 'Échec de sauvegarde.'; });
                },
                fin() { this.entraine = null; },
            };
        }
        </script>
    </div>
@endsection
