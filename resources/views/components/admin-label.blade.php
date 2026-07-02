{{--
    Admin form label component (Task H20 / audit H41).

    Replaces raw <label> tags in admin forms with an accessible label
    that has the `for` attribute properly set. Previously 55 of 55 admin
    <label> tags were missing `for=` — screen readers announced inputs
    as "edit text" with no label.

    Usage:
        <x-admin-label for="title">Gallery Title *</x-admin-label>
        <input id="title" name="title" ...>

    The `for` attribute MUST match the input's `id` attribute. If you
    change the input's id, change the label's for= too.

    Optional props:
        - required: bool — adds a red asterisk (visual only; the input's
          `required` attribute is the semantic one)
        - optional: bool — adds "(optional)" gray text
--}}
@props(['for' => null, 'required' => false, 'optional' => false])

<label for="{{ $for }}" {{ $attributes->merge(['class' => 'block text-sm font-medium text-gray-300 mb-1.5']) }}>
    {{ $slot }}
    @if($required) <span class="text-red-400" aria-hidden="true">*</span> @endif
    @if($optional) <span class="text-gray-500 text-xs">(optional)</span> @endif
</label>
