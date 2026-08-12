<div>
    <x-page-header title="Upload document"
                   description="Add a controlled document that does not live in a scanned folder." />

    <form wire:submit="save" class="max-w-2xl space-y-5 rounded-lg border border-slate-200 bg-white p-6">

        <div>
            <label for="file" class="block text-sm font-medium text-slate-700">Document file</label>
            <input id="file" type="file" wire:model="file"
                   accept="{{ collect($allowedExtensions)->map(fn ($e) => '.'.$e)->implode(',') }}"
                   class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium">

            <p class="mt-1 text-xs text-slate-500">
                {{ mb_strtoupper(implode(', ', $allowedExtensions)) }} only, up to {{ $maxSizeMb }} MB.
                Contents are checked against the extension — a renamed file will be rejected.
            </p>

            <div wire:loading wire:target="file" class="mt-1 text-xs text-slate-500">Uploading…</div>
            @error('file') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="documentCode" class="block text-sm font-medium text-slate-700">Document code</label>
                <input id="documentCode" type="text" wire:model="documentCode" placeholder="SOP-QA-001"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @error('documentCode') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="revision" class="block text-sm font-medium text-slate-700">Revision</label>
                <input id="revision" type="text" wire:model="revision" placeholder="Rev. 03"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @error('revision') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="sm:col-span-2">
                <label for="documentTitle" class="block text-sm font-medium text-slate-700">Title</label>
                <input id="documentTitle" type="text" wire:model="documentTitle"
                       placeholder="Left blank, the file name is used"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @error('documentTitle') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="documentType" class="block text-sm font-medium text-slate-700">Document type</label>
                <select id="documentType" wire:model="documentType"
                        class="mt-1 w-full rounded border border-slate-300 px-2 py-2 text-sm">
                    <option value="">Detect from file name</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
                @error('documentType') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="department" class="block text-sm font-medium text-slate-700">Department</label>
                <input id="department" type="text" wire:model="department"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @error('department') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
            <button type="submit" wire:loading.attr="disabled" wire:target="save,file"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">Upload</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ route('documents.index') }}" class="text-sm text-slate-600 hover:underline">Cancel</a>
        </div>

        <p class="border-t border-slate-100 pt-4 text-xs text-slate-500">
            The document is stored under a generated name and queued for analysis. Until the Phase 2
            analyzer is enabled it will stay at <strong>Pending</strong>.
        </p>
    </form>
</div>
