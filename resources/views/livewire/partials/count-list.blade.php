{{--
    A label/count list with a proportional bar.
    Expects: $counts (array<string,int>), $empty (string)
--}}
@php $max = $counts === [] ? 0 : max($counts); @endphp

@forelse ($counts as $label => $count)
    <div class="mt-3 first:mt-4">
        <div class="flex items-baseline justify-between text-sm">
            <span class="truncate pr-3 text-slate-700">{{ $label }}</span>
            <span class="tabular-nums text-slate-600">{{ number_format($count) }}</span>
        </div>
        <div class="mt-1 h-1.5 w-full overflow-hidden rounded bg-slate-100">
            <div class="h-full rounded bg-slate-400"
                 style="width: {{ $max > 0 ? round(($count / $max) * 100, 1) : 0 }}%"></div>
        </div>
    </div>
@empty
    <p class="mt-3 text-sm text-slate-500">{{ $empty }}</p>
@endforelse
