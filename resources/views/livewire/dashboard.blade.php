@use('App\Enums\AnalysisStatus')

<div>
    <x-page-header title="Dashboard"
                   description="Trilingual compliance across every registered document source." />

    {{-- Headline counters -------------------------------------------------}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
        <div class="rounded-lg border border-slate-200 bg-white p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Total</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums">{{ number_format($totalDocuments) }}</div>
        </div>

        @foreach (AnalysisStatus::dashboardOrder() as $status)
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $status->label() }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ number_format($statusCounts[$status->value]) }}
                </div>
            </div>
        @endforeach
    </div>

    {{-- Compliance headline ------------------------------------------------}}
    <div class="mt-4 rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <div class="text-sm font-medium text-slate-500">Overall compliance</div>
                <div class="mt-1 text-3xl font-semibold tabular-nums">
                    {{ $compliancePercent === null ? '—' : $compliancePercent.'%' }}
                </div>
            </div>
            <p class="max-w-md text-xs text-slate-500">
                Share of graded documents that pass all three languages. Documents still pending analysis
                are excluded, so a backlog cannot inflate this figure.
            </p>
        </div>

        @if ($compliancePercent !== null)
            <div class="mt-3 h-2 w-full overflow-hidden rounded bg-slate-100">
                <div class="h-full rounded bg-green-600" style="width: {{ $compliancePercent }}%"></div>
            </div>
        @endif
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">

        {{-- Language compliance ---------------------------------------------}}
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Language compliance</h2>
            <p class="mt-1 text-xs text-slate-500">Documents meeting the configured minimum for each language.</p>

            <dl class="mt-4 space-y-3">
                @foreach ($languageCompliance as $language)
                    @php
                        $percent = $language['total'] > 0
                            ? round(($language['meets'] / $language['total']) * 100, 1)
                            : null;
                    @endphp
                    <div>
                        <div class="flex items-baseline justify-between text-sm">
                            <dt class="font-medium text-slate-700">{{ $language['label'] }}</dt>
                            <dd class="tabular-nums text-slate-600">
                                {{ $language['meets'] }} / {{ $language['total'] }}
                                <span class="ml-1 text-slate-400">{{ $percent === null ? '' : "({$percent}%)" }}</span>
                            </dd>
                        </div>
                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded bg-slate-100">
                            <div class="h-full rounded bg-slate-700" style="width: {{ $percent ?? 0 }}%"></div>
                        </div>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- Recent analyses --------------------------------------------------}}
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Recent analysis</h2>

            @forelse ($recentAnalyses as $analysis)
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 first:border-0 first:pt-0">
                    <div class="min-w-0">
                        <a href="{{ route('documents.show', $analysis->document_id) }}"
                           class="block truncate text-sm font-medium text-slate-800 hover:underline">
                            {{ $analysis->document?->document_title ?: $analysis->document?->file_name }}
                        </a>
                        <div class="text-xs text-slate-500">
                            {{ $analysis->completed_at?->diffForHumans() }}
                        </div>
                    </div>
                    <x-status-badge :status="$analysis->status" />
                </div>
            @empty
                <p class="mt-3 text-sm text-slate-500">
                    No analyses have completed yet. The analyzer service arrives in Phase 2 — until then
                    discovered documents stay at Pending.
                </p>
            @endforelse
        </div>

        {{-- Documents by type ------------------------------------------------}}
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Documents by type</h2>
            @include('livewire.partials.count-list', ['counts' => $byType, 'empty' => 'No documents indexed yet.'])
        </div>

        {{-- Documents by source ----------------------------------------------}}
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Documents by source</h2>
            @include('livewire.partials.count-list', ['counts' => $bySource, 'empty' => 'No sources have been scanned yet.'])
        </div>

        {{-- Documents by department -------------------------------------------}}
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Documents by department</h2>
            @include('livewire.partials.count-list', ['counts' => $byDepartment, 'empty' => 'No department has been recorded yet.'])
        </div>

        {{-- Failed scans ------------------------------------------------------}}
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="text-sm font-semibold text-slate-900">Recent failed scans</h2>

            @forelse ($failedScans as $scan)
                <div class="mt-3 border-t border-slate-100 pt-3 first:border-0 first:pt-0">
                    <div class="flex items-center justify-between gap-3">
                        <span class="truncate text-sm font-medium text-slate-800">{{ $scan->source?->name }}</span>
                        <x-status-badge :status="$scan->status" />
                    </div>
                    <p class="mt-1 text-xs text-slate-500">{{ $scan->message }}</p>
                    <p class="text-xs text-slate-400">{{ $scan->started_at?->diffForHumans() }}</p>
                </div>
            @empty
                <p class="mt-3 text-sm text-slate-500">No scan failures. </p>
            @endforelse
        </div>
    </div>
</div>
