<div>
    <x-page-header title="Side-by-side comparison"
                   :description="$document->displayTitle()">
        <x-slot:actions>
            <a href="{{ route('documents.show', $document) }}"
               class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Back to document
            </a>
            <button type="button" wire:click="refresh" wire:loading.attr="disabled"
                    class="rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="refresh">Read again</span>
                <span wire:loading wire:target="refresh">Reading…</span>
            </button>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('status') }}
        </div>
    @endif

    {{-- What this page does and does not claim --------------------------------}}
    <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
        <p class="text-sm text-slate-700">
            The three languages, paired up by section. The application does not judge whether a
            translation is <em>correct</em> — it puts the passages next to each other so you can.
        </p>
        <p class="mt-1 text-xs text-slate-500">
            Text is read from the source file each time this page is opened and is never stored.
            Each paragraph is shown under whichever language it is mostly written in; the columns
            are not a paragraph-by-paragraph correspondence.
        </p>
    </div>

    @if ($unavailableReason !== null)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-5">
            <h2 class="text-sm font-semibold text-amber-900">Nothing to compare</h2>
            <p class="mt-1 text-sm text-amber-800">{{ $unavailableReason }}</p>
        </div>
    @else
        @if ($extraction->truncated)
            <div class="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                This document is long and was cut short. You are not looking at all of it.
            </div>
        @endif

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-slate-500">
                {{ $extraction->sectionCount() }} section{{ $extraction->sectionCount() === 1 ? '' : 's' }}
                read by the <span class="font-medium">{{ $extraction->parser }}</span> parser.
            </p>

            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model.live="onlyGaps"
                       class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                Only sections with a missing language
            </label>
        </div>

        @forelse ($sections as $section)
            <div class="mb-4 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2">
                    <h2 class="text-sm font-semibold text-slate-900">{{ $section->name }}</h2>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($section->page !== null)
                            <span class="text-xs text-slate-500">Page {{ $section->page }}</span>
                        @endif

                        @foreach ($section->missing as $code)
                            <span class="rounded bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">
                                No {{ \App\Enums\LanguageCode::from($code)->label() }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full table-fixed border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500">
                                @foreach ($languages as $language)
                                    <th class="w-1/3 px-4 py-2 align-bottom">
                                        {{ $language->label() }}
                                        <span class="ml-1 font-normal normal-case text-slate-400">
                                            {{ number_format($section->charactersFor($language)) }} chars
                                        </span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @for ($row = 0; $row < $section->rowCount(); $row++)
                                <tr class="border-b border-slate-100 last:border-0 align-top">
                                    @foreach ($languages as $language)
                                        @php $segments = $section->segmentsFor($language); @endphp
                                        <td class="px-4 py-2 text-slate-700">
                                            @if (isset($segments[$row]))
                                                <p class="whitespace-pre-wrap break-words">{{ $segments[$row] }}</p>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>

                @if ($section->unassigned !== [])
                    <div class="border-t border-slate-100 bg-slate-50/60 px-4 py-2">
                        <p class="text-xs font-medium text-slate-500">
                            Not attributable to a language ({{ count($section->unassigned) }})
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Codes, figures and tables. Shown so nothing in the section is hidden from review.
                        </p>
                        <ul class="mt-2 space-y-1">
                            @foreach ($section->unassigned as $text)
                                <li class="whitespace-pre-wrap break-words text-xs text-slate-600">{{ $text }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-lg border border-slate-200 bg-white p-8 text-center">
                <p class="text-sm text-slate-600">
                    @if ($onlyGaps)
                        Every section contains all three languages.
                    @else
                        No readable text was found in this document.
                    @endif
                </p>
            </div>
        @endforelse
    @endif
</div>
