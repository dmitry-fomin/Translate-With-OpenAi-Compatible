# Архитектура TranslaterLLM

CLI-приложение на PHP 8.2, переводящее EPUB-книги через LLM. Архитектура — Domain-Driven Design (порты и адаптеры).

## Слои

```
bin/translate                          entrypoint, сборка DI, разбор .env, CLI
└── src/Application                    оркестрация
    ├── TranslateBookUseCase           главный сценарий (главы → перевод → кеш → саммари)
    ├── TranslateCommand               Symfony Console + живая панель (ConsoleSectionOutput)
    ├── PackCommand                    упаковка ранее переведённой папки в .epub
    └── CoverTranslationOverrides      DTO опций --cover-*
├── src/Domain                         ядро (чистый PHP, без I/O)
│   ├── Book, Chapter, CoverImage      агрегаты
│   ├── BookMetadataTranslation        VO
│   ├── CoverTranslationBrief          VO для запроса перевода обложки
│   ├── TranslatorInterface            порт перевода главы
│   ├── SummarizerInterface            порт саммаризации главы
│   ├── CoverTranslatorInterface       порт перевода обложки (image-edit)
│   ├── BookMetadataTranslatorInterface порт перевода title/author
│   ├── EpubRepositoryInterface        порт I/O книги (load/save/pack)
│   ├── SummaryCacheInterface          порт кеша саммари
│   └── TranslationCacheInterface      порт кеша переводов глав
└── src/Infrastructure                 адаптеры
    ├── EpubHandler                    DOMDocument + ZipArchive, копия+перезапись
    ├── LlmTranslator                  обёртка над LlmClientInterface для перевода
    ├── LlmSummarizer                  обёртка для саммари (с TextSplitter)
    ├── LlmBookMetadataTranslator      перевод title/author для обложки
    ├── FileSummaryCache               .summary_cache.json
    ├── FileTranslationCache           .translation_cache.json (с sha1-инвалидацией)
    ├── TextSplitter                   разбиение длинных глав по символам/тегам
    ├── Http/RetryingGuzzleClientFactory  Guzzle + exponential backoff на 429/5xx
    └── LLM/
        ├── LlmClientInterface         request(systemPrompt, userPrompt): string
        ├── OpenAICompatibleClient     реализация (OpenAI/OpenRouter/closerouter)
        ├── LlmCoverTranslator         image-edit через chat/completions
        ├── LlmUsage                   VO {inputTokens, outputTokens}
        ├── LlmUsageObserverInterface  side-channel для usage из ответа
        └── LlmUsageTracker            аккумулятор + хук onRecord для UI
```

## Поток данных

1. CLI: `php bin/translate <input.epub> [--chapter <path>] [--cover] [--cover-* ...]`.
2. `bin/translate` загружает `.env`, проверяет обязательные переменные, собирает PHP-DI контейнер. Один общий `LlmUsageTracker` инжектится во все `OpenAICompatibleClient`-ы.
3. `TranslateCommand::execute` создаёт `ConsoleSectionOutput`, навешивает `LlmUsageTracker::setOnRecord` для перерисовки, вызывает `TranslateBookUseCase::execute`.
4. `EpubRepositoryInterface::load()` (`EpubHandler`) распаковывает EPUB в `.cache/translated-<sha1>/`, парсит `content.opf`, возвращает `Book` со списком `Chapter` и опциональной `CoverImage`.
5. Для каждой главы:
   - `TranslationCacheInterface::get` — если попали по sha1, переводчик не дёргается, саммари тоже (последовательная запись гарантирует консистентность).
   - Иначе: `TranslatorInterface::translate(content, previousSummary)` → перевод → запись в кеш перевода.
   - Если саммари этой главы нет в `SummaryCacheInterface` — `SummarizerInterface::summarize(content, previousSummary)` → запись.
   - Callback прогресса вызывается раз на главу (`current, total, chapterId, cached`).
6. Если задан `--cover`: при отсутствии явных `--cover-title`/`--cover-author` — `BookMetadataTranslatorInterface::translate(title, author)` достраивает их. Затем `CoverTranslatorInterface::translate(cover, brief)` отдаёт байты переведённой обложки.
7. `EpubRepositoryInterface::save()` копирует распакованный EPUB и перезаписывает переведённые XHTML/обложку. `EpubRepositoryInterface::pack()` собирает обратно в `.epub`.

## Контракты

### `LlmClientInterface::request(systemPrompt, userPrompt): string`
OpenAI-совместимый чат-формат (`messages: [{role:system}, {role:user}]`). Реализация (`OpenAICompatibleClient`) ожидает `choices[0].message.content` в ответе и бросает исключение при отсутствии/пустоте.

### Side-channel токенов
`OpenAICompatibleClient` принимает опциональный `LlmUsageObserverInterface`. После каждого успешного ответа извлекает `usage.{prompt_tokens|input_tokens, completion_tokens|output_tokens}` и зовёт `record(LlmUsage)`. Контракт `LlmClientInterface` остаётся узким — токены не протекают в Domain/Application.

`LlmUsageTracker` (общий синглтон в DI) аккумулирует total/last in-out и количество запросов; `setOnRecord(callable)` позволяет UI перерисовываться после каждого LLM-запроса.

### Кеширование
- `FileTranslationCache`: ключ `bookId → chapterRelativePath`, значение `{hash: sha1(content), translated}`. Несовпадение sha1 = miss.
- `FileSummaryCache`: ключ `bookId → chapterId`, значение — текст саммари. `bookId = realpath(inputPath)`.

Оба кеша — один JSON-файл. Не атомарны при параллельных запусках одного и того же файла.

## Точки расширения

- **Новый LLM-провайдер с не-OpenAI протоколом:** реализация `LlmClientInterface` в `src/Infrastructure/LLM/`, регистрация в `bin/translate`. Если поддерживает поле `usage` — пробросить туда же `LlmUsageObserverInterface`.
- **Кеш в SQLite/Redis:** реализации `SummaryCacheInterface` / `TranslationCacheInterface`, замена в DI.
- **Другой формат книги (FB2, MOBI):** новая реализация `EpubRepositoryInterface` или отдельный порт, если поведение существенно другое.
- **Параллелизм по главам:** TranslateBookUseCase сейчас последовательный — саммари предыдущей главы нужно следующей. Параллелить можно либо группами без cross-chapter контекста, либо со sliding window саммари.

## Известные ограничения

- `EpubHandler` ищет `.opf` через `RecursiveDirectoryIterator` — берёт первый попавшийся. При нескольких `.opf` поведение недетерминировано.
- `TextSplitter` режет по символам/тегам, без учёта токенизации модели. На очень длинных главах возможны проблемы с контекстом.
- Кеш-файлы — единые JSON, не атомарны.
- ETA в панели — линейная экстраполяция `elapsed/processed × remaining`; ускоряется по мере прохождения кешированных глав.

## Риск-зоны (см. `CLAUDE.md`)

- `src/Domain/*` — изменение портов ломает все реализации.
- `bin/translate` — DI-сборка, единственное место с `$_ENV`.
- `EpubHandler::save` — файловые операции с пользовательскими EPUB.
- `FileSummaryCache` / `FileTranslationCache` — формат кеша; смена схемы инвалидирует уже переведённые книги.
- `.env`, `mcp-servers.json` — секреты.
