{{-- Every Kiosk email: one translated paragraph and a link to the member's purchases. Product names (a member's listing name too) are escaped, HTML and Markdown, before they reach the Markdown converter. --}}
@php($mail = $blueprint->email())
<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans($mail['key'], array_map(fn ($value) => e(preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~<>])/', '\\\\$1', (string) $value)), $mail['params']) + [
            '{url}' => $url->to('forum')->route('kiosk.purchases'),
        ])) !!}
    </x-slot:body>
</x-mail::html.notification>
