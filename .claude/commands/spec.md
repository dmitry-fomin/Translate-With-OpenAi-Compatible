---
description: Создать спецификацию по шаблону docs/spec-template.md
argument-hint: <feature-slug>
---

Цель: задать новый цикл Spec-Driven Development.

Шаги:
1. Прочитай `docs/spec-template.md`.
2. Скопируй его в `docs/specs/$ARGUMENTS.md`.
3. Заполни секции: Why, Acceptance Criteria, Scope (in/out), Edge Cases, Technical Decisions, Risks.
4. После заполнения — задай мне **только** оставшиеся вопросы (паттерн `/zadacha`): не предлагай решения, пока не закроем gaps.
5. Когда спека готова — создай задачу в beads: `bd create -t "spec:$ARGUMENTS" -d "see docs/specs/$ARGUMENTS.md"`.

**Запрет:** не писать ни строки кода до моего явного `approve`.
