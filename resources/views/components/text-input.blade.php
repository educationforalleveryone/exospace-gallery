@props(['disabled' => false])

{{-- Uses the shared `.input-base` recipe from resources/css/app.css.
     Focus = brand border + soft ring; error = add `.input-error` and
     aria-invalid="true" at the call site. --}}
<input
    @disabled($disabled)
    {{ $attributes->merge(['class' => 'input-base']) }}
>
