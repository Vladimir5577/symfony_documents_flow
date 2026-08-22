# Prompt: Refactor Purchase Approval Into Explicit Workflow

You are working in the Symfony project `symfony_documents_flow`. Refactor the purchase module architecture toward an explicit workflow model while preserving the current business behavior.

Important constraints:

- Do not add route template versioning. The current principle is enough: templates affect new requests, while submitted requests keep their own copied route snapshot.
- Do not introduce blockchain, BPMN, Camunda, external workflow engines, or a generic company-wide process engine.
- Keep the solution pragmatic and module-local to `purchase`.
- Preserve current API behavior unless a deliberate migration requires a clearly documented compatibility change.
- Keep existing tests passing, and add/adjust tests for every migrated workflow scenario.
- Avoid unrelated refactors.

## Current Context

The purchase module currently models approval with these main pieces:

- `PurchaseRequest` is the business document.
- `PurchaseRouteTemplate` and `PurchaseRouteTemplateStep` define route templates for fast and standard requests.
- `ApprovalRouteBuilder` copies template steps into `PurchaseApprovalStep` rows when a request is submitted.
- `PurchaseApprovalStep` currently acts as both a workflow stage and an approval task.
- `position` currently means both route order and parallel group.
- `PurchaseApproverKind::USER` in template steps is not a fixed user assignment. It is effectively a dynamic slot for profile deputies selected later by the director.
- `PurchaseRequestService` owns many concerns: submit, approve, reject, return to department, revoke, director decision, sourcing, execution status changes, history, and notifications.
- `PurchaseAccess` centralizes permissions and should remain the single source for permission decisions.

The current behavior is conceptually good, but the code can be made clearer by separating stages from tasks and by making dynamic assignment explicit.

## Target Architecture

Move toward this conceptual model:

```text
PurchaseRequest
  ApprovalProcess/route snapshot
    ApprovalStage
      ApprovalTask
  PurchaseRequestHistory / ApprovalEvent
```

No versioning is required. The submitted request should still keep a snapshot of the route that was generated at submit time.

Recommended entity model:

```text
PurchaseRouteTemplate
PurchaseRouteDefault
PurchaseRouteTemplateStage
PurchaseRouteTemplateTask

PurchaseApprovalStage
PurchaseApprovalTask

PurchaseRequestHistory
```

If a smaller first step is preferred, it is acceptable to introduce service-level stage/task abstractions first, then migrate database schema later.


## Route Template Selection

Support multiple route templates. Do not keep the architecture limited to exactly one route for `FAST` and one route for `STANDARD`.

Separate these concepts:

```text
createdAs / PurchaseRequestKind
  Which request form and business mode was used: FAST or STANDARD.

routeTemplate
  Which approval workflow should be used for this concrete request.
```

The current default behavior should remain simple:

```text
FAST requests use the admin-selected default fast route.
STANDARD requests use the admin-selected default standard route.
```

But the model must allow more active templates:

```text
FAST_DEFAULT
FAST_WITH_DIRECTOR
STANDARD_DEFAULT
STANDARD_EXTENDED
STANDARD_WITH_SECURITY
STANDARD_SPECIAL_PROJECT
```

Recommended fields for `PurchaseRouteTemplate`:

```text
id
code
name
description nullable
is_active
sort_order
allowed_kinds or kind constraint
stages/tasks
updated_by_id nullable
updated_at nullable
```

Recommended table/entity for defaults:

```text
PurchaseRouteDefault
  id
  kind              FAST / STANDARD
  template_id
  updated_by_id
  updated_at
```

Recommended field on `PurchaseRequest`:

```text
selected_route_template_id nullable
```

Meaning:

- if `selected_route_template_id` is null, use the admin default route for `createdAs`;
- if it is set, use that template for this request;
- after submit, the generated stages/tasks are the real route snapshot, so later template/default changes do not affect the request in progress.

Add a module-local resolver:

```text
ApprovalRouteResolver
```

It should resolve a route template for a request:

```text
1. if request has selectedRouteTemplate, use it;
2. otherwise use PurchaseRouteDefault for request.createdAs;
3. if there is no active/default template, reject submit with the existing route-not-configured style error.
```

Admin route assignment:

- Admin must be able to manage a list of route templates.
- Admin must be able to choose the default route for `FAST`.
- Admin must be able to choose the default route for `STANDARD`.
- Changing a default route affects only future submissions, not already submitted requests.

Director route override:

- The model and workflow should support letting the director choose a route for a concrete request during the active `TRIAGE` stage.
- This can be implemented immediately or left behind a simple API/UI boundary, but the architecture must not block it.
- If implemented now, route override should be allowed only while the request is on an active `TRIAGE` stage and before later stages have decisions.
- The chosen template must be active and compatible with the request kind.
- After the director selects another route, rebuild the request approval snapshot from that template while preserving the current director triage decision according to a clearly documented rule.
- Prefer keeping the first director triage stage in every route template. This avoids introducing a special global intake stage outside templates.

