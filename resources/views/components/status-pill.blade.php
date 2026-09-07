{{--
    One badge, one meaning. Down is a critical monitor failing; degraded is
    something failing that nobody promised was load-bearing; quiet is jobs that
    have stopped without the site going with them.
--}}
@props(['status', 'label' => null])

@php
    $styles = match ($status) {
        'down' => 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
        'degraded' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        'quiet' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
        'paused' => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
        'up' => 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
        default => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
    };

    $dot = match ($status) {
        'down' => 'bg-red-500',
        'degraded', 'quiet' => 'bg-amber-500',
        'paused' => 'bg-zinc-400',
        'up' => 'bg-green-500',
        default => 'bg-zinc-400',
    };
@endphp

<span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium {{ $styles }}">
    <span class="size-1.5 rounded-full {{ $dot }}"></span>
    {{ $label ?? ucfirst($status) }}
</span>
