{{-- Every Kiosk email: one translated paragraph and a link to the member's purchases. Product names are escaped before they reach the Markdown converter. --}}
@php($mail = $blueprint->email())
<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans($mail['key'], array_map(fn ($value) => e($value), $mail['params']) + [
            '{url}' => $url->to('forum')->route('kiosk.purchases'),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
