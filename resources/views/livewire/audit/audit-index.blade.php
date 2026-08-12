<div>
    <x-page-header title="Audit log"
                   description="Administrative actions, newest first. Entries are never edited or removed." />

    <div class="mb-4 flex flex-wrap gap-3 rounded-lg border border-slate-200 bg-white p-4">
        <div class="min-w-56 flex-1">
            <label for="audit-search" class="block text-xs font-medium text-slate-600">User email</label>
            <input id="audit-search" type="search" wire:model.live.debounce.300ms="search"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 text-sm">
        </div>
        <div class="min-w-56">
            <label for="audit-action" class="block text-xs font-medium text-slate-600">Action</label>
            <select id="audit-action" wire:model.live="action"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">All actions</option>
                @foreach ($actions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th scope="col" class="px-3 py-2 font-medium">When</th>
                <th scope="col" class="px-3 py-2 font-medium">User</th>
                <th scope="col" class="px-3 py-2 font-medium">Action</th>
                <th scope="col" class="px-3 py-2 font-medium">Entity</th>
                <th scope="col" class="px-3 py-2 font-medium">Change</th>
                <th scope="col" class="px-3 py-2 font-medium">IP</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($entries as $entry)
                <tr class="hover:bg-slate-50 align-top">
                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-600">
                        {{ $entry->created_at?->format('Y-m-d H:i:s') }}
                    </td>
                    <td class="px-3 py-2">
                        <div class="text-slate-800">{{ $entry->actorName() }}</div>
                        <div class="text-xs text-slate-400">{{ $entry->user_email }}</div>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-slate-700">{{ $entry->action }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-600">
                        {{ $entry->entity_type ? $entry->entity_type.' #'.$entry->entity_id : '—' }}
                    </td>
                    <td class="max-w-md px-3 py-2">
                        @if ($entry->new_values)
                            <dl class="space-y-0.5 text-xs">
                                @foreach ($entry->new_values as $field => $value)
                                    <div class="flex gap-1">
                                        <dt class="text-slate-500">{{ $field }}:</dt>
                                        <dd class="truncate text-slate-700">
                                            @if (isset($entry->old_values[$field]))
                                                <span class="text-slate-400 line-through">{{ is_scalar($entry->old_values[$field]) ? $entry->old_values[$field] : '…' }}</span>
                                                →
                                            @endif
                                            {{ is_scalar($value) ? $value : json_encode($value) }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">{{ $entry->ip_address ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-3 py-10 text-center text-sm text-slate-500">No audit entries yet.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>
</div>
