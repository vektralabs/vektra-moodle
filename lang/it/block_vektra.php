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
 * Italian language strings for block_vektra.
 *
 * @package    block_vektra
 * @copyright  2026 VektraLabs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Assistente AI Vektra';
$string['vektra:addinstance'] = 'Aggiungere un blocco Assistente AI Vektra';
$string['vektra:usechatbot'] = 'Usare il chatbot AI Vektra';

// Block content.
$string['widgetactive'] = 'L\'Assistente AI è attivo. Cerca il pulsante chat nell\'angolo in basso a destra.';
$string['notconfigured'] = 'Vektra non è configurato. Vai su Amministrazione del sito > Plugin > Blocchi > Assistente AI Vektra.';
$string['tokenerror'] = 'Impossibile connettersi all\'API Vektra. Controlla URL e chiave API nelle impostazioni del plugin.';

// Global settings.
$string['settings_connection'] = 'Connessione';
$string['settings_connection_desc'] = 'Configura la connessione alla tua istanza Vektra.';
$string['settings_apiurl'] = 'URL API Vektra';
$string['settings_apiurl_desc'] = 'URL base della tua istanza Vektra (es. https://vektra.example.com). Non includere lo slash finale.';
$string['settings_apikey'] = 'Chiave API';
$string['settings_apikey_desc'] = 'Chiave API Vektra con scope admin. Necessaria per generare i token degli studenti.';
$string['settings_widget'] = 'Widget - Impostazioni predefinite';
$string['settings_widget_desc'] = 'Impostazioni predefinite per l\'aspetto del widget chatbot. Possono essere sovrascritte per ogni corso.';
$string['settings_theme'] = 'Tema predefinito';
$string['settings_theme_desc'] = 'Tema colore predefinito per il widget chatbot.';
$string['theme_light'] = 'Chiaro';
$string['theme_dark'] = 'Scuro';

// Instance settings.
$string['config_title'] = 'Titolo blocco';
$string['config_course_id'] = 'ID corso Vektra';
$string['config_course_id_help'] = 'L\'identificativo del corso in Vektra. Lascia vuoto per usare il nome breve del corso Moodle. Deve corrispondere al course_id usato durante l\'ingestion dei materiali in Vektra.';
$string['config_theme'] = 'Tema';
$string['config_language'] = 'Lingua';
$string['config_language_help'] = 'Sovrascrivere la lingua del widget (es. "en", "it"). Lascia vuoto per usare la lingua corrente di Moodle.';
$string['usedefault'] = 'Usa predefinito';

// Privacy.
$string['privacy:metadata'] = 'Il blocco Assistente AI Vektra non memorizza dati personali. I token sono generati tramite l\'API Vektra esterna e conservati solo nella sessione PHP.';
