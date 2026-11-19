{{--
    Every Kiosk email: one translated paragraph and a link to the member's purchases.
    Flarum 2.0.2+ keeps parameters out of the Markdown parser and escapes them itself
    (Mail\SafeSubstitution); before that, product names (a member's listing name too)
    are escaped here, HTML and Markdown. The account to pay into for a bank transfer
    is its own block: escaped, its lines kept.
--}}
@php($mail = $blueprint->email())
@php($params = class_exists(\Flarum\Mail\SafeSubstitution::class) ? $mail['params'] : array_map(fn ($value) => e(preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~<>])/', '\\\\$1', (string) $value)), $mail['params']))
<x-mail::html.notification>
    <x-slot:body>
        {!! $formatter->convert($translator->trans($mail['key'], $params + [
            '{url}' => $url->to('forum')->route('kiosk.purchases'),
        ])) !!}
        @if (! empty($mail['block']))
            <p><strong>{{ $translator->trans('ernestdefoe-kiosk.email.awaiting.pay_to') }}</strong></p>
            <p style="white-space: pre-line; padding: 12px 16px; border-left: 3px solid #ccc;">{{ $mail['block']['details'] }}</p>
            @if ($mail['block']['instructions'] !== '')
                <p style="white-space: pre-line;">{{ $mail['block']['instructions'] }}</p>
            @endif
        @endif
    </x-slot:body>
</x-mail::html.notification>
