@use('App\Enums\LanguageCode')

<div>
    <x-page-header title="Documents"
                   description="Every document discovered in a source or uploaded manually.">
        <x-slot:actions>
            @can('upload-document')
                <a href="{{ route('documents.upload') }}"
                   class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    Upload document
                </a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Filters -----------------------------------------------------------}}
    <div class="mb-4 rounded-lg border border-slate-200 bg-white p-4">
        <div class="grid gap-3 md:grid-cols-3 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="search" class="block text-xs font-medium text-slate-600">Search</label>
                <input id="search" type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Document code, title or file name"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 text-sm">
            </div>

            <div>
                <label for="filter-status" class="block text-xs font-medium text-slate-600">Status</label>
                <select id="filter-status" wire:model.live="status"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="filter-type" class="block text-xs font-medium text-slate-600">Document type</label>
                <select id="filter-type" wire:model.live="type"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">All types</option>
                    @foreach ($types as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="filter-source" class="block text-xs font-medium text-slate-600">Source</label>
                <select id="filter-source" wire:model.live="source"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">All sources</option>
                    @foreach ($sources as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="filter-missing" class="block text-xs font-medium text-slate-600">Language missing</label>
                <select id="filter-missing" wire:model.live="missingLanguage"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Any</option>
                    @foreach ($languages as $language)
                        <option value="{{ $language->value }}">{{ $language->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="filter-department" class="block text-xs font-medium text-slate-600">Department</label>
                <select id="filter-department" wire:model.live="department"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">All departments</option>
                    @foreach ($departments as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex items-end justify-between gap-2">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" wire:model.live="includeInactive"
                           class="rounded border-slate-300 text-slate-900">
                    Show missing files
                </label>
                <button type="button" wire:click="clearFilters"
                        class="rounded border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">
                    Clear
                </button>
            </div>
        </div>
    </div>

    {{-- Table -------------------------------------------------------------}}
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                @php
                    $columns = [
                        'document_code' => 'Code',
                        'document_title' => 'Title',
                        'document_type' => 'Type',
                    ];
                @endphp

                @foreach ($columns as $field => $label)
                    <th scope="col" class="px-3 py-2 font-medium">
                        <button type="button" wire:click="sortBy('{{ $field }}')" class="hover:text-slate-900">
                            {{ $label }}
                            @if ($sortField === $field)
                                <span aria-hidden="true">{{ $sortDirection === 'asc' ? '▲' : '▼' }}</span>
                                <span class="sr-only">sorted {{ $sortDirection === 'asc' ? 'ascending' : 'descending' }}</span>
                            @endif
                        </button>
                    </th>
                @endforeach

                <th scope="col" class="px-3 py-2 font-medium">Source</th>
                <th scope="col" class="px-3 py-2 font-medium">File</th>
                <th scope="col" class="px-3 py-2 font-medium">Rev</th>
                <th scope="col" class="px-3 py-2 text-center font-medium">EN</th>
                <th scope="col" class="px-3 py-2 text-center font-medium">ID</th>
                <th scope="col" class="px-3 py-2 text-center font-medium">ZH</th>
                <th scope="col" class="px-3 py-2 font-medium">
                    <button type="button" wire:click="sortBy('compliance_score')" class="hover:text-slate-900">Score</button>
                </th>
                <th scope="col" class="px-3 py-2 font-medium">
                    <button type="button" wire:click="sortBy('analysis_status')" class="hover:text-slate-900">Status</button>
                </th>
                <th scope="col" class="px-3 py-2 font-medium">
                    <button type="button" wire:click="sortBy('source_last_modified_at')" class="hover:text-slate-900">Modified</button>
                </th>
                <th scope="col" class="px-3 py-2 font-medium">
                    <button type="button" wire:click="sortBy('last_analyzed_at')" class="hover:text-slate-900">Analyzed</button>
                </th>
                <th scope="col" class="px-3 py-2 font-medium"><span class="sr-only">Actions</span></th>
            </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
            @forelse ($documents as $document)
                @php $results = $document->latestAnalysis?->languageResults->keyBy(fn ($r) => $r->language_code->value); @endphp
                <tr class="hover:bg-slate-50 {{ $document->is_active ? '' : 'opacity-60' }}">
                    <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-slate-600">
                        {{ $document->document_code ?: '—' }}
                    </td>
                    <td class="max-w-xs px-3 py-2">
                        <a href="{{ route('documents.show', $document) }}"
                           class="block truncate font-medium text-slate-800 hover:underline">
                            {{ $document->displayTitle() }}
                        </a>
                        @unless ($document->is_active)
                            <span class="text-xs text-amber-700">Not seen in the last scan</span>
                        @endunless
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">
                        {{ $document->document_type?->label() ?? '—' }}
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $document->source?->name }}</td>
                    <td class="max-w-[14rem] truncate px-3 py-2 text-slate-600" title="{{ $document->file_name }}">
                        {{ $document->file_name }}
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $document->current_revision ?: '—' }}</td>

                    @foreach ($languages as $language)
                        @php $result = $results?->get($language->value); @endphp
                        <td class="px-3 py-2 text-center">
                            @if ($result === null)
                                <span class="text-xs text-slate-400" title="Not analyzed yet">—</span>
                            @elseif ($result->meets_threshold)
                                <span class="text-xs font-medium text-green-700">Yes</span>
                            @elseif ($result->detected)
                                <span class="text-xs font-medium text-amber-700">Low</span>
                            @else
                                <span class="text-xs font-medium text-red-700">No</span>
                            @endif
                        </td>
                    @endforeach

                    <td class="whitespace-nowrap px-3 py-2 tabular-nums text-slate-700">
                        {{ $document->compliance_score === null ? '—' : $document->compliance_score.'%' }}
                    </td>
                    <td class="px-3 py-2"><x-status-badge :status="$document->analysis_status" /></td>
                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">
                        {{ $document->source_last_modified_at?->format('Y-m-d H:i') ?? '—' }}
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-500">
                        {{ $document->last_analyzed_at?->format('Y-m-d H:i') ?? '—' }}
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-right">
                        <a href="{{ route('documents.show', $document) }}"
                           class="text-xs font-medium text-slate-600 hover:text-slate-900 hover:underline">View</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="14" class="px-3 py-10 text-center text-sm text-slate-500">
                        No documents match these filters.
                        @can('manage-sources')
                            <a href="{{ route('sources.index') }}" class="text-slate-700 underline">Register a source</a>
                            and run a scan to get started.
                        @endcan
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $documents->links() }}</div>
</div>