## Business Route To Preserve

The current standard route must remain expressible:

```text
1. Director: triage / initial review
2. Purchase department: sourcing and documents
3. Accounting + Legal: parallel document review
4. Profile deputies: dynamic users selected by director
5. Director: final sign-off
6. Finance/economy director: sign-off
```

After approval, execution remains outside the approval route:

```text
APPROVED -> INVOICE_PAID -> DELIVERED -> DONE
```

Fast requests must also remain expressible as a shorter route, usually:

```text
1. Purchase department: sourcing/sign-off
```

## Core Design Requirements

### 1. Make Stage Explicit

Replace the implicit meaning of `position` with an explicit stage model.

Each stage should have:

```text
id
purchase_request_id or template_id
position
title
purpose
completion_mode
status or derived status
created_at
started_at nullable
completed_at nullable
```

`purpose` should keep the current business meaning:

```text
TRIAGE
SOURCING
SIGN_OFF
```

`completion_mode` can start with:

```text
ALL
ANY
```

Only `ALL` is required for current behavior, but defining the enum now makes the model clearer and future-safe.

### 2. Make Task Explicit

Each stage contains one or more tasks.

Each task should have:

```text
id
stage_id
assignment_type
role_code nullable
assignee_user_id nullable
decision
decided_by_id nullable
decided_at nullable
comment nullable
requires_file_type nullable
created_by_id nullable
created_at
```

Suggested assignment types:

```text
ROLE
FIXED_USER
DYNAMIC_USERS
```

Or:

```text
ROLE
USER
DIRECTOR_SELECTED_USERS
```

Choose the naming that best matches the existing code style, but the important rule is: do not use `USER` to mean both fixed user and dynamic slot.

### 3. Preserve Snapshot Behavior

When a request is submitted:

- resolve the template using `ApprovalRouteResolver`;
- create approval stages and tasks for that request;
- do not keep the request dependent on future template edits;
- if the route has a dynamic deputies stage, create the stage but do not create deputy tasks until the director selects users.

This replaces the current `approversPosition` concept with an explicit stage that can be found by assignment type or purpose.

### 4. Keep Permissions Centralized

`PurchaseAccess` should remain the central permission service.

It should answer questions like:

```text
canView(request, user)
canActOn(task, user)
findMyActiveTask(request, user, purpose = null)
canAssignDynamicApprovers(request, user)
canSelectRoute(request, user)
canClassify(request, user)
canCancel(request, user)
canAdvanceTo(request, user, targetStatus)
```

Avoid reintroducing role-specific gates in controllers.

### 5. Split Services By Responsibility

Reduce `PurchaseRequestService` responsibilities. Target split:

```text
PurchaseRequestService
  create/update request-level business data, submit facade if needed

PurchaseApprovalWorkflow
  submit route, activate current stage, approve/reject/revoke/return, assign dynamic approvers

PurchaseSourcingService
  supplier, price edits, sourcing-specific validations

PurchaseExecutionService
  APPROVED -> INVOICE_PAID -> DELIVERED -> DONE

PurchaseHistoryLogger
  append immutable history records

PurchaseNotificationPublisher
  keep notification publication, but call it from the workflow/execution services
```

Do not over-split if it makes the first migration unsafe. The goal is clearer ownership, not abstraction for its own sake.

## Admin Route Builder

The route must still be built in the admin UI.

Instead of editing a flat list of steps with repeated `position`, the admin should conceptually edit:

```text
Route template
  Stage 1
    title
    purpose
    completion mode
    tasks
      - role/user/dynamic assignment
  Stage 2
    ...
```

For example:

```text
Stage: Document review
Purpose: SIGN_OFF
Completion: ALL
Tasks:
  - ROLE: ACCOUNTING
  - ROLE: LEGAL
```

This is clearer than:

```text
position 3: ACCOUNTING
position 3: LEGAL
```

The backend should validate and normalize stage positions the same way it currently normalizes step positions.

## Validation/Invariants

Enforce these rules in the route editor and/or domain services:

- A route cannot be empty.
- A default route for `FAST`/`STANDARD` must point to an active compatible template.
- A request-level selected route must point to an active compatible template.
- A stage cannot be empty unless it is a valid dynamic assignment stage waiting for runtime users.
- `ROLE` task must have `role_code`.
- `FIXED_USER` or `USER` task must have `assignee_user_id`.
- `DYNAMIC_USERS` task/stage must not have a fixed role or fixed user unless the chosen design explicitly needs a selector role.
- There should be at most one dynamic profile deputy stage unless business explicitly allows more.
- Dynamic profile deputy stage must be after the first `TRIAGE` stage.
- The route should not contain a task that nobody can ever complete.
- If return-to-department relies on `SOURCING`, define what happens when there is no `SOURCING` stage.
- Prefer one sourcing stage unless multiple sourcing stages are explicitly supported.

Database constraints should be added where practical, but business validation should remain in services so API errors stay meaningful.

## Migration Strategy

Use an incremental migration. Do not rewrite the module blindly.

