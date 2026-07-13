<?php
/**
 * TC_AI_Column_Type_Detector
 *
 * Single LLM pass over a sample of Gravity Form entries to propose a column type
 * per field. Delegates the actual provider call to TC_AI_Provider_Registry::active().
 *
 * @package GravityTables
 * @since   4.7.0
 */

// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
    exit;
}
// @codeCoverageIgnoreEnd
/**
 * Returns true only when the builder UI should render the
 * "Detect column types with AI" button.
 *
 * Why: free users and unconfigured premium installs would always hit the
 * gt_ai_no_provider fallback - surfacing a button that always errors is worse
 * than not surfacing it at all.
 */
// @codeCoverageIgnoreStart
if (!function_exists('gt_ai_can_render_detector_button')) {
// @codeCoverageIgnoreEnd
    function gt_ai_can_render_detector_button(): bool {
        if (!function_exists('gt_is_premium') || !gt_is_premium()) {
            return false;
        }
        if (!class_exists('TC_AI_Provider_Registry')) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }
        return TC_AI_Provider_Registry::active() instanceof TC_AI_Provider;
    }
}

class TC_AI_Column_Type_Detector
{
    /**
     * Inspect a Gravity Form's recent entries and propose a column type per field.
     *
     * @param int $form_id     Gravity Form ID.
     * @param int $sample_size Maximum entries to send to the model. Default 25.
     * @return array|WP_Error  ['fields' => [field_id => ['proposed_type','confidence','reason']]]
     *                         or WP_Error on failure / no provider.
     */
    public static function detect(int $form_id, int $sample_size = 25)
    {
        $provider = class_exists('TC_AI_Provider_Registry')
            ? TC_AI_Provider_Registry::active()
            // @codeCoverageIgnoreStart
            : null;
            // @codeCoverageIgnoreEnd

        if (!$provider instanceof TC_AI_Provider) {
            return new WP_Error(
                'gt_ai_no_provider',
                __('No AI provider is active. Configure one in TableCrafter → Settings → AI.', 'tc-data-tables')
            );
        }

        $form    = self::get_form($form_id);
        if (!is_array($form) || empty($form['fields'])) {
            return new WP_Error('gt_ai_unknown_form', __('Form not found or has no fields.', 'tc-data-tables'));
        }

        $privacy = !empty(get_option('gt_ai_settings', [])['privacy_mode']);
        $sample  = $privacy ? [] : self::get_sample_entries($form_id, max(1, $sample_size));
        $prompt  = self::build_prompt($form, $sample, $privacy);

        $raw = $provider->complete($prompt, ['response_format' => 'json']);
        if (is_wp_error($raw)) {
            return $raw;
        }

        if (class_exists('TC_AI_Usage_Tracker')) {
            TC_AI_Usage_Tracker::record($provider->provider_id(), 0, 0);
        }

        return self::parse_response(is_string($raw) ? $raw : (string) $raw);
    }

    /**
     * Build the prompt sent to the provider.
     *
     * Privacy contract: when $privacy_mode is true, the prompt MUST NOT contain
     * any row-sample values - only the column names and Gravity Forms types.
     */
    public static function build_prompt(array $form, array $entries, bool $privacy_mode): string
    {
        $title  = isset($form['title']) ? (string) $form['title'] : '';
        $fields = isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : [];

        $lines = [];
        $lines[] = 'You are inferring the best Gravity Tables column type for each Gravity Forms field.';
        $lines[] = 'Form: ' . $title;
        $lines[] = 'Allowed proposed_type values: text, number, date, boolean, url, email, currency, percent, longtext.';
        $lines[] = '';
        $lines[] = 'Columns:';
        foreach ($fields as $f) {
            $id    = isset($f['id'])    ? (string) $f['id']    : '';
            $label = isset($f['label']) ? (string) $f['label'] : '';
            $type  = isset($f['type'])  ? (string) $f['type']  : '';
            $lines[] = sprintf('- field_id=%s, label=%s, gf_type=%s', $id, $label, $type);
        }

        if (!$privacy_mode && !empty($entries)) {
            $lines[] = '';
            $lines[] = 'Sample rows (one per line, JSON):';
            foreach ($entries as $row) {
                $lines[] = wp_json_encode_safe($row);
            }
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"fields":[{"field_id":N,"proposed_type":"...","confidence":0.0-1.0,"reason":"..."}]}.';
        return implode("\n", $lines);
    }

    /**
     * Convert raw provider output into the detector schema.
     */
    public static function parse_response(string $raw)
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return new WP_Error('gt_ai_bad_response', __('AI response was not valid JSON.', 'tc-data-tables'));
        }
        if (empty($decoded['fields']) || !is_array($decoded['fields'])) {
            return new WP_Error('gt_ai_bad_response', __('AI response missing fields key.', 'tc-data-tables'));
        }

        $out = [];
        foreach ($decoded['fields'] as $entry) {
            if (!is_array($entry) || !isset($entry['field_id'])) {
                continue;
            }
            $fid = (int) $entry['field_id'];
            $out[$fid] = [
                'proposed_type' => isset($entry['proposed_type']) ? (string) $entry['proposed_type'] : 'text',
                'confidence'    => isset($entry['confidence'])    ? (float)  $entry['confidence']    : 0.0,
                'reason'        => isset($entry['reason'])        ? (string) $entry['reason']        : '',
            ];
        }

        return ['fields' => $out];
    }

    // -----------------------------------------------------------------------
    // Internal data fetchers - kept thin so tests can substitute via globals.
    // -----------------------------------------------------------------------

    private static function get_form(int $form_id)
    {
        if (isset($GLOBALS['gt_test_form'])) {
            return $GLOBALS['gt_test_form'];
        }
        if (class_exists('GFAPI')) {
            $form = GFAPI::get_form($form_id);
            return is_array($form) ? $form : null;
        }
        return null;
    }

    private static function get_sample_entries(int $form_id, int $sample_size): array
    {
        if (isset($GLOBALS['gt_test_entries'])) {
            return array_slice($GLOBALS['gt_test_entries'], 0, $sample_size);
        }
        if (class_exists('GFAPI')) {
            $entries = GFAPI::get_entries($form_id, [], null, ['offset' => 0, 'page_size' => $sample_size]);
            return is_array($entries) ? array_slice($entries, 0, $sample_size) : [];
        }
        return [];
    }
}

// @codeCoverageIgnoreStart
if (!function_exists('wp_json_encode_safe')) {
// @codeCoverageIgnoreEnd
    function wp_json_encode_safe($v): string {
        $j = function_exists('wp_json_encode') ? wp_json_encode($v) : json_encode($v);
        return is_string($j) ? $j : '';
    }
}
