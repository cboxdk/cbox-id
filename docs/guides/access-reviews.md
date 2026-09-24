---
title: Access reviews
weight: 90
description: Run a round of certification over who holds which role and membership, revoke what is no longer needed, and leave an auditable record that you did.
---

# Access reviews

**Console page:** Access control › Access reviews

An access review is a round where you go through who holds which role and
membership, and confirm each one is still needed. A review covers one organization, or,
on the environment console, every [staff role](#staff-roles). Access accumulates quietly —
people change teams, cover for someone, join a project that ended — and nobody
ever files a ticket to have their own permissions reduced. A review is the
scheduled moment when that gets cleaned up.

It is also the artefact an auditor asks for. "We review access quarterly" is a
claim; a closed review with names, decisions and dates is evidence.

## Run one

1. **New review**, named the way your auditor will recognise it — `Q3 access
   review`, not `test 2`.
2. Work down the list of grants in scope. For each: keep it, or revoke it.
3. **Close the review.** Revocations are applied at that point — not as you click,
   which means you can change your mind mid-review without having already broken
   somebody's access.

## Staff roles

**Environment console only.** A [staff role](roles.md#staff-roles) is held across the
whole environment, so no organization's review includes it. On the environment console,
**New review** asks what to review: the organization chosen in the bar above, or **Staff
roles**. The Staff page's **Review staff access** button opens the second directly.

A staff review lists every staff role and who holds it. Revoking an item takes the role
back **in every organization at once** when the review closes. A staff review stays on the
environment console's list whichever organization is chosen, marked *Staff roles*.

An organization's administrators never see a staff review or the grants in it, and cannot
open one.

## Doing it well

- **Ask the person who knows.** The reviewer should be whoever can actually say
  whether a grant is still needed — usually a team lead, not the platform admin.
- **Default to revoking.** If nobody can say why someone has a role, that is the
  answer. Re-granting takes seconds; an unnoticed standing grant lasts years.
- **Review after the events that create drift** — a reorganisation, an acquisition,
  the end of a big project — not only on the calendar.
- **Fix the cause, not the instance.** If the same unnecessary grants keep coming
  back, the group mapping that creates them is the real finding — see
  [Sync users in](sync-users-in.md).

## Related

- [Role conflicts](role-conflicts.md) — the rules that should have prevented the
  worst combinations in the first place.
- [Activity log](activity-log.md) — where the review's decisions are recorded.
