# TranslaterLLM

CLI-переводчик EPUB-книг через LLM с OpenAI-совместимым API (OpenAI, OpenRouter, closerouter и т.п.).

Главу за главой пересылает текст в LLM, складывает результат обратно в EPUB, сохраняя структуру и стили. Между главами держит краткую саммари предыдущих — переводчик «помнит» контекст. Опционально русифицирует обложку через image-edit модель.

## Возможности

- **Перевод EPUB** с сохранением XHTML/CSS/изображений — на выходе валидная книга.
- **Контекст между главами** через саммари: каждая глава переводится с учётом краткого пересказа предыдущей.
- **Двойное кеширование** на диске:
  - `.translation_cache.json` — готовые переводы глав (привязка по sha1 содержимого; падение/повтор не теряют прогресс);
  - `.summary_cache.json` — саммари глав для контекста.
- **Русификация обложки** (`--cover`) — title/author переводятся LLM автоматически или задаются вручную; image-edit модель перерисовывает.
- **Живая консольная панель** во время перевода: прогресс, прошло/ETA, число LLM-запросов, токены отправлено/получено (всего и в последнем запросе).
- **Retry с экспоненциальным backoff** на 429/5xx/ConnectException.
- **DDD-архитектура**: домен без зависимостей от инфраструктуры, лёгкая подмена LLM-клиентов и кешей.

## Требования

- PHP `^8.2` с расширениями `ext-dom`, `ext-zip`.
- Composer.
- API-ключ OpenAI-совместимого провайдера. Для обложки — дополнительно image-edit endpoint (например, `google/nano-banana-2-edit` через CloseRouter).

## Установка

```bash
git clone <repo> TranslaterLLM
cd TranslaterLLM
composer install
cp .env.example .env
# отредактировать .env
```

## Конфигурация `.env`

```dotenv
# OpenAI-совместимый endpoint (api.openai.com, OpenRouter, closerouter и т.п.)
OPENAI_COMPATIBLE_API_KEY=sk-...
OPENAI_COMPATIBLE_API_URL=https://api.closerouter.dev/v1/chat/completions
OPENAI_COMPATIBLE_MODEL=openai/gpt-5.5

# Язык перевода (подставляется в TRANSLATION_PROMPT.md)
TRANSLATION_TARGET_LANGUAGE=Russian

# Творческая свобода LLM (0.0–2.0)
TRANSLATION_TEMPERATURE=0.4
SUMMARY_TEMPERATURE=0.3

# Retry/backoff: задержка = BASE_DELAY_MS * (2 ** retry)
LLM_MAX_RETRIES=5
LLM_RETRY_BASE_DELAY_MS=1000

# HTTP-таймауты (секунды)
LLM_CONNECT_TIMEOUT=60
LLM_REQUEST_TIMEOUT=600
COVER_REQUEST_TIMEOUT=600

# Image-edit модель для русификации обложки (флаг --cover)
COVER_API_KEY=sk-...
COVER_API_URL=https://api.closerouter.dev/v1/chat/completions
COVER_MODEL=google/nano-banana-2-edit
```

Промпты лежат в `prompts/` — `TRANSLATION_PROMPT.md`, `SUMMARY_PROMPT.md`, `COVER_PROMPT.md`, `METADATA_PROMPT.md`. Редактируй под свои нужды; они подгружаются на старте.

## Запуск

```bash
# Перевод целой книги
php bin/translate path/to/book.epub

# Только одна глава (для отладки промпта)
php bin/translate path/to/book.epub --chapter OEBPS/xhtml/chapter12.xhtml

# Перевод + русификация обложки (title/author подберёт LLM)
php bin/translate path/to/book.epub --cover

# Полный контроль над обложкой
php bin/translate path/to/book.epub --cover \
  --cover-title="Империя рассвета" \
  --cover-author="Джей Кристофф" \
  --cover-series="Книга третья"
```

Результат — `book.<TRANSLATION_TARGET_LANGUAGE>.epub` рядом с исходником.

### Что показывает панель

```
 📖 EPUB Translator → Russian
 Источник: Empire of the Dawn.epub
 ──────────────────────────────────────────────────────────────
 Глава 12 / 47  ( 25.5%)   OEBPS/chapter12.xhtml
 [████████████░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   ► переводим главу через LLM…

 ⏱  прошло  00:12:34     ETA  ~00:37:18
 🔁 LLM запросов: 24     кеш-хитов: 5
 ↑ отправлено   142 580 ток.   (посл. 12 043)
 ↓ получено      38 914 ток.   (посл.  3 201)
```

Счётчики токенов берутся из поля `usage` ответа LLM. Если провайдер его не возвращает — счётчики останутся нулевыми, остальное (прогресс, время, ETA) работает.

## Кеширование

- **`.translation_cache.json`** — ключ `bookId → chapterRelativePath`, значение `{hash, translated}`. Если содержимое главы изменилось — sha1 не совпадёт, перевод запросится заново.
- **`.summary_cache.json`** — ключ `bookId → chapterId`, значение — текст саммари.

Безопасно прерывать (`Ctrl-C`) и перезапускать — пройденные главы пропускаются. Чтобы перевести заново: удалить запись из кеша или соответствующий файл целиком.

## Архитектура

DDD (порты и адаптеры). Подробно — `docs/architecture.md`.

```
bin/translate              DI-сборка, CLI entrypoint
├── src/Application/       оркестрация (TranslateBookUseCase, TranslateCommand, PackCommand)
├── src/Domain/            ядро без зависимостей (Book, Chapter, *Interface)
└── src/Infrastructure/    адаптеры (EpubHandler, Llm*, File*Cache, LLM/, Http/)
```

## Разработка

Обязательные гейты после любых правок в `src/`/`tests/`:

```bash
composer check   # php-cs-fixer (dry-run) + phpstan (level 6) + phpunit
```

Полезные команды:

```bash
composer test          # phpunit
composer stan          # phpstan
composer fmt           # автофикс стиля
composer fmt:check     # проверка стиля
```

Интеграционные тесты (`@group integration`) исключены по умолчанию — для них нужны реальные API-ключи.

## Безопасность

- `.env`, `.cache/`, `*.epub`, `.summary_cache.json`, `.translation_cache.json` — в `.gitignore`. Не коммитить.
- В коде ни в логи, ни в исключения содержимое `.env` не печатается.
