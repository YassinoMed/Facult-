# OpenCode Agents and Skills Configuration

This project uses OpenCode with integrated skills and agents for Laravel development.

## Available Skills

| Skill | Description | Path |
|-------|-------------|------|
| `auto-fix` | Boucle Test → Debug → Patch jusqu'à ce que les tests passent. Inspiré de Aider, OpenHands et code-repair-demo. | `.opencode/skills/auto-fix/SKILL.md` |
| `code-review` | Code review expert pour Laravel — conventions, sécurité, patterns, maintenabilité. | `.opencode/skills/code-review/SKILL.md` |
| `swe-bench-style` | Style SWE-agent / SWE-bench pour les corrections multi-fichiers sur le projet inscription-etudiants. | `.opencode/skills/swe-bench-style/SKILL.md` |

## Available Agents

| Agent | Description | Mode | Model |
|-------|-------------|------|-------|
| `fixer` | Agent spécialisé dans la correction automatique de bugs via boucle test-debug-patch | subagent | anthropic/claude-sonnet-4-5 |
| `reviewer` | AgentReviewer expert en revue de code Laravel | subagent | anthropic/claude-sonnet-4-5 |
| `swe_agent` | Agent SWE-bench-style pour corrections multi-fichiers | subagent | anthropic/claude-sonnet-4-5 |

## Usage

### Loading a Skill

```bash
# Load a skill to guide the current session
opencode skill load auto-fix
# or
opencode skill load code-review
# or
opencode skill load swe-bench-style
```

### Using an Agent

Agents can be referenced in prompts or loaded as needed. They follow the project-specific conventions outlined in their respective markdown files.

## Project Conventions (referenced by skills)

### Laravel Conventions

- **French naming**: All variables, methods, comments, and user messages in French
- **Structure**: Namespaced controllers by domain (`App\Http\Controllers\Admin\...`, `App\Http\Controllers\Api\V1\...`, `App\Http\Controllers\Etudiant\...`)
- **No duplication**: Before creating anything (Model, Service, Policy, etc.): search existing, identify responsibility, check reusability, justify new abstraction, avoid functional duplicates
- **Modules**: Via `config/modules.php` + `ModuleRegistry` + `ModuleServiceProvider`. New module = folder `app/Modules/Xxx/` + views `resources/views/modules/xxx/`. Never add column to central table or route in `routes/web.php`
- **Architecture**: CORE + MODULES — core doesn't hard-depend on any module

### Testing

- After notable change: `vendor/bin/pint --dirty`, `php artisan test --compact` or targeted (`--filter`)
- OCR/Identity scan: `php artisan test --filter="IdentityScanTest|PpocrEngineTest|TesseractEngineTest|HfVlmOcrEngineTest"`
- If business behavior modified in docs/: update corresponding doc and ADD/ADAPT tests

### Security (key rules)

- **OCR fail-soft**: No OCR engine should raise exception to user — fallback = manual input + verification alerts
- **Identity files**: Scanned document = TEMPORARY private in `storage/app/private`, deleted immediately after extraction (`finally`), NO archiving in student folder
- **Server-side validation**: Deep validation (extension + real MIME via finfo + re-executable image + max size) — no browser trust
- **CSRF**: Web registration routes protected; API v1 = Sanctum tokens with restricted scopes, webhooks with HMAC+timestamp anti-replay
- **No PII in logs**: No OCR/PII data in logs; centralized admin audit

## Skills & Agents Workflow

1. **Auto-fix** : Use when tests fail or bug reported — follows Test→Debug→Patch loop
2. **Code-review** : Use before merge — checks all Laravel conventions and project specifics
3. **SWE-bench-style** : Use for multi-file changes — ensures consistency across all impacted files
4. **Fixer** : Subagent for actual code patches following the auto-fix loop
5. **Reviewer** : Subagent for code reviews following project conventions
6. **SWE Agent** : Subagent for structured multi-file corrections

## Configuration Files

- `opencode.json` — Main configuration (skills, agents, MCP, plugins)
- `.opencode/skills/*/SKILL.md` — Skill definitions
- `.opencode/agents/*.md` — Agent definitions
- `AGENTS.md` — This file (project-level rules and conventions)
- `.env` — Environment variables (MODULE_* flags, OCR settings, etc.)

## Quick Start

```bash
# List available skills
opencode skill list

# Load a skill
opencode skill load auto-fix

# Run relevant tests for the area you're working on
php artisan test --compact
# or
vendor/bin/phpunit --filter="TestNamePattern"

# Check code formatting
vendor/bin/pint --dirty

# Check routes
php artisan route:list
```