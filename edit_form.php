<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Per-instance configuration form for block_vektra.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Per-instance configuration form for block_vektra.
 */
class block_vektra_edit_form extends block_edit_form {
    /** @var array|null Cached namespace config response (raw + resolved). */
    private ?array $namespaceconfig = null;

    /** @var bool Whether the namespace config GET has already been attempted. */
    private bool $namespaceconfigfetched = false;

    /**
     * Add form fields for per-instance configuration.
     *
     * @param MoodleQuickForm $mform The form being built.
     */
    protected function specific_definition($mform) {
        $mform->addElement(
            'header',
            'vektraheader',
            get_string('blocksettings', 'block')
        );

        // Block title override (empty = use plugin name).
        $mform->addElement(
            'text',
            'config_title',
            get_string('config_title', 'block_vektra')
        );
        $mform->setType('config_title', PARAM_TEXT);

        // Vektra course_id override (defaults to Moodle course shortname).
        $mform->addElement(
            'text',
            'config_course_id',
            get_string('config_course_id', 'block_vektra')
        );
        $mform->setType('config_course_id', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('config_course_id', 'config_course_id', 'block_vektra');

        // Vektra namespace override (defaults to course_id on the API side).
        $mform->addElement(
            'text',
            'config_namespace',
            get_string('config_namespace', 'block_vektra')
        );
        $mform->setType('config_namespace', PARAM_ALPHANUMEXT);
        $mform->addRule('config_namespace', get_string('maximumchars', '', 64), 'maxlength', 64);
        $mform->addHelpButton('config_namespace', 'config_namespace', 'block_vektra');

        // Theme override.
        $mform->addElement(
            'select',
            'config_theme',
            get_string('config_theme', 'block_vektra'),
            [
                ''      => get_string('usedefault', 'block_vektra'),
                'light' => get_string('theme_light', 'block_vektra'),
                'dark'  => get_string('theme_dark', 'block_vektra'),
            ]
        );

        // Language override.
        $mform->addElement(
            'text',
            'config_language',
            get_string('config_language', 'block_vektra')
        );
        $mform->setType('config_language', PARAM_ALPHA);
        $mform->addHelpButton('config_language', 'config_language', 'block_vektra');

        // Welcome message override (textarea, optional, max 500 chars per plan Phase C5).
        $mform->addElement(
            'textarea',
            'config_welcome_message',
            get_string('config_welcome_message', 'block_vektra'),
            ['rows' => 2, 'cols' => 60]
        );
        $mform->setType('config_welcome_message', PARAM_TEXT);
        // Note: the 'client' validation flag in HTML_QuickForm::addRule means
        // "client + server", not "client only". Server-side validation runs
        // unconditionally for every registered rule (see lib/pear/HTML/QuickForm.php
        // ::validate()); the flag only controls whether JS is also generated.
        $mform->addRule(
            'config_welcome_message',
            get_string('maximumchars', '', 500),
            'maxlength',
            500,
            'client'
        );
        $mform->addHelpButton('config_welcome_message', 'config_welcome_message', 'block_vektra');

        // Behavioral section: grounding_mode and show_sources_choice are saved on
        // the Vektra backend (PATCH), not in Moodle configdata.
        $mform->addElement(
            'header',
            'vektrabehavioral',
            get_string('config_behavioral_header', 'block_vektra')
        );
        $mform->setExpanded('vektrabehavioral', true);

        // Best-effort fetch of the namespace config (2s timeout) for "Effective" labels.
        $nsconfig = $this->fetch_namespace_config();
        $getok    = ($nsconfig !== null);

        // Hidden marker so instance_config_save knows whether the form-open GET succeeded.
        // When it didn't, the save handler must skip the PATCH to avoid clobbering the
        // backend with values the teacher never had a chance to see.
        $mform->addElement('hidden', 'config_get_ok', $getok ? '1' : '0');
        $mform->setType('config_get_ok', PARAM_INT);

        $mform->addElement(
            'select',
            'config_grounding_mode',
            get_string('config_grounding_mode', 'block_vektra'),
            [
                'inherit' => get_string('config_inherit', 'block_vektra'),
                'strict'  => get_string('config_grounding_strict', 'block_vektra'),
                'hybrid'  => get_string('config_grounding_hybrid', 'block_vektra'),
            ]
        );
        $mform->setDefault('config_grounding_mode', 'inherit');
        $mform->addHelpButton('config_grounding_mode', 'config_grounding_mode', 'block_vektra');
        if ($getok) {
            $mform->addElement(
                'static',
                'config_grounding_mode_effective',
                '',
                $this->compose_grounding_effective_label($nsconfig)
            );
        }

        $mform->addElement(
            'select',
            'config_show_sources_choice',
            get_string('config_show_sources_choice', 'block_vektra'),
            [
                'inherit' => get_string('config_inherit', 'block_vektra'),
                'yes'     => get_string('config_show_sources_yes', 'block_vektra'),
                'no'      => get_string('config_show_sources_no', 'block_vektra'),
            ]
        );
        $mform->setDefault('config_show_sources_choice', 'inherit');
        $mform->addHelpButton('config_show_sources_choice', 'config_show_sources_choice', 'block_vektra');
        if ($getok) {
            $mform->addElement(
                'static',
                'config_show_sources_choice_effective',
                '',
                $this->compose_show_sources_effective_label($nsconfig)
            );
        }

        if (!$getok && $this->namespaceconfigfetched) {
            // GET attempted but failed (timeout/network/auth/missing config).
            // Freeze the selects so the teacher cannot mistakenly act on inputs that
            // would be ignored at save time.
            $mform->freeze(['config_grounding_mode', 'config_show_sources_choice']);
            $mform->addElement(
                'static',
                'config_namespace_unavailable',
                '',
                get_string('config_namespace_unavailable', 'block_vektra')
            );
        }
    }

    /**
     * Seed defaults for behavioral fields from the namespace GET response.
     *
     * Behavioral fields are not cached in Moodle configdata, so the parent's
     * automatic config_* mapping does not populate them. We pull the raw config
     * from the API and translate it into form values.
     *
     * @param object|array $defaults
     */
    public function set_data($defaults) {
        $defaults = (object) $defaults;

        $nsconfig = $this->fetch_namespace_config();
        $defaults->config_get_ok = ($nsconfig !== null) ? 1 : 0;
        $raw = ($nsconfig['config'] ?? []);

        if (isset($raw['grounding_mode']) && in_array($raw['grounding_mode'], ['strict', 'hybrid'], true)) {
            $defaults->config_grounding_mode = $raw['grounding_mode'];
        } else {
            $defaults->config_grounding_mode = 'inherit';
        }

        if (array_key_exists('show_sources', $raw) && is_bool($raw['show_sources'])) {
            $defaults->config_show_sources_choice = $raw['show_sources'] ? 'yes' : 'no';
        } else {
            $defaults->config_show_sources_choice = 'inherit';
        }

        parent::set_data($defaults);
    }

    /**
     * Fetch the namespace config from the Vektra API, cached for the form lifetime.
     *
     * Uses a short 2s timeout to avoid blocking the form open. Returns null on
     * any failure (missing config, network, auth, decode).
     *
     * @return array{config: array, resolved: array}|null
     */
    private function fetch_namespace_config(): ?array {
        if ($this->namespaceconfigfetched) {
            return $this->namespaceconfig;
        }
        $this->namespaceconfigfetched = true;

        $apiurl = get_config('block_vektra', 'apiurl');
        $apikey = get_config('block_vektra', 'apikey');
        if (empty($apiurl) || empty($apikey)) {
            return null;
        }

        $namespace = $this->resolve_namespace();
        if ($namespace === '') {
            return null;
        }

        $client = new \block_vektra\vektra_client($apiurl, $apikey);
        $this->namespaceconfig = $client->get_namespace_config($namespace, 2);
        return $this->namespaceconfig;
    }

    /**
     * Resolve the effective namespace for this block instance.
     *
     * Delegates to the shared `\block_vektra\namespace_resolver` so the form's
     * admin GET targets the same identifier the backend uses on the JWT path
     * (explicit namespace override > course_id override > course shortname).
     */
    private function resolve_namespace(): string {
        return \block_vektra\namespace_resolver::resolve(
            $this->block->config ?? null,
            $this->page->course ?? null
        );
    }

    /**
     * Compose the localized "Effective: …" label for grounding_mode.
     */
    private function compose_grounding_effective_label(array $nsconfig): string {
        $resolved = $nsconfig['resolved'] ?? [];
        $raw      = $nsconfig['config'] ?? [];

        $effvalue = isset($resolved['grounding_mode']) ? (string) $resolved['grounding_mode'] : '';
        $valuetoken = match ($effvalue) {
            'strict' => 'config_grounding_strict',
            'hybrid' => 'config_grounding_hybrid',
            default  => 'config_value_unknown',
        };

        $statustoken = isset($raw['grounding_mode'])
            ? 'config_status_override'
            : 'config_status_default';

        $a = (object) [
            'value'  => get_string($valuetoken, 'block_vektra'),
            'status' => get_string($statustoken, 'block_vektra'),
        ];
        return get_string('config_effective_label', 'block_vektra', $a);
    }

    /**
     * Compose the localized "Effective: …" label for show_sources.
     */
    private function compose_show_sources_effective_label(array $nsconfig): string {
        $resolved = $nsconfig['resolved'] ?? [];
        $raw      = $nsconfig['config'] ?? [];

        $effvalue = $resolved['show_sources'] ?? null;
        if ($effvalue === true) {
            $valuetoken = 'config_show_sources_yes';
        } else if ($effvalue === false) {
            $valuetoken = 'config_show_sources_no';
        } else {
            $valuetoken = 'config_value_unknown';
        }

        $statustoken = array_key_exists('show_sources', $raw)
            ? 'config_status_override'
            : 'config_status_default';

        $a = (object) [
            'value'  => get_string($valuetoken, 'block_vektra'),
            'status' => get_string($statustoken, 'block_vektra'),
        ];
        return get_string('config_effective_label', 'block_vektra', $a);
    }
}
