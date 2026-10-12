@extends('layouts.app')

@section('title', 'Campagnes')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Campagnes" description="Envois groupés planifiés, idempotents, avec taux de lecture."
            :fil="['Annonces' => route('admin.annonces.index'), 'Campagnes' => '#']" />

        <form method="post" action="{{ route('admin.campagnes.store') }}" class="mt-4 ds-card grid gap-3 p-5 sm:grid-cols-2">
            @csrf
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="titre">Titre</label>
                <input id="titre" name="titre" maxlength="150" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="audience">Audience</label>
                <select id="audience" name="audience" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2">
                    <option value="etudiants">Étudiants</option>
                    <option value="personnel">Personnel</option>
                    <option value="tous">Tous</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="message">Message</label>
                <textarea id="message" name="message" rows="3" required maxlength="2000" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2"></textarea>
            </div>
            <div class="sm:col-span-2"><x-btn type="submit">Créer (estimation d’audience)</x-btn></div>
        </form>

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Titre</th><th class="px-4 py-2">Audience</th><th class="px-4 py-2">Statut</th><th class="px-4 py-2 text-right">Envoyés</th><th class="px-4 py-2 text-right">Lus</th><th class="px-4 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($campagnes as $campagne)
                        <tr>
                            <td class="px-4 py-2 font-bold">{{ $campagne->titre }}</td>
                            <td class="px-4 py-2">{{ $campagne->audience }}</td>
                            <td class="px-4 py-2"><x-status-badge :statut="$campagne->statut" /></td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $campagne->envoyes }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $campagne->lus }}{{ $campagne->tauxLecture() !== null ? ' ('.$campagne->tauxLecture().' %)' : '' }}</td>
                            <td class="px-4 py-2 text-right">
                                @if(in_array($campagne->statut, ['brouillon', 'planifiee'], true))
                                    <form method="post" action="{{ route('admin.campagnes.envoyer', $campagne) }}" class="inline">
                                        @csrf
                                        <x-btn size="sm" type="submit">Envoyer</x-btn>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $campagnes->links() }}</div>
        </div>
    </div>
@endsection