### Step 1: Introduce names and enums

- Add explicit assignment type enum.
- Add explicit stage completion mode enum.
- Rename or deprecate confusing `USER` semantics in templates.
- Keep existing behavior passing.

### Step 2: Add stage/task abstractions in services

Before changing the DB schema, create helper/resolver classes if useful:

```text
ApprovalStageResolver
ApprovalTaskResolver
```

They can group existing `PurchaseApprovalStep` rows by `position` temporarily.

This reduces risk and prepares services for the new model.

### Step 3: Introduce template stages/tasks

Add entities:

```text
PurchaseRouteDefault
PurchaseRouteTemplateStage
PurchaseRouteTemplateTask
```

Migrate current `PurchaseRouteTemplateStep` data into:

```text
one stage per position
one task per old step
```

For the current dynamic profile deputy slot, create a dynamic stage/task instead of a fake user step.

Route template migration:

- Convert the existing unique-by-kind route templates into named templates.
- Create default assignments:

```text
FAST -> existing fast template
STANDARD -> existing standard template
```

- Remove or relax the old unique constraint that allowed only one template per kind.
- Keep compatibility endpoints if the frontend still expects fast/standard route settings during the migration.

### Step 4: Introduce approval stages/tasks for requests

Add entities:

```text
PurchaseApprovalStage
PurchaseApprovalTask
```

Migrate current `PurchaseApprovalStep` rows into:

```text
one approval stage per request+position
one approval task per old step
```

Keep old fields temporarily if needed for compatibility, but avoid long-term duplication.

### Step 5: Move workflow logic

Move approval-related methods from `PurchaseRequestService` into `PurchaseApprovalWorkflow`:

```text
submit
approveTask
rejectTask
returnToSourcing
revokeTask
assignDynamicApprovers
selectRouteOnTriage
activateNextStage
resetFromStage
```

Keep `PurchaseRequestService` as a facade only if controllers need a stable dependency during migration.

### Step 6: Update controllers and presenter

Controllers should operate on active tasks instead of active steps.

Presenter should expose:

```text
stages[]
  tasks[]
currentStage
actions.activeTaskId
actions.canApproveTask
```

Compatibility can be preserved by also returning the old `steps` shape temporarily if the frontend needs a staged migration.

### Step 7: Remove old model

After tests and frontend are updated:

- remove or fully deprecate `PurchaseApprovalStep`;
- remove `approversPosition`;
- remove code paths that depend on `position` as parallel group;
- remove old template step API if no longer used.

## Tests To Preserve And Add

Keep or rewrite the existing tests around:

- fast route is submitted and approved;
- standard route shape is preserved;
- accounting and legal are parallel and both required;
- profile deputies are selected by director and inserted into the later dynamic stage;
- author cannot become their own profile deputy approver;
- duplicate selected deputies are collapsed;
- out-of-turn approval is rejected;
- required file blocks task completion;
- sourcing actor becomes executor;
- return to department resets later approvals;
- reject sends request back to author;
- revoke resets the actor's own approval and later approvals;
- repeated submit rebuilds the route from current template;
- unconfigured route cannot be submitted.

Add new tests for:

- multiple active route templates per request kind;
- admin can assign default route for `FAST`;
- admin can assign default route for `STANDARD`;
- submit uses the admin-selected default route;
- request-level selected route overrides the default route;
- director can select an active compatible route during active `TRIAGE` if the feature is implemented now;
- route cannot be changed after later stage decisions unless a deliberate reset rule is implemented and tested;
- explicit stage grouping;
- `completion_mode = ALL`;
- optional `completion_mode = ANY` if implemented;
- route editor rejects empty stages;
- route editor rejects invalid assignment type combinations;
- dynamic stage is visible as a stage before runtime tasks are created;
- admin route presentation returns stages with nested tasks;
- API permissions still use `PurchaseAccess`, not hard-coded roles.

## Acceptance Criteria

The refactor is complete when:

- The business process from the current module is preserved.
- The route is still configured in admin.
- Multiple route templates can exist.
- Admin can assign default templates for `FAST` and `STANDARD`.
- A concrete request can store an optional selected route template for future director/department override.
- The route resolver chooses selected route first and default route second.
- Submitted requests still keep their own route snapshot.
- The code has explicit stage/task concepts.
- Dynamic profile deputy assignment is explicit, not hidden behind `USER`.
- Parallel approval is represented by multiple tasks inside one stage, not by repeated raw positions.
- `PurchaseRequestService` no longer owns most workflow internals.
- Tests cover the old behavior and the new stage/task semantics.
- No route versioning, blockchain, BPMN, or external engine was introduced.

## Implementation Style

Follow existing Symfony/Doctrine project conventions.

Prefer small, reviewable commits or patches:

1. enums and read-only abstractions;
2. template stage/task model;
3. approval stage/task model;
4. workflow service migration;
5. controller/presenter migration;
6. cleanup.

When unsure, preserve behavior first and improve naming/model clarity second.
