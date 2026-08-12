@props(['status'])

{{--
    Status is always rendered as text, never as colour alone (CLAUDE.md 34).
    The colour is a fast visual scan aid; the label is what carries the
    meaning, so the table stays readable when printed or viewed by someone
    who cannot distinguish the palette.
--}}
<span {{ $attributes->merge([
    'class' => 'inline-flex items-center whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset '.$status->cssClasses(),
]) }}>
    {{ $status->label() }}
</span>
