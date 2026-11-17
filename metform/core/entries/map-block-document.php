<?php
namespace MetForm\Core\Entries;

use MetForm\Core\FormBuilder\Block_Registry;
use MetForm_Pro\Core\FormBuilder\Blocks\Simplerepeater\Simplerepeater;

defined('ABSPATH') || exit;


final class Map_Block_Document {

    private const WIDGET_TYPE_MAP = [
        'creditcard' => 'mf-credit-card',
        'simplerepeater' => 'mf-simple-repeater',
        'fileupload' => 'mf-file-upload',
        'urlinput' => 'mf-url',
        'simplecaptcha' => 'mf-simple-captcha',
        'recaptcha' => 'mf-recaptcha',
        'maplocation' => 'mf-map-location',
        'text-editor' => 'mf-text-editor',
        'paymentmethod' => 'mf-payment-method',
        'imageselect' => 'mf-image-select',
        'toggleselect' => 'mf-toggle-select',
        'likedislike' => 'mf-like-dislike',
        'switch-field' => 'mf-switch',
        'colorpicker' => 'mf-color-picker',
        'rangeslider' => 'mf-range',
        // Message is a Textarea under another name (it extends the Textarea
        // block) — map it to the same legacy widgetType so entries keep its
        // line breaks (form-data.php's 'mf-textarea' handling) exactly like
        // a Textarea's.
        'message' => 'mf-textarea',
    ];

    /**
     * Block types with no real per-instance `name` semantics — like
     * `Map_El::search_el()`'s existing behavior for any legacy Elementor
     * widget whose settings have no `mf_input_name` at all (recaptcha,
     * simple-captcha): register the field keyed by its widgetType string
     * instead of by name, unconditionally, regardless of whatever the block's
     * own (cosmetic-only, for settings-panel UI parity) `name` prop holds or
     * whether it's even set. Without this, `simplecaptcha` fell through the
     * normal name-keyed path below — which both (a) skips registration
     * entirely whenever `name` is blank (its default), and (b) even when
     * set, would key the field by the user's arbitrary name rather than the
     * literal `mf-simple-captcha` string `core/entries/action.php` checks
     * for by exact key (`isset($this->fields['mf-simple-captcha'])`) — so
     * the security check there could never see a form-builder captcha block
     * at all. See `core/form-builder/blocks/simplecaptcha/simplecaptcha.php`'s
     * class doc comment for the full picture (that block also always
     * submits its input as `mf-captcha-challenge`, matching the legacy
     * widget, regardless of the `name` prop).
     */
    private const UNNAMED_WIDGET_TYPES = ['simplecaptcha', 'recaptcha'];

    /** Matches inputControls.js/optionControls.js's `validation.type` values to the legacy strings validate_form_data() switches on. */
    private const VALIDATION_TYPE_MAP = [
        'character' => 'by_character_length',
        'word' => 'by_word_length',
        'pattern' => 'by_expresssion_based',
    ];

