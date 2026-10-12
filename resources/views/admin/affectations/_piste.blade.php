{{-- Piste d'action après blocage (affichage seul) : $piste = ['sans_inscription' => bool, 'compatibles' => array], $etudiantId, $annee. --}}
@isset($piste)
    <p class="mt-1 text-xs">
        <span class="font-bold text-slate-700">Piste :</span>
        @if($piste['sans_inscription'])
            <span>à (ré)inscrire en {{ $annee?->libelle ?? '' }} d'abord — <a class="underline" href="{{ route('admin.etudiants.show', $etudiantId) }}">ouvrir la fiche</a>.</span>
        @endif
        @if($piste['compatibles'] !== [])
            <span>Groupes compatibles : <span class="font-mono">{{ implode(', ', $piste['compatibles']) }}</span>.</span>
        @elseif(! $piste['sans_inscription'])
            <span>aucun groupe compatible (formation/niveau) — vérifiez le rattachement.</span>
        @endif
    </p>
@endisset
