<form method="GET" action="{{ route('admin.etudiants.archive', $etudiant) }}" class="mt-4 flex flex-col sm:flex-row gap-2">
    <input type="hidden" name="onglet" value="documents" />
    <input type="search" name="search" value="{{ $filtres['search'] ?? '' }}" placeholder="Titre, référence, type…" aria-label="Rechercher" class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm min-h-[44px]">
    <select name="annee_universitaire_id" aria-label="Année" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <option value="">Toutes années</option>
        @foreach($annees as $annee)
            <option value="{{ $annee->id }}" @selected((string) ($filtres['annee_universitaire_id'] ?? '') === (string) $annee->id)>{{ $annee->libelle }}</option>
        @endforeach
    </select>
    <select name="type" aria-label="Type" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <option value="">Tous types</option>
        @foreach(['releve_notes' => 'Relevé', 'attestation' => 'Attestation', 'diplome' => 'Diplôme', 'certificat' => 'Certificat', 'convention' => 'Convention', 'autre' => 'Autre'] as $valeur => $libelle)
            <option value="{{ $valeur }}" @selected(($filtres['type'] ?? '') === $valeur)>{{ $libelle }}</option>
        @endforeach
    </select>
    <x-btn size="sm" variant="secondary" type="submit">Filtrer</x-btn>
</form>

<section aria-label="Diplômes" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Diplômes (projection : jury + pièces)</h2>
    @if($diplomes['decisions']->isNotEmpty())
        <ul class="mt-2 space-y-1.5 text-sm">
            @foreach($diplomes['decisions'] as $decision)
                <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                    <span><strong>{{ $decision->deliberation?->programme?->nom ?? 'Diplôme' }}</strong>
                        <span class="text-slate-500">· {{ $decision->deliberation?->anneeUniversitaire?->libelle ?? '—' }} · Moy. {{ $decision->moyenne ?? '—' }}</span></span>
                    <x-status-badge :statut="$decision->decision" />
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-2 text-xs text-slate-500">Aucune décision d'admission.</p>
    @endif
    @if($diplomes['documents']->isNotEmpty())
        <h3 class="mt-3 text-xs font-black uppercase tracking-wider text-slate-500">Pièces diplôme ({{ $diplomes['documents']->count() }})</h3>
        <ul class="mt-1.5 space-y-1.5 text-sm">
            @foreach($diplomes['documents'] as $document)
                <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                    <span>{{ $document['titre'] }} <span class="text-slate-400 text-xs">· {{ $document['source'] }}</span></span>
                    @if($document['telechargement'] !== null)<a href="{{ $document['telechargement'] }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Télécharger</a>@endif
                </li>
            @endforeach
        </ul>
    @endif
</section>

<section aria-label="Certificats" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Certificats & attestations ({{ $certificats->count() }})</h2>
    @if($certificats->isNotEmpty())
        <ul class="mt-2 space-y-1.5 text-sm">
            @foreach($certificats as $document)
                <li class="flex items-center justify-between gap-2 rounded-xl border border-slate-100 px-3.5 py-2.5">
                    <span><strong>{{ $document['titre'] }}</strong>
                        <span class="text-slate-400 text-xs">· {{ $document['type'] }} · {{ $document['source'] }}@if($document['reference']) · {{ $document['reference'] }}@endif</span></span>
                    @if($document['telechargement'] !== null)<a href="{{ $document['telechargement'] }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Télécharger</a>@else<span class="text-xs text-slate-400">Lecture seule</span>@endif
                </li>
            @endforeach
        </ul>
    @else
        <div class="mt-2"><x-empty-state message="Aucun certificat." /></div>
    @endif
</section>

<section aria-label="Tous les documents" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Tous les documents ({{ $documents->count() }})</h2>
    @if($documents->isNotEmpty())
        <div class="mt-2 overflow-x-auto admin-table-scroll">
            <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-3 py-2">Titre</th><th class="px-3 py-2">Catégorie</th><th class="px-3 py-2">Année</th>
                        <th class="px-3 py-2">Date</th><th class="px-3 py-2">Statut</th><th class="px-3 py-2"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($documents as $document)
                        <tr>
                            <td class="px-3 py-2 font-bold">{{ $document['titre'] }}@if($document['officiel'] ?? false) <span class="ml-1 rounded bg-primary-50 px-1.5 py-0.5 text-[10px] font-black text-primary-800">OFFICIEL</span>@endif</td>
                            <td class="px-3 py-2 text-slate-600">{{ $document['categorie'] }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $document['annee'] ?? '—' }}</td>
                            <td class="px-3 py-2 tabular-nums text-slate-600">{{ $document['date'] instanceof \DateTimeInterface ? $document['date']->format('d/m/Y') : '—' }}</td>
                            <td class="px-3 py-2 text-slate-600">{{ $document['statut'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-right">@if($document['telechargement'] !== null)<a href="{{ $document['telechargement'] }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Télécharger</a>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="mt-2"><x-empty-state message="Aucun document pour ces filtres." /></div>
    @endif
</section>
