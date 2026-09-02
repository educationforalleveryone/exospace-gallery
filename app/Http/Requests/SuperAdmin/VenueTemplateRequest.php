<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\VenueTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates create & update payloads for VenueTemplate.
 *
 * The JSON fields (visual_config, material_config, decorations,
 * lighting_fixtures) accept either:
 *   - a JSON string (sent by the textarea-based JSON editor in the form), or
 *   - a native array (sent if the form is later upgraded to a structured editor).
 *
 * Either way, the validation rules below ensure the resulting array has the
 * expected shape. The controller JSON-decodes strings before validation runs
 * (via prepareForValidation).
 */
class VenueTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isSuperAdmin();
    }

    protected function prepareForValidation(): void
    {
        // Iteration 5 "Authoring" (§9.3): the structured visual/material
        // form posts per-key inputs where an EMPTY field means "inherit the
        // viewer default". Strip empty values BEFORE validation runs so
        // nullable / Rule::in / regex rules never see blank strings.
        foreach (['visual_config', 'material_config'] as $field) {
            if ($this->has($field) && is_array($this->input($field))) {
                $this->merge([$field => array_filter(
                    $this->input($field),
                    fn ($v) => $v !== '' && $v !== null
                )]);
            }
        }

        // Decode JSON-as-string fields submitted by the textarea editors.
        foreach (['tags', 'visual_config', 'material_config', 'decorations', 'lighting_fixtures', 'supported_layouts', 'visual_config_advanced'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $raw = trim($this->input($field));
                if ($raw === '') {
                    $this->merge([$field => null]);
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->merge([$field => $decoded]);
                } else {
                    // Leave the original string in place — validation below
                    // will reject it with a friendly message.
                    $this->merge([$field => '__INVALID_JSON__']);
                }
            }
        }
    }

    public function rules(): array
    {
        $venueId = $this->route('venue')?->id;

        return [
            'name'          => ['required', 'string', 'max:100'],
            'slug'          => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+$/',
                                Rule::unique('venue_templates', 'slug')->ignore($venueId)],
            'description'   => ['required', 'string', 'max:1000'],
            'category'      => ['required', 'string', Rule::in(array_keys(VenueTemplate::CATEGORIES))],
            'plan_required' => ['required', 'string', Rule::in(VenueTemplate::PLANS)],
            'capacity_min'  => ['required', 'integer', 'min:1'],
            'capacity_max'  => ['nullable', 'integer', 'min:1', 'gte:capacity_min'],

            'tags'                => ['nullable', 'array'],
            'tags.*'              => ['string', 'max:40'],

            'visual_config'       => ['nullable', 'array'],
            'visual_config.wall_height'         => ['nullable', 'numeric', 'min:1', 'max:50'],
            'visual_config.wall_depth'          => ['nullable', 'numeric', 'min:0.05', 'max:5'],
            'visual_config.ceiling_type'        => ['nullable', 'string', Rule::in(['flat', 'beamed', 'glass', 'none'])],
            'visual_config.ceiling_height'      => ['nullable', 'numeric', 'min:1', 'max:50'],
            'visual_config.background_color'    => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{6}$/'],
            'visual_config.fog_color'           => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{6}$/'],
            'visual_config.fog_near'            => ['nullable', 'numeric', 'min:0'],
            'visual_config.fog_far'             => ['nullable', 'numeric', 'min:0'],
            'visual_config.ambient_color'       => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{6}$/'],
            'visual_config.ambient_intensity'   => ['nullable', 'numeric', 'min:0', 'max:5'],
            'visual_config.spot_intensity'      => ['nullable', 'numeric', 'min:0', 'max:10'],
            'visual_config.fill_intensity'      => ['nullable', 'numeric', 'min:0', 'max:5'],
            'visual_config.tone_mapping_exposure' => ['nullable', 'numeric', 'min:0', 'max:3'],
            'visual_config.frame_override'      => ['nullable', 'string', Rule::in(['gold', 'silver', 'bronze', 'black', 'white'])],

            // ── Iteration 6 "Consolidation" (P2.2): the declared shell +
            // structure keys. These used to live ONLY in the advanced JSON —
            // they are stable vocabulary now, so flat validation catches
            // typos at the form (a malformed ceiling_color or an unknown
            // structure_pass would otherwise fail silently at runtime).
            'visual_config.ceiling_color'   => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{6}$/'],
            'visual_config.ceiling_beams'   => ['nullable', 'boolean'],
            'visual_config.ceiling_neon'    => ['nullable', 'boolean'],
            'visual_config.open_air'        => ['nullable', 'boolean'],
            'visual_config.layout_shape'    => ['nullable', 'string', Rule::in(['circular'])],
            'visual_config.structure_pass'  => ['nullable', 'string', Rule::in(['rooms', 'cube', 'loft', 'museum', 'garden', 'phenomena'])],
            'visual_config.void_dust'       => ['nullable', 'boolean'],
            'visual_config.void_starfield'  => ['nullable', 'boolean'],
            'visual_config.void_colonnade'  => ['nullable', 'boolean'],
            'visual_config.void_shards'     => ['nullable', 'boolean'],
            'visual_config.void_lake'       => ['nullable', 'boolean'],

            // ── Iteration 6 curation (P2.3, §6.3–§6.5): opt-in placement.
            // Absent block ⇒ uniform default hang; the config IS the switch.
            'visual_config.placement'                   => ['nullable', 'array'],
            'visual_config.placement.density'           => ['nullable', 'string', Rule::in(['intimate', 'standard', 'generous'])],
            'visual_config.placement.pair_orientation'  => ['nullable', 'boolean'],
            'visual_config.placement.focal_wall'        => ['nullable', 'string', Rule::in(['front', 'back', 'left', 'right'])],

            'material_config'     => ['nullable', 'array'],
            'material_config.wall_color'          => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{6}$/'],
            'material_config.wall_roughness'      => ['nullable', 'numeric', 'min:0', 'max:1'],
            'material_config.wall_metalness'      => ['nullable', 'numeric', 'min:0', 'max:1'],
            'material_config.wall_normal_strength'=> ['nullable', 'numeric', 'min:0', 'max:5'],
            'material_config.floor_color'         => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{6}$/'],
            'material_config.floor_roughness'     => ['nullable', 'numeric', 'min:0', 'max:1'],
            'material_config.floor_metalness'     => ['nullable', 'numeric', 'min:0', 'max:1'],
            'material_config.floor_normal_strength' => ['nullable', 'numeric', 'min:0', 'max:5'],

            'decorations'         => ['nullable', 'array', 'max:100'],
            'decorations.*.type'          => ['required_with:decorations', 'string'],
            'decorations.*.model_path'    => ['required_with:decorations', 'string'],
            'decorations.*.position'      => ['required_with:decorations', 'array', 'size:3'],
            'decorations.*.position.*'    => ['numeric'],
            'decorations.*.rotation'      => ['nullable', 'array', 'size:3'],
            'decorations.*.rotation.*'    => ['numeric'],
            'decorations.*.scale'         => ['nullable'],
            'decorations.*.plan_required' => ['nullable', 'string', Rule::in(VenueTemplate::PLANS)],

            'lighting_fixtures'   => ['nullable', 'array', 'max:60'],
            'lighting_fixtures.*.type'        => ['required_with:lighting_fixtures', 'string', Rule::in(['point', 'spot', 'directional', 'strip'])],
            'lighting_fixtures.*.position'    => ['required_with:lighting_fixtures', 'array', 'size:3'],
            'lighting_fixtures.*.position.*'  => ['numeric'],
            'lighting_fixtures.*.color'       => ['nullable', 'string', 'regex:/^0x[0-9a-fA-F]{6}$/'],
            'lighting_fixtures.*.intensity'   => ['nullable', 'numeric', 'min:0', 'max:20'],
            'lighting_fixtures.*.cast_shadow' => ['nullable', 'boolean'],
            'lighting_fixtures.*.distance'    => ['nullable', 'numeric', 'min:0'],
            'lighting_fixtures.*.decay'       => ['nullable', 'numeric', 'min:0'],

            'supported_layouts'   => ['nullable', 'array'],
            'supported_layouts.*' => ['string', Rule::in(VenueTemplate::LAYOUTS)],

            // Iteration 5 "Authoring" (§9.3): the structured visual/material
            // inputs only manage the stable flat keys. This field carries the
            // REST of visual_config — structure descriptors (IT3),
            // structure_pass / glazing_wall / sun_shadows gates, placement,
            // tier_fallbacks, anything a 3D pipeline pastes in. Schema hint,
            // not schema prison: decoded to an array (or null), merged over
            // the structured keys by the controller.
            'visual_config_advanced' => ['nullable', 'array'],

            'is_active'    => ['boolean'],
            'is_featured'  => ['boolean'],
            'is_draft'     => ['boolean'],
            'sort_order'   => ['integer', 'min:0'],
            'version'      => ['nullable', 'string', 'max:16', 'regex:/^\d+\.\d+\.\d+$/'],

            // File uploads
            'thumbnail_image'  => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'preview_model'    => ['nullable', 'file', 'mimes:glb,gltf', 'max:51200'], // 50 MB
            'hdri_file'        => ['nullable', 'file', 'mimes:hdr,exr', 'max:51200'],
            'default_audio'    => ['nullable', 'file', 'mimes:mp3,wav,m4a', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'visual_config.background_color.regex' => 'Background color must be a hex string like 0x0f0f0f.',
            'visual_config.fog_color.regex'        => 'Fog color must be a hex string like 0x0f0f0f.',
            'visual_config.ambient_color.regex'    => 'Ambient color must be a hex string like 0x0f0f0f.',
            'material_config.wall_color.regex'     => 'Wall color must be a hex string like 0xffffff.',
            'material_config.floor_color.regex'    => 'Floor color must be a hex string like 0xffffff.',
            'preview_model.mimes'                  => 'Preview model must be a .glb or .gltf file.',
            'hdri_file.mimes'                      => 'HDRI must be a .hdr or .exr file.',
            'slug.regex'                           => 'Slug may only contain lowercase letters, numbers, and hyphens.',
            'visual_config_advanced.array'         => 'The advanced visual_config must be a valid JSON object (e.g. {"structure": []}).',
            'decorations.max'                      => 'A venue can hold at most 100 decoration props (draw-call budget, §11.4).',
            'lighting_fixtures.max'                => 'A venue can declare at most 60 custom light fixtures.',
            // Iteration 6 consolidation + curation keys
            'visual_config.ceiling_color.regex'    => 'Ceiling color must be a hex string like 0x080808.',
            'visual_config.layout_shape.in'        => 'Layout shape may be "circular" (or left empty for the room_layout default).',
            'visual_config.structure_pass.in'      => 'Structure pass must be one of: rooms, cube, loft, museum, garden, phenomena.',
            'visual_config.placement.density.in'   => 'Placement density must be one of: intimate, standard, generous.',
            'visual_config.placement.focal_wall.in'=> 'Focal wall must be one of: front, back, left, right.',
        ];
    }

    /**
     * Iteration 5 "Authoring" (§9.3): semantic-slip validation — the cheap
     * cross-field checks that prevent silently broken venues. These are
     * mistakes a JSON editor makes easily and a walkthrough catches late:
     * fog that ends before it starts, a ceiling below the walls, props
     * parked at non-numeric coordinates.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $visual = $this->input('visual_config');

            if (!is_array($visual)) {
                return;
            }

            // Fog must extend forward, not fold inside itself.
            if (isset($visual['fog_near'], $visual['fog_far'])
                && is_numeric($visual['fog_near'])
                && is_numeric($visual['fog_far'])
                && (float) $visual['fog_far'] <= (float) $visual['fog_near']) {
                $validator->errors()->add(
                    'visual_config.fog_far',
                    'Fog far distance must be greater than fog near (far '.((string) $visual['fog_far']).' ≤ near '.((string) $visual['fog_near']).').'
                );
            }

            // A ceiling can never sit below the walls that hold it.
            if (isset($visual['ceiling_height'], $visual['wall_height'])
                && is_numeric($visual['ceiling_height'])
                && is_numeric($visual['wall_height'])
                && (float) $visual['ceiling_height'] < (float) $visual['wall_height']) {
                $validator->errors()->add(
                    'visual_config.ceiling_height',
                    'Ceiling height ('.((string) $visual['ceiling_height']).') cannot be lower than wall height ('.((string) $visual['wall_height']).').'
                );
            }
        });
    }
}
