# 7. The wizard suggests and explains; it never changes configuration silently

Status: accepted

## Decision
`DefinitionSuggester` is a pure function of a `TableProfile` (engine-neutral); engines only provide
the introspection. Every column gets a `Decision` with a human-readable reason, including skipped
ones (sensitive-looking columns are never indexed automatically). Output is code the developer
owns (YAML, builder, attributes); `--try` creates a throw-away index but does not edit config files.

## Consequences
+ Heuristics are unit-testable without a database; exports round-trip through the loaders (tested).
+ The same suggester serves the CLI, the web wizard and a future MySQL engine.
− Heuristics are English/Hungarian-name based; unusual schemas need manual edits.
