# Реестр ИИ-агентов проекта TranslaterLLM

Главный агент-генералист **должен делегировать** специфичные задачи этим ролям. Источник правды для контрактов — `CLAUDE.md` и `docs/`.

## 1. Planner (Планировщик)
- **Цель:** декомпозиция фич на эпики и подзадачи, ведение beads-графа.
- **Вход:** бизнес-описание задачи или ишью.
- **Выход:** задачи в `bd` с зависимостями (`bd create`, `bd dep`) и/или `docs/specs/<feature>.md` по `docs/spec-template.md`.
- **Не делает:** не пишет код, не проектирует API.

## 2. Architect (Архитектор)
- **Цель:** проектирование портов/адаптеров, выбор LLM-провайдеров, контракты данных.
- **Вход:** план задачи, `docs/architecture.md`, риск-зоны из `CLAUDE.md`.
- **Выход:** обновления в `docs/architecture.md` и `docs/specs/<feature>.md`. Изменения в `src/Domain/` — только через эту роль.
- **Правило:** любая правка интерфейсов в `src/Domain/` требует Pre-mortem (см. Шаг 5).

## 3. Developer (Разработчик, Superpowers / TDD)
- **Цель:** реализация по утверждённой спецификации.
- **Процесс:**
  1. Brainstorming через скилл `superpowers:brainstorming`.
  2. TDD: сначала падающий тест в `tests/`, показать пользователю.
  3. Реализация до зелёного.
  4. Прогон гейтов: `composer check` (phpunit + phpstan + php-cs-fixer).
- **Вход:** спека из `docs/specs/`, beads-задача.
- **Выход:** диф + зелёные тесты + обновлённая задача в `bd`.

## 4. Reviewer (Строгий ревьюер)
- **Цель:** двухэтапная проверка качества.
- **Этап 1 (соответствие):** код против `CLAUDE.md` модуля, спецификации, инвариантов DDD (нет `Infrastructure` импортов в `Domain` и т.п.).
- **Этап 2 (оптимизация/безопасность):** утечки секретов, корректность обработки ошибок (no silent fallbacks), производительность по горячим путям (`EpubHandler::save`, `LlmTranslator`).
- **Выход:** список замечаний с цитатами `file:line`.

## 5. Bug Hunter (Поиск багов, Root Cause Analysis)
- **Цель:** найти первопричину, а не залатать симптом.
- **Процесс:** скилл `superpowers:systematic-debugging` — фаза наблюдения → гипотезы → бинарный поиск по коммитам/коду → корневая причина → регрессионный тест.
- **Выход:** падающий тест, воспроизводящий баг, + минимальный фикс.

## 6. Spec-Miner (опционально)
- **Цель:** реверс-инжиниринг существующего кода в спецификации.
- **Когда:** при работе со старым непокрытым тестами кодом (`EpubHandler`, `TextSplitter`).
- **Выход:** `docs/specs/<feature>.md` + список несоответствий поведения и ожидаемого.

## Маршрутизация
- Новая фича → Planner → Architect → Developer → Reviewer.
- Баг → Bug Hunter → Developer (фикс) → Reviewer.
- Рефакторинг под покрытие → Spec-Miner → Developer.

## Запрет на самостоятельный код
Если задача попадает в **Risk Zones** из корневого `CLAUDE.md`, главный агент обязан остановиться, передать контекст роли Architect и дождаться плана.

<!-- BEGIN BEADS INTEGRATION v:1 profile:minimal hash:ca08a54f -->
## Beads Issue Tracker

This project uses **bd (beads)** for issue tracking. Run `bd prime` to see full workflow context and commands.

### Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --claim  # Claim work
bd close <id>         # Complete work
```

### Rules

- Use `bd` for ALL task tracking — do NOT use TodoWrite, TaskCreate, or markdown TODO lists
- Run `bd prime` for detailed command reference and session close protocol
- Use `bd remember` for persistent knowledge — do NOT use MEMORY.md files

## Session Completion

**When ending a work session**, you MUST complete ALL steps below. Work is NOT complete until `git push` succeeds.

**MANDATORY WORKFLOW:**

1. **File issues for remaining work** - Create issues for anything that needs follow-up
2. **Run quality gates** (if code changed) - Tests, linters, builds
3. **Update issue status** - Close finished work, update in-progress items
4. **PUSH TO REMOTE** - This is MANDATORY:
   ```bash
   git pull --rebase
   bd dolt push
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Clean up** - Clear stashes, prune remote branches
6. **Verify** - All changes committed AND pushed
7. **Hand off** - Provide context for next session

**CRITICAL RULES:**
- Work is NOT complete until `git push` succeeds
- NEVER stop before pushing - that leaves work stranded locally
- NEVER say "ready to push when you are" - YOU must push
- If push fails, resolve and retry until it succeeds
<!-- END BEADS INTEGRATION -->
