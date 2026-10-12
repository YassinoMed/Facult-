@extends('layouts.app')

@section('title', 'Types d’évaluation')

@section('content')
    <div class="mx-auto max-w-3xl px-4 sm:px-6 py-8">
        <x-page-header titre="Types d’évaluation" description="Référentiel configurable : les épreuves référencent un code actif."
            :fil="['Évaluations' => route('admin.evaluations.index'), 'Types' => '#']" />

        <div class="mt-4 ds-card p-5">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                        <th class="py-2">Code</th>
                        <th class="py-2">Libellé</th>
                        <th class="py-2">Coeff.</th>
                        <th class="py-2">Durée (min)</th>
                        <th class="py-2">Actif</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($types as $type)
                        <tr class="border-t border-slate-100">
                            <td class="py-2 font-mono font-bold">{{ $type->code }}</td>
                            <td class="py-2">{{ $type->libelle }}</td>
                            <td class="py-2">{{ $type->coefficient_defaut }}</td>
                            <td class="py-2">{{ $type->duree_defaut_minutes }}</td>
                            <td class="py-2">{{ $type->actif ? 'Oui' : 'Non' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <form method="post" action="{{ route('admin.evaluations.types.store') }}" class="mt-4 ds-card space-y-4 p-5">
            @csrf
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="code">Code</label>
                    <input id="code" name="code" maxlength="30" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="qcm" />
                </div>
                <div>
                    <label class="text-xs font-bold uppercase tracking-wider text-slate-500" for="libelle">Libellé</label>
                    <input id="libelle" name="libelle" maxlength="100" required class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" placeholder="QCM" />
                </div>
            </div>
            @if($errors->any())
                <x-alert type="error">{{ $errors->first() }}</x-alert>
            @endif
            <x-btn type="submit">Créer le type</x-btn>
        </form>
    </div>
@endsection
