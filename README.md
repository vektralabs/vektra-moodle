# Vektra AI Assistant - Moodle Block Plugin

Moodle block plugin that integrates the [Vektra](https://github.com/vektralabs/vektra-stack) RAG chatbot into course pages. Students get an AI assistant that answers questions based on their course materials.

## Requirements

- Moodle 5.1+
- PHP 8.2+
- A running Vektra instance with the Learn module enabled

## Installation

1. Copy this directory to `moodle/blocks/vektra/`
2. Log in as admin and follow the upgrade notification
3. Configure the plugin at **Site administration > Plugins > Blocks > Vektra AI Assistant**

### Configuration

| Setting | Description |
|---------|-------------|
| **Vektra API URL** | Base URL of your Vektra instance (e.g., `https://vektra.example.com`) |
| **API Key** | Vektra API key with `admin` scope (required for token generation) |
| **Default theme** | Widget color theme (`light` or `dark`) |

### Per-course setup

1. Navigate to the course page
2. Turn editing on and add the **Vektra AI Assistant** block
3. Optionally override the Vektra course ID, theme, or language in block settings

The Vektra course ID defaults to the Moodle course short name. It must match the `course_id` used when ingesting materials into Vektra.

## How it works

1. When a user opens a course page, the block generates a short-lived JWT token via the Vektra API
2. The token is cached in the PHP session to avoid repeated API calls
3. The block injects the Vektra chatbot widget (`vektra-chat.js`) into the page footer
4. The widget appears as a floating chat button in the bottom-right corner
5. All RAG queries are scoped to the student's course materials

## Capabilities

| Capability | Default roles | Description |
|------------|---------------|-------------|
| `block/vektra:addinstance` | Editing teacher, Manager | Add the block to a course |
| `block/vektra:usechatbot` | Student, Teacher, Editing teacher, Manager | See and use the chatbot widget |

## License

This plugin is licensed under the [GNU GPL v3](LICENSE), as required by the Moodle Plugin Directory.
