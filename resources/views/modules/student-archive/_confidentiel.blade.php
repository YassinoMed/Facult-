<div class="mt-4"><x-alert type="warning" titre="Dossier confidentiel">
    <p class="mt-1 text-sm">Accès restreint — les consultations, modifications et téléchargements sont journalisés (acteur, date, IP, résultat). Aucun contenu sensible ne figure dans les notifications ni les exports standards.</p>
</x-alert></div>

<section aria-label="Dossiers sensibles" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Dossiers sensibles ({{ $sensibles->count() }})</h2>
    @if($sensibles->isNotEmpty())
        <ul class="mt-2 space-y-1.5 text-sm">
            @foreach($sensibles as $dossier)
                <li class="rounded-xl border border-slate-100 px-3.5 py-2.5">
                    <div class="flex items-center justify-between gap-2">
                        <span><strong>{{ $dossier->titre }}</strong>
                            <span class="text-slate-500">· {{ $dossier->typeEnum()->libelle() }} · {{ $dossier->statut }}</span></span>
                        <x-status-badge :statut="$dossier->statut" />
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Période : {{ $dossier->date_debut?->format('d/m/Y') ?? '—' }} → {{ $dossier->date_fin?->format('d/m/Y') ?? '—' }}
                        · Par {{ $dossier->createur?->name ?? '—' }} le {{ $dossier->created_at?->format('d/m/Y') }}
                    </p>
                    @if($dossier->description)<p class="mt-1 text-sm text-slate-700">{{ $dossier->description }}</p>@endif
                    <div class="mt-2 flex flex-wrap gap-2">
                        @php($lien = app(\App\Modules\StudentArchive\Services\StudentArchiveService::class)->lienTelechargementSensible($dossier, auth()->user()))
                        @if($lien !== null)
                            <a href="{{ $lien }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Télécharger la pièce</a>
                        @elseif($dossier->document_source !== null)
                            <span class="text-xs text-slate-400">Pièce non communicable en ligne</span>
                        @endif
                        @can('student_archive.sensitive.manage')
                            <details class="text-xs">
                                <summary class="cursor-pointer font-bold text-slate-600 underline underline-offset-2">Modifier / clôturer</summary>
                                <form method="post" action="{{ route('admin.etudiants.archive.sensible.update', [$etudiant, $dossier]) }}" class="mt-1.5 flex flex-col gap-1.5">
                                    @csrf @method('PATCH')
                                    <select name="statut" class="rounded-xl border border-slate-200 px-2.5 py-1.5 text-xs">
                                        @foreach(['ouvert', 'en_cours', 'cloture', 'archive'] as $statut)
                                            <option value="{{ $statut }}" @selected($dossier->statut === $statut)>{{ $statut }}</option>
                                        @endforeach
                                    </select>
                                    <input type="date" name="date_fin" value="{{ $dossier->date_fin?->format('Y-m-d') }}" class="rounded-xl border border-slate-200 px-2.5 py-1.5 text-xs" />
                                    <x-btn size="sm" variant="secondary" type="submit">Enregistrer</x-btn>
                                </form>
                            </details>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <div class="mt-2"><x-empty-state message="Aucun dossier sensible accessible." /></div>
    @endif
</section>

@can('student_archive.sensitive.manage')
    <section aria-label="Nouveau dossier sensible" class="mt-4 ds-card p-5">
        <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Nouveau dossier (titre administratif neutre — jamais de diagnostic)</h2>
        <form method="post" action="{{ route('admin.etudiants.archive.sensible.store', $etudiant) }}" class="mt-2 grid gap-2 sm:grid-cols-2">
            @csrf
            <select name="type" required aria-label="Type" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                @foreach(['medical' => 'Médical', 'disciplinaire' => 'Disciplinaire', 'situation_particuliere' => 'Situation particulière', 'administratif_restreint' => 'Administratif restreint'] as $valeur => $libelle)
                    <option value="{{ $valeur }}">{{ $libelle }}</option>
                @endforeach
            </select>
            <input type="text" name="titre" required minlength="3" maxlength="150" placeholder="Titre neutre (ex. Aménagement session)" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" />
            <textarea name="description" rows="2" maxlength="2000" placeholder="Faits administratifs uniquement" class="sm:col-span-2 rounded-xl border border-slate-200 px-3 py-2 text-sm"></textarea>
            <input type="date" name="date_debut" aria-label="Début" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" />
            <input type="date" name="date_fin" aria-label="Fin" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" />
            <div class="sm:col-span-2"><x-btn type="submit">Enregistrer (audité)</x-btn></div>
        </form>
    </section>
@endcan

<section aria-label="Timeline confidentielle" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Timeline confidentielle ({{ $timelineSensible['total'] }})</h2>
    <div class="mt-2">
        <x-timeline :elements="$timelineSensible['elements']->map(fn ($e) => ['titre' => $e['titre'], 'detail' => $e['detail'], 'date' => $e['date'] instanceof \DateTimeInterface ? $e['date']->format('d/m/Y') : (string) ($e['date'] ?? '')])->all()" />
    </div>
</section>
