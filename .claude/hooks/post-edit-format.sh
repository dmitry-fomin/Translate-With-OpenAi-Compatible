#!/usr/bin/env bash
# PostToolUse hook: после изменений PHP-файлов запускает php-cs-fixer на затронутом файле.
# Тихо завершается, если инструмент не установлен.

set -uo pipefail

input="$(cat || true)"
# Извлекаем file_path из tool_input
file="$(printf '%s' "$input" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\(.*\)".*/\1/p' | head -1)"

if [[ -z "$file" ]] || [[ "${file##*.}" != "php" ]]; then
  exit 0
fi

# Не лезем в vendor и кеши
case "$file" in
  */vendor/*|*/.summary_cache.json|*/.phpunit.result.cache) exit 0 ;;
esac

if [[ -x "vendor/bin/php-cs-fixer" ]]; then
  vendor/bin/php-cs-fixer fix "$file" --quiet >/dev/null 2>&1 || true
fi

exit 0
