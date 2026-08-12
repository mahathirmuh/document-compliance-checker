<div>
    <x-page-header :title="'Scan history — '.$source->name"
                   :description="$source->type->label()">
        <x-slot:actions>
            <a href="{{ route('sources.index') }}"
               class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Back to sources
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th scope="col" class="px-3 py-2 font-medium">Started</th>
                <th scope="col" class="px-3 py-2 font-medium">Status</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Found</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">New</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Modified</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Unchanged</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Missing</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Queued</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Errors</th>
                <th scope="col" class="px-3 py-2 text-right font-medium">Duration</th>
                <th scope="col" class="px-3 py-2 font-medium">Triggered by</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($scans as $scan)
                <tr class="hover:bg-slate-50">
                    <td class="whitespace-nowrap px-3 py-2 text-slate-700">
                        {{ $scan->started_at?->format('Y-m-d H:i:s') }}
                    </td>
                    <td class="px-3 py-2"><x-status-badge :status="$scan->status" /></td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ number_format($scan->total_found) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums font-medium text-green-700">{{ number_format($scan->new_files) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums font-medium text-blue-700">{{ number_format($scan->modified_files) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-slate-500">{{ number_format($scan->unchanged_files) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-amber-700">{{ number_format($scan->deleted_files) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums text-slate-700">{{ number_format($scan->queued_for_analysis) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums {{ $scan->error_count > 0 ? 'font-medium text-red-700' : 'text-slate-500' }}">
                        {{ number_format($scan->error_count) }}
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-right text-xs text-slate-500">{{ $scan->durationForHumans() }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">
                        {{ $scan->trigger?->name ?? 'Scheduler' }}
                    </td>
                </tr>
                @if ($scan->message)
                    <tr class="bg-slate-50/60">
                        <td colspan="11" class="px-3 pb-2 text-xs text-slate-500">{{ $scan->message }}</td>
                    </tr>
                @endif
            @empty
                <tr>
                    <td colspan="11" class="px-3 py-10 text-center text-sm text-slate-500">
                        This source has not been scanned yet.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $scans->links() }}</div>
</div>
