<form method="GET" action="{{ route('admin.etudiants.archive', $etudiant) }}" class="mt-4 flex flex-col sm:flex-row gap-2">
    <input type="hidden" name="onglet" value="timeline" />
    <select name="annee_universitaire_id" aria-label="Année" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <option value="">Toutes années</option>
        @foreach($annees as $annee)
            <option value="{{ $annee->id }}" @selected((string) ($filtres['annee_universitaire_id'] ?? '') === (string) $annee->id)>{{ $annee->libelle }}</option>
        @endforeach
    </select>
    <select name="categorie" aria-label="Catégorie" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <option value="">Toutes catégories</option>
        @foreach($categories as $categorie)
            <option value="{{ $categorie }}" @selected(($filtres['categorie'] ?? '') === $categorie)>{{ ucfirst($categorie) }}</option>
        @endforeach
    </select>
    <x-btn size="sm" variant="secondary" type="submit">Filtrer</x-btn>
</form>

<section aria-label="Timeline complète" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Timeline ({{ $timeline['total'] }})</h2>
    <div class="mt-2">
        <x-timeline :elements="$timeline['elements']->map(fn ($e) => ['titre' => $e['titre'], 'detail' => trim(($e['categorie'] ?? '') . ' ' . ($e['detail'] ?? '')), 'date' => $e['date'] instanceof \DateTimeInterface ? $e['date']->format('d/m/Y') : (string) ($e['date'] ?? '')])->all()" />
    </div>
    @if($timeline['total'] > $timeline['elements']->count())
        <a href="{{ route('admin.etudiants.archive', array_merge([$etudiant->id, 'onglet' => 'timeline', 'offset' => $timeline['elements']->count()], array_filter($filtres))) }}" class="mt-2 inline-block text-xs font-bold text-primary-800 underline underline-offset-2">Charger plus ({{ $timeline['total'] - $timeline['elements']->count() }} restants)</a>
    @endif
</section>
