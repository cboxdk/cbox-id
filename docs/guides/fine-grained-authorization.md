---
title: Fine-grained authorization
weight: 23
description: Model who may do what to which of your app's own resources — documents in folders, owners, editors and viewers with inheritance — then write relationship tuples and ask checks and list queries from your backend, an agent, the CLI or the console.
---

# Fine-grained authorization

**Console page:** Users & orgs › Fine-grained authorization in an environment console, with
the schema editor behind **Edit schema**.

[Roles](roles.md) and [permissions](permissions.md) answer "may this person approve
invoices in this organization?". Fine-grained authorization answers "may this person edit
**this** document?" — where the answer depends on who owns it, which folder it is in, who
can see that folder, and which groups the person is in.

You describe that model once, as a **schema**. Your app then writes **tuples** — the facts,
such as "the readme is in the handbook folder" or "alice is a member of eng" — and asks
**checks** on every request. Cbox ID evaluates the check through every inheritance the
schema defines, caches the answer, and never mixes one environment's model with another's.

## When to use it

| You want to say | Use |
|---|---|
| "Admins of an organization can manage its members" | [Roles](roles.md) |
| "Billing admins can approve invoices, anywhere in this organization" | [Roles](roles.md) and [permissions](permissions.md) |
| "Alice can edit this document, because she owns the folder it is in" | Fine-grained authorization |
| "Everyone in the eng group can view this dashboard, except contractors" | Fine-grained authorization |
| "Show me only the documents this user can see" | Fine-grained authorization, with list-resources |

## A worked example: documents in folders

A document lives in a folder; folders live in other folders. Each has owners, editors and
viewers. An owner can edit; an editor can view; and anyone who can view or edit a folder
can do the same to everything in it, however deep. People are put in groups, and groups in
other groups.

```text
# Documents live in folders; a folder's access is inherited by everything in it.
type user

type group
  relation member: [user, group#member]

type folder
  relation parent: [folder]
  relation owner: [user]
  relation editor: [user, group#member] or owner or editor from parent
  relation viewer: [user, group#member] or editor or viewer from parent

type document
  relation parent: [folder]
  relation owner: [user]
  relation editor: [user, group#member] or owner or editor from parent
  relation viewer: [user, group#member] or editor or viewer from parent
```

Read `relation viewer: [user, group#member] or editor or viewer from parent` as: a
document's viewers are

- any **user** a tuple names as a viewer, or every **member of a group** a tuple names;
- **or** anyone who is an **editor** of the same document;
- **or** anyone who is a **viewer of its parent** folder.

### The schema language

| Written | Means |
|---|---|
| `type document` | a resource type; its relations are the indented lines below it |
| `[user, group#member]` | the subjects a tuple may name directly — any user, or everybody who is a member of one group |
| `owner` | another relation on the same resource |
| `viewer from parent` | `viewer` on the resources this one's `parent` tuples point at |
| `a or b` | either |
| `a and b` | both |
| `a but not b` | `a`, except whoever has `b` |
| `( … )` | grouping; `or` and `and` only mix with parentheses |
| `# …` | a comment, at the start of a line or after a space |

Names are lower-case letters, digits, `_` and `-`, up to 64 characters.

To block somebody from a document they would otherwise inherit:

```text
type document
  relation parent: [folder]
  relation viewer: [user, group#member] or viewer from parent
  relation blocked: [user]
  relation can_read: viewer but not blocked
```

and check `can_read` rather than `viewer`.

### What the schema refuses

Saving a schema checks it first and lists **every** problem by line:

- a type or relation it does not define — including a typo in `[usr]`;
- `viewer from parent` where `parent` is not a plain list of types like `[folder]`, or no
  type it lists has a `viewer`;
- more than one `[...]` list on one relation;
- a relation defined as itself;
- a `but not` whose subtracted side depends, through any chain, on the relation it decides
  — "a viewer, but not a viewer" has no answer.

Cycles that only **add** access — a group inside a group, a folder inside a folder — are
allowed; the schema above has both. They are bounded when a check runs instead.

A schema change is also refused while tuples exist that the new schema would no longer
allow, and the refusal names them (`schema_conflict`). Delete those tuples first: changing
the schema never takes access away silently.

To check a schema without saving it, `GET /api/v1/fga/schema/validate?schema=…` answers
with every problem by line, or the parsed types. The schema travels in the URL there, so
for a very large one call `fga.schema.update` instead: an invalid schema is refused before
anything is saved or any approval is asked for.

## Writing tuples

A tuple is one fact, written `resource#relation@subject`:

```text
folder:handbook#owner@user:olivia          olivia owns the handbook folder
folder:policies#parent@folder:handbook     policies is inside the handbook
document:leave#parent@folder:policies      the leave policy is inside policies
group:eng#member@user:alice                alice is in eng
folder:policies#viewer@group:eng#member    everybody in eng can view policies
```

Write them from your backend in batches of up to 100, all or nothing:

```bash
curl https://id.example.com/api/v1/fga/tuples \
  -H "Authorization: Bearer $CBOX_ID_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "tuples": [
      {"resource_type": "group", "resource_id": "eng", "relation": "member",
       "subject": {"type": "user", "id": "alice"}},
      {"resource_type": "folder", "resource_id": "policies", "relation": "viewer",
       "subject": {"type": "group", "id": "eng", "relation": "member"}}
    ]
  }'
```

```json
{ "data": { "written": 2, "deleted": 0, "consistency_token": "7.9f3c1a7be2d0" } }
```

