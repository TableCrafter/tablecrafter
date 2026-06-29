<?php
// @codeCoverageIgnoreStart
if (!defined('ABSPATH')) {
	exit;
}
// @codeCoverageIgnoreEnd
class TC_Date_Service {
	/**
	 * Convert PHP date format to MySQL STR_TO_DATE format
	 */
	public function phpToMysqlFormat(string $php_format): string {
		$format_map = [
			'Y' => '%Y',
			'y' => '%y',
			'm' => '%m',
			'n' => '%c',
			'd' => '%d',
			'j' => '%e',
			'/' => '/',
			'-' => '-',
			'.' => '.'
		];

		return strtr($php_format, $format_map);
	}

	/**
	 * Convert Gravity Forms format to PHP format
	 */
	public function gravityFormsToPhpFormat(string $gf_format): string {
		$format_map = [
			'mdy' => 'm/d/Y',
			'dmy' => 'd/m/Y',
			'ymd' => 'Y-m-d',
			'ymd_slash' => 'Y/m/d',
			'ymd_dash' => 'Y-m-d',
			'ymd_dot' => 'Y.m.d',
			'mdy_dash' => 'm-d-Y',
			'dmy_dash' => 'd-m-Y',
			'dmy_dot' => 'd.m.Y',
			'mdy_dot' => 'm.d.Y',
			// Additional common formats
			'mdY' => 'm/d/Y',
			'dmY' => 'd/m/Y',
			'Ymd' => 'Y-m-d',
			// Handle edge cases
			'mm/dd/yyyy' => 'm/d/Y',
			'dd/mm/yyyy' => 'd/m/Y',
			'yyyy-mm-dd' => 'Y-m-d',
		];

		// Log for debugging
		// error_log("GT Date Service: Converting GF format '{$gf_format}' to PHP format");
		
		$php_format = $format_map[$gf_format] ?? 'm/d/Y';
		// error_log("GT Date Service: Result PHP format: '{$php_format}'");
		
		return $php_format;
	}

	/**
	 * Get date format for a specific field, with fallbacks
	 */
	public function getFieldDateFormat(int $form_id, string $field_id): array {
		// 1. Try to get format from Gravity Forms field config
		if (class_exists('GFAPI')) {
			$form = GFAPI::get_form($form_id);
			if ($form && isset($form['fields'])) {
				foreach ($form['fields'] as $field) {
					if (strval($field->id) === strval($field_id) && $field->type === 'date') {
						$gf_format = $field->dateFormat ?? 'mdy';
						$php_format = $this->gravityFormsToPhpFormat($gf_format);
						return [
							'source' => 'gravity_forms',
							'php_format' => $php_format,
							'mysql_format' => $this->phpToMysqlFormat($php_format),
							'gf_format' => $gf_format
						];
					}
				}
			}
		}

		// 2. Fallback to plugin global date format
		$settings = get_option('gt_settings', []);
		$php_format = $settings['date_format'] ?? 'm/d/Y';

		return [
			'source' => 'plugin_settings',
			'php_format' => $php_format,
			'mysql_format' => $this->phpToMysqlFormat($php_format)
		];
	}

	/**
	 * Convert PHP date format to JavaScript format (for frontend)
	 */
	public function phpToJavascriptFormat(string $php_format): string {
		$format_map = [
			'Y' => 'YYYY',
			'y' => 'YY',
			'm' => 'MM',
			'n' => 'M',
			'd' => 'DD',
			'j' => 'D'
		];

		return strtr($php_format, $format_map);
	}
}


