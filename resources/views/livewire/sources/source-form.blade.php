@use('App\Enums\DocumentSourceType')

<div>
    <x-page-header :title="$source?->exists ? 'Edit source' : 'Add document source'"
                   description="Where the application should look for controlled documents." />

    <form wire:submit="save" class="max-w-2xl space-y-5 rounded-lg border border-slate-200 bg-white p-6">

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700">Name</label>
            <input id="name" type="text" wire:model="name" placeholder="SOP Shared Folder"
                   class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
            @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="type" class="block text-sm font-medium text-slate-700">Type</label>
            <select id="type" wire:model.live="type"
                    class="mt-1 w-full rounded border border-slate-300 px-2 py-2 text-sm">
                @foreach ($types as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            @error('type') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror

            @if ($type === DocumentSourceType::SHAREPOINT->value)
                <p class="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    SharePoint sources can be registered now, but scanning arrives with the Microsoft Graph
                    integration in Phase 3. Only non-sensitive identifiers are stored here — credentials
                    always come from the server environment.
                </p>
            @endif
        </div>

        @if ($type === DocumentSourceType::SHAREPOINT->value)
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="siteId" class="block text-sm font-medium text-slate-700">Site ID</label>
                    <input id="siteId" type="text" wire:model="siteId"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs">
                    @error('siteId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="driveId" class="block text-sm font-medium text-slate-700">Drive ID</label>
                    <input id="driveId" type="text" wire:model="driveId"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs">
                    @error('driveId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="folderPath" class="block text-sm font-medium text-slate-700">Folder path</label>
                    <input id="folderPath" type="text" wire:model="folderPath"
                           placeholder="/sites/DocumentControl/Documents"
                           class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    @error('folderPath') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        @else
            <div>
                <label for="path" class="block text-sm font-medium text-slate-700">Folder path</label>
                <input id="path" type="text" wire:model="path"
                       placeholder="\\fileserver\DocumentControl\SOP"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm">
                @error('path') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-slate-500">
                    An absolute path such as <code class="rounded bg-slate-100 px-1">D:\DocumentControl\SOP</code>,
                    a UNC path, or a mount point on Linux. The account running the web server
                    <em>and the queue worker</em> must have read access — an interactive login having access
                    is not enough.
                </p>
            </div>
        @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="scanIntervalMinutes" class="block text-sm font-medium text-slate-700">Scan interval (minutes)</label>
                <input id="scanIntervalMinutes" type="number" min="5" max="10080" wire:model="scanIntervalMinutes"
                       class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @error('scanIntervalMinutes') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="enabled" class="rounded border-slate-300 text-slate-900">
                    Enabled
                </label>
            </div>
        </div>

        <div class="flex items-center gap-3 border-t border-slate-100 pt-4">
            <button type="submit" wire:loading.attr="disabled"
                    class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">{{ $source?->exists ? 'Save changes' : 'Create source' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ route('sources.index') }}" class="text-sm text-slate-600 hover:underline">Cancel</a>
        </div>
    </form>
</div>
