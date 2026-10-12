---
description: Fix TypeScript type errors
model: anthropic/claude-sonnet-4-20250514
subtask: true
---

# Fix TypeScript Errors

Run TypeScript compiler and fix all type errors:

!`npx tsc --noEmit 2>&1`

## Guidelines

1. **Prefer proper typing over `any`** - Use specific types whenever possible
2. **Use type inference** - Don't over-annotate when TypeScript can infer
3. **Fix root causes** - Don't just silence errors with assertions
4. **Maintain type safety** - Ensure fixes don't break other parts of the code
5. **Add missing types** - Create interfaces/types if needed

For each error:
- Explain what the error means
- Show the fix
- Explain why this fix is appropriate
