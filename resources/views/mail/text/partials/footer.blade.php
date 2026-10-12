{{--
    The plain-text part's footer. Text parts use `{!! !!}` throughout: this is not HTML, and
    escaping it would mail "Don&#039;t" and "Smith &amp; Co" to anybody whose client shows
    the text part — a screen reader in a text client, a watch, a gateway that strips HTML.
--}}

--
{!! __('mail.layout.footer', ['year' => date('Y'), 'brand' => $brand]) !!}
