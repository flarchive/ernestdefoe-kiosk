@php($mail = $blueprint->email())
<x-mail::plain.notification>
<x-slot:body>
{!! $translator->trans($mail['key'], $mail['params'] + ['{url}' => $url->to('forum')->route('kiosk.purchases')]) !!}
</x-slot:body>
</x-mail::plain.notification>
