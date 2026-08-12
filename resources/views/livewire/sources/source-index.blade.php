<div>
    <x-page-header title="Document sources"
                   description="Where the application looks for controlled documents.">
        <x-slot:actions>
            <a href="{{ route('sources.create') }}"
               class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
                Add source
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-3">
        @forelse ($sources as $source)
            @php $lastScan = $source->scanLogs->first(); @endphp

            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-sm font-semibold text-slate-900">{{ $source->name }}</h2>

                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                {{ $source->type->label() }}
                            </span>

                            @if ($source->enabled)
                                <span class="rounded bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">Enabled</span>
                            @else
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/20">Disabled</span>
                            @endif

                            {{-- A SharePoint source is only as usable as the server's Graph
                                 credentials, which are not visible from the source itself. --}}
                            @if ($source->type === \App\Enums\DocumentSourceType::SHAREPOINT && ! $graphConfigured)
                                <span class="rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">
                                    Graph not configured
                                </span>
                            @endif
                        </div>

                        {{-- Only shown here, on a screen already restricted to
                             admins - never in the document list. --}}
                        <p class="mt-1 break-all font-mono text-xs text-slate-500">
                            {{ $source->path ?: $source->displayLocation() }}
                        </p>

                        <dl class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                            <div><dt class="inline">Documents:</dt> <dd class="inline font-medium text-slate-700">{{ number_format($source->documents_count) }}</dd></div>
                            <div><dt class="inline">Interval:</dt> <dd class="inline font-medium text-slate-700">{{ $source->scan_interval_minutes }} min</dd></div>
                            <div><dt class="inline">Last scan:</dt> <dd class="inline font-medium text-slate-700">{{ $source->last_scan_at?->diffForHumans() ?? 'Never' }}</dd></div>
                        </dl>

                        @if ($lastScan)
                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <x-status-badge :status="$lastScan->status" />
                                <span class="text-slate-500">{{ $lastScan->message }}</span>
                            </div>
                        @endif

                        @isset($connectionResults[$source->id])
                            <p class="mt-2 rounded px-2 py-1 text-xs {{ $connectionResults[$source->id]['ok'] ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800' }}">
                                {{ $connectionResults[$source->id]['message'] }}
                            </p>
                        @endisset
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @can('testConnection', $source)
                            <button type="button" wire:click="testConnection({{ $source->id }})"
                                    wire:loading.attr="disabled" wire:target="testConnection({{ $source->id }})"
                                    class="rounded border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                                Test connection
                            </button>
                        @endcan

                        @can('scan', $source)
                            <button type="button" wire:click="scanNow({{ $source->id }})"
                                    wire:loading.attr="disabled" wire:target="scanNow({{ $source->id }})"
                                    class="rounded bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                                Scan now
                            </button>
                        @endcan

                        @can('update', $source)
                            <button type="button" wire:click="toggleEnabled({{ $source->id }})"
                                    class="rounded border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                {{ $source->enabled ? 'Disable' : 'Enable' }}
                            </button>

                            <a href="{{ route('sources.edit', $source) }}"
                               class="rounded border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                                Edit
                            </a>
                        @endcan

                        <a href="{{ route('sources.scans', $source) }}"
                           class="rounded border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                            Scan history
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center">
                <p class="text-sm text-slate-600">No document sources registered yet.</p>
                <a href="{{ route('sources.create') }}"
                   class="mt-3 inline-block rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    Add your first source
                </a>
            </div>
        @endforelse
    </div>
</div>
