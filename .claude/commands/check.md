---
description: Прогнать обязательные гейты качества (phpunit + phpstan + php-cs-fixer)
---

Выполни в проекте: `composer check`.

Если команда отсутствует — запусти руками по очереди и отчитайся о падениях:

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

Не отчитывайся об успехе, если хотя бы один шаг красный. На красные тесты — переходи в роль **Bug Hunter** из `AGENTS.md`.
