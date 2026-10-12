---
description: Review PR changes and suggest improvements
model: anthropic/claude-sonnet-4-20250514
---

# PR Review

Review the changes in this PR and provide feedback:

!`git diff origin/main...HEAD`
!`git log --oneline origin/main...HEAD`

## Review Checklist

Please analyze the changes and check:

1. **Code Quality**
   - Clean, readable code
   - Consistent naming conventions
   - No code duplication
   - Proper error handling

2. **Security**
   - No exposed secrets or credentials
   - Input validation where needed
   - Proper authentication/authorization

3. **Performance**
   - No obvious performance issues
   - Efficient algorithms and data structures

4. **Testing**
   - Are there tests for new functionality?
   - Do existing tests still pass?

5. **Documentation**
   - Are comments helpful and up-to-date?
   - Is README updated if needed?

Provide specific, actionable feedback with line references.
