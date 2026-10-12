@extends('layouts.app')

@section('title', 'Catalogue des services')

@section('content')
    <div class="mx-auto max-w-4xl px-4 sm:px-6 py-8">
        <x-page-header titre="Catalogue des services" description="Types de demandes configurables : pièces, SLA, document final."
            :fil="['Demandes' => route('admin.demandes.index'), 'Catalogue' => '#']" />

        <div class="mt-4 ds-card p-5">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                        <th class="py-2">Code</th>
                        <th class="py-2">Nom</th>
                        <th class="py-2">Pièce</th>
                        <th class="py-2">SLA (j)</th>
                        <th class="py-2">Actif</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($types as $type)
                        <tr class="border-t border-slate-100">
                            <td class="py-2 font-mono font-bold">{{ $type->code }}</td>
                            <td class="py-2">{{ $type->name }}</td>
                            <td class="py-2">{{ $type->requires_attachment ? 'Oui' : 'Non' }}</td>
                            <td class="py-2">{{ $type->processing_days }}</td>
                            <td class="py-2">{{ $type->active ? 'Oui' : 'Non' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="post" action="{{ route('admin.demandes.types.store') }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="code">Code</label>
                    <input id="code" name="code" maxlength="40" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="DUPLICATA_DIPLOME" />
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="name">Nom</label>
                    <input id="name" name="name" maxlength="150" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="Duplicata de diplôme" />
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="processing_days">SLA (jours)</label>
                    <input id="processing_days" name="processing_days" type="number" min="1" max="90" value="5" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" />
                </div>
                <div class="flex items-end gap-4 pb-2 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="requires_attachment" value="1" class="rounded" /> Pièce requise</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="generates_document" value="1" class="rounded" /> Génère un document</label>
                </div>
            </div>
            @if($errors->any())
                <x-alert type="error">{{ $errors->first() }}</x-alert>
            @endif
            <x-btn type="submit">Créer le service</x-btn>
        </form>
    </div>
@endsection
