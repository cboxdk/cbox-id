---
title: Role conflicts
weight: 100
description: Declare the roles that must never be held by the same person — segregation of duties — so conflicting grants are blocked and existing conflicts are surfaced.
---

# Role conflicts

**Console page:** Access control › Role conflicts

Some pairs of roles must never sit with the same person. Whoever raises a payment
should not also approve it; whoever requests access should not also grant it. In
audit language this is **segregation of duties** — the control that stops a single
person completing a sensitive process alone.

Declaring a conflict here does two things:

1. **Blocks new grants** that would put a conflicting pair on one person.
2. **Surfaces people who already hold one**, which is the part you cannot get from
   a policy document.

## Declare a conflict

1. **New rule**, named the way the risk is described in your own controls —
   `Raise payment vs. approve payment` — not `rule 4`. The name is what shows up
   when a grant is refused, so it should explain itself to whoever hits it.
2. Pick the roles that must not be combined.
3. Save. Existing holders of the combination are listed for you to resolve.

## Things worth knowing

- **Existing conflicts are not auto-resolved.** Cbox ID will not pick which of
  someone's two roles to take away; that is a decision with consequences and it
  belongs to a person. They are listed so you can act.
- **Start with the pairs you would have to explain.** A rule for every theoretical
  combination produces noise nobody reads. Two or three real ones, enforced, beat
  twenty aspirational ones.
- **A blocked grant is a signal, not just an error.** If people keep hitting the
  same rule, either the process needs a second person or the rule is wrong. Both
  are worth knowing.

## Related

- [Roles](roles.md), [Access reviews](access-reviews.md).