    /** @param array $document A sanitized core/form-builder document ({version, elements: [...]}). */
    public static function fields(array $document): array {
        $fields = [];

        foreach (($document['elements'] ?? []) as $element) {
            if (!is_array($element)) {
                continue;
            }

            $type = (string) ($element['type'] ?? '');
            $props = is_array($element['props'] ?? null) ? $element['props'] : [];
            $widget_type = self::WIDGET_TYPE_MAP[$type] ?? ('mf-' . $type);

            if (null === Block_Registry::instance()->get($type)) {
                continue;
            }

            if (in_array($type, self::UNNAMED_WIDGET_TYPES, true)) {
                $fields[$widget_type] = self::create_field([
                    'mf_input_name' => $widget_type,
                    'mf_input_label' => is_scalar($props['label'] ?? null) ? (string) $props['label'] : '',
                    'mf_input_required' => !empty($props['required']) ? 'yes' : 'no',
                    'mf_input_placeholder' => is_scalar($props['placeholder'] ?? null) ? (string) $props['placeholder'] : '',
                    'widgetType' => $widget_type,
                ]);
                continue;
            }

            $name = is_scalar($props['name'] ?? null) ? (string) $props['name'] : '';

            // Non-field blocks (title/paragraph/button/responsemessage/...)
            // never have a `name` prop — nothing to register.
            if ('' === $name) {
                continue;
            }

            $validation = is_array($props['validation'] ?? null) ? $props['validation'] : [];
            $validation_type = self::VALIDATION_TYPE_MAP[$validation['type'] ?? ''] ?? 'none';
            $min_value = '';
            $max_value = '';
            if ('number' === $type && (self::has_value($props['min'] ?? null) || self::has_value($props['max'] ?? null))) {
                $validation_type = 'by_range';
                $min_value = $props['min'] ?? '';
                $max_value = $props['max'] ?? '';
            }

            $field_obj = self::create_field([
                'mf_input_name' => $name,
                'mf_input_label' => is_scalar($props['label'] ?? null) ? (string) $props['label'] : '',
                'mf_input_required' => !empty($props['required']) ? 'yes' : 'no',
                'mf_input_min_length' => $validation['min'] ?? '',
                'mf_input_max_length' => $validation['max'] ?? '',
                'mf_input_min_value' => $min_value,
                'mf_input_max_value' => $max_value,
                'mf_input_validation_type' => $validation_type,
                'mf_input_validation_expression' => is_scalar($validation['value'] ?? null) ? (string) $validation['value'] : '',
                'mf_input_validation_message' => is_scalar($validation['message'] ?? null) ? (string) $validation['message'] : '',
                'mf_input_placeholder' => is_scalar($props['placeholder'] ?? null) ? (string) $props['placeholder'] : '',
                'widgetType' => $widget_type,
            ]);

            // Choice-based blocks: bridge block options into mf_input_list
            // so form-data entries and emails can resolve option labels alongside values.
            $choice_types = ['select', 'radio', 'checkbox', 'imageselect', 'toggleselect'];
            if (in_array($type, $choice_types, true) && !empty($props['options']) && is_array($props['options'])) {
                $field_obj->mf_input_list = self::normalize_options($props['options']);
            }

            if ('maplocation' === $type && !empty($props['showMap'])) {
                $base_label = (is_scalar($props['label'] ?? null) && '' !== (string) $props['label']) ? (string) $props['label'] : '';
                $map_coords = [
                    'lat' => $base_label ? ($base_label . ' (Latitude)') : 'Latitude',
                    'lng' => $base_label ? ($base_label . ' (Longitude)') : 'Longitude',
                ];
                foreach ($map_coords as $suffix => $coord_label) {
                    $coord_name = $name . '_' . $suffix;
                    $fields[$coord_name] = self::create_field([
                        'mf_input_name' => $coord_name,
                        'mf_input_label' => $coord_label,
                        'widgetType' => $widget_type,
                    ]);
                }
            }
            // Simple Repeater (Pro): describe its sub-fields the way the
            // legacy Elementor widget did, so the entries screen shows each
            // value under its label ("Phone: …") instead of the raw key
            // ("phone-1: …"). Uses the block's own name/label resolution.
            if ('simplerepeater' === $type && class_exists(Simplerepeater::class)) {
                $field_obj->mf_input_repeater = array_map(
                    static function (array $sub): array {
                        $options_raw = $sub['options'] ?? '';
                        $options_str = '';

                        if (is_array($options_raw)) {
                            $lines = [];
                            foreach ($options_raw as $o) {
                                if (is_array($o)) {
                                    $lbl = (string) ($o['label'] ?? $o['value'] ?? '');
                                    $val = (string) ($o['value'] ?? $o['label'] ?? '');
                                } else {
                                    $lbl = (string) $o;
                                    $val = (string) $o;
                                }
                                if ('' !== $lbl) {
                                    $lines[] = "$lbl|$val";
                                }
                            }
                            $options_str = implode("\n", $lines);
                        } elseif (is_string($options_raw) && '' !== trim($options_raw)) {
                            $items = array_filter(array_map('trim', explode(',', $options_raw)));
                            $lines = [];
                            foreach ($items as $item) {
                                $lines[] = "$item|$item";
                            }
                            $options_str = implode("\n", $lines);
                        }

                        return [
                            'mf_input_repeater_label'  => $sub['label'],
                            'mf_input_repeater_name'   => $sub['name'],
                            'mf_input_repeater_type'   => (string) ($sub['type'] ?? 'text'),
                            'mf_input_repeater_option' => $options_str,
                        ];
                    },
                    Simplerepeater::normalize_fields($props['fields'] ?? null)
                );
            }
            if ('paymentmethod' === $type) {
                $field_obj->mf_input_payment_field_name = is_scalar($props['paymentFieldName'] ?? null) ? (string) $props['paymentFieldName'] : '';
                $field_obj->mf_input_payment_field_amount = is_scalar($props['paymentFieldAmount'] ?? null) ? (string) $props['paymentFieldAmount'] : '';
            }
            // File Upload: bridge the block's own type/size settings into the
            // legacy keys File_Data_Validation reads. Without these it fell
            // back to a hardcoded jpg/jpeg/gif/png list and no size limit,
            // rejecting e.g. a PDF the block itself allowed.
            if ('fileupload' === $type) {
                $field_obj->mf_input_file_types = self::file_types($props['allowedTypes'] ?? null);

                // Same default as Fileupload::get_default_settings(), so the
                // server limit always matches what the frontend enforces.
                $max_kb = (int) ($props['maxSize'] ?? 128);
                $field_obj->mf_input_file_size_status = $max_kb > 0 ? 'on' : '';
                $field_obj->mf_input_file_size_limit = $max_kb;
            }

            $fields[$name] = $field_obj;
        }

        return $fields;
    }