Each tuple is checked against the schema: an unknown type or relation, a relation that is
only computed (such as `viewer` defined as `editor or …` with no `[...]`), or a subject the
relation does not take refuses the whole batch with `invalid_tuple`, naming the position.
Re-writing a tuple that exists is not an error and is not counted. Delete with
`POST /api/v1/fga/tuples/delete` and the same body; deleting a tuple that is not there is
not an error either.

Ids are your own: up to 128 printable characters without spaces, `#` or `@`.

## Asking checks

```bash
curl "https://id.example.com/api/v1/fga/check?resource_type=document&resource_id=leave&relation=viewer&subject_type=user&subject_id=alice" \
  -H "Authorization: Bearer $CBOX_ID_KEY"
```

```json
{ "data": { "allowed": true, "resource_type": "document", "resource_id": "leave",
            "relation": "viewer", "subject": {"type": "user", "id": "alice", "relation": null},
            "consistency_token": "7.9f3c1a7be2d0" } }
```

alice can view the leave policy: she is in eng, eng can view the policies folder, and the
leave policy is in it.

- **Many at once**: `GET /api/v1/fga/check/batch?checks[]=document:leave%23viewer@user:alice&checks[]=…`
  — up to 100, each in the tuple notation, answered in order at one revision. Use it for a
  page that shows twenty documents with an edit button each. (`#` is `%23` in a URL.)
- **A group as the subject**: `subject_type=group&subject_id=eng&subject_relation=member`
  asks whether everybody in eng has the relation.
- **Which documents can alice view?**
  `GET /api/v1/fga/resources?resource_type=document&relation=viewer&subject_type=user&subject_id=alice`
  — ids, sorted, up to 1000 a page (`limit`, `after`).
- **Who can view the leave policy?**
  `GET /api/v1/fga/subjects?resource_type=document&resource_id=leave&relation=viewer&subject_type=user`
  — every group expanded.

A check that names a type or relation the schema does not define is refused with
`unknown_relation`, never answered "no" — a typo in your code would otherwise deny everyone
without a word. A check whose inheritance goes deeper than 25 relations is refused with
`resolution_too_complex` rather than guessed.

## Fresh enough: consistency tokens

Every write answers with a `consistency_token`. Pass it with a check —
`&consistency_token=7.9f3c1a7be2d0` — and the answer is **at least as fresh as that
write**. Use it when a user has just been given access and the very next request must see
it: right after they are added to a document, or right after an admin removes them.

Without a token a check is as fresh as the database it reads, which on a single database
is the latest. A token from another environment, or for a revision that has not happened,
is refused with `invalid_consistency_token`.

Answers are cached per environment under the model's current revision, and every write
moves the revision on. So a write is visible to the next check immediately, on every
server, without anything being flushed.

## From an agent or the CLI

Every operation is an action, so an MCP client and the `cbox` CLI reach the same thing
with the same scopes:

| Action | REST | MCP tool | Scope | Danger |
|---|---|---|---|---|
| `fga.schema.get` | `GET /api/v1/fga/schema` | `fga_schema_get` | `fga:read` | read |
| `fga.schema.validate` | `GET /api/v1/fga/schema/validate` | `fga_schema_validate` | `fga:read` | read |
| `fga.schema.update` | `PUT /api/v1/fga/schema` | `fga_schema_update` | `fga:schema` | critical |
| `fga.tuples.write` | `POST /api/v1/fga/tuples` | `fga_tuples_write` | `fga:write` | write |
| `fga.tuples.delete` | `POST /api/v1/fga/tuples/delete` | `fga_tuples_delete` | `fga:write` | destructive |
| `fga.tuples.list` | `GET /api/v1/fga/tuples` | `fga_tuples_list` | `fga:read` | read |
| `fga.check` | `GET /api/v1/fga/check` | `fga_check` | `fga:read` | read |
| `fga.check.batch` | `GET /api/v1/fga/check/batch` | `fga_check_batch` | `fga:read` | read |
| `fga.resources.list` | `GET /api/v1/fga/resources` | `fga_resources_list` | `fga:read` | read |
| `fga.subjects.list` | `GET /api/v1/fga/subjects` | `fga_subjects_list` | `fga:read` | read |

```bash
cbox id fga resources list --resource-type=document --relation=viewer \
  --subject-type=user --subject-id=alice
```

Give your app's backend a key with `fga:read` and `fga:write` only. Changing the schema
changes the answer to every check at once, so `fga:schema` is flagged on the key form, and
an agent should change it through an approval — see [Step-up
approvals](step-up-approvals.md). A token a person signed in for one organization cannot
reach this model at all: it belongs to the environment.

## In the console

**Users & orgs › Fine-grained authorization** shows the schema's types and relations as they
read, the tuples stored (filter by resource or subject), a box to write or delete one in
the notation above, and a **check playground**: type a resource, a relation and a subject,
and it answers exactly as the API would, with the revision it was decided at. A playground
question is a link you can paste into a ticket.

**Edit schema** opens the editor, behind a fresh password confirmation. **Validate** lists
every problem by line without saving; **Save** puts the schema in force for every check at
once. A new environment's editor opens with the documents-in-folders example above.

## What is recorded

The [Audit log](activity-log.md) records `fga.schema.updated` (the version and the types),
`fga.tuples.written` and `fga.tuples.deleted` (every tuple by name, and the revision it
produced), with the key over the API and the person in the console. Checks and list
queries are reads and record nothing.

## Related

- [Roles](roles.md) and [Permissions](permissions.md) — what a person may do in an organization.
- [Agents and MCP](agents-and-mcp.md) — connecting an agent to these actions.
- [Actions](../core-concepts/actions.md) — how one action is reached from four doors.
