<div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
    <x-stat-card libelle="Moyenne générale" :valeur="$academique['synthese']['moyenne_generale'] ?? '—'" />
    <x-stat-card libelle="Crédits" :valeur="$academique['synthese']['credits_obtenus'] . '/' . $academique['synthese']['credits_total']" couleur="emerald" />
    <x-stat-card libelle="Années validées" :valeur="$academique['synthese']['annees_validees'] . '/' . $academique['synthese']['annees_total']" />
    <x-stat-card libelle="Décisions jury" :valeur="$academique['decisions']->count()" couleur="amber" />
</div>

<form method="GET" action="{{ route('admin.etudiants.archive', $etudiant) }}" class="mt-4 flex flex-col sm:flex-row gap-2">
    <input type="hidden" name="onglet" value="academique" />
    <select name="annee_universitaire_id" aria-label="Année" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <option value="">Toutes années</option>
        @foreach($annees as $annee)
            <option value="{{ $annee->id }}" @selected((string) ($filtres['annee_universitaire_id'] ?? '') === (string) $annee->id)>{{ $annee->libelle }}</option>
        @endforeach
    </select>
    <select name="session" aria-label="Session" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <option value="">Toutes sessions</option>
        @foreach(['normale' => 'Principale', 'rattrapage' => 'Rattrapage'] as $valeur => $libelle)
            <option value="{{ $valeur }}" @selected(($filtres['session'] ?? '') === $valeur)>{{ $libelle }}</option>
        @endforeach
    </select>
    <select name="verrouillee" aria-label="Verrouillage" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <option value="">Verrouillées + en cours</option>
        <option value="oui" @selected(($filtres['verrouillee'] ?? '') === 'oui')>Verrouillées</option>
        <option value="non" @selected(($filtres['verrouillee'] ?? '') === 'non')>En cours</option>
    </select>
    <x-btn size="sm" variant="secondary" type="submit">Filtrer</x-btn>
</form>

