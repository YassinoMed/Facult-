@extends('layouts.app')

@section('title', 'Tarifs')

@section('content')
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
        <x-page-header titre="Référentiel des frais" description="Montants configurables par année, programme, niveau."
            :fil="['Finances' => route('admin.finances.index'), 'Tarifs' => '#']" />

        <form method="post" action="{{ route('admin.finances.tarifs.store') }}" class="mt-4 ds-card grid gap-3 p-5 sm:grid-cols-3">
            @csrf
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="code">Code</label>
                <input id="code" name="code" maxlength="30" required value="{{ old('code') }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="INSC-L1" />
                @error('code')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="libelle">Libellé</label>
                <input id="libelle" name="libelle" maxlength="150" required value="{{ old('libelle') }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                @error('libelle')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="montant">Montant</label>
                <input id="montant" name="montant" type="number" step="0.01" min="0" required value="{{ old('montant') }}" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                @error('montant')<p class="mt-1 text-[11px] font-bold text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="annee">Année</label>
                <select id="annee" name="annee_universitaire_id" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    @foreach($annees as $annee)
                        <option value="{{ $annee->id }}">{{ $annee->libelle }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="type">Type</label>
                <select id="type" name="type_frais" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    @foreach(\App\Modules\Finances\Models\Tarif::TYPES as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end"><x-btn type="submit">Créer le tarif</x-btn></div>
        </form>

        <div class="mt-4 ds-card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr><th class="px-4 py-2">Code</th><th class="px-4 py-2">Libellé</th><th class="px-4 py-2 text-right">Montant</th><th class="px-4 py-2">Année</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($tarifs as $tarif)
                        <tr>
                            <td class="px-4 py-2 font-mono font-bold">{{ $tarif->code }}</td>
                            <td class="px-4 py-2">{{ $tarif->libelle }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $tarif->montantFormate() }}</td>
                            <td class="px-4 py-2">{{ $tarif->anneeUniversitaire->libelle }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $tarifs->links() }}</div>
        </div>
    </div>
@endsection
