@php($mail = $blueprint->email())
<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans($mail['key'], $mail['params'] + ['{url}' => $url->to('forum')->route('kiosk.purchases')]) !!}
@if (! empty($mail['block']))

{!! $translator->trans('ernestdefoe-kiosk.email.awaiting.pay_to') !!}

{!! $mail['block']['details'] !!}
@if ($mail['block']['instructions'] !== '')

{!! $mail['block']['instructions'] !!}
@endif
@endif
</x-slot:body>
</x-mail::plain.notification>
