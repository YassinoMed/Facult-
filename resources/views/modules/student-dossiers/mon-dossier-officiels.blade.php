@extends('layouts.app')

@section('title', 'Mes documents officiels')

@section('content')
<div class="mx-auto max-w-4xl px-4 py-8">
    <x-page-header titre="Mes documents officiels" description="Relevés et attestations émis par la scolarité. Vérifiables via le code imprimé." />

    @if($documents->isEmpty())
        <div class="mt-4"><x-empty-state message="Aucun document officiel disponible pour le moment." /></div>
    @else
        <div class="mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                        <th class="px-3 py-2">Document</th>
                        <th class="px-3 py-2">Référence</th>
                        <th class="px-3 py-2">Émis le</th>
                        <th class="px-3 py-2">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $document)
                        <tr class="border-t border-slate-100">
                            <td class="px-3 py-2 font-semibold">{{ $document->type }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $document->reference }}</td>
                            <td class="px-3 py-2">{{ $document->emis_le?->format('d/m/Y') ?? $document->created_at?->format('d/m/Y') }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('etudiant.dossier.officiels.download', $document) }}" class="text-xs font-bold text-primary-800 underline underline-offset-2">Télécharger</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $documents->links() }}
    @endif
</div>
@endsection
