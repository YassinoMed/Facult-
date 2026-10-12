---
description: Refactor code for better maintainability
model: anthropic/claude-sonnet-4-20250514
---

# Refactor Code

Refactor: $ARGUMENTS

## Principles

Follow these refactoring principles:

1. **Single Responsibility** - Each function/class should do one thing well
2. **DRY (Don't Repeat Yourself)** - Extract duplicated code
3. **KISS (Keep It Simple)** - Simplify complex logic
4. **Small Functions** - Break large functions into smaller, focused ones
5. **Clear Naming** - Use descriptive variable and function names

## Steps

1. Analyze the current code structure
2. Identify code smells:
   - Long functions (>50 lines)
   - Deep nesting (>3 levels)
   - Duplicated code
   - Magic numbers/strings
   - Poor naming
3. Apply refactoring in small, safe steps
4. Ensure tests still pass after each change

## Output

- Explain what you changed and why
- Show before/after comparison for significant changes
- List any potential risks or considerations
