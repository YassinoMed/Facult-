{{--
    Confirmation d'action sensible : modale + résumé + double garde
    (case à cocher exigée pour les actions destructrices irréversibles).
    Le formulaire reste fourni par l'appelant (CSRF + méthode inclus).
    Usage :
    <div x-data="{ confirmer: false }">
        <x-btn variant="danger" @click="confirmer = true">Supprimer</x-btn>
        <x-confirm titre="Supprimer ?" nom="confirmer" annonce="Cette action est irréversible.">
            <form method="POST" action="…">@csrf @method('DELETE')
                <x-btn variant="danger" type="submit">Supprimer définitivement</x-btn>
            </form>
        </x-confirm>
    </div>
--}}
@props(['titre', 'nom' => 'confirmer', 'annonce' => null])

<x-modal :titre="$titre" :nom="$nom">
    @if($annonce !== null)
        <p class="font-semibold">{{ $annonce }}</p>
    @endif
    <div class="mt-1">{{ $slot }}</div>
    <x-slot:actions>
        <x-btn variant="secondary" type="button" @click="{{ $nom }} = false">Annuler</x-btn>
    </x-slot:actions>
</x-modal>
