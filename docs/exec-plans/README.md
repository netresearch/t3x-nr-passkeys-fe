# Execution plans

Working directory for multi-step agent task plans, per the agent-harness
convention.

- `active/` — plans currently being executed. One Markdown file per task:
  goal, constraints, ordered steps with verification commands, current status.
- `completed/` — finished plans, kept for traceability.

Plans are scratch coordination artifacts, not documentation: decisions that
outlive a task belong in `Documentation/Adr/` (rendered ADRs) or
`docs/ARCHITECTURE.md`, not here. Delete or archive a plan once its outcome
is merged.
