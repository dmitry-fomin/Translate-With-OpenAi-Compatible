---
globs: ["**/*.php"]
---
# Правила PHP для TranslaterLLM

## Базовое
- PHP `^8.2`, PSR-4 (`App\\` → `src/`, `App\\Tests\\` → `tests/`).
- В новых файлах — `declare(strict_types=1);` первой строкой после `<?php`.
- Все свойства типизированные. `mixed` запрещён, кроме границы с внешним JSON.
- Конструкторская инъекция, без сервис-локаторов.

## Стиль
- Имена интерфейсов — суффикс `Interface` (по уже принятой конвенции).
- VO / immutable data → `readonly` class или `readonly` свойства.
- Используй `match` вместо длинных `switch`.
- `final class` по умолчанию для реализаций инфраструктуры.

## DDD
- `src/Domain/` не импортирует ничего из `App\Infrastructure\*`, `Symfony\*`, `GuzzleHttp\*`, `Dotenv\*`. Запрещено.
- `src/Application/` оркестрирует порты, не лезет напрямую в HTTP/DOM.
- `src/Infrastructure/` — единственное место, где `DOMDocument`, Guzzle, `file_get_contents`, `$_ENV`.

## Ошибки
- Никаких "тихих" fallback'ов на пустую строку при ошибке LLM/HTTP. Бросаем исключение.
- В Use case можно ловить и оборачивать в доменное исключение со смыслом.
- В CLI-команде (`TranslateCommand`) — `try/catch` верхнего уровня уже есть, расширяй только осмысленные ветки.

## Тесты
- PHPUnit `^10.5`. Тесты в `tests/`, namespace `App\Tests\…`.
- Юнит-тесты ядра — без I/O, моки только на доменные порты.
- Интеграционные тесты с реальной сетью — в `tests/Integration/` с группой `@group integration`, по умолчанию исключаются.

## Запрещено
- Глобалы (`$GLOBALS`), статические синглтоны для бизнес-состояния.
- Чтение `$_ENV` внутри `src/Domain/` и `src/Application/`. Только в `bin/translate`.
- Печать содержимого `.env` в любом виде.