@foreach($academique['annees'] as $bloc)
    <section aria-label="Année {{ $bloc['inscription']->anneeUniversitaire?->libelle }}" class="mt-4 ds-card overflow-hidden">
        <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-3">
            <h2 class="font-black text-slate-900">{{ $bloc['inscription']->anneeUniversitaire?->libelle ?? '—' }}
                <span class="font-normal text-slate-500">· {{ $bloc['inscription']->niveau ?? '—' }} · Moyenne {{ $bloc['inscription']->moyenne_annuelle ?? '—' }} · Crédits {{ $bloc['inscription']->credits_obtenus ?? 0 }}/{{ $bloc['inscription']->credits_total ?? 0 }}</span></h2>
        </div>
        @foreach($bloc['semestres'] as $semestre)
            <div class="border-b border-slate-100 px-5 py-3 last:border-0">
                <h3 class="text-sm font-black text-slate-800">Semestre {{ $semestre->numero }} — Moyenne {{ $semestre->moyenne ?? '—' }} · {{ $semestre->credits_obtenus ?? 0 }}/{{ $semestre->credits_total ?? 0 }} crédits
                    @if($semestre->decision)<x-status-badge :statut="$semestre->decision" />@endif</h3>
            </div>
        @endforeach
        @foreach($bloc['ues'] as $paquet)
            <div class="border-b border-slate-100 px-5 py-3 last:border-0">
                <h3 class="text-sm font-black text-primary-900">{{ $paquet['ue']?->code ?? 'Sans UE' }} — {{ $paquet['ue']?->nom ?? '' }}
                    <span class="font-normal text-slate-500">· Moy. {{ $paquet['moyenne'] ?? '—' }} · {{ $paquet['credits'] }} crédits</span></h3>
                <div class="mt-2 overflow-x-auto admin-table-scroll">
                    <table class="w-full min-w-[640px] text-left text-xs sm:text-sm">
                        <thead>
                            <tr class="text-[11px] uppercase tracking-wider text-slate-400">
                                <th class="py-1.5 pr-3">ECUE</th><th class="py-1.5 pr-3">Enseignant</th>
                                <th class="py-1.5 pr-3">Note</th><th class="py-1.5 pr-3">Session</th>
                                <th class="py-1.5 pr-3">Crédits</th><th class="py-1.5">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($paquet['ecues'] as $ligne)
                                <tr>
                                    <td class="py-1.5 pr-3"><span class="font-mono font-bold">{{ $ligne['matiere']?->code }}</span> {{ $ligne['matiere']?->nom }}</td>
                                    <td class="py-1.5 pr-3 text-slate-600">{{ $ligne['matiere']?->responsable?->nomComplet() ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 tabular-nums font-bold">{{ $ligne['note']?->note ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 text-slate-600">{{ $ligne['note']?->session ?? '—' }}</td>
                                    <td class="py-1.5 pr-3 tabular-nums">{{ $ligne['note']?->credits ?? $ligne['matiere']?->credits ?? '—' }}</td>
                                    <td class="py-1.5">@if($ligne['note'] !== null)<x-status-badge :statut="$ligne['note']->estVerrouillee() ? 'verrouillee' : 'en_cours'" />@else<span class="text-slate-400">—</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    </section>
@endforeach

@if($academique['annees']->isEmpty())
    <div class="mt-4"><x-empty-state message="Aucune année pour ces filtres." /></div>
@endif

<section aria-label="Notes paginées" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Toutes les notes ({{ $notes->total() }})</h2>
    <div class="mt-2 overflow-x-auto admin-table-scroll">
        <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
            <thead>
                <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                    <th class="px-3 py-2">Année</th><th class="px-3 py-2">Semestre</th><th class="px-3 py-2">UE</th>
                    <th class="px-3 py-2">ECUE</th><th class="px-3 py-2">Session</th><th class="px-3 py-2">Note</th>
                    <th class="px-3 py-2">Coef</th><th class="px-3 py-2">Crédits</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($notes as $note)
                    <tr>
                        <td class="px-3 py-2 text-slate-600">{{ $note->inscriptionAcademique?->anneeUniversitaire?->libelle ?? '—' }}</td>
                        <td class="px-3 py-2">S{{ $note->semestre?->numero ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono">{{ $note->matiere?->ue?->code ?? '—' }}</td>
                        <td class="px-3 py-2"><span class="font-mono font-bold">{{ $note->matiere?->code }}</span> {{ $note->matiere?->nom }}</td>
                        <td class="px-3 py-2 text-slate-600">{{ $note->session }}</td>
                        <td class="px-3 py-2 tabular-nums font-bold">{{ $note->note }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ $note->coefficient }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ $note->credits }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-2">{{ $notes->links() }}</div>
</section>

<section aria-label="Délibérations" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Décisions de jury ({{ $academique['decisions']->count() }})</h2>
    @if($academique['decisions']->isNotEmpty())
        <ul class="mt-2 space-y-1.5 text-sm">
            @foreach($academique['decisions'] as $decision)
                <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                    <span><strong>{{ $decision->deliberation?->anneeUniversitaire?->libelle ?? '—' }}</strong>
                        <span class="text-slate-500">· Moy. {{ $decision->moyenne ?? '—' }} · {{ $decision->credits_obtenus }}/{{ $decision->credits_total }} crédits{{ $decision->publie ? '' : ' · non publiée' }}</span></span>
                    <span class="flex items-center gap-2">
                        <x-status-badge :statut="$decision->decision" />
                        @if($decision->deliberation_id)<a href="{{ route('admin.evaluations.deliberations.show', $decision->deliberation_id) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Jury →</a>@endif
                    </span>
                </li>
            @endforeach
        </ul>
    @else
        <div class="mt-2"><x-empty-state message="Aucune décision de jury." /></div>
    @endif
</section>
