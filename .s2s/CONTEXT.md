# vektra-moodle - Component Context

<!--
This file is maintained by Spec2Ship init command.
Import this in CLAUDE.md using @.s2s/CONTEXT.md
Run /s2s:init to populate or update this file.

NOTE: S2S paths and how-to documentation are in README.md (not loaded in memory)
-->

## Business Domain

Education / E-learning - AI-powered course assistant integration for Moodle LMS.

## Project Objectives

- Adding capabilities to existing product: extend the Vektra RAG platform with a Moodle LMS block plugin

## Project Constraints

- Must use PHP (Moodle plugin API, Moodle coding standards)
- Must integrate with Vektra API (vektra-stack) and Moodle LMS

## Component Overview

Moodle block plugin that integrates the Vektra RAG chatbot into course pages. Part of the Vektra platform (see [vektra-stack](../vektra-stack/)). Students get an AI assistant that answers questions based on their course materials. vektra-moodle is the Moodle-specific adapter that calls the vektra-learn APIs for enrollment registration, content ingestion triggers, dashboard token generation, and chatbot embedding with course context.

## Scope

**Type**: Full implementation

**In scope**:
- Moodle block plugin for embedding Vektra chatbot in course pages
- Course enrollment sync with Vektra API (optional enrollment via namespace)
- Plugin settings (API endpoint, API key, namespace configuration)
- Privacy provider implementation (GDPR compliance)
- Multi-language support (en, it)

**Out of scope**:
- Admin UI (handled by vektra-admin)
- Analytics/reporting (handled by vektra-analytics)
- Content ingestion (handled by vektra-ingest/n8n pipelines)
- RAG engine logic (handled by vektra-core)

## Technical Stack

- **Language**: PHP
- **Framework**: Moodle 5.1+ plugin API
- **Plugin type**: Block plugin (block_vektrachat)

## Related Projects

| Project | Role | Relationship |
|---------|------|-------------|
| [vektra-stack](../vektra-stack/) | Main platform monorepo | vektra-moodle calls vektra-learn APIs |
| vektra-learn | E-learning vertical backend | LMS-agnostic API that vektra-moodle consumes |
| vektra-core | RAG engine | Underlying engine accessed via vektra-learn |

## Component Open Questions

<!-- Populated during /s2s:specs or /s2s:design sessions -->
- None identified yet

---
*Last updated: 2026-03-17*
