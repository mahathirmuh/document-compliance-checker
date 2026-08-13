@use('App\Enums\LanguageCode')

<div>
    <x-page-header :title="$document->displayTitle()"
                   :description="$document->document_code ? 'Document code '.$document->document_code : 'No document code recorded.'">
        <x-slot:actions>
            <a href="{{ route('documents.index') }}"
               class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Back to list
            </a>
            <a href="{{ route('documents.compare', $document) }}"
               class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Compare languages
            </a>
            @can('reanalyze', $document)
                <button type="button" wire:click="reanalyze" wire:loading.attr="disabled"
                        class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="reanalyze">Re-analyze</span>
                    <span wire:loading wire:target="reanalyze">Queueing…</span>
                </button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 lg:grid-cols-3">

        {{-- Metadata -----------------------------------------------------}}
        <div class="rounded-lg border border-slate-200 bg-white p-5 lg:col-span-1">
            <h2 class="text-sm font-semibold text-slate-900">Document</h2>

            <dl class="mt-3 space-y-2 text-sm">
                @php
                    $rows = [
                        'Code' => $document->document_code ?: '—',
                        'Title' => $document->displayTitle(),
                        'Type' => $document->document_type?->label() ?? '—',
                        'Department' => $document->department ?: '—',
                        'Revision' => $document->current_revision ?: '—',
                        'Source' => $document->source?->name,
                        'Source type' => $document->source_type->label(),
                        'File name' => $document->file_name,
                        'File size' => $document->humanFileSize(),
                        'Last modified' => $document->source_last_modified_at?->format('Y-m-d H:i') ?? '—',
                        'Last analyzed' => $document->last_analyzed_at?->format('Y-m-d H:i') ?? 'Never',
                    ];
                @endphp

                @foreach ($rows as $label => $value)
                    <div class="flex justify-between gap-3 border-b border-slate-100 pb-2 last:border-0">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="text-right font-medium text-slate-800">{{ $value }}</dd>
                    </div>
                @endforeach

                {{-- The path maps the internal network, so it is only shown to
                     people who can already manage sources (CLAUDE.md 12). --}}
                @can('manage-sources')
                    <div class="flex justify-between gap-3 pt-1">
                        <dt class="text-slate-500">Path</dt>
                        <dd class="break-all text-right font-mono text-xs text-slate-600">{{ $document->file_path }}</dd>
                    </div>
                @endcan
            </dl>

            <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                <span class="text-sm text-slate-500">Current status</span>
                <x-status-badge :status="$document->analysis_status" />
            </div>

            <div class="mt-2 flex items-center justify-between">
                <span class="text-sm text-slate-500">Overall score</span>
                <span class="text-lg font-semibold tabular-nums">
                    {{ $document->compliance_score === null ? '—' : $document->compliance_score.'%' }}
                </span>
            </div>
        </div>

        {{-- Languages + issues -------------------------------------------}}
        <div class="space-y-4 lg:col-span-2">

            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-900">Language coverage</h2>

                @if ($analysis === null)
                    <p class="mt-3 rounded border border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-600">
                        This document has not been analyzed yet. It stays at <strong>Pending</strong> until a
                        queue worker picks it up and the analyzer service is reachable.
                    </p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="py-2 pr-3 font-medium">Language</th>
                                <th scope="col" class="px-3 py-2 font-medium">Detected</th>
                                <th scope="col" class="px-3 py-2 font-medium">Characters</th>
                                <th scope="col" class="px-3 py-2 font-medium">Words</th>
                                <th scope="col" class="px-3 py-2 font-medium">Coverage</th>
                                <th scope="col" class="px-3 py-2 font-medium">Confidence</th>
                                <th scope="col" class="px-3 py-2 font-medium">Threshold</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($languageResults as $code => $result)
                                @php $language = LanguageCode::from($code); @endphp
                                <tr>
                                    <th scope="row" class="py-2 pr-3 text-left font-medium text-slate-800">
                                        {{ $language->label() }}
                                    </th>
                                    <td class="px-3 py-2">
                                        @if ($result === null)
                                            <span class="text-slate-400">—</span>
                                        @elseif ($result->detected)
                                            <span class="font-medium text-green-700">Yes</span>
                                        @else
                                            <span class="font-medium text-red-700">No</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 tabular-nums text-slate-700">
                                        {{ $result === null ? '—' : number_format($result->character_count) }}
                                    </td>
                                    <td class="px-3 py-2 tabular-nums text-slate-700">
                                        @if ($result === null)
                                            —
                                        @elseif ($language->isCharacterCounted())
                                            {{-- Chinese has no whitespace word boundaries; a word count
                                                 here would be meaningless (CLAUDE.md 8.6). --}}
                                            <span class="text-xs text-slate-400" title="Not applicable to Chinese">n/a</span>
                                        @else
                                            {{ number_format((int) $result->word_count) }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 tabular-nums text-slate-700">
                                        {{ $result === null ? '—' : $result->coverage_percent.'%' }}
                                    </td>
                                    <td class="px-3 py-2 tabular-nums text-slate-700">
                                        {{ $result?->confidencePercent() === null ? '—' : $result->confidencePercent().'%' }}
                                    </td>
                                    <td class="px-3 py-2 text-xs">
                                        @if ($result === null)
                                            <span class="text-slate-400">—</span>
                                        @elseif ($result->meets_threshold)
                                            <span class="font-medium text-green-700">Met ({{ $result->threshold_applied }})</span>
                                        @else
                                            <span class="font-medium text-amber-700">Below ({{ $result->threshold_applied }})</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Document Control rules ------------------------------------------}}
            @if ($analysis?->rule_results)
                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Document Control rules</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        A rule that could not run is shown as <strong>Not checked</strong>, never as a pass —
                        font colour cannot be read from a scanned PDF, and calling that compliant would be
                        a clean bill of health on the documents least likely to deserve one.
                    </p>

                    <div class="mt-3 divide-y divide-slate-100">
                        @foreach ($analysis->rule_results as $rule)
                            <div class="flex flex-wrap items-start justify-between gap-3 py-2 first:pt-0">
                                <div class="min-w-0">
                                    <span class="text-sm font-medium text-slate-800">
                                        {{ \App\Services\Settings\RuleSettingsService::RULES[$rule['rule']]['label'] ?? $rule['rule'] }}
                                    </span>
                                    @if (! empty($rule['skipped_reason']))
                                        <p class="mt-0.5 max-w-xl text-xs text-slate-500">{{ $rule['skipped_reason'] }}</p>
                                    @endif
                                </div>

                                @if (! $rule['applicable'])
                                    <span class="whitespace-nowrap rounded bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/20">
                                        Not checked
                                    </span>
                                @elseif ($rule['passed'])
                                    <span class="whitespace-nowrap rounded bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20">
                                        Pass
                                    </span>
                                @else
                                    <span class="whitespace-nowrap rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">
                                        {{ $rule['finding_count'] }} finding{{ $rule['finding_count'] === 1 ? '' : 's' }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Per-section coverage ------------------------------------------}}
            @if ($sections->isNotEmpty())
                <div class="rounded-lg border border-slate-200 bg-white p-5">
                    <h2 class="text-sm font-semibold text-slate-900">Coverage by section</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        The section is the smallest unit expected to hold all three languages. Sections too
                        short to reasonably carry a translation are measured but not listed here.
                    </p>

                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="py-2 pr-3 font-medium">#</th>
                                <th scope="col" class="px-3 py-2 font-medium">Section</th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">EN</th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">ID</th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">ZH</th>
                                <th scope="col" class="px-3 py-2 font-medium">Finding</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            @foreach ($sections as $section)
                                <tr class="{{ $section->isComplete() ? '' : 'bg-amber-50/40' }}">
                                    <td class="py-2 pr-3 tabular-nums text-slate-400">{{ $section->sequence }}</td>
                                    <td class="max-w-xs px-3 py-2">
                                        <span class="block truncate font-medium text-slate-800">{{ $section->name }}</span>
                                        @if ($section->page_number)
                                            <span class="text-xs text-slate-400">Page {{ $section->page_number }}</span>
                                        @endif
                                    </td>

                                    @foreach (LanguageCode::requiredOrder() as $language)
                                        <td class="px-3 py-2 text-center">
                                            @if ($section->isMissing($language))
                                                <span class="text-xs font-medium text-red-700">None</span>
                                            @elseif ($section->isShort($language))
                                                <span class="text-xs font-medium text-amber-700"
                                                      title="{{ $section->charactersFor($language) }} characters">Short</span>
                                            @else
                                                <span class="text-xs tabular-nums text-slate-600">{{ number_format($section->charactersFor($language)) }}</span>
                                            @endif
                                        </td>
                                    @endforeach

                                    <td class="px-3 py-2 text-xs">
                                        @if ($section->isComplete())
                                            <span class="text-green-700">Complete</span>
                                        @else
                                            @php
                                                $notes = [];
                                                foreach ($section->missing_languages ?? [] as $code) {
                                                    $notes[] = LanguageCode::from(strtoupper($code))->label().' missing';
                                                }
                                                foreach ($section->short_languages ?? [] as $code) {
                                                    $notes[] = LanguageCode::from(strtoupper($code))->label().' short';
                                                }
                                            @endphp
                                            <span class="text-amber-800">{{ implode(', ', $notes) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-900">Issues</h2>

                @forelse ($issues as $issue)
                    <div class="mt-3 border-t border-slate-100 pt-3 first:border-0 first:pt-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $issue->severity->cssClasses() }}">
                                {{ $issue->severity->label() }}
                            </span>
                            <span class="text-sm font-medium text-slate-800">{{ $issue->issue_type->label() }}</span>
                            @if ($issue->language_code)
                                <span class="text-xs text-slate-500">{{ $issue->language_code->label() }}</span>
                            @endif
                            <span class="text-xs text-slate-400">{{ $issue->displayLocation() }}</span>
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $issue->description }}</p>
                    </div>
                @empty
                    <p class="mt-3 text-sm text-slate-500">No issues recorded.</p>
                @endforelse
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="text-sm font-semibold text-slate-900">Version history</h2>
                <p class="mt-1 text-xs text-slate-500">
                    A changed file creates a new version; earlier analyses are never overwritten.
                </p>

                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="py-2 pr-3 font-medium">Revision</th>
                            <th scope="col" class="px-3 py-2 font-medium">Detected</th>
                            <th scope="col" class="px-3 py-2 font-medium">Analyzed</th>
                            <th scope="col" class="px-3 py-2 font-medium">Score</th>
                            <th scope="col" class="px-3 py-2 font-medium">Status</th>
                            <th scope="col" class="px-3 py-2 font-medium">Size</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        @foreach ($versions as $version)
                            <tr class="{{ $version->is_current ? 'bg-slate-50' : '' }}">
                                <td class="py-2 pr-3 font-medium text-slate-800">
                                    {{ $version->displayRevision() }}
                                    @if ($version->is_current)
                                        <span class="ml-1 text-xs font-normal text-slate-500">(current)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ $version->detected_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ $version->analyzed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="px-3 py-2 tabular-nums text-slate-700">
                                    {{ $version->latestAnalysis?->overall_score === null ? '—' : $version->latestAnalysis->overall_score.'%' }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($version->latestAnalysis)
                                        <x-status-badge :status="$version->latestAnalysis->status" />
                                    @else
                                        <span class="text-xs text-slate-400">Not analyzed</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-500">
                                    {{ number_format($version->file_size / 1024, 1) }} KB
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
