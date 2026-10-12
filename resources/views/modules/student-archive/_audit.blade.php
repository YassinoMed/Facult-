<section aria-label="Audit du dossier" class="mt-4 ds-card p-5">
    <h2 class="text-sm font-black uppercase tracking-wider text-slate-500">Audit — qui a consulté quoi ({{ $audits->total() }})</h2>
    <div class="mt-2 overflow-x-auto admin-table-scroll">
        <table class="w-full min-w-[720px] text-left text-xs sm:text-sm">
            <thead>
                <tr class="border-b border-slate-100 text-[11px] uppercase tracking-wider text-slate-400">
                    <th class="px-3 py-2">Quand</th><th class="px-3 py-2">Qui</th><th class="px-3 py-2">Action</th><th class="px-3 py-2">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach($audits as $audit)
                    <tr>
                        <td class="px-3 py-2 tabular-nums text-slate-600">{{ $audit->created_at?->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2">{{ $audit->utilisateur?->name ?? '—' }}</td>
                        <td class="px-3 py-2">{{ $audit->action_libelle }}</td>
                        <td class="px-3 py-2 font-mono text-slate-500">{{ $audit->adresse_ip ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-2">{{ $audits->links() }}</div>
</section>
