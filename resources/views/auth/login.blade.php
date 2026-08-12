<x-layouts.guest title="Sign in">
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-6">
            <div class="mb-3 inline-block rounded bg-slate-900 px-2 py-1 text-xs font-semibold tracking-wide text-white">
                DCC
            </div>
            <h1 class="text-lg font-semibold">Document Compliance Checker</h1>
            <p class="mt-1 text-sm text-slate-500">Sign in with your work account.</p>
        </div>

        @if ($errors->any())
            <div role="alert" class="mb-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="username"
                       value="{{ old('email') }}"
                       class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500">
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1"
                       class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                Remember me
            </label>

            <button type="submit"
                    class="w-full rounded bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-500 focus:ring-offset-2">
                Sign in
            </button>
        </form>

        <p class="mt-6 text-xs text-slate-400">
            Accounts are created by your system administrator. There is no self-registration.
        </p>
    </div>
</x-layouts.guest>
