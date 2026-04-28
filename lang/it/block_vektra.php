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
$string['settings_publicurl'] = 'URL pubblica (browser)';
$string['settings_publicurl_desc'] = 'URL accessibile dal browser dell\'utente per caricare il widget e inviare le query. Lascia vuoto se l\'URL API è già raggiungibile dal browser. Necessario solo quando l\'URL server differisce (es. hostname Docker interno).';
$string['settings_apikey'] = 'Chiave API';
$string['settings_apikey_desc'] = 'Chiave API Vektra con scope admin. Necessaria per generare i token degli studenti.';
$string['settings_widget'] = 'Widget - Impostazioni predefinite';
$string['settings_widget_desc'] = 'Impostazioni predefinite per l\'aspetto del widget chatbot. Possono essere sovrascritte per ogni corso.';
$string['settings_theme'] = 'Tema predefinito';
$string['settings_theme_desc'] = 'Tema colore predefinito per il widget chatbot.';
$string['theme_light'] = 'Chiaro';
$string['theme_dark'] = 'Scuro';

// Branding (globale al plugin; nessun override per corso).
$string['settings_branding'] = 'Branding';
$string['settings_branding_desc'] = 'Aspetto visivo applicato al widget chatbot in tutti i corsi.';
$string['settings_primary_color'] = 'Colore primario';
$string['settings_primary_color_desc'] = 'Colore primario usato dal widget (es. #3366cc). Lascia vuoto per il valore predefinito del widget.';
$string['settings_logo_url'] = 'URL logo widget';
$string['settings_logo_url_desc'] = 'URL di un\'immagine icona mostrata nell\'intestazione del widget. Lascia vuoto per il valore predefinito.';

// Attribution (globale al plugin; visibile per impostazione predefinita).
$string['settings_attribution'] = 'Attribuzione';
$string['settings_attribution_desc'] = 'Attribuzione "powered by" mostrata nel widget.';
$string['settings_powered_by_text'] = 'Testo attribuzione';
$string['settings_powered_by_text_desc'] = 'Testo "powered by" personalizzato mostrato nel widget. Lascia vuoto per mantenere il valore predefinito.';
$string['settings_powered_by_url'] = 'Link attribuzione';
$string['settings_powered_by_url_desc'] = 'URL opzionale a cui collegare il testo di attribuzione.';

// Instance settings.
$string['config_title'] = 'Titolo blocco';
$string['config_course_id'] = 'ID corso Vektra';
$string['config_course_id_help'] = 'L\'identificativo del corso in Vektra. Lascia vuoto per usare il nome breve del corso Moodle (che viene automaticamente slugificato per corrispondere all\'algoritmo del workflow n8n di ingestion). Quando impostato esplicitamente, questo valore è inviato a Vektra così com\'è e deve rispettare il charset namespace di Vektra [0-9a-zA-Z_-]; in caso contrario le query restituiranno silenziosamente risultati vuoti. Vedi n8n/README.md "Namespace Convention" per l\'algoritmo di slugificazione.';
$string['config_namespace'] = 'Namespace Vektra';
$string['config_namespace_help'] = 'Sovrascrivere il namespace incluso nel token JWT (max 64 caratteri). Lascia vuoto per usare la catena predefinita (course_id o nome breve del corso slugificato). Quando impostato esplicitamente, questo valore è inviato a Vektra così com\'è e deve rispettare il charset namespace di Vektra [0-9a-zA-Z_-]; in caso contrario le query restituiranno silenziosamente risultati vuoti.';
$string['config_theme'] = 'Tema';
$string['config_language'] = 'Lingua';
$string['config_language_help'] = 'Sovrascrivere la lingua del widget (es. "en", "it"). Lascia vuoto per usare la lingua corrente di Moodle.';
$string['config_welcome_message'] = 'Messaggio di benvenuto';
$string['config_welcome_message_help'] = 'Saluto opzionale mostrato all\'apertura della chat. Lascia vuoto per il valore predefinito del widget.';
$string['usedefault'] = 'Usa predefinito';

// Impostazioni comportamentali per istanza (salvate sul backend Vektra, non in configdata).
$string['config_behavioral_header'] = 'Comportamento (Vektra)';
$string['config_inherit'] = 'Eredita';
$string['config_grounding_mode'] = 'Modalità grounding';
$string['config_grounding_mode_help'] = 'Quanto rigorosamente l\'assistente deve restare entro i materiali del corso. "Eredita" usa il valore predefinito del namespace.';
$string['config_grounding_strict'] = 'Stretto';
$string['config_grounding_hybrid'] = 'Ibrido';
$string['config_show_sources_choice'] = 'Mostra fonti';
$string['config_show_sources_choice_help'] = 'Indica se il widget mostra le citazioni delle fonti sotto le risposte. "Eredita" usa il valore predefinito del namespace.';
$string['config_show_sources_yes'] = 'Sì';
$string['config_show_sources_no'] = 'No';
$string['config_effective_label'] = 'Effettivo: {$a->value} ({$a->status})';
$string['config_status_default'] = 'predefinito';
$string['config_status_override'] = 'override';
$string['config_value_unknown'] = 'sconosciuto';
$string['config_namespace_unavailable'] = 'Impossibile caricare la configurazione Vektra corrente. I valori salvati saranno comunque applicati.';

// Avvisi di salvataggio (PATCH best-effort).
$string['save_warning_not_configured'] = 'API Vektra non configurata; le impostazioni comportamentali non sono state inviate al backend.';
$string['save_warning_no_namespace'] = 'Impossibile risolvere il namespace; le impostazioni comportamentali non sono state inviate al backend.';
$string['save_warning_patch_failed'] = 'Impossibile salvare le impostazioni comportamentali su Vektra: {$a->message} ({$a->code})';
$string['save_info_behavioral_skipped'] = 'Vektra non era raggiungibile all\'apertura del form: le impostazioni comportamentali non sono state inviate al backend. Riapri il form per modificarle.';

// Titolo predefinito del blocco (usato quando non è impostato un override).
$string['default_title'] = 'Assistente di {$a}';

// Errors.
$string['invalidblockinstance'] = 'Istanza del blocco non valida per questo corso.';

// Privacy.
$string['privacy:metadata'] = 'Il blocco Assistente AI Vektra non memorizza dati personali. I token sono generati tramite l\'API Vektra esterna e conservati solo nella sessione PHP.';
