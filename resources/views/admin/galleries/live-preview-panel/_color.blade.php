@php
    /**
     * Color picker row for the Live Preview panel.
     *
     * Vars:
     *   $id       string  — control id (also the JSON key)
     *   $label    string  — human label
     *   $value    string  — current value, in '0xRRGGBB' form (from venue/override)
     *   $default  string  — venue default, in '0xRRGGBB' form
     *   $group    string  — 'visual_config' | 'material_config' | 'post_fx'
     *   $hint     string  — one-line explanation
     *
     * The HTML <input type="color"> needs '#RRGGBB' form, so we convert here.
     * The parent JS converts back to '0xRRGGBB' before storing in the state
     * (and before posting to the iframe).
     */
    $toHex = fn($v) => $v && str_starts_with($v, '0x')
        ? '#' . substr($v, 2)
        : ($v && str_starts_with($v, '#') ? $v : '#0a0a0a');
    $hexValue = $toHex($value);
    $hexDefault = $toHex($default);
@endphp

<div class="lp-control" data-lp-control-wrapper="{{ $id }}">
    <div class="flex items-center justify-between mb-1">
        <label class="text-xs font-medium text-gray-300 flex items-center gap-1.5 cursor-help" data-lp-hint-trigger="{{ $id }}">
            {{ $label }}
            <svg class="w-3 h-3 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
        </label>
        <button type="button" data-lp-reset-for="{{ $id }}"
                class="p-2 -m-1.5 rounded-md text-xs text-gray-500 hover:text-brand-300 hover:bg-white/[0.06] transition"
                title="Reset to venue default"
                aria-label="Reset {{ $label }} to venue default">↺</button>
    </div>

    <div class="flex items-center gap-2">
        <input type="color"
               data-lp-control
               data-lp-key="{{ $id }}"
               data-lp-group="{{ $group }}"
               data-lp-default="{{ $hexDefault }}"
               data-lp-requires-reload="false"
               value="{{ $hexValue }}"
               class="w-8 h-8 rounded border border-gray-600 cursor-pointer bg-transparent" />
        <span class="text-xs text-gray-400 font-mono tabular-nums">{{ strtoupper($hexValue) }}</span>
    </div>

    <div data-lp-hint-popover="{{ $id }}"
         class="hidden mt-1 w-full bg-gray-900 border border-gray-700 rounded-lg shadow-xl p-3 text-xs text-gray-300">
        <div>{{ $hint }}</div>
        <div class="mt-2 pt-2 border-t border-gray-700 text-xs text-gray-500">
            Venue default: <span class="text-gray-400 font-mono">{{ strtoupper($hexDefault) }}</span>
        </div>
    </div>
</div>
