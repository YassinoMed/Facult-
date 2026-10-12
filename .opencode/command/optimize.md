---
description: Optimize code for performance
model: anthropic/claude-sonnet-4-20250514
---

# Performance Optimization

Optimize: $ARGUMENTS

## Analysis Areas

1. **Algorithm Complexity**
   - Look for O(n^2) or worse operations
   - Consider more efficient data structures

2. **Memory Usage**
   - Identify memory leaks
   - Reduce unnecessary allocations
   - Use streaming for large data

3. **I/O Operations**
   - Batch database queries
   - Cache frequently accessed data
   - Parallelize independent operations

4. **Frontend Specific**
   - Reduce bundle size
   - Lazy load components
   - Memoize expensive computations
   - Optimize re-renders

## Process

1. Profile first - measure before optimizing
2. Focus on hot paths
3. Make one change at a time
4. Verify improvements with benchmarks

## Output

- Current bottlenecks identified
- Proposed optimizations with expected impact
- Trade-offs (readability vs speed, memory vs CPU)
