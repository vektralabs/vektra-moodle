# Vektra AI Assistant - Moodle Block Plugin

Moodle block plugin that integrates the [Vektra](https://github.com/vektralabs/vektra-stack) RAG chatbot into course pages. Students get an AI assistant that answers questions based on their course materials.

## Requirements

- Moodle 5.1+
- PHP 8.2+
- A running Vektra instance with the Learn module enabled
- Vektra v0.5.0+ to use the per-course **Behavior** settings (grounding mode, source visibility). Earlier versions remain compatible: the form will gracefully degrade and skip the backend sync.

## Installation

1. Copy this directory to `moodle/blocks/vektra/`
2. Log in as admin and follow the upgrade notification
3. Configure the plugin at **Site administration > Plugins > Blocks > Vektra AI Assistant**

### Configuration (site-wide)

| Setting | Description |
|---------|-------------|
| **Vektra API URL** | Base URL of your Vektra instance (server-side, e.g. `https://vektra.example.com` or a Docker hostname). Do not include a trailing slash. |
| **Public URL (browser)** | Browser-accessible URL for the widget JS and API calls. Leave empty if the API URL is already browser-accessible. Useful when the server-side URL is a Docker-internal hostname. |
| **API Key** | Vektra API key with `admin` scope (required for token generation and namespace config). |
| **Default theme** | Widget color theme (`light` or `dark`). Per-course override available. |
| **Primary color** | White-label brand color for the widget (e.g. `#3366cc`). Plugin-global. |
| **Widget logo URL** | Optional icon shown in the widget header. Plugin-global. |
| **Attribution text** | Custom "powered by" text shown in the widget. Plugin-global. |
| **Attribution link** | Optional URL the attribution text links to. Plugin-global. |

Branding and attribution are intentionally site-wide (no per-course override) to keep the visual identity consistent across all courses on the same Moodle instance.

### Per-course setup

1. Navigate to the course page
2. Turn editing on and add the **Vektra AI Assistant** block
3. Configure the block:

   **Block settings**

   | Field | Purpose |
   |-------|---------|
   | Block title | Override the block heading shown to students. Defaults to "Assistant for *{course name}*". |
   | Vektra course ID | Override the course identifier sent to Vektra. Defaults to the Moodle course shortname. Must match the `course_id` used when ingesting materials. |
   | Vektra namespace | Override the namespace included in the JWT token. Defaults to the course ID. Useful when multiple courses share materials, or when the course shortname contains characters not valid as a namespace. |
   | Theme / Language | Override the site-wide defaults for this course. |
   | Welcome message | Optional greeting shown when the chat opens. |

   **Behavior (Vektra)** — requires Vektra v0.5.0+

   | Field | Options |
   |-------|---------|
   | Grounding mode | `Inherit` (use namespace/server default), `Strict`, `Hybrid` |
   | Show sources | `Inherit`, `Yes`, `No` |

   Behavioral settings are stored on the Vektra backend (the namespace is the single source of truth) and applied via `PATCH /api/v1/admin/namespaces/{ns}/config` on save. The form pre-populates these fields from the backend on open and shows the resolved value next to each select. If the Vektra API is unreachable when the form opens, the behavioral selects are frozen and the save flow skips writing to the Vektra namespace until the form is reopened with a successful read; this protects existing overrides from being wiped.

## How it works

1. When a user opens a course page, the block generates a short-lived JWT token via the Vektra API
2. The token is cached in the PHP session to avoid repeated API calls
3. The block injects the Vektra chatbot widget (`vektra-chat.js`) into the page footer
4. The widget appears as a floating chat button in the bottom-right corner
5. All RAG queries are scoped to the student's course materials
6. Source citation visibility and grounding behavior resolve server-side from the namespace config (set per-course in the form above)

## Capabilities

| Capability | Default roles | Description |
|------------|---------------|-------------|
| `block/vektra:addinstance` | Editing teacher, Manager | Add the block to a course |
| `block/vektra:usechatbot` | Student, Teacher, Editing teacher, Manager | See and use the chatbot widget |

## Optional components

- [`docker/`](docker/README.md) — local Moodle 5.1 dev environment for testing the plugin against vektra-stack on the same host.
- [`n8n/`](n8n/README.md) — n8n workflow that polls Moodle Web Services and syncs course materials into Vektra automatically (PDF, DOCX, PPTX, Markdown).

## Languages

The plugin ships with English and Italian translations.

## License

This plugin is licensed under the [GNU GPL v3](LICENSE), as required by the Moodle Plugin Directory.
