@extends('layouts.app')

@section('title', 'Configurations SMTP')

@section('content')
<div class="mx-auto max-w-6xl px-4 sm:px-6 py-8">
    <x-page-header titre="Configurations SMTP" description="Secrets chiffrés, jamais réaffichés. Test de connexion réel, sans envoi."
        :fil="['SMTP' => '#']" />

    @if(session('success'))
        <div class="mt-4"><x-alert type="success">{{ session('success') }}</x-alert></div>
    @endif
    @if($errors->any())
        <div class="mt-4"><x-alert type="error">{{ $errors->first() }}</x-alert></div>
    @endif

    <div class="mt-4 flex justify-end">
        <a href="{{ route('admin.smtp.create') }}" class="rounded-xl bg-primary-600 px-4 py-2 text-xs font-bold text-white">Nouvelle configuration</a>
    </div>

    <div class="mt-4 ds-card overflow-hidden">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-wider text-slate-400">
                <tr><th class="px-5 py-3.5">Nom</th><th class="px-5 py-3.5">Serveur</th><th class="px-5 py-3.5">Expéditeur</th><th class="px-5 py-3.5">État</th><th class="px-5 py-3.5 text-right">Actions</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($configurations as $configuration)
                    <tr class="hover:bg-primary-50/30">
                        <td class="px-5 py-4 font-bold text-slate-900">{{ $configuration->nom }}</td>
                        <td class="px-5 py-4 font-mono text-xs text-slate-600">{{ $configuration->hote }}:{{ $configuration->port }} ({{ $configuration->chiffrement }})</td>
                        <td class="px-5 py-4 text-xs text-slate-600">{{ $configuration->expediteur }}</td>
                        <td class="px-5 py-4">
                            @if($configuration->actif)
                                <span class="rounded-full bg-emerald-100 text-emerald-800 px-2.5 py-1 text-[10px] font-bold">Active</span>
                            @else
                                <span class="rounded-full bg-slate-100 text-slate-500 px-2.5 py-1 text-[10px] font-bold">Inactive</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <form method="POST" action="{{ route('admin.smtp.tester', $configuration) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-[11px] font-bold text-slate-700">Tester</button>
                                </form>
                                <a href="{{ route('admin.smtp.edit', $configuration) }}" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-[11px] font-bold text-slate-700">Modifier</a>
                                <form method="POST" action="{{ route('admin.smtp.destroy', $configuration) }}" class="inline"
                                      onsubmit="return confirm('Supprimer cette configuration SMTP ?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="rounded-lg px-2 py-1.5 text-[11px] text-rose-500">✕</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-14 text-center text-sm text-slate-400">Aucune configuration SMTP. Le pilote reste celui de l'environnement.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
