# Project: TranslaterLLM

CLI-переводчик EPUB через OpenAI-совместимый LLM. PHP 8.2, DDD.

Обзор для пользователя — `README.md`. Архитектура — `docs/architecture.md`. PHP-конвенции — `.claude/rules/php.md`.

## Project Map

- `bin/translate` — entrypoint, DI, единственное место с `$_ENV`.
- `src/Domain/` — ядро и порты (`*Interface`). **Никаких импортов из Infrastructure/Symfony/Guzzle/Dotenv.**
- `src/Application/` — use cases и Symfony Console команды.
- `src/Infrastructure/` — `EpubHandler`, `Llm*`, `File*Cache`, `TextSplitter`, `LLM/`, `Http/`.
- `prompts/` — `TRANSLATION_PROMPT.md`, `SUMMARY_PROMPT.md`, `COVER_PROMPT.md`, `METADATA_PROMPT.md`.
- `tests/` — PHPUnit, `@group integration` исключается по умолчанию.

## Mandatory Gates

После правок в `src/` или `tests/`:

```
composer check   # fmt:check + phpstan (level 6) + phpunit
```

Зелёные все три — задача завершена.

## Risk Zones

Меняй с предварительным планом (узкий диф, спека если нужно):

- `src/Domain/` — контракты, ломают все реализации.
- `bin/translate` — DI wiring; сохраняй совместимость с `.env.example`.
- `src/Infrastructure/EpubHandler.php` — копирование/перезапись EPUB, риск порчи исходников.
- `FileSummaryCache` / `FileTranslationCache` — формат кеша; смена схемы ломает уже переведённые книги.
- `.env` / `.env.example` — никогда не коммитить реальные ключи, не печатать в логи и диффы.

## Context Hygiene

Не грузи в контекст без явной необходимости: `.summary_cache.json`, `.translation_cache.json`, `*.epub`, `.cache/`, `By_Nora_Gal*`, `Empire of the Dawn*`, `vendor/`.