    /**
     * Creates a standardized field object with default properties merged with overrides.
     */
    private static function create_field(array $data = []): \stdClass {
        return (object) array_merge([
            'mf_input_name' => '',
            'mf_input_label' => '',
            'mf_input_required' => 'no',
            'mf_input_min_length' => '',
            'mf_input_max_length' => '',
            'mf_input_min_value' => '',
            'mf_input_max_value' => '',
            'mf_input_validation_type' => 'none',
            'mf_input_validation_expression' => '',
            'mf_input_validation_message' => '',
            'mf_input_placeholder' => '',
            'widgetType' => '',
        ], $data);
    }

    /**
     * Normalizes choice block options into legacy mf_input_list format.
     */
    private static function normalize_options(array $options): array {
        return array_values(array_map(static function ($opt): array {
            if (!is_array($opt)) {
                $val = (string) $opt;
                return [
                    'label'                 => $val,
                    'value'                 => $val,
                    'mf_input_option_text'  => $val,
                    'mf_input_option_value' => $val,
                    'mf_image_select_title' => $val,
                ];
            }
            $lbl = is_scalar($opt['label'] ?? null) ? (string) $opt['label'] : '';
            $val = is_scalar($opt['value'] ?? null) ? (string) $opt['value'] : $lbl;
            if ('' === $lbl && '' !== $val) {
                $lbl = $val;
            }

            return [
                'label'                 => $lbl,
                'value'                 => $val,
                'mf_input_option_text'  => $lbl,
                'mf_input_option_value' => $val,
                'mf_image_select_title' => $lbl,
            ];
        }, $options));
    }

    /** Every option the File Upload block's "Allowed File Types" control offers (see Fileupload::register_controls()). */
    private const FILE_UPLOAD_TYPES = ['.jpg', '.png', '.gif', '.webp', '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.zip', '.mp3', '.mp4'];

    /**
     * Normalizes the File Upload block's `allowedTypes` into the lowercase,
     * dot-prefixed extension list File_Data_Validation compares against.
     * Empty means "allow all" in the block, which maps to every option the
     * block offers; `.jpg` also allows `.jpeg` (same format).
     */
    private static function file_types($allowed): array {
        $types = [];
        foreach ((is_array($allowed) ? $allowed : []) as $type) {
            if (!is_scalar($type) || '' === trim((string) $type)) {
                continue;
            }
            $type = strtolower(trim((string) $type));
            $types[] = '.' === $type[0] ? $type : '.' . $type;
        }

        if (empty($types)) {
            $types = self::FILE_UPLOAD_TYPES;
        }
        if (in_array('.jpg', $types, true)) {
            $types[] = '.jpeg';
        }

        return array_values(array_unique($types));
    }

    /** Distinguishes "not set" from a real 0 (min/max are legitimately allowed to be 0). */
    private static function has_value($value): bool {
        return null !== $value && '' !== $value;
    }
}
