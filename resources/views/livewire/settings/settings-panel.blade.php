<div>
    <x-page-header title="Settings"
                   description="Business thresholds and limits. Nothing here is hard-coded in the application." />

    <form wire:submit="save" class="max-w-3xl space-y-4">

        @php
            $groups = collect($definitions)->groupBy('group');
            $groupTitles = [
                'thresholds' => 'Trilingual thresholds',
                'uploads' => 'Uploads',
                'scanning' => 'Scanning',
                'analysis' => 'Analysis features',
                'rules' => 'Document Control rules',
                'housekeeping' => 'Housekeeping',
            ];
        @endphp

        @foreach ($groups as $group => $items)
            <div class="rounded-lg border border-slate-200 bg-white p-6">
                <h2 class="text-sm font-semibold text-slate-900">{{ $groupTitles[$group] ?? ucfirst($group) }}</h2>

                <div class="mt-4 space-y-4">
                    @foreach ($items as $key => $definition)
                        <div>
                            @if ($definition['type'] === 'boolean')
                                <label class="flex items-start gap-2">
                                    <input type="checkbox" wire:model="values.{{ $key }}"
                                           class="mt-0.5 rounded border-slate-300 text-slate-900">
                                    <span>
                                        <span class="block text-sm font-medium text-slate-700">{{ $definition['label'] }}</span>
                                        <span class="block text-xs text-slate-500">{{ $definition['description'] }}</span>
                                    </span>
                                </label>
                            @else
                                <label for="setting-{{ $key }}" class="block text-sm font-medium text-slate-700">
                                    {{ $definition['label'] }}
                                </label>
                                <input id="setting-{{ $key }}"
                                       type="{{ in_array($definition['type'], ['integer', 'float'], true) ? 'number' : 'text' }}"
                                       @if ($definition['type'] === 'float') step="0.1" @endif
                                       wire:model="values.{{ $key }}"
                                       class="mt-1 w-full max-w-sm rounded border border-slate-300 px-3 py-2 text-sm">
                                <p class="mt-1 text-xs text-slate-500">{{ $definition['description'] }}</p>
                            @endif

                            @error('values.'.$key) <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                @if ($group === 'rules')
                    <p class="mt-4 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        All rules are off until you turn them on. Each one states how documents in
                        <em>this</em> organisation are supposed to look, which is your decision rather than
                        a default. Turning one on applies it to every analysis from that point; documents
                        already analysed keep the result they were given until they are re-analyzed.
                    </p>
                @endif

                @if ($group === 'uploads')
                    <p class="mt-4 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        <strong>Always blocked, regardless of the list above:</strong>
                        {{ implode(', ', $blockedExtensions) }}.
                        Uploads are also checked against their detected content type, so renaming a file
                        will not get it past this.
                    </p>
                @endif
            </div>
        @endforeach

        <div class="flex items-center gap-3">
            <button type="submit" wire:loading.attr="disabled"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">Save settings</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <p class="text-xs text-slate-500">
                Changes apply to future analyses. Results already recorded keep the threshold they were graded against.
            </p>
        </div>
    </form>
</div>
