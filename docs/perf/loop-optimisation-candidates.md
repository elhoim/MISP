# Loop performance optimisation candidates

Machine-generated sweep of every `foreach`/`for`/`while` in `app/Model`, `app/Controller`,
`app/Lib` and `app/Console` (489 PHP files, CakePHP 2 / PHP 8), looking for loop-centred
performance problems: n+1 queries, per-row saves, quadratic scans, loop-invariant work and
unbounded accumulation.

Every candidate was found by one agent and then handed to a second, independent agent whose
instructions were to **refute** it: open the code, confirm the loop is really as described,
grep for a caller that runs it at scale, and check whether the proposed fix would change
behaviour. Candidates that survived are listed first. Refuted candidates are kept, with the
refutation, so nobody re-investigates them from scratch.

## How to read the percentage

`est.` is the estimated reduction in the wall clock of the **enclosing operation** - the
fetch, the export, the sync step - **not** of total MISP runtime. A 40% entry on a workflow
module that is off by default is worth far less in practice than a 20% entry on the event
fetch path. Read `why_hot` before ranking work by this number.

## Status of this sweep

| | |
|---|---|
| Candidates raised | 49 |
| Survived refutation | **19** |
| Refuted | 29 |
| No verdict returned | 1 |
| Files agents reported reviewing | **337 of 489 (69%)** |

**The file coverage is incomplete and the list is therefore not exhaustive.** Agents
self-reported how many files they opened; the shortfall is concentrated in the smaller files
of each slice, since every agent was told to lead with the largest. Treat an absence here as
"not looked at", not as "clean".

One optimisation of exactly this kind has already been implemented and measured, and is the
reason the `save_in_loop` pattern is weighted highly: `Galaxy::__createClusters` saved one
cluster per `Model::save()` (~55k round trips). Batching it cut galaxy ingestion from 639.9s
to 342.9s (-46.4%) with byte-identical output. See commit `9b811512cf`.

## Confirmed candidates

Ranked by estimated gain, then confidence. Each survived an agent trying to kill it.

### 1. Log::findDeletedEvents - `Model/Log.php`

| | |
|---|---|
| Location | `app/Model/Log.php:618` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **50%** of the enclosing operation |
| Original estimate | 65% (revised by the verifier) |
| Confidence | medium |
| Hot path confirmed | **no** |
| Realistic iterations | tens to ~200 deleted events per recovery-tool invocation (one bulk-delete incident or audit window); basis: this is an incident-response/audit action, not a per-request hot path, but each such invocation is exactly this multiplicative |

**What is slow.** For every distinct deleted-event log entry in the date range, the function issues 2-5 separate find() queries (Event existence check, Log creation-entry lookup, Org lookups, User lookups) instead of batching them, and the User lookups aren't even memoized despite an identical Org lookup two lines above being correctly memoized.

**Why it is on a hot path.** Called from EventsController.php:8429 (admin 'recover deleted events in date range' tool). For each deleted event in the window it runs: Event->find('first') (line 624) to check it isn't already restored, Log->find('first') (line 638) to fetch the creation log entry, up to 2 Org 'list' finds (memoized via $orgs, lines 655-660), and 2 User 'list' finds (lines 663 and 669) that are NOT memoized even though a $users cache array exists right there -- if the same user (e.g. the admin who did a bulk delete, or a prolific event creator) recurs across many deleted events, each occurrence re-queries. A bulk-delete incident being investigated/recovered realistically involves tens to a few hundred events.

**Basis for the estimate.** The function is almost entirely DB round trips; for N events it currently issues roughly 2N to 5N queries. Deduping model_ids up front and doing one Event.id IN(...) existence check, one Log query with model_id IN(...) AND action='add' indexed by model_id, plus properly memoized Org/User lookups (mirroring the pattern already used for $orgs) collapses this to a small constant number of queries. Conservative estimate given N in the tens-to-hundreds range and that JSON change-parsing per row still remains after the fix.

**Proposed fix.** Before the loop: collect all unique model_id (event id) values from $deletions. Issue one Event->find('all', ['Event.id' => $uniqueIds], fields=['id']) to build a set of still-existing event ids (skip those in the loop instead of querying per row). Issue one Log->find('all', ['model_id' => $uniqueIds, 'model'=>'Event', 'action'=>'add']) and index the results by model_id for O(1) lookup in the loop. Add `if (!isset($users[$id]))` guards before both User->find('list') calls at lines 663 and 669, matching the existing $orgs memoization pattern immediately above.

**Implementation notes.** Keep the existing dedup-by-event-id skip at lines 618-623 (it already prevents processing the same event twice within one call). $orgs and $users are function-local, so memoization is safe within a single call; it does not need to persist across requests. The output shape ($deleted_events array of flat rows) must stay identical since it's rendered directly by the admin UI/API in EventsController.

**Risk.** Low behavioral risk: it's a read-only reporting/preparation function (recoverDeletedEvent, the actual restore, is separate). Main risk is subtle: batching the 'still exists' check changes it from 'find first matching id' to a membership test against a pre-fetched id set, which is equivalent as long as the IN() query isn't truncated by driver limits for very large id lists (unlikely here given realistic N).

**Verifier correction.** Not refuted, but hot path and size corrected. The N+1 is real and matches the code: per deleted event id it runs Event->find('first') (624), Log->find('first') for the 'add' entry (638), and two User->find('list') calls (663, 669) that assign into $users but never isset-check it, so they are genuinely unmemoized while $orgs (655-660) is. However the sole caller is EventsController::restoreDeletedEvents (line 8429), a one-shot admin recovery tool for the 2020 login-deletion incident: the window is hardcoded ('2020-07-31' to the fix_login AdminSetting) and the whole result is cached in redis under misp:event_recovery for 600s, so it is not a recurring production path -- real per-invocation multiplicativity, near-zero ongoing payoff. Hence hot_path_confirmed=false. Estimate lowered 65 -> 50: batching removes the 2N Event/Log round trips, but the User memoization only pays off on repeated user ids and the per-row changeParser JSON work is unchanged. Additional correctness caveat the notes do not cover: the batched action='add' lookup must select the lowest Log.id row per model_id to reproduce today's find('first') result when an event id has more than one 'add' log entry (id reuse after deletion/recreation).

---

### 2. Module_attach_decay_score::exec - `Model/WorkflowModules/action/Module_attach_decay_score.php`

| | |
|---|---|
| Location | `app/Model/WorkflowModules/action/Module_attach_decay_score.php:60` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **40%** of the enclosing operation |
| Original estimate | 60% (revised by the verifier) |
| Confidence | medium |
| Hot path confirmed | yes |
| Realistic iterations | One restSearch()+fetchAttributes() pair per matched attribute; events routinely carry hundreds to low-thousands of attributes, and a workflow with a permissive filter (or none) attached to this module would match all of them, giving hundreds to thousands of full search executions per workflow run. |

**What is slow.** For every attribute matched by the workflow node's filters, runs a full restSearch()+fetchAttributes() call (built for bulk/API search with its own filter parsing, joins, and decay-score computation) to compute the decay score for that single attribute, instead of one restSearch call for all matched UUIDs.

**Why it is on a hot path.** This workflow action module (id 'attach-decay-score') is designed to run on 'matchingItems' returned by getMatchingItemsForAttributes(), which is filtered from an event's attribute set by the workflow node's configured filters ('support_filters' => true); a workflow triggered on event-publish or attribute-after-save with a broad filter can match every attribute of an event, so this executes once per attribute in the triggering event.

**Basis for the estimate.** restSearch() is the same heavy query-building/permission/join pipeline used for full attribute exports; replacing N single-uuid restSearch calls with 1 call filtered by the full uuid list of matchingItems collapses N query-plan builds + N round trips into one, and decay-score computation itself (Base.php computeCurrentScore) also re-inits the Sighting model on every call, compounding the per-item overhead removed by batching.

**Proposed fix.** Collect all matchingItems uuids into a single array, issue one restSearch(user, 'json', ['uuid' => $allUuids, 'includeDecayScore' => '1', 'decayingModel' => ...], true) + one fetchAttributes() call, then index the results by uuid and iterate matchingItems in memory to call _overrideAttribute() for each, instead of one restSearch/fetchAttributes pair per attribute.

**Implementation notes.** restSearch's 'uuid' filter already accepts arrays elsewhere in the codebase (same pattern used by other bulk searches), so filters['uuid'] = array_column($matchingItems, 'uuid') should work without restSearch changes. Must preserve per-attribute _overrideAttribute() semantics — index the batch result by ['Attribute']['uuid'] and skip attributes with no match exactly as the current 'if (!empty($attributeWithScore))' guard does.

**Risk.** Medium: needs verifying restSearch's uuid-array filter behaves identically to a single-uuid filter with respect to includeDecayScore/decayingModel option handling and permission scoping for every uuid in the batch; also confirm result-set size/pagination limits in restSearch don't silently truncate very large matchingItems batches (may need to chunk into batches of e.g. 500 if restSearch has an internal limit).

**Verifier correction.** Confirmed, but the estimate is inflated - corrected 60% -> 40%. The loop at lines 59-70 does run a full restSearch()+fetchAttributes() pair per matched attribute, and getMatchingItemsForAttributes (WorkflowBaseModule.php:171-184) falls back to Hash::extract of Event._AttributeFlattened.{n} when no filter is set, so it genuinely matches every attribute of the triggering event. Two of the candidate's open risks resolve in its favour: MispAttribute::restSearch (line 3642) sets no default limit (only if isset($filters['limit'])), so there is no silent truncation and no chunking is needed; and MispAttribute::set_filter_uuid (line 4006) runs $params['uuid'] through convert_filters and handles the resulting OR array, so an array uuid filter is supported - drop both worries from the fix notes. The estimate is trimmed because batching removes N query-pipeline builds and round trips but the per-attribute decay-score/Sighting work inside fetchAttributes remains, so it is not a 60% cut. Hot-path caveat to state plainly: this is conditional - workflows must be enabled and this module explicitly wired into a workflow; it is not a default code path.

---

### 3. Module_splunk_hec_export::sendToSplunk - `Model/WorkflowModules/action/Module_splunk_hec_export.php`

| | |
|---|---|
| Location | `app/Model/WorkflowModules/action/Module_splunk_hec_export.php:126` |
| Pattern | `file_io_in_loop` |
| Estimated gain | **40%** of the enclosing operation |
| Original estimate | 75% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | once per attribute in the triggering event when event_per_attribute=1; events with 1k-10k attributes are common in threat-intel feeds, so 1k-10k sequential HTTP round trips per workflow execution is realistic. |

**What is slow.** sendToSplunk() issues one blocking HTTP POST (doRequest) per Splunk event; when the module's 'event_per_attribute' option is enabled (line 96-102), that is one HTTP request per attribute of the triggering event instead of a single batched HEC request (Splunk HEC natively accepts multiple newline-delimited event JSON docs in one POST body).

**Why it is on a hot path.** This is a Workflow action module triggered on events such as 'event-publish' (Module_event_publish.php, also in this slice) or any other configured trigger; the module's own description explicitly warns 'Due to the potential high amount of requests' when used with event_per_attribute, confirming the maintainers are aware many attributes -> many requests. MISP events routinely carry hundreds to 10k+ attributes.

**Basis for the estimate.** Splunk HEC supports multiple event JSON objects concatenated in a single POST body without wrapping; batching in chunks of e.g. 500 would turn N round trips (each incurring TLS+HTTP overhead, commonly 20-100ms) into N/500 round trips, so for large attribute counts the request-count reduction (and thus wall-clock reduction of sendToSplunk, which is the bulk of exec()'s runtime for this module) is on the order of 99%; estimating a more conservative 75% to account for typical attribute counts being smaller than the 10k extreme and the current concurrent_task mitigation devs already recommend.

**Proposed fix.** Batch $splunk_events into chunks (e.g. 500) and send each chunk as a single POST with concatenated newline-delimited JSON event bodies to the HEC endpoint, instead of one doRequest() call per event.

**Implementation notes.** HEC's multi-event format is simply consecutive JSON objects with no separator/array wrapping (each still wrapped in the {'event': ...} envelope); construct the batched body by concatenating json_encode() output for each event in the chunk. Preserve existing per-request error handling/errors[] reporting but report at chunk granularity. Keep the existing single-event path unchanged when event_per_attribute is off (already just 1 event).

**Risk.** Chunk-level failure reporting is coarser than per-attribute failure reporting (currently each doRequest() failure is caught and logged individually per event) — one bad event in a chunk could obscure which specific attribute failed unless per-event details from the batch response are parsed.

**Verifier correction.** Not refuted — the code is exactly as described (one doRequest() per element of $splunk_events, one element per attribute when event_per_attribute is set) and HEC does accept concatenated event docs. But the estimate is inflated and the hot path is not confirmed: execution requires three opt-ins (Security.workflow_enable_arbitrary_urls enabled, a workflow explicitly wired to this module, and event_per_attribute=1); nothing in the codebase calls it at scale on its own. The risk note is also wrong about the current behaviour: sendToSplunk returns false on the FIRST failing request, and the $errors[] it writes to is an undefined local variable (the by-ref $errors belongs to exec()), so per-event error reporting is already broken and batching loses less than claimed. Corrected 75 -> 40 to reflect the config gating and that the win only materialises on large event_per_attribute exports.

---

### 4. __statisticsOrgs - `Controller/UsersController.php`

| | |
|---|---|
| Location | `app/Controller/UsersController.php:2403` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **35%** of the enclosing operation |
| Original estimate | 55% (revised by the verifier) |
| Confidence | medium |
| Hot path confirmed | yes |
| Realistic iterations | One extra Event query per organisation with events; a mid-size MISP community instance commonly has 100-500 local+external orgs, so 100-500 full unbounded Event scans per page load. |

**What is slow.** For every organisation returned by the grouped events query, __statisticsOrgs calls User::getOrgActivity($orgId, ...) which runs its own full, unaggregated Event->find('all') (no LIMIT) over the last 365 days and aggregates in PHP -- one extra full query per org.

**Why it is on a hot path.** Called from UsersController::statistics('orgs') (line 2213: $this->__statisticsOrgs($this->params['named'])), an admin/site-admin statistics page. $orgs is every Organisation matching the scope (local or external), so getOrgActivity (app/Model/User.php:1451, calling $this->Event->find('all', ['conditions'=>['Event.orgc_id'=>$orgId], 'fields'=>[...]])) runs once per org in the foreach at line 2409-2413.

**Basis for the estimate.** getOrgActivity's find('all') is the only unindexed, full-row, un-aggregated query in the page and is repeated once per org while every other statistic on the page (users, events, attribute sums) is a single grouped query; consolidating it into one GROUP BY orgc_id, DATE(timestamp) query removes what is very likely the dominant share of this page's wall clock. Conservative estimate given I did not have production timing data.

**Proposed fix.** Replace the per-org getOrgActivity() calls with a single query grouped by (Event.orgc_id, DATE(Event.timestamp)) summing attribute_count, restricted to the same 365d window and to array_keys($orgs); split the result into a per-org sparkline array in PHP the way the existing per-org loop already assembles it, then remove the per-iteration call at line 2412.

**Implementation notes.** The loop lives in app/Controller/UsersController.php (line 2409-2413: foreach ($events as $event) { ... $orgs[$event['Event']['orgc_id']]['orgActivity'] = $this->User->getOrgActivity(...); }). getOrgActivity itself is defined in app/Model/User.php:1451 (outside this slice) and builds a date=>attribute_count sparkline per org from Event rows ordered by timestamp; a batched replacement needs a new method (e.g. getOrgActivityBulk(array $orgIds, $params)) that issues one grouped Event query and returns [$orgId => $sparklineData] so the existing per-org consumption code (which expects $orgs[$id]['orgActivity']) stays unchanged.

**Risk.** Low-to-medium: getOrgActivity also computes best/worst-day style min/max stats after the sparkline (lines beyond 1476 in User.php) -- those need to be recomputed per org from the batched sparkline in PHP rather than dropped. Must confirm the grouped SQL 'GROUP BY orgc_id, DATE(timestamp)' produces identical per-day sums to the existing per-event PHP accumulation (attribute_count nulls, timezone of DATE() vs PHP date()).

**Verifier correction.** Confirmed as a real N+1: the foreach at 2409-2413 calls User::getOrgActivity() once per org that has events, and User.php:1451 runs its own Event->find('all') per call. ACL grants 'statistics' => array('*'), so it is not admin-only. BUT the estimate is inflated on a false premise: the candidate calls the per-org query 'unindexed' and a 'full unbounded Event scan'. It is not — events.orgc_id is indexed (INSTALL/MYSQL.sql KEY `orgc_id`), the find is recursive=-1 with only 3 fields and a 365d timestamp filter, so each query is cheap; the win is round-trip count plus PHP aggregation, not scan elimination. Corrected to ~35%. Also a defect in the proposed fix, not fatal: Event.timestamp is a unix int column, so 'GROUP BY orgc_id, DATE(timestamp)' will not work literally — it needs FROM_UNIXTIME() (timezone-sensitive vs PHP date()) or, more safely, one un-grouped query over all orgc_ids with the existing PHP day-bucketing reused verbatim. The min/max recomputation risk the notes flag is moot: getOrgActivity has no best/worst-day logic beyond the sparkline/CSV assembly.

---

### 5. OrgsContributorsGeneric::handler - `Lib/Dashboard/OrgsContributorsGeneric.php`

| | |
|---|---|
| Location | `app/Lib/Dashboard/OrgsContributorsGeneric.php:51` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **35%** of the enclosing operation |
| Original estimate | 70% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | yes |
| Realistic iterations | Once per local organisation on a MISP instance -- community/ISAC-style instances commonly have 50-300+ local orgs, so each dashboard render of one of these widgets issues 50-300+ separate Event queries (each cached for cacheLifetime=3600s, but still 1 query per org on every cache-cold render). |

**What is slow.** handler() fetches all local organisations in one query, then calls the per-widget filter() callback once per org, and every concrete filter() implementation issues its own Event->find() query -- one Events query per organisation instead of one aggregate query.

**Why it is on a hot path.** OrgsContributorsGeneric is the shared base for the dashboard widgets OrgsContributorLastMonthWidget, OrgsUsingObjectsWidget and OrgsUsingMitreWidget (grep confirms `extends OrgsContributorsGeneric` in app/Lib/Dashboard/OrgsContributorLastMonthWidget.php and OrgsUsingObjectsWidget.php). Each subclass's filter() (verified in OrgsUsingObjectsWidget.php and OrgsContributorLastMonthWidget.php) runs `$this->Event->find('all', ['conditions' => ..., 'limit' => 1, ...])` scoped to a single org_id/orgc_id -- executed inside the foreach at line 53 of OrgsContributorsGeneric.php, once per local organisation returned by the earlier Org->find('all').

**Basis for the estimate.** Each per-org query is a small indexed lookup (~1-5ms) but N+1 round trips serialize; for 200 orgs that's roughly 200-1000ms of sequential query overhead versus a single GROUP BY Event.orgc_id query with a >= timestamp / event-visibility filter and a HAVING/exists check, which would run in tens of milliseconds. This is a conservative estimate since it doesn't assume network latency to a remote DB, which would make the ratio even larger.

**Proposed fix.** Replace the per-org filter() query with a single Event->find grouped by orgc_id (or org_id) with the same visibility/timestamp conditions and no org_id restriction, producing a set of org ids that qualify; then filter the $orgs array in memory against that set instead of calling filter() per org.

**Implementation notes.** filter() is a template-method hook overridden per widget with different join/condition logic (event visibility via createEventConditions($user), plus either orgc_id/org_id OR-condition or an INNER JOIN to objects/tags), so this requires refactoring the base class contract from a per-org boolean callback to a per-widget bulk-query callback returning a set of qualifying org ids -- touches OrgsContributorsGeneric.php and its 3 known subclasses (OrgsContributorLastMonthWidget.php, OrgsUsingObjectsWidget.php, OrgsUsingMitreWidget.php, the latter two outside this review slice).

**Risk.** Medium: the visibility conditions (createEventConditions($user)) and the exists-style join (e.g. INNER JOIN objects for OrgsUsingObjectsWidget) need to be preserved exactly when converted to a grouped/bulk query, or the widget could start showing organisations that shouldn't be visible to the current user.

**Verifier correction.** Confirmed exactly as described: handler() does one Org->find('all') on Organisation.local=1 and then calls filter() inside the foreach (line 53); OrgsContributorLastMonthWidget::filter and OrgsUsingObjectsWidget::filter each issue their own $this->Event->find('all', ... 'limit' => 1) scoped to a single org id, so one Events query per local org. Nothing in CakePHP batches this and no containable setting avoids it. However the magnitude claim is inflated: each query is recursive=-1, fields=Event.id, limit=1 against indexed org_id/orgc_id/timestamp columns; the set is bounded by local orgs only (Organisation.local=1), which on most instances is a handful rather than the claimed 50-300; the widget has cacheLifetime=3600 so the cost is paid at most once an hour per user/config, and only if an operator has actually added one of these opt-in widgets to a dashboard. The refactor also requires changing the template-method contract across three subclasses while preserving createEventConditions($user) visibility and the INNER JOIN exists-semantics exactly. Cut 70% to ~35% and confidence to low.

---

### 6. Event::includeRelatedTags - `Model/Event.php`

| | |
|---|---|
| Location | `app/Model/Event.php:3649` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **20%** of the enclosing operation |
| Original estimate | 30% (revised by the verifier) |
| Confidence | high |
| Hot path confirmed | yes |
| Realistic iterations | Loop iterates once per (attribute, correlated-attribute) pair in event['RelatedAttribute']; a moderately popular IOC (e.g. a common IP or hash) can correlate to tens or hundreds of other attributes, and an event can have many such attributes, so an event with 50 correlating attributes averaging 20 correlations each issues roughly 1000 individual AttributeTag find() calls (on top of the already-cached event-tag lookup at line 3656). |

**What is slow.** For every correlated (related) attribute of every attribute in the event, runs a fresh AttributeTag::find('all') query to fetch that related attribute's tags, instead of batching all attribute_ids into one query.

**Why it is on a hot path.** Called from Event::fetchEvent (app/Model/Event.php:3364) whenever a caller passes includeRelatedTags=1, which is wired up as a first-class, user-facing option on both the Events index/view controller (app/Controller/EventsController.php:1356-1360, 1819-1820) and the REST search component (app/Controller/Component/RestSearchComponent.php:136), i.e. any API/UI consumer that wants correlation tags on an event.

**Basis for the estimate.** Each find('all') on AttributeTag with a Tag contain is a full round trip plus join; collapsing all distinct attribute_ids in event['RelatedAttribute'] into a single find('all', ['AttributeTag.attribute_id' => $allIds]) followed by in-memory grouping removes essentially all of these round trips for one, replacing O(n) queries with O(1); conservative estimate reflects that this is one contributor among several to fetchEvent's total cost when includeRelatedTags is requested.

**Proposed fix.** Before the outer loop, collect every relatedAttribute['attribute_id'] across all of event['RelatedAttribute'], issue one AttributeTag::find('all') with 'attribute_id' IN (...ids) and the same Tag contain/local filter, then group results into an attribute_id => [Tag,...] map in memory; replace the per-relatedAttribute find() at line 3676 with a lookup into that map.

**Implementation notes.** Also worth folding in: the attributePos lookup at lines 3682-3688 does a linear scan of event['Attribute'] for every attributeId in RelatedAttribute; building an id=>index map once before the outer loop turns that into O(1) too. __cacheRelatedEventTags (line 3619) is already correctly memoized per related event id — only the AttributeTag find is unbatched.

**Risk.** Low: this is a pure read/aggregation path with no side effects; the only care needed is preserving the excludeLocalTags filter semantics when batching the single query.

**Verifier correction.** Confirmed, but the estimate is inflated - corrected 30% -> 20%. The code is exactly as described: __cacheRelatedEventTags memoizes per related event id, but the AttributeTag::find('all') at line ~3676 fires once per (attribute, correlated-attribute) pair with no cache and no batching, and the attributePos lookup below it is an O(n) linear scan per attribute. RelatedAttribute is built by DefaultCorrelationBehavior::runGetAttributesRelatedToEvent grouped by parent attribute id with an 'attribute_id' key per entry, so a single batched find on the distinct attribute_ids is straightforward and behaviour-preserving (pure read path; only the excludeLocalTags condition must be carried into the batched query). One correction to the why_hot framing: the loop is gated on includeGranularCorrelations AND includeRelatedTags (Event.php:3362-3364), not includeRelatedTags alone - the event view/REST paths do set both, so the hot path stands. The 30% claim overstates it because getRelatedAttributes' own correlation collection plus the attribute/tag/object fetch dominate fetchEvent; removing the AttributeTag round trips is one contributor, hence ~20%.

---

### 7. AnalystDataParentBehavior::attachFlatAnalystData / attachAnalystData - `Model/Behavior/AnalystDataParentBehavior.php`

| | |
|---|---|
| Location | `app/Model/Behavior/AnalystDataParentBehavior.php:41` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **20%** of the enclosing operation |
| Original estimate | 30% (revised by the verifier) |
| Confidence | medium |
| Hot path confirmed | yes |
| Realistic iterations | bounded by page size (Event index pagination is commonly 25-60 rows/page), so ~25-60 iterations x 4 queries = 100-240 extra queries per page load when includeAnalystData is requested |

**What is slow.** AppModel::find() (app/Model/AppModel.php:4844) calls this behavior's attachAnalystData() once per row for find('all', [...,'includeAnalystData'=>true]) results instead of using the model's own attachAnalystDataBulk() batch method that already exists in this same file (line 111) and is used correctly by Event::fetchEvent(). Each attachAnalystData() call does 3 single-uuid fetchForUuid() queries (one per Note/Opinion/Relationship type) plus a getInboundRelationships() query -- 4+ queries per row.

**Why it is on a hot path.** EventsController::index() sets $this->paginate['includeAnalystData'] from a passed URL/API parameter (line 775) and calls $this->paginate(), which is a find('all', ...) -- hitting the per-row path in AppModel::find() for every event on the paginated page.

**Basis for the estimate.** the file already contains a correct bulk implementation (attachAnalystDataBulk, chunked fetchForUuids) used elsewhere in the codebase (Event::fetchEvent for Attribute/Object/EventReport); swapping the find('all') per-row branch in AppModel::find() to call that instead removes ~3/4 of the query volume for this code path, conservatively

**Proposed fix.** In AppModel::find()'s type==='all' branch (app/Model/AppModel.php ~line 4843), replace the per-row loop calling attachAnalystData() with a single call to attachAnalystDataBulk($results-as-objects) followed by merging results back by key, mirroring how Event::fetchEvent already does it for Attribute/Object.

**Implementation notes.** AppModel.php itself is outside this review slice, but the fix belongs in this behavior class's public surface: attachAnalystDataBulk already accepts an array of objects and returns them merged with analyst data, so the AppModel::find() call site just needs to pass $results (keyed by row) through it instead of looping attachAnalystData per row. Must preserve the RelationshipInbound field that attachAnalystData (but not attachAnalystDataBulk) currently sets per row -- confirm whether that's needed for the affected callers before dropping it.

**Risk.** attachAnalystDataBulk does not currently populate RelationshipInbound the way attachAnalystData does (see line 37) -- if index-page consumers rely on that field, the bulk path needs it added, or this fix regresses that data for the paginated event list view.

**Verifier correction.** Confirmed: AppModel::find (app/Model/AppModel.php:4840-4847) loops attachAnalystData() per row for type 'all', each call doing 3 fetchForUuid() queries plus getInboundRelationships(); attachAnalystDataBulk (line 111) with chunked fetchForUuids exists in the same file. Hot path confirmed: Event actsAs includes 'AnalystDataParent' (Event.php:47), EventsController::index sets $this->paginate['includeAnalystData'] (line 775) and CakePHP's PaginatorComponent passes unrecognised settings through as $extra into find($type, array_merge($parameters, $extra)), so the flag does reach AppModel::find. Estimate trimmed from 30% to ~20% for two reasons: the path only fires when includeAnalystData is explicitly requested (not a default page load), and the index action does substantial other work (__attachInfoToEvents, tag/correlation decoration) that the fix does not touch. The swap is also not drop-in - attachAnalystDataBulk omits RelationshipInbound (set at line 37) and the non-REST nested path's per-element fetchChildNotesAndOpinions depth-5 recursion - but the candidate already flags that, so it corrects rather than refutes.

---

### 8. APIActivityWidget::handler - `Lib/Dashboard/APIActivityWidget.php`

| | |
|---|---|
| Location | `app/Lib/Dashboard/APIActivityWidget.php:138` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **20%** of the enclosing operation |
| Original estimate | 40% (revised by the verifier) |
| Confidence | medium |
| Hot path confirmed | yes |
| Realistic iterations | one iteration per distinct API key active in the window; a moderately active MISP instance (dozens of users each with 1-3 keys, feeds/sync workers) commonly has 20-150 distinct keys hit the API in a week |

**What is slow.** For every distinct API key seen in the Redis activity log over the report window, the widget runs a separate AuthKey::find('first') (with User/Organisation/Role contain) instead of resolving all keys in one query.

**Why it is on a hot path.** handler() is the render method for the 'API Activity' dashboard widget, invoked on every dashboard page load for any user/site-admin who has it configured. $counts is keyed by every unique authkey seen across the (default 7-day) window pulled via a Redis pipeline, so the loop runs once per distinct key that made a request in the period.

**Basis for the estimate.** Replacing N sequential find('first') calls (each with a User/Organisation/Role join) with a single find('all') using an OR'd list of (authkey_start, authkey_end) pairs collapses N round-trips into 1; for N=50-150 keys this is the dominant cost of widget render since the Redis pipeline portion is already batched.

**Proposed fix.** Build the OR condition list ['AuthKey.authkey_start' => x, 'AuthKey.authkey_end' => y] for every key in $counts, run one find('all') with that OR array (same contain), then index the results by 'authkey_start.authkey_end' composite key to populate $resolved in memory instead of per-key querying.

**Implementation notes.** authkey_start/authkey_end are a 4+4 char split of the live key so the batched query must use nested OR/AND blocks (one AND pair per candidate key) rather than a single IN(), since the match is a composite pair not a single column. Keep the existing 'unknown key' counting (empty result) semantics.

**Risk.** Low: read-only lookup, no behavior beyond widget rendering depends on ordering or side effects; correctness hinges on the OR-condition being built correctly for the composite key match.

**Verifier correction.** Code confirmed exactly as described: one AuthKey::find('first') with User/Organisation/Role contain per distinct redis key, inside foreach(array_keys($counts)). Hot path plausible — site-admin-only widget but autoRefreshDelay = 30, so a dashboard left open re-runs it every 30s. Estimate corrected 40 -> 20: both match columns are indexed (db_schema.json lists auth_keys.authkey_start and auth_keys.authkey_end under indexes), so each iteration is a cheap single-row indexed SELECT; at the realistic N of 20-150 the absolute saving is milliseconds and the Redis pipeline plus dashboard/widget render overhead is a large share of the request. No semantic risk: find('first') and a batched find('all') both resolve duplicate (start,end) pairs arbitrarily, and the empty-result 'unknown key' counting is preserved.

---

### 9. EventsController::pushProposals - `Controller/EventsController.php`

| | |
|---|---|
| Location | `app/Controller/EventsController.php:6360` |
| Pattern | `quadratic_nested_loop` |
| Estimated gain | **20%** of the enclosing operation |
| Original estimate | 40% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | yes |
| Realistic iterations | proportional to proposals pushed per call; realistic sync batches are tens to low hundreds of shadow attributes for an actively-worked event, worst case low thousands |

**What is slow.** pushProposals() nests a foreach over incoming proposals inside a foreach over the event's existing ShadowAttributes for dedup (O(n*m)), then does a per-row Organisation::captureOrg() find, a per-row ShadowAttribute create()+save(), and for non-deleted proposals a per-row sendProposalAlertEmail() that internally does an Event->read(), a User->find('all', ...), a mail send loop, and an Event->save() (via setProposalLock) -- all repeated once per proposal in the push.

**Why it is on a hot path.** pushProposals is the endpoint a remote MISP instance's ShadowAttribute/proposal push hits (this->Event->ShadowAttribute->save() per item, called from sync push of accumulated local proposals). Events under active collaborative triage can accumulate hundreds to low-thousands of pending proposals; a single push syncing that backlog re-reads and re-saves the parent Event and re-queries all alertable org members once per proposal.

**Basis for the estimate.** sendProposalAlertEmail alone issues 2 queries + N mail sends + 1 save per call; multiplying that by every non-deleted proposal in the batch (instead of once per Event) plus the O(n*m) dedup scan and per-row captureOrg find is the dominant cost of the whole action for any batch above a handful of proposals -- conservative estimate given email/query overhead typically dwarfs the in-PHP work

**Proposed fix.** Pre-index existing ShadowAttributes by (event_uuid,value,type,category,to_ids) once before the loop instead of a nested scan; batch captureOrg lookups by fetching all needed orgs in one find(); move sendProposalAlertEmail out of the per-row loop to run once after the loop (it already alerts per-event, not per-proposal, so accumulate a 'anyKept' flag and call it once); consider ShadowAttribute->saveMany() for the successful rows.

**Implementation notes.** sendProposalAlertEmail's own lock check (proposal_email_lock) already tries to prevent duplicate emails, but each call still pays 2 queries + a save before hitting the lock -- moving the call outside the loop removes that per-row cost entirely. Preserve existing per-row validation/skip semantics (the `continue 2` when a newer proposal already exists) when restructuring.

**Risk.** Moving sendProposalAlertEmail outside the loop changes timing slightly (one email after all proposals processed rather than attempted after each) -- but existing lock logic already collapses to at most one email per event within the lock window, so behavior is effectively unchanged for the common case.

**Verifier correction.** Code confirmed at 6340-6402 (loop starts ~6360) and the caller chain is real: Event.php:6285 / Server.php:1414 -> Server::syncProposals (Server.php:1576) POSTs the event's whole ShadowAttribute array to /events/pushProposals/<uuid>. But the estimate basis is factually wrong and 40% is inflated. ShadowAttribute::sendProposalAlertEmail (ShadowAttribute.php:573-604) checks proposal_email_lock FIRST and returns immediately if set; the first call sets the lock via setProposalLock, so rows 2..n cost exactly one Event->read() SELECT each - not '2 queries + N mail sends + 1 save per call'. The genuine per-row waste is: 1 captureOrg SELECT (Organisation.php:218, no memoisation), 1 redundant Event->read, and the O(n*m) in-PHP dedup scan (cheap relative to DB). The unavoidable ShadowAttribute create()+save() with its callbacks remains and dominates, and real proposal batches are tens, not thousands. Corrected to ~20%, confidence lowered.

---

### 10. AttributesController::addTag - `Controller/AttributesController.php`

| | |
|---|---|
| Location | `app/Controller/AttributesController.php:3307` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **15%** of the enclosing operation |
| Original estimate | 35% (revised by the verifier) |
| Confidence | medium |
| Hot path confirmed | yes |
| Realistic iterations | outer loop over idList (attributes) x inner loop over tag_id_list (tags); realistic case is 200-500 attributes x 1-3 tags = 200-1500 iterations, each doing 2 SELECTs + 1 INSERT + a ClassRegistry::init('Log') + createLogEntry() INSERT |

**What is slow.** Bulk 'add tag to selected attributes' loops attributes x tags, running a hasAny() existence check, a find('column') re-query of every tag on the attribute, and an individual AttributeTag::save() + Log::createLogEntry() for every (attribute, tag) pair instead of resolving/inserting in bulk.

**Why it is on a hot path.** addTag($id='selected', $tag_id) is the handler behind the 'Add tag' bulk action used from the event view attribute list, the Taxonomies mass-tag confirmation (View/Taxonomies/ajax/taxonomy_mass_confirmation.ctp), and Elements/ajaxTags.ctp/ajaxTagCollectionTags.ctp — all of which POST an attribute_ids JSON array plus one or more tag/tag-collection ids. Selecting 200-1000 attributes (a routine 'select all on page/filtered result' action) and applying 1-3 tags is a normal workflow.

**Basis for the estimate.** Each iteration currently issues up to 4 DB round-trips (hasAny, find('column') for tagsOnAttribute, AttributeTag save, Log insert) plus fetchAttributeSimple() once per attribute. Pre-loading existing AttributeTag rows for the whole idList in one query and building an in-memory set, and using insertMulti/saveMany for the AttributeTag + Log rows, removes 3 of those round-trips per pair; conservative estimate given fetchAttributeSimple() and the taxonomy exclusivity check still run per-attribute.

**Proposed fix.** Before the loops: fetch all existing AttributeTag rows for idList in one query (attribute_id IN idList, local = $local) and index by [attribute_id][tag_id]; skip hasAny()/find('column') re-queries by maintaining that in-memory index instead of re-querying per pair. Batch the AttributeTag inserts with insertMulti/saveMany and batch the Log rows similarly (or defer log writing to one createLogEntry-per-batch call if the Log model supports it).

**Implementation notes.** Must preserve per-pair semantics: taxonomy exclusivity check (checkIfNewTagIsAllowedByTaxonomy) depends on the CURRENT set of tags on that attribute and must see tags added earlier in the same batch (in-memory index update on each accepted add, not just DB state). Must keep per-attribute permission check (__canModifyTag) and per-attribute Event::insertLock/touch behavior intact. Success/fail counters and the JSON response shape must be unchanged.

**Risk.** Medium: taxonomy exclusivity logic must stay correct against in-progress additions in the same request; changing insert mechanics risks bypassing model callbacks (afterSave hooks on AttributeTag, e.g. correlation/notification triggers) that per-row save() currently guarantees — needs verification that no afterSave side effect is relied upon before switching to insertMulti/saveMany.

**Verifier correction.** Code confirmed: per (attribute,tag) pair the loop does AttributeTag::hasAny(), a find('column') of Tag.name for the taxonomy check, an AttributeTag::save() and a Log::createLogEntry(); nothing is hoisted. Hot path confirmed: app/webroot/js/misp.js:774 quickSubmitAttributeTagForm() posts to /attributes/addTag/selected with getSelected() feeding AttributeAttributeIds (form in View/Attributes/ajax/attributeEditMassForm.ctp / Events/add_tag.ctp). Estimate corrected 35 -> 15: (a) both AttributeTag lookups hit indexed columns (db_schema.json indexes attribute_tags.attribute_id and .tag_id) so they are cheap single-row queries, while the per-attribute costs the fix does NOT touch — fetchAttributeSimple() with its ACL joins, Event::insertLock(), touch() — remain and dominate; (b) only the read half of the fix is safely applicable: AttributeTag::afterSave (app/Model/AttributeTag.php:37) fires ZMQ / Kafka / 'tag-attached-after-save' workflow triggers per row, which insertMulti/insertMulti-style batching would silently skip, so the insert batching is not a legitimate win. The proposed preload is also wrong as written: hasAny() is NOT scoped by 'local', but the proposed preload is (local = $local); a $local-scoped index would miss an existing global attachment and re-insert a duplicate — the dedup index must cover both local states, with a separate local-scoped view for the taxonomy check.

---

### 11. CollectionElement::captureElements - `Model/CollectionElement.php`

| | |
|---|---|
| Location | `app/Model/CollectionElement.php:246` |
| Pattern | `save_in_loop` |
| Estimated gain | **12%** of the enclosing operation |
| Original estimate | 30% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | yes |
| Realistic iterations | One save() per incoming CollectionElement (line 246-273) plus one delete() per stale element no longer present upstream (line 274-276); for a collection with, say, 1,000 elements this is up to 2,000 individual queries per sync. |

**What is slow.** When syncing a Collection's element corpus from a remote instance, new/changed elements are saved one at a time (create()+save() per element) and removed elements are deleted one at a time (delete() per id), instead of a single bulk insert/update and a single deleteAll().

**Why it is on a hot path.** Called from Collection::captureCollection() during instance-to-instance Collection sync (app/Model/Collection.php:532) and from CollectionsController on manual add/edit (app/Controller/CollectionsController.php:48,237). Collections are designed to carry a corpus of many elements (IOC/attribute/object lists); a pull of a collection with hundreds-to-thousands of elements re-saves every element on every sync.

**Basis for the estimate.** Two separate O(n) query loops (save-per-row, delete-per-row) can each become O(1): new rows via one bulk insert, unchanged/changed rows via one updateAll or a small number of updateAll calls keyed by field, and removals via one deleteAll(['CollectionElement.id' => $ids]). I scope the estimate conservatively to ~30% of captureElements()'s wall time because it already does 2 full find('all') scans (already single-query, not the bottleneck) and the try/catch-per-row error handling on save() has legitimate diagnostic value that a naive batch rewrite must preserve.

**Proposed fix.** Split $elementsToSave into two buckets: rows with an existing id (updates) and rows without (new inserts). For inserts, build all row arrays and call insertMulti() once. For updates, either loop updateAll() per differing field set or, if only 'description' changes, a single updateAll keyed by id/CASE-WHEN, or keep them as a much smaller residual save loop only for rows that actually changed. Replace the oldElements deletion loop with a single $this->deleteAll(['CollectionElement.id' => array_column($oldElements, 'id')]).

**Implementation notes.** The method already guards the parent Collection's 'modified' bump via $this->skipCollectionModifiedBump — that flag presumably gates a Behavior or afterSave hook on CollectionElement that touches Collection.modified; any bulk-insert/deleteAll path bypasses afterSave/afterDelete entirely, so whatever that flag protects against must be reproduced (e.g. a single explicit Collection.modified touch after the batch, done once instead of suppressed n times). The trailing re-fetch (lines 277-284) that rebuilds $data['Collection']['CollectionElement'] from a fresh find('all') stays correct regardless of how the rows got written, since it re-reads from the DB.

**Risk.** insertMulti/deleteAll skip beforeSave/afterSave/beforeDelete/afterDelete callbacks and Behaviors, so any hook depending on per-row events (audit logging, the modified-bump suppressed by skipCollectionModifiedBump, cascading effects on the parent Collection) needs to be explicitly reproduced once for the whole batch rather than assumed to still fire n times; the per-row try/catch(PDOException) diagnostic logging (which records which specific element failed and why) is lost with a single bulk INSERT unless duplicate/constraint-violating rows are pre-filtered in memory first.

**Verifier correction.** Confirmed and survives: save-per-row at line 246 and delete-per-row at line 274, reached per collection per pull via Collection.php:307 -> captureCollection -> 532. Unlike the other candidates, the eliminated cost IS the iteration's dominant cost, and the write amplification is code-confirmed regardless of corpus size: every existing element is re-saved unconditionally (elementsToSave carries oldElements rows with no change detection), so a sync re-writes the whole corpus even when nothing changed. Estimate corrected 30 -> 12 because: (a) corpus sizes are unverified - nothing in the code bounds Collections at 'hundreds to thousands' of elements; (b) the cheapest real fix is skipping unchanged rows, which does not need insertMulti at all; (c) the per-row try/catch PDOException logging is load-bearing diagnostics that a naive bulk INSERT loses. Two corrections to the notes: skipCollectionModifiedBump is set for the whole method body (211-287) so the afterSave/afterDelete touchCollection risk is already moot inside captureElements, and CollectionElement's only behavior is Containable (line 9-11) - no AuditLog - so callback loss is a smaller risk than claimed; conversely the notes miss deduceType (line 188-201), which does up to 2 SELECTs per new element lacking element_type and must be pre-resolved by any batch path.

---

### 12. Module_attribute_edition_operation::__saveAttributes - `Model/WorkflowModules/action/Module_attribute_edition_operation.php`

| | |
|---|---|
| Location | `app/Model/WorkflowModules/action/Module_attribute_edition_operation.php:43` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **12%** of the enclosing operation |
| Original estimate | 30% (revised by the verifier) |
| Confidence | low-medium |
| Hot path confirmed | **no** |
| Realistic iterations | once per attribute in the triggering event; realistic events run from dozens to 10k+ attributes when the workflow module has no filter narrowing the selection |

**What is slow.** Before doing the actual bulk save, __saveAttributes calls MispAttribute::editAttribute() once per attribute, and that call issues its own find('first') by uuid (app/Model/MispAttribute.php:3242) purely to pre-populate fields for validation/merge — a full SELECT per attribute even though editAttributeBulk() immediately afterwards does a proper saveAll/saveMany batch.

**Why it is on a hot path.** This is the base class for four production workflow action modules (Module_attribute_ids_flag_operation, Module_attribute_distribution_operation, Module_attribute_comment_operation, Module_attribute_edition_operation itself), all invoked via getMatchingItemsForAttributes() which — when the workflow node has no filter configured — returns Hash::extract($rData, 'Event._AttributeFlattened.{n}'), i.e. every flattened attribute of the triggering event (WorkflowBaseModule.php:171-184). MISP events routinely carry hundreds to tens of thousands of attributes (feed-imported events, MITRE ATT&CK bundles, bulk IOC imports), and these 'toggle IDS flag on all attributes' / 'set distribution on all attributes' workflow actions are exactly the kind of automation users attach to event-publish or attribute-add triggers, so they run over the full attribute set of an event on every trigger firing.

**Basis for the estimate.** editAttribute()'s find('first') and editAttributeBulk()'s saveMany() are both single-row DB round trips of comparable cost; the current flow issues roughly 2N DB round trips (N finds + N saves) for N attributes. Replacing the per-attribute find with one find('all', ['Attribute.uuid' => $uuids]) built into an in-memory uuid=>row map before the loop removes N of those ~2N round trips, i.e. roughly halves the DB-round-trip count of the enclosing __saveAttributes() call; conservatively estimated at 30% of its wall clock once other fixed overhead (validation, tag capture, logging) is accounted for.

**Proposed fix.** In __saveAttributes(), before the foreach, collect all uuids from $attributes, run a single MispAttribute::find('all', ['conditions' => ['Attribute.uuid' => $uuids], 'recursive' => -1]) and index the results by uuid. Change editAttribute() to accept this pre-fetched existing-row (or a lookup map) instead of doing its own find('first') per call, falling back to the existing per-uuid find only when called standalone (e.g. from single-attribute sync paths) so other callers of editAttribute() aren't broken.

**Implementation notes.** editAttribute() is shared by other callers outside this slice (event sync/pull, single-attribute edit) that rely on its per-call find for uuid-collision handling — do not remove that find outright; add an optional $existingAttribute parameter (or a pre-populated instance-level cache keyed by uuid) that editAttribute() consults first and only falls back to find() when the uuid isn't in the cache. editAttributeBulk() already does saveAll(validate-only) then saveMany() — the fix only targets the redundant find, not the save path.

**Risk.** Low-medium: must preserve editAttribute()'s existing per-row semantics (uuid-collision detection across events/objects, timestamp-based skip, recoverFields merge) exactly, and must not break other call sites of editAttribute() that pass a single attribute without a pre-warmed cache. Needs a regression test on the four workflow action modules plus normal sync/edit attribute flows.

**Verifier correction.** Code confirmed: __saveAttributes calls MispAttribute::editAttribute() per attribute (line 46) and editAttribute() issues an unhoisted find('first') by Attribute.uuid (MispAttribute.php:3241-3244); nothing is saved inside the loop (the actual write is editAttributeBulk() after it), so a uuid=>row prefetch map is semantically safe and the proposed fallback-find keeps sync/single-edit callers intact. NOT refuted, but corrected downward on two points. (1) Hot path unconfirmed: the whole workflow subsystem is gated behind Plugin.Workflow_enable, whose default is false and which is labelled '[experimental]' in Server.php:8660-8666, so the 'runs on every trigger firing' claim only holds on instances that have explicitly enabled workflows and attached one of these modules. Also three modules call __saveAttributes (comment, distribution, ids_flag), not four — Module_attribute_edition_operation::exec is a stub that never calls it. (2) Estimate inflated: the per-row find is a cheap indexed single-row lookup, while the remaining work per attribute is far heavier — saveMany() fires per-row beforeValidate/beforeSave/afterSave (correlation regeneration) and editAttributePostProcessing() does per-attribute analyst-data capture, tag capture and AttributeTag queries. Removing N cheap finds is nowhere near half the wall clock; ~12% is realistic, not 30%.

---

### 13. EventsController::addTag - `Controller/EventsController.php`

| | |
|---|---|
| Location | `app/Controller/EventsController.php:5870` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **8%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | yes |
| Realistic iterations | size of the tag collection being applied, typically 5-50 tags per call |

**What is slow.** The per-tag loop in addTag() (applying a tag or an entire tag collection to an event) runs an EventTag::hasAny() existence check and a separate EventTag::find('column', ...) fetch of all tags currently on the event, for every tag_id in tag_id_list, before finally create()+save()-ing the new EventTag row.

**Why it is on a hot path.** Reached whenever a tag collection is applied to an event (tag_id_list built from TagCollectionTag at lines 5756-5776) as well as for bulk/API tagging; tag collections in practice hold anywhere from a handful up to several dozen tags, each iteration paying 2 extra queries purely to check state that changes only slightly between iterations.

**Basis for the estimate.** hasAny() (an existence query) is fully redundant with the very next find('column') fetch of tags-on-event -- both could come from a single pre-loop fetch of already-attached tag_ids checked via isset(); that alone removes 1 of the 2-3 queries per iteration, roughly a third of this loop's DB cost

**Proposed fix.** Before the loop, fetch the set of tag_ids already on the event once into a hash (id => true) and replace the per-iteration hasAny() with isset(). The taxonomy-exclusivity check (checkIfNewTagIsAllowedByTaxonomy) genuinely needs an up-to-date tag-name list as tags are added within the loop, so that find('column') must stay per-iteration unless refactored to update an in-memory list instead of re-querying.

**Implementation notes.** Keep the taxonomy exclusivity semantics identical -- it must see tags added earlier in the same loop iteration, so replace its data source with an in-memory array appended to after each successful save rather than dropping the per-iteration refresh outright.

**Risk.** Low if only the hasAny() duplicate-existence check is hoisted; higher if the taxonomy find('column') is also touched without care, since that check must reflect tags added mid-loop.

**Verifier correction.** Loop and both queries confirmed (hasAny at 5880, EventTag find('column') at 5892; cited line 5870 is a few lines off - not a refutation). Two corrections to the reasoning: (a) the two queries are NOT 'fully redundant' - hasAny tests existence irrespective of local, while the find('column') filters EventTag.local = $effectiveLocal and returns Tag.name, so one cannot be derived from the other as written; a pre-loop hash of attached tag_ids does still eliminate the hasAny. (b) The 20% figure is inflated: per iteration the loop also does the EventTag INSERT plus $log->createLogEntry (another write, with AuditLog behavior), and the common case is tag_id_list of size 1 (single UI/API tag add) - collections of 5-50 are the exception. Removing one cheap indexed LIMIT-1 SELECT out of ~4 statements is worth roughly 8%, not 20%.

---

### 14. MispAttribute::editAttributePostProcessing - `Model/MispAttribute.php`

| | |
|---|---|
| Location | `app/Model/MispAttribute.php:3417` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **5%** of the enclosing operation |
| Original estimate | 15% (revised by the verifier) |
| Confidence | medium |
| Hot path confirmed | yes |
| Realistic iterations | One find('first') per (attribute, tag) attach pair; an event edit touching 500 attributes with 2 tags each yields ~1000 individual SELECT queries in this loop alone. |

**What is slow.** After collecting all tag-attach actions across every edited attribute, the code loops over each pending attach entry and does a separate AttributeTag->find('first') to check for an existing association before the eventual single saveMany().

**Why it is on a hot path.** editAttributePostProcessing is called from editAttributeBulk (app/Model/MispAttribute.php:3346), which is called from MispObject::editObject (app/Model/MispObject.php:1356) and Event::_edit (app/Model/Event.php:5797) - the core path for syncing/pulling an updated event with many attributes, each potentially carrying multiple tags.

**Basis for the estimate.** Conservative estimate: each find('first') is a small indexed lookup (tens of ms including PHP/Cake overhead), so 1000 sequential queries add up to multiple seconds of the overall edit; replacing with one batched find keyed by (tag_id, attribute_id) pairs and an in-memory index removes essentially all of that round-trip overhead, but this loop is only a fraction of editAttributePostProcessing's total work (which also does per-attribute captureTag/handleAttributeTag calls), so I estimate roughly 15% of the enclosing bulk-edit call.

**Proposed fix.** Before the loop, issue one AttributeTag->find('all') with conditions ['tag_id' => array_column($tagActions['attach'],'tag_id'), 'attribute_id' => array_column($tagActions['attach'],'attribute_id')], build a ['attribute_id-tag_id' => AttributeTag] lookup map, then iterate the in-memory map instead of querying per pair.

**Implementation notes.** Keep the same dedup semantics: only unset an attach entry when local and relationship_type both match the existing row, exactly as today. The batched find must not use 'recursive=-1' composite AND-per-pair filtering (a naive IN/IN combination could over-match cross pairs) - build the lookup keyed by 'attribute_id-tag_id' string to avoid this, mirroring the existing $k naming convention ($attributeId . '-' . $tag_id) already used a few lines above.

**Risk.** Low - purely a read-then-index refactor with no behavior change, as long as the composite key matching is implemented correctly to avoid false-positive matches between unrelated attribute/tag pairs.

**Verifier correction.** Real and correctly described: MispAttribute.php:3413-3430 issues one AttributeTag->find('first') per pending (attribute_id, tag_id) attach pair before the single saveMany, and the enclosing editAttributeBulk is genuinely on the sync/edit path (Event.php:5797 unconditionally for every pushed/pulled event's attributes; MispObject.php:1356 per object). NOT refuted, but the estimate is inflated: these are recursive=-1 lookups on the indexed (attribute_id, tag_id) pair, well under 1ms each, not 'tens of ms'; ~1000 of them cost a few hundred ms against an edit that also performs 500 attribute saveAll/saveMany passes with validation, correlation regeneration, per-attribute captureAnalystData/captureSightings and (note) a captureTag call per attribute because $tag_id_store is reset inside the per-attribute loop. Corrected to ~5% of the enclosing bulk-edit call. The proposed keyed-map refactor is behaviour-preserving.

---

### 15. afterFind / getRelatedElement - `Model/Relationship.php`

| | |
|---|---|
| Location | `app/Model/Relationship.php:84` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **5%** of the enclosing operation |
| Original estimate | 15% (revised by the verifier) |
| Confidence | medium |
| Hot path confirmed | yes |
| Realistic iterations | Once per Relationship row surfaced while rendering one event view. Events with heavy 'analyst data' usage (a feature meant to link attributes/objects/events together) can carry relationships on a meaningful fraction of their attributes; an event with a few hundred attributes and even 10-20% carrying one relationship yields several dozen extra full-fetch queries on a single page load, scaling linearly with both attribute count and relationship count. |

**What is slow.** Relationship::afterFind() runs one extra full model fetch (fetchSimpleEvent/fetchAttributeSimple/fetchObjectSimple/fetchNote/fetchOpinion/fetchRelationship) per Relationship row returned, just to resolve that row's related_object for display.

**Why it is on a hot path.** EventsController::view (app/Controller/EventsController.php:1311-1312) sets `$this->Event->Attribute->includeAnalystData = true` before fetching an event's attributes. AnalystDataParentBehavior::attachAnalystData (app/Model/Behavior/AnalystDataParentBehavior.php:15,37) is then invoked once per attribute and calls Relationship::getInboundRelationships, whose find() result passes through this afterFind. Every Relationship record attached to any attribute of the viewed event therefore triggers one additional full-object query on top of the one already spent fetching the relationship rows themselves.

**Basis for the estimate.** Conservative: event view already issues dozens of queries (attributes, objects, tags, correlations, sightings); each Relationship-driven extra full object/event/attribute fetch is comparable in cost to those, so on events with non-trivial relationship usage this proportionally adds up to double-digit percent of the view's query time. Not measured directly, hence conservative.

**Proposed fix.** Move related-object resolution out of the per-row afterFind and into a batch step: after the initial Relationship find() completes, group all rows by related_object_type, collect their uuids, and issue one bulk fetch per type (e.g. Event/Attribute/Object model queried with `uuid IN (...)`) instead of one per row, then map results back onto the rows by uuid.

**Implementation notes.** getRelatedElement() (lines 92-162) dispatches by type to Event::fetchSimpleEvent, MispAttribute::fetchAttributeSimple, MispObject::fetchObjectSimple, Note::fetchNote, Opinion::fetchOpinion, Relationship::fetchRelationship, each called with a single uuid. A batch version needs each of those to accept an array of uuids and return a uuid-keyed map; ACL filtering currently applied per-call (via $user) must still apply per-row in the bulk variant. Caller: AnalystDataParentBehavior::attachAnalystData/attachAnalystDataBulk in app/Model/Behavior/AnalystDataParentBehavior.php, which is the natural place to batch resolution across the many objects (attributes) it iterates, not just within a single Relationship::find() call.

**Risk.** Medium: touches ACL-sensitive fetch* methods on multiple models (Event, MispAttribute, MispObject) shared by other callers, so the batch variant must not weaken per-row authorization; also afterFind is a generic CakePHP hook so any direct `Relationship->find()` call elsewhere must keep working unbatched or be migrated deliberately.

**Verifier correction.** Real n+1, but the estimate and the claimed mechanism are both wrong. Code confirmed: afterFind (line 84-88) calls getRelatedElement() per row with no cache, and getRelatedElement issues one full fetchSimpleEvent/fetchAttributeSimple/fetchObjectSimple per uuid. Mechanism correction: the event view does NOT go through the candidate's path (attachAnalystData -> getInboundRelationships); Event::fetchEvent uses attachAnalystDataBulk (app/Model/Event.php:3414/3468/3477), which never calls getInboundRelationships. The n+1 survives anyway because AnalystDataBehavior::fetchForUuids (app/Model/Behavior/AnalystDataBehavior.php:71) does a plain $Model->find('all'), so Relationship::afterFind still fires once per batch and resolves each returned row individually. Estimate corrected 15 -> 5: the loop is a no-op on events with no Relationship analyst data, which is the overwhelmingly common case; the cost only appears on relationship-heavy events. Extra note for anyone implementing the batch fix: the Note/Opinion/Relationship branches of getRelatedElement call fetchNote()/fetchOpinion()/fetchRelationship(), which exist nowhere in app/Model (grep -rn 'function fetchNote|fetchOpinion|fetchRelationship' returns nothing) - those branches would fatal today, so only the Event/Attribute/Object branches are actually exercisable and batchable.

---

### 16. JSONConverterTool::convert - `Lib/Tools/JSONConverterTool.php`

| | |
|---|---|
| Location | `app/Lib/Tools/JSONConverterTool.php:111` |
| Pattern | `quadratic_nested_loop` |
| Estimated gain | **5%** of the enclosing operation |
| Original estimate | 35% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | For an event with 500 objects and 5,000 top-level attributes, the inner double loop alone runs 2,500,000 times per convert() call; convert() itself can run per event in a batch export/PubSub-publish loop. |

**What is slow.** When enriching Objects with RelatedAttribute correlation data, convert() loops over EVERY object and, for each one, re-iterates the entire top-level $event['Event']['Attribute'] list (not just that object's own attributes) to look up correlations by attribute id — O(objects × event-level attributes) instead of O(attributes).

**Why it is on a hot path.** convert() is called once per event by Model/Event.php:7645 (export module enrichment, can iterate many fetched events), by Lib/Tools/PubSubTool.php:85 (fires on every event/attribute create/edit/publish when ZMQ/Kafka pubsub is enabled — a very common deployment), by Model/TaxiiServer.php:130 (TAXII collection responses), and by Lib/Tools/WorkflowFormatConverterTool.php:48 (every workflow execution using the standard format). Events with hundreds of objects and thousands of attributes (common for bulk-imported feeds, e.g. malware-sample dumps or bulk IOC objects) hit the full cross-product on every single conversion.

**Basis for the estimate.** The correlation lookup ($event['Event']['RelatedAttribute'][$attribute['id']]) does not depend on which object is being processed, so the O(objects*attributes) work can collapse to a single O(attributes) pass reused across objects (or restricted to each object's own Attribute array, whichever was the actual intent). For large events this loop dominates convert()'s wall time; 35% is a conservative estimate of the share of convert()'s cost this one nested loop represents on large/bulk events, based on it being the only unbounded nested loop in the function (everything else is a single pass).

**Proposed fix.** Hoist correlation lookup out of the per-object loop: pre-index $event['Event']['RelatedAttribute'] is already keyed by attribute id, so replace the inner `foreach ($event['Event']['Attribute'] as $k2 => $attribute)` with a single pass that either (a) if the intent is to annotate each object's OWN attributes, iterate $event['Event']['Object'][$k]['Attribute'] instead of the top-level Attribute list, or (b) if the intent is to build one shared enrichment structure, compute it once before the object loop and reuse it for every object instead of recomputing it $count(objects) times.

**Implementation notes.** Flag for the code owner: as currently written the inner loop assigns into $event['Event']['Object'][$k]['Attribute'][$k2]['RelatedAttribute'] using $k2 taken from the TOP-LEVEL attribute list, not from that object's own Attribute array — this looks like it may also be a correctness bug (writing correlation info onto the wrong Attribute index within the object), not just a performance one. Whoever fixes performance here should verify against a fixture event with objects containing attributes and confirm the RelatedAttribute output is unchanged (or, if it was already wrong, decide the correct semantics) before optimizing the loop shape.

**Risk.** Medium — because the exact intended semantics of the inner loop are unclear (see implementation_notes), a naive 'just hoist it' fix without confirming intended behavior first could silently change (or silently perpetuate a wrong) output for exported/pushed events; needs a behavior-preserving refactor plus a fixture-based regression test before merging.

**Verifier correction.** Code confirmed as described (objects x top-level attributes nested loop at 111-112), but every claimed hot caller is wrong. The loop is guarded by !empty($event['Event']['RelatedAttribute']), and RelatedAttribute is only populated by Event::fetchEvent when options['includeGranularCorrelations'] is set (Event.php:3361). The ZMQ/Kafka publish path builds its event via __prepareEventForPubSub (Event.php:9884-9890) with params = ['eventid'] (+includeAttachments) only, so RelatedAttribute is always empty and the loop never executes; same for TaxiiServer::__pushEvents (TAXII filters), WorkflowFormatConverterTool, and Event.php:7645 export modules unless a caller explicitly passes the flag. Grep shows the flag is set only by EventsController::view (line 1323, one event per page load), restsearch/periodic-notification filters, and CorrelationGraphTool - i.e. per-single-event UI/API views, not bulk publish or export loops. So hot_path_confirmed=false and 35% of convert() is far too high; on the one path where it does fire, the loop body is a cheap isset() over an array already keyed by attribute id, and it competes with correlation fetching, warninglist hits and tag attachment that dominate that request. Corrected to ~5%. Additionally: the primary defect here is correctness, not speed - fetchEvent moves object attributes out of $event['Attribute'] into the Objects container (Event.php:3451-3452), so the top-level index $k2 has no relation to an object's own Attribute indices and the loop writes RelatedAttribute onto wrong/phantom keys inside every object. Any change must be treated as a bug fix with a fixture test, as the candidate's implementation_notes already state.

---

### 17. MispAttribute::editAttributePostProcessing - `Model/MispAttribute.php`

| | |
|---|---|
| Location | `app/Model/MispAttribute.php:3369` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **4%** of the enclosing operation |
| Original estimate | 10% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | yes |
| Realistic iterations | One extra find('all') per attribute when the condition is met; a sync-pushed event with 5000 attributes yields 5000 additional SELECTs purely for this pruning check. |

**What is slow.** Inside the per-attribute loop, when the server is configured with remove_missing_tags or the syncing user is an authoritative sync user, an AttributeTag->find('all') is issued for every single attribute to fetch its existing global tags for pruning.

**Why it is on a hot path.** Same call chain as the candidate above (editAttributeBulk -> called from Event::_edit / MispObject::editObject during sync pull/push), but gated behind server['Server']['remove_missing_tags'] or perm_sync_authoritative - both common configurations for authoritative sync feeds/instances that push large events with thousands of attributes.

**Basis for the estimate.** Same reasoning as the sibling attach-dedup candidate: batching this into a single find keyed by attribute_id in {$eventId's attribute ids} before the loop, then indexing in memory, removes thousands of round trips for large authoritative-sync events, but this is only exercised under specific sync configuration so I scope the estimate conservatively to that subset of edit-attribute calls.

**Proposed fix.** Hoist a single AttributeTag->find('all', ['conditions' => ['attribute_id' => array_values($this->updateLookupTable), 'local' => 0], 'contain' => ['Tag' => ...]]) before the loop, group results by attribute_id into a map, and look up $existingGlobalTagsByAttributeId[$attributeId] inside the loop instead of querying per attribute.

**Implementation notes.** $this->updateLookupTable already holds all attribute_id values processed in this batch (uuid -> id), so the IN-list is readily available without an extra pass over $attributes.

**Risk.** Low-medium: only exercised in the sync/authoritative path, so this is harder to exercise/test locally and any bug here would surface as incorrect tag pruning behavior only on production sync flows, so it needs solid sync-scenario test coverage before merging.

**Verifier correction.** The N+1 is real (MispAttribute.php:3369-3379, one AttributeTag->find('all') with Tag contain per attribute) and the call chain is confirmed, but it is gated behind server remove_missing_tags or perm_sync_authoritative, so it only fires on a subset of sync edits. Two corrections. (1) The implementation note is wrong and would make the fix worse: $this->updateLookupTable (declared MispAttribute.php:98, written at :644) is NEVER reset - it accumulates every attribute uuid->id processed for the whole request, so on the editObject->editAttributeBulk-per-object path a hoisted find keyed on the whole table re-fetches all previously processed attributes on every object, turning a linear cost into a quadratic one. The batch find must be keyed off the uuids of the current $attributes argument only. (2) Estimate inflated relative to the rest of the pass (per-attribute save, correlation, captureTag, prune deletes still remain); corrected to ~4%.

---

### 18. StixExport::__handle_event_galaxies - `Lib/Export/StixExport.php`

| | |
|---|---|
| Location | `app/Lib/Export/StixExport.php:303` |
| Pattern | `in_array_on_large_array` |
| Estimated gain | **3%** of the enclosing operation |
| Confidence | high |
| Hot path confirmed | yes |
| Realistic iterations | one in_array scan per (attribute, galaxy, cluster) triple during export; for a large STIX export (many events, each attribute galaxy-tagged) this can be thousands of clusters, each scanned against a uuid list of comparable size |

**What is slow.** Deduplication of galaxy cluster uuids uses in_array() against a flat, ever-growing $this->__cluster_uuids array (accumulated across the whole export) instead of an isset() hash lookup, making dedup cost grow with total clusters seen so far in the export rather than being O(1) per cluster.

**Why it is on a hot path.** StixExport is the base class for Stix1Export and Stix2Export (app/Lib/Export/Stix1Export.php, Stix2Export.php), wired in as the 'stix'/'stix-json'/'stix2' export formats in Event.php:124-126 and reachable via restSearch(returnFormat=stix|stix2) (e.g. EventsController.php:6289). __handle_event_galaxies is called from __addMetadataToAttribute (line 192, 199) once per attribute-galaxy-cluster combination as the export streams through every attribute of every exported event; for a large multi-event STIX export with many galaxy-tagged attributes, __cluster_uuids keeps growing and each new cluster does a full linear scan against it.

**Basis for the estimate.** The pattern is a genuine O(n^2) instead of O(n) in isolation, but __parse_misp_data() shells out to the external misp-stix Python converter which dominates large-export wall clock (seconds to minutes); the in_array cost here is at most low hundreds of milliseconds of PHP-side string comparisons even in a large export, so the effect on the enclosing export operation's wall clock is small. Reported because the fix is trivial and risk-free, not because it's a major bottleneck.

**Proposed fix.** Key $this->__cluster_uuids by uuid (e.g. $this->__cluster_uuids[$cluster['uuid']] = true) and replace in_array($cluster['uuid'], $this->__cluster_uuids) with isset($this->__cluster_uuids[$cluster['uuid']]).

**Implementation notes.** Only the storage/lookup shape of __cluster_uuids changes; every place that appends to it (lines 308, 314) must switch from $this->__cluster_uuids[] = $uuid to $this->__cluster_uuids[$uuid] = true, and any other reader of __cluster_uuids (check __write_event_galaxies / class properties) must be checked for assumptions about it being a plain indexed list.

**Risk.** Very low: purely a dedup-check data structure change with no behavioral difference in what gets deduplicated, as long as every append site is updated consistently.

---

### 19. Allowedlist::removeAllowedlistedFromArray - `Model/Allowedlist.php`

| | |
|---|---|
| Location | `app/Model/Allowedlist.php:91` |
| Pattern | `quadratic_nested_loop` |
| Estimated gain | **2%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | yes |
| Realistic iterations | For a fetch of N attributes against M allowedlist regex entries, worst case is N*M preg_match calls; without the early break, an attribute that matches the very first allowedlist pattern still pays for all M-1 remaining preg_match calls. Typical MISP allowedlists have single-digit to low-tens of entries, but attribute counts of 5,000-50,000+ per export are routine. |

**What is slow.** The inner loop over allowedlist regex patterns keeps testing and re-matching every remaining pattern against an attribute even after that attribute has already matched and been unset, instead of breaking out once a match is found.

**Why it is on a hot path.** Called from MispAttribute::fetchAttributes (app/Model/MispAttribute.php:3847) and MispObject's attribute-fetch path (app/Model/MispObject.php:1829) whenever attributes are returned to a REST/search caller -- i.e. on ordinary attribute/event fetch and export operations, which can return thousands of attributes at once, each checked against every configured allowedlist entry.

**Basis for the estimate.** Only matters for attributes that actually match an allowedlist pattern (the common case is 0 matches, where the fix changes nothing). For instances with a non-trivial allowedlist and matching traffic, the fix removes on average roughly half of the wasted post-match preg_match calls for matched attributes; since matched attributes are typically a small fraction of the total, this is a modest, targeted win (conservatively ~20% of this function's wall time on workloads with meaningful allowedlist hit rates), not a change to the dominant no-match path.

**Proposed fix.** Add `break;` immediately after `unset($data[$k]);` in the inner foreach ($allowedlists as $wlitem) loop (and the mirrored loop for the event-array branch and removeAllowedlistedValuesFromArray) so no further patterns are tested once an attribute/value is already excluded.

**Implementation notes.** Same missing-break issue exists in the event-array branch (~line 110) and in removeAllowedlistedValuesFromArray (~line 131). The fix is purely eliminating redundant work after a decision is already made -- output (`$data` with matched entries unset) is unchanged.

**Risk.** Very low -- the unset() already fully removes the item from $data; testing further patterns against an already-removed item has no effect on the result, so adding break is behavior-preserving.

**Verifier correction.** The missing break is real (inner loop at line ~95 keeps preg_match-ing after unset($data[$k]); same in the event branch ~line 110 and in removeAllowedlistedValuesFromArray ~line 131), the fix is behaviour-preserving, and the callers (MispAttribute::fetchAttributes:3847, MispObject:1829, Event:9325) are genuinely hot. But the value is near zero, not 20%: the whole body is guarded by `if (!empty($allowedlists))`, which is empty on the overwhelming majority of instances, and getBlockedValues() caches the list in $this->allowedlistedItems so the DB cost is one-time. The saved work is only the trailing preg_match calls for attributes that ALREADY matched -- typically a tiny fraction of rows -- against typically single-digit patterns. This is a tidiness fix worth maybe ~2% of this function's time on a worst-case workload, not a meaningful optimisation.

---

## No verdict returned

The verifier did not return a verdict for these. Treat as unreviewed.

### 20. graphJsonContains / graphJsonContainsLink (called from __createNode, __addLink, __handleAttributes, __handleObjects) - `Lib/Tools/CorrelationGraphTool.php`

| | |
|---|---|
| Location | `app/Lib/Tools/CorrelationGraphTool.php:392` |
| Pattern | `quadratic_nested_loop` |
| Estimated gain | **60%** of the enclosing operation |
| Confidence | high |
| Hot path confirmed | unknown |
| Realistic iterations | Realistic events run 500-10,000+ attributes/objects; each triggers a scan over the nodes array built so far, giving ~N^2/2 comparisons -- e.g. ~12.5M comparisons for a 5,000-attribute event, ~50M for 10,000. |

**What is slow.** Every node/link added to the correlation graph is deduplicated by a full linear scan of the nodes-so-far (and links-so-far) array, so building a graph with N attributes/objects does O(N^2) comparisons instead of an O(1) hash lookup.

**Why it is on a hot path.** CorrelationGraphTool::__expandEvent() (line 35-79) is the sole path for EventsController's correlation-graph view (app/Controller/EventsController.php lines 6867-6888, `viewGraph`/`getEventGraphData`-style action). It calls __handleAttributes()/__handleObjects() once per attribute/object of the event; each of those calls __createNode() -> graphJsonContains() (line 392-415, foreach over $this->data['nodes']) and __addLink() -> graphJsonContainsLink() (line 416-427, foreach over $this->data['links']). Events routinely carry thousands of attributes in MISP, so this is O(attributes^2) work on graph render.

**Basis for the estimate.** The DB fetch (fetchEvent with includeGranularCorrelations) is a fixed cost per call; the node/link dedup loop is the part that scales quadratically with attribute count and dominates wall time once event size exceeds a few hundred attributes. Replacing the O(N) scans with an O(1) keyed lookup (unique_id => node index, and a source|target link-index set) removes essentially all of that quadratic cost, leaving only the linear DB-fetch and node-construction work -- conservatively a 60% cut in buildGraphJson()'s wall clock for large/typical events (would be far higher, but a portion of time is the initial fetchEvent DB call which is unaffected).

**Proposed fix.** Maintain hash indexes alongside $this->data['nodes']/['links']: a map from a stable node key (e.g. "{type}-{id}" or attribute value for attributes) to node index, and a map from "min(id1,id2)-max(id1,id2)" to link index. graphJsonContains()/graphJsonContainsLink() become isset() lookups instead of foreach scans; update the indexes wherever a node/link is pushed (__createNode, __addLink) and wherever nodes/links are rebuilt (cleanLinks()).

**Implementation notes.** cleanLinks() (line 363-390) also does an O(links * nodes) nested scan to re-resolve link source/target after node array reindexing -- same fix applies: build a node-object-to-index map once before the links loop instead of scanning nodes per link. Must preserve current dedup semantics exactly (matching by type+id for event/tag/galaxy/object, by name/value for attribute) since callers rely on node ids returned from __createNode for subsequent __addLink calls.

**Risk.** Low if the key functions used for the hash exactly mirror the existing equality checks in graphJsonContains (type+id, or type+value for attributes); a mismatched key could create duplicate nodes rather than reusing an existing one, which would be a visible but easily-tested regression (extra nodes in the graph).

**Verifier correction.** NO VERDICT RETURNED

---

## Refuted candidates

Kept deliberately. Each looked plausible and is not worth re-raising - the refutation says
why. The most common reasons were: no caller that runs the loop at scale, and a per-row save
whose model callbacks or uniqueness validation are load-bearing.

### 1. BlocklistComponent::add - `Controller/Component/BlockListComponent.php`

| | |
|---|---|
| Location | `app/Controller/Component/BlockListComponent.php:63` |
| Pattern | `save_in_loop` |
| Estimated gain | **10%** of the enclosing operation |
| Original estimate | 35% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | One create()+save() (one INSERT, plus any model-level beforeValidate/beforeSave query) per uuid in the request; realistic bulk-import requests are in the hundreds-to-low-thousands range based on the unbounded input format. |

**What is slow.** REST bulk-add endpoint loops over a caller-supplied list of uuids and calls create()+save() once per uuid instead of validating duplicates once and inserting in bulk.

**Why it is on a hot path.** Used by EventBlocklistsController::add, OrgBlocklistsController::add and GalaxyClusterBlocklistsController::add (all declare 'BlockList' as a component and call $this->BlockList->add($this->_isRest())). The REST payload accepts 'uuids' as an array or newline-separated list with no documented size cap, so an admin importing a curated blocklist feed (event/org/galaxy-cluster blocklist import) can pass hundreds to thousands of uuids in one request.

**Basis for the estimate.** Each save() is a full CakePHP save cycle (validation query/queries + INSERT, each its own round trip); a bulk insert via insertMulti/saveMany reduces this to O(1) queries (a dedupe pre-check plus one multi-row INSERT) versus O(n) round trips. Conservative estimate because the per-row loop also does array construction (cheap) and the request's own JSON decode/HTTP overhead is fixed cost outside the loop, so I scope the 35% to the wall-clock of the add() action itself, not just the loop.

**Proposed fix.** Precompute the set of existing blocklistTarget uuids with one find('list'/'column') call, filter/dedupe the incoming uuids in memory against it and against each other, build the full row array for each new entry, and insert with $Model->insertMulti() (or saveMany with 'validate'=>false) in one call instead of per-row create()/save().

**Implementation notes.** blocklistFields and blocklistTarget are model-defined column lists (e.g. EventBlocklist, OrgBlocklist, GalaxyClusterBlocklist) — the bulk insert must build rows in that same column order/shape used at lines 67-74. The $successes/$fails response arrays are consumed by RestResponse->viewData() and by the non-REST Flash message, so the batched path must still classify which input uuids ended up counted as successes vs fails (e.g. malformed length-!=36 uuids stay in $fails as today; duplicates against existing rows should also count as fails, matching current save()-returns-false-on-duplicate behavior).

**Risk.** Per-row save() currently runs the model's validation/behaviors (e.g. AuditLog behavior logging each add, any beforeValidate duplicate checks) — insertMulti/saveMany('validate'=>false) skips those callbacks, so audit-log entries and any model-specific validation must be reproduced explicitly (e.g. one upfront duplicate-uuid query, and either a single bulk AuditLog entry or keeping per-row calls to the audit hook) or the change will silently drop blocklist audit trail.

**Refuted.** Loop exists as described (line 63, create()+save() per uuid), but no caller at scale is confirmable from code: EventBlocklists/OrgBlocklists/GalaxyClusterBlocklists add is an admin-only POST action; 'hundreds to thousands of uuids' is usage speculation, not evidence. The per-row save is also load-bearing: the models declare validate['*_uuid']['unique' => isUnique] (e.g. GalaxyClusterBlocklist.php:23-32) and that per-row failure is exactly what populates $fails, and both AuditLog and SysLogLogable behaviors (EventBlocklist.php:10-14, GalaxyClusterBlocklist.php:10-17) fire per row. Reproducing duplicate classification plus per-row audit entries erases most of the batching win. Corrected to ~10% of an action that is not hot.

---

### 2. getEligibleClusterIdsFromServerForPull - `Model/Server.php`

| | |
|---|---|
| Location | `app/Model/Server.php:861` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **5%** of the enclosing operation |
| Original estimate | 45% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | One hasAny() query per remote cluster. The official misp-galaxy corpus (ATT&CK, threat-actor, tool, malware galaxies combined) totals several thousand clusters (commonly cited as 3,000-8,000+ depending on version), so a full galaxy pull runs thousands of individual blocklist-check queries in this single loop. |

**What is slow.** Inside the foreach over all remote galaxy clusters returned by the sync partner, GalaxyClusterBlocklist::checkIfBlocked() runs one hasAny() (SELECT ... LIMIT 1) query per cluster instead of a single batched lookup.

**Why it is on a hot path.** Called from GalaxyCluster::getClusterIdListBasedOnPullTechnique() (app/Model/GalaxyCluster.php:1973-2004). The default 'pull everything' branch (technique not 'update'/'pull_relevant_clusters'/numeric) calls getEligibleClusterIdsFromServerForPull($serverSync, false) with no conditions, i.e. the plain 'Update Galaxies' pull action fetches and checks every cluster the remote server exposes.

**Basis for the estimate.** Two scopes: (a) the eligibility-filtering phase itself (this loop plus the preceding find('list')) — replacing N hasAny() calls with one query (SELECT cluster_uuid FROM ... WHERE cluster_uuid IN (...)) collapses N round-trips to 1, a ~60-70% cut of that phase, since each hasAny() is a full DB round-trip (~0.2-2ms locally, more over a networked DB) while the batched version is one query regardless of N. (b) Scoped to the whole pull (this function plus the subsequent per-cluster download in GalaxyCluster::__pullGalaxyCluster which also does per-cluster network+DB work), the eligibility phase is a meaningful but non-dominant fraction, so I give a conservative ~45% for the enclosing getEligibleClusterIdsFromServerForPull() call itself (the function whose loop this is), not the full multi-stage pull operation.

**Proposed fix.** Before the loop, fetch all blocked uuids in one query: $blocked = array_flip($GalaxyClusterBlocklist->find('column', ['fields'=>['cluster_uuid'], 'conditions'=>['cluster_uuid' => array_column(array_column($clusterArray,'GalaxyCluster'),'uuid')]])); then replace $GalaxyClusterBlocklist->checkIfBlocked($clusterUuid) with isset($blocked[$clusterUuid]).

**Implementation notes.** checkIfBlocked() just wraps hasAny(['cluster_uuid' => $uuid]); a WHERE cluster_uuid IN (...) covers all uuids from $clusterArray in one round trip. $clusterArray items are keyed by ['GalaxyCluster']['uuid'] as seen at line 852/859. Keep the existing $eligibleClusters version-comparison logic unchanged; only the blocklist check needs hoisting.

**Risk.** Low — checkIfBlocked() is a pure read (hasAny), so batching it changes no write semantics or callback behavior; only the SQL access pattern changes. Must ensure the IN-list uuids come from the same $clusterArray already fetched (no extra remote round trip needed).

**Refuted.** Code at line 861 matches (checkIfBlocked -> hasAny per cluster), but the scale premise is provably false. fetchCustomClusterIdsFromServer (Server.php:769-782) hardcodes the filter ['published'=>1,'minimal'=>1,'custom'=>1], and GalaxyCluster.php:1435-1436 maps custom=1 to 'GalaxyCluster.default' => false. So $clusterArray never contains the default misp-galaxy corpus (ATT&CK/threat-actor/tool/malware clusters are default=1) that the candidate's '3,000-8,000+ clusters' estimate is entirely based on. N is the number of remote *custom, published* clusters, typically tens to low hundreds, and the single remote HTTP galaxyClusterSearch round trip in the same function dominates at that N. Real micro-optimisation, but not the claimed win; corrected to ~5%.

---

### 3. Module_tag_country_asn_from_enrichment::exec - `Model/WorkflowModules/action/Module_tag_country_asn_from_enrichment.php`

| | |
|---|---|
| Location | `app/Model/WorkflowModules/action/Module_tag_country_asn_from_enrichment.php:136` |
| Pattern | `redundant_recomputation` |
| Estimated gain | **5%** of the enclosing operation |
| Original estimate | 25% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | _buildFastLookupForRoamingData does O(total attributes in event) work (WorkflowBaseModule.php:480-496); called 2x per matching attribute inside a loop over 'matching' attributes, so for an event with A total attributes and M matching ones, total rebuild cost is O(2*M*A) instead of O(A) if hoisted/incrementalized. |

**What is slow.** Inside the foreach over matching attributes, _buildFastLookupForRoamingData() is called twice per successful match, and that method rebuilds the fast-lookup hash maps by rescanning the entire event's Attribute, Object.Attribute, and _AttributeFlattened arrays from scratch, rather than incrementally updating just the one changed attribute.

**Why it is on a hot path.** This is a Workflow action node run per-event (or per-attribute trigger) whenever a user builds a workflow using this module after an enrichment step; matchingAttributes comes from Hash::extract(..., 'Event._AttributeFlattened.{n}') so it scans every attribute of the event. Sibling module Module_attach_enrichment.php (same directory) calls the identical _buildFastLookupForRoamingData() exactly once, before its loop (line 78) — confirming the per-iteration rebuild here is avoidable, not required by the API.

**Basis for the estimate.** The rebuild cost scales with event size and match count, but each matched attribute's iteration also does real DB work (attachTagsToAttributeAndTouch performs tag lookups/inserts), which likely dominates wall time per iteration for small-to-medium events. The quadratic array rebuild becomes the dominant cost only as A and M both grow (e.g. large enriched events with hundreds+ attributes), so I give a moderate 25% estimate for the exec() call's wall time on realistically-sized enriched events, lower confidence than the DB-query candidates above since I did not measure actual event sizes in this deployment.

**Proposed fix.** Call _buildFastLookupForRoamingData() once before the foreach (matching Module_attach_enrichment's pattern), and after attachTagsToAttributeAndTouch()/_addTag() mutate roamingData, update only the specific attribute's entry in the existing lookup arrays (or the small delta) instead of calling the full rebuild again.

**Implementation notes.** _addTag()/_handleTag() (WorkflowBaseModule.php ~546-576) read from $this->fastLookupArrayFlattened to locate the attribute being tagged; as long as tag additions don't add/remove/reorder attributes in _AttributeFlattened (they only append to an attribute's 'Tag' subarray), the existing fastLookupArrayFlattened/fastLookupArrayMispFormat mappings remain valid without a full rebuild — only genuinely needed if the roaming data's attribute set itself changes shape.

**Risk.** Low functional risk since this only removes redundant recomputation, not behavior — but must verify no downstream code relies on _buildFastLookupForRoamingData also being called after every tag add for some other side effect (e.g. rebuilding the unfiltered-lookup arrays when enabledFilters is set); check that path stays correct if the rebuild is hoisted out of the loop.

**Refuted.** The double rebuild at lines 136 and 140 is real, but it is not a worthwhile optimisation. Both calls sit inside 'if ($saveSuccess)', i.e. they only run after attachTagsToAttributeAndTouch() has already done tag capture, an attribute save and an event touch - multiple DB writes that dominate the iteration by orders of magnitude over _buildFastLookupForRoamingData's pure in-memory integer-keyed array writes (WorkflowBaseModule.php:480-496). The O(2*M*A) term only becomes material on events with thousands of matching attributes, where the per-attribute DB writes are already the wall clock, and this is an opt-in workflow node with no code-confirmable bulk caller (matchingAttributes comes from the single event in roamingData). The hoist is trivially safe if ever done - the sibling Module_attach_enrichment.php:78 proves the one-call-before-loop pattern - but the payoff is noise; corrected to ~5%.

---

### 4. CRUDComponent::delete - `Controller/Component/CRUDComponent.php`

| | |
|---|---|
| Location | `app/Controller/Component/CRUDComponent.php:481` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **5%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Once per selected id; bulk 'select all + delete' on a filtered list (e.g. hundreds of tags or correlation exclusions) is a realistic admin workflow, giving one extra find query per row on top of the delete itself. |

**What is slow.** The generic mass-delete action runs a separate $Model->find('first', ...) for every id in the selection before deleting it, instead of resolving all ids/uuids to rows in one query.

**Why it is on a hot path.** CRUDComponent::delete() is the shared 'delete selected' handler used by $this->CRUD->delete(...) in 36 controllers (grep confirms TagsController, CorrelationExclusionsController, AllowedlistsController, FeedsController, GalaxiesController, WorkflowsController, etc.). Any of those index pages supports selecting many rows (e.g. all filtered tags, all correlation exclusions) and submitting a bulk delete, which lands in this one foreach.

**Basis for the estimate.** Model->delete() cannot be safely collapsed to deleteAll() here because MISP models commonly implement beforeDelete/afterDelete side effects (confirmed in this same slice: OrgBlocklist::afterDelete triggers a Redis cleanup), so the delete() calls must stay per-row. Only the find('first') lookup is redundant and batchable, which is roughly one of several per-row operations (find, permission callback, delete, and whatever the model's own callbacks do) -- a conservative fraction of the mass-delete request's wall clock.

**Proposed fix.** Split $idList into numeric ids and UUIDs up front, issue one $Model->find('all', ['conditions'=>['id'=>$numericIds] OR ['uuid'=>$uuids], 'recursive'=>-1]) and index the results by id/uuid in memory, then iterate $idList doing only the lookup (array access), the checkModifyCallback, and $Model->delete($itemId) -- unchanged in the loop.

**Implementation notes.** Loop is app/Controller/Component/CRUDComponent.php:481-508. Must preserve exact current semantics: items not found still get pushed to $fails (line 489), checkModifyCallback still runs per resolved item (line 495-497) before delete, and the success/fail message formatting (lines 509-525) depends on the same $successes/$fails arrays keyed by the original $cid values (which may be uuids), not by resolved numeric id.

**Risk.** Low: this only removes redundant SELECTs, not the delete() calls or their callbacks, so per-model business logic (audit logging, cascading deletes, Redis/cache invalidation) is unaffected. Care needed only to keep the uuid-vs-id branch (Validation::uuid($cid)) working identically when resolving from the batched find result.

**Refuted.** Symbol misidentified and the hot-path argument collapses with it. Line 481 is inside CRUDComponent::deleteSelection() (declared line 450), not CRUDComponent::delete(): delete() is declared at line 298 with signature 'public function delete(int $id, array $params = [])' — a single numeric id, one find('first'), no loop at all. So the stated justification ('the shared delete-selected handler used by $this->CRUD->delete(...) in 36 controllers') is false; the ~36 CRUD->delete call sites are single-row deletes and never reach this loop. The real bulk entry point is CRUD->deleteSelection (~20 controllers), and even there the per-id find('first') is a recursive=-1 primary-key/uuid lookup that is dwarfed by the work the fix cannot touch: Cake's Model::delete($id) itself issues its own exists() SELECT plus beforeDelete/afterDelete callbacks and cascade deletes per row (the notes concede these must stay per-row). Selections come from a paginated index page, so the realistic N is a page of rows, not 'hundreds'. Removing one cheap PK SELECT out of several queries per row on a rare admin action is a low-single-digit win, not 20%.

---

### 5. AttributeTag::attachTagToAttribute - `Model/AttributeTag.php`

| | |
|---|---|
| Location | `app/Model/AttributeTag.php:172` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 25% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | once per (attribute, tag) pair on import; for a 5,000-attribute event at ~2 tags/attribute, ~10,000 find+save round trips |

**What is slow.** attachTagToAttribute() runs a find('first') existence check plus a create()+save() per call, and is invoked once per tag from handleAttributeTags()'s foreach ($attribute['Tag'] as $tag) loop (line 114), which is itself called once per attribute during MISP-JSON event/object import.

**Why it is on a hot path.** handleAttributeTags is called from MispAttribute::saveAttributes-style per-attribute capture code (app/Model/MispAttribute.php:2886) and from MispObject.php:1112/1146, i.e. once per attribute processed during an event import or object save. A typical tagged attribute carries 1-5 tags, but imported events routinely have thousands of attributes, so a 5k-attribute event with an average of 2 tags each drives roughly 10k find+save pairs through this single-row path.

**Basis for the estimate.** each iteration is 1 SELECT + 1 INSERT (or UPDATE) against attribute_tags; collapsing the existence check to an in-memory index (one bulk SELECT of existing associations for the event before the loop) and batching the inserts removes roughly half the DB round trips in this sub-path of import; conservative because tag capture is one of several per-attribute costs during import, not the whole cost

**Proposed fix.** Before the per-attribute tag loop, bulk-fetch existing AttributeTag rows for the event (or the whole batch of attributes being imported) once, index by (attribute_id,tag_id) in a PHP array, and use isset() instead of the per-call find('first'); accumulate new rows and insert with a single bulk save/insertMulti call instead of create()+save() per tag.

**Implementation notes.** attachTagToAttribute is also called standalone from UI single-tag-add flows where the per-call query is fine; the bulk-lookup optimization should be added as a batch-aware variant used specifically by the import path (handleAttributeTags when handling many attributes), not by removing the existing single-call API.

**Risk.** Must preserve the existing relationship_type update-in-place behavior (lines 194-197) for tags that are already attached but whose relationship_type changed; a naive isset()-only check would silently skip that update.

**Refuted.** The code is as described (find('first') + create()/save() per (attribute,tag)), but the hot-path claim is wrong. MispAttribute.php:2886 is inside saveAttributes(), whose only callers are MispObject.php:555, MispObject.php:1175 and ObjectsController.php:634 - single-object save paths, a handful of attributes each. MispObject.php:1112/1146 are inside deltaMerge(), called only from ObjectsController.php:466 (single-object UI/API edit), and only for attributes that actually changed. The mass MISP-JSON import/sync path the candidate invokes ('5,000-attribute event, ~10,000 find+save pairs') does NOT reach this code: Event.php:5797 -> MispAttribute::editAttributeBulk -> editAttributePostProcessing (MispAttribute.php:3349) already uses handleAttributeTag($...,$mock=true) to collect tag actions and flushes them with a single AttributeTag->saveMany() (MispAttribute.php:3430-3435), with tag ids memoised in $tag_id_store. So the scale path is already batched and the cited path never runs at scale. (The one remaining per-attach find('first') at MispAttribute.php:3417 is a different function and line, not this candidate.)

---

### 6. Event::savePreparedEvent - `Model/Event.php`

| | |
|---|---|
| Location | `app/Model/Event.php:5969` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 45% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | 1 save() per Attribute/ShadowAttribute/EventTag/Object, plus for every ObjectReference two extra find('first') queries (lines 5988 and 5993) and one save(); realistic events range from hundreds to several thousand attributes, so a few-thousand-attribute event means several-thousand individual INSERT statements plus 2x that many SELECTs for any objects present. |

**What is slow.** Rebuilds a whole event (Attributes, ShadowAttributes, EventTags, Objects, ObjectReferences) with one Model::save()/create() call per row instead of a bulk insert, plus a find('first') per ObjectReference to resolve the referenced element's id.

**Why it is on a hot path.** Called by EventDelegation::transferEvent() (app/Model/EventDelegation.php:46) whenever an event-delegation request is accepted: the target event is fetched in full via fetchEvent(includeAttachments=1), the original event row is deleted, and the entire structure is re-inserted through savePreparedEvent. An event that has been delegated for review is exactly the kind of event that tends to be large (dozens to thousands of attributes/objects), so every accepted delegation re-inserts the full attribute/object/tag set one row at a time.

**Basis for the estimate.** Directly analogous to the already-measured Galaxy::__createClusters fix (per-row save() -> insertMulti() gave 46% reduction in that ingestion path); here the same per-row Model::save() overhead (full validation cycle, behavior hooks, one round-trip per row) applies to Attribute/ShadowAttribute/EventTag/Object saves, plus the extra find() calls per ObjectReference are pure additional n+1 overhead on top.

**Proposed fix.** Batch-prepare the Attribute/ShadowAttribute/EventTag/Object rows (setting event_id/object_id first) and use saveMany()/insertMulti() per model instead of per-item create()+save(); for ObjectReference resolution, do one find('all') for all needed uuids up front (both ObjectReference.uuid and the referenced Attribute/Object uuids), build an in-memory uuid=>id map, then batch-save the ObjectReference rows with updated referenced_id in one saveMany() pass.

**Implementation notes.** The referenced_id resolution must happen after the referenced Attribute/Object rows are already saved (their ids are only known post-insert), so keep two phases: (1) bulk-save Attributes/Objects/ShadowAttributes/EventTags and build uuid->id maps from the returned/reloaded rows, (2) bulk-resolve+save ObjectReferences using those in-memory maps instead of find() per reference. Preserve the existing return value (event['Event']['id']) and the fact that __savePreparedAttribute/__savePreparedObject also recurse into nested ShadowAttribute/AttributeTag arrays — those nested saves would need the same batching treatment or at minimum should stay correct if left as-is for a first pass.

**Risk.** Medium: save() currently runs through model validation and any beforeSave/afterSave behavior (e.g. correlation triggers) per row; switching to insertMulti()/saveMany() will skip whichever callbacks those bulk paths don't invoke, so correlation indexing or other afterSave side effects for Attribute in particular must be verified/replicated (e.g. an explicit index-rebuild pass) or the delegated event could end up with an event structure saved but under-indexed for search/correlation.

**Refuted.** Code matches the description, but the candidate fails on both hot path and semantics. Hot path: the ONLY caller in the whole tree is EventDelegation::transferEvent (app/Model/EventDelegation.php:46), whose only caller is EventDelegationsController.php:168 (accepting a single delegation) - a manual, one-event-at-a-time admin action. Nothing invokes it in a loop, in a job, or over a feed, so there is no at-scale caller. Semantics: transferEvent DELETES the original event before re-inserting, so the per-row Attribute::save() calls are precisely what rebuild correlations/uuid/validation via before/afterSave; insertMulti()/saveMany() skip those, leaving the transferred event stored but uncorrelated - the candidate's own risk note only says this 'must be verified/replicated', i.e. it acknowledges the break rather than handling it. Additionally __savePreparedAttribute/__savePreparedObject depend on $this->Attribute->id / $this->Object->id for the nested ShadowAttribute/AttributeTag/Object-child rows, which insertMulti does not return, and fetchEvent is called with includeAttachments=1, so bulk multi-row inserts would carry base64 attachment payloads (max_allowed_packet hazard). The 45% figure is transplanted from the Galaxy ingestion measurement with no basis in this path.

---

### 7. AppModel::find - `Model/AppModel.php`

| | |
|---|---|
| Location | `app/Model/AppModel.php:4834` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 35% (revised by the verifier) |
| Confidence | refuted |
| Hot path confirmed | **no** |
| Realistic iterations | once per row of the result set; a typical paginated index page returns 25-100 rows, and each iteration calls attachAnalystData(), which itself issues 3 (Note/Opinion/Relationship fetchForUuid) + 1 (getInboundRelationships) queries — so a 50-row page turns into roughly 200 additional queries instead of the ~4 that attachAnalystDataBulk would issue for the whole page. |

**What is slow.** AppModel::find() overrides parent::find() and, for type='all' with includeAnalystData set, loops over every returned row calling the single-item AnalystDataParentBehavior::attachAnalystData() (line 4844), even though a batched AnalystDataParentBehavior::attachAnalystDataBulk() already exists and is used elsewhere (app/Model/Behavior/AnalystDataParentBehavior.php:111).

**Why it is on a hot path.** query['includeAnalystData']=true is set unconditionally by CRUDComponent::index() (app/Controller/Component/CRUDComponent.php:61,72) for every 'all' find/paginate call, and CRUDComponent::index() is used by 31 controllers (grep -rl 'CRUD->index' app/Controller/ = 31 files, e.g. GalaxiesController, TagsController, FeedsController, OrganisationsController, WorkflowsController...). It is also set directly by GalaxyClustersController (line 189-190), EventsController attribute listings, ObjectsController, and AttributesController. So every REST/paginated index page on those 31+ controllers triggers this loop.

**Basis for the estimate.** attachAnalystDataBulk already exists and chunks queries in batches of 1000 uuids (1 query per type per chunk) versus attachAnalystData's 4 queries per row; for a 50-100 row index page this collapses roughly 200-400 queries into about 4-8, and query round-trip overhead typically dominates wall time on these list endpoints, so a 30-40% reduction in the enclosing find()/paginate() call is a conservative estimate.

**Proposed fix.** In AppModel::find(), replace the per-row foreach calling attachAnalystData() with a single call to attachAnalystDataBulk() (already implemented and chunked) on the whole result set, then write the returned per-uuid data back into $results[$k][$this->alias].

**Implementation notes.** attachAnalystDataBulk(Model $model, array $objects, array $types) expects $objects as a flat array keyed the same way as $results but with the alias-level fields directly at the top (it reads $object['uuid']), and returns the same shape with Note/Opinion/Relationship keys merged in. So: build $objects = array_column($results, $this->alias) preserving keys, call $objects = $this->attachAnalystDataBulk($objects) (via AnalystDataParentBehavior, dispatched through $this->Behaviors or a wrapper), then loop once to reassign $results[$k][$this->alias] = $objects[$k]. Must preserve original array keys (array_column loses them unless the 3rd arg / manual loop is used) since $results is often keyed 0..n sequentially by CakePHP but downstream code may rely on that. Also must still call $this->Relationship->getInboundRelationships per Model - check whether attachAnalystDataBulk already covers RelationshipInbound (it does not appear to in the code shown) which may need to remain per-row or be added to the bulk path.

**Risk.** attachAnalystDataBulk does not compute 'RelationshipInbound' the way attachAnalystData does (line 37 of AnalystDataParentBehavior.php) — that would need to be added to the bulk path or kept as a lighter-weight batched query, otherwise RelationshipInbound data would silently disappear from list views that currently show it.

**Refuted.** Code matches, but the hot-path claim is false and the fix breaks semantics. (a) The loop is guarded by $this->Behaviors->enabled('AnalystDataParent'), and only 5 models attach that behavior (Event, EventReport, GalaxyCluster, MispObject, MispAttribute). None of the 31 CRUD->index controllers use those models, so CRUDComponent::index()'s includeAnalystData=true never reaches this branch for them; GalaxyClustersController:189 is in view() (a single fetch), not an 'all' find. The real trigger is the opt-in includeAnalystData:1 REST/paginate param on Events (EventsController:774-775), and Event::fetchEvent already uses attachAnalystDataBulk (Event.php:3414/3469/3478). (b) On the paths where it does fire, AnalystDataParentBehavior::afterFind() (line 164-172) has ALREADY attached the same data per row because $model->includeAnalystData is set, so the find() loop is duplicate work; replacing it with the bulk call would not remove the N+1, it would only remove the second pass. The correct fix is deduplicating find()/afterFind. (c) attachAnalystDataBulk always calls fetchChildNotesAndOpinions(..., true, 1) (flat REST shape, depth 1), never the nested depth-5 shape used for the web UI, drops RelationshipInbound, and ends with call_user_func_array('array_merge', $objects), which reindexes and destroys the array keys the fix is supposed to preserve.

---

### 8. CorrelationValue::getIds - `Model/CorrelationValue.php`

| | |
|---|---|
| Location | `app/Model/CorrelationValue.php:46` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 15% (revised by the verifier) |
| Confidence | refuted |
| Hot path confirmed | yes |
| Realistic iterations | once per distinct new correlation value in a batch; during a large feed import or a full re-correlation job (generateCorrelationRouter -> generateCorrelation) processing thousands of attributes, hundreds of previously-unseen values is plausible. |

**What is slow.** getIds() looks up existing correlation_values in one query, then for every value not yet present it calls create()+save() one row at a time inside a foreach, instead of bulk-inserting the missing values and re-fetching their ids in one pass.

**Why it is on a hot path.** getIds() is called from replaceValueWithId(), which is called from DefaultCorrelationBehavior::saveCorrelations() (app/Model/Behavior/DefaultCorrelationBehavior.php:153) on every attribute save/correlation generation, and from generateCorrelation()'s bulk re-correlation job (app/Model/Correlation.php) which processes every attribute in an event or the whole instance. Each new/unseen correlation value (new IOC value never seen before) triggers one create()+save() call.

**Basis for the estimate.** Each save() call here has validate=false but still goes through full CakePHP Model::save() (query build, id retrieval) as one INSERT statement; replacing N sequential single-row inserts with 1 bulk INSERT (matching the insertMulti() pattern already used elsewhere in this same behavior for default_correlations, e.g. DefaultCorrelationBehavior.php:168) removes N-1 round trips. Most values in a mature instance already exist (cache hit), so the fraction of getIds() calls that hit this branch, and the fraction of saveCorrelations()'s total time this consumes, is modest — hence the conservative 15% estimate on the enclosing saveCorrelations()/generateCorrelation() call.

**Proposed fix.** Replace the per-value create()+save() loop with a single insertMulti() (or saveMany with atomic=false) of all $notExistValues, then re-query the value->id map for those values in one find('list') (mirroring the existing INSERT+lookup pattern already used for default_correlations in the same file's saveCorrelations()).

**Implementation notes.** Must keep the existing duplicate-key fallback behavior (the catch(Exception) -> getValueId() path) since concurrent inserts of the same value from parallel imports can race; after a bulk insert, do one find('list', ['conditions' => ['value' => $notExistValues]]) to pick up ids for rows that succeeded, and only fall back to per-value getValueId() for any values still missing (which should be rare, from races). The begin()/commit() transaction wrapping should still apply.

**Risk.** mb_substr truncation to 191 chars is applied per value already, but a bulk INSERT with ON DUPLICATE/unique constraint violations mid-batch could abort the whole batch depending on transaction/error mode, requiring INSERT IGNORE or catching a bulk-insert exception and falling back to the existing per-row path only for the failed subset.

**Refuted.** The caller chain is real (replaceValueWithId <- DefaultCorrelationBehavior::saveCorrelations:153 / NoAclCorrelationBehavior:101, on every attribute correlation), but the iteration estimate is wrong by orders of magnitude. Every correlation row in a saveCorrelations batch is created with createCorrelationEntry($value, ...) where $value resolves to the triggering attribute's own correlating value ($cV, at most value1/value2 — Correlation.php:598-608), and correlateValue() (Correlation.php:367) passes a single $value for the whole batch. After array_unique + the existing-values find('list'), $notExistValues is therefore at most ~1-2 entries per call, and 0 on a mature instance where the value already exists — never 'hundreds of previously-unseen values'. Bulk-inserting 1-2 rows plus an extra find('list') to recover their ids is a wash or a regression, and it would complicate the existing per-value duplicate-race fallback for no measurable gain.

---

### 9. UserSettingsController::deleteSelection - `Controller/UserSettingsController.php`

| | |
|---|---|
| Location | `app/Controller/UserSettingsController.php:407` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 40% (revised by the verifier) |
| Confidence | refuted |
| Hot path confirmed | **no** |
| Realistic iterations | once per selected id in a bulk delete; admin bulk actions in this UI commonly operate on a full page (25-100) or a 'select all matching filter' set which can be larger. |

**What is slow.** The POST branch of deleteSelection() loops over the submitted id list and calls find('first') then delete() once per id, while the GET branch of the same action (line 469) already demonstrates the batched alternative: one find('all', ['id' => $idList]) query.

**Why it is on a hot path.** deleteSelection is the bulk-delete action for the UserSettings admin index's checkbox multi-select; an admin selecting many rows (e.g. clearing stale per-user settings across the instance) submits an id list that becomes one find + one delete per row.

**Basis for the estimate.** Collapsing the per-row find('first') into the single find('all') the GET branch already uses removes N-1 SELECT queries; the ACL check per row still requires the fetched User contain data but can run in-memory. delete() calls could similarly be gathered into one deleteAll() for the ids that pass ACL, removing N-1 DELETE statements. For a 50-row selection this turns ~100 queries into ~2, and since this is a lightweight admin table this fully dominates the action's wall time.

**Proposed fix.** Fetch all requested UserSetting rows in one find('all', ['conditions' => ['UserSetting.id' => $idList], 'contain' => ['User.id','User.org_id']]) (as the GET branch already does), iterate in memory to classify deletable/blocked/failed, then issue a single UserSetting->deleteAll(['UserSetting.id' => $deletableIds]) instead of per-row delete().

**Implementation notes.** Must keep per-row checkAccess()/checkSettingAccess() calls (these are in-memory ACL checks, not queries) to preserve the existing deleted/blocked/failed counters and messaging. deleteAll() bypasses model callbacks the same way individual delete() calls with default options would only if callbacks were already off; verify UserSetting::delete() has no beforeDelete/afterDelete side effects relied upon (e.g. cache invalidation) before switching to deleteAll().

**Risk.** If UserSetting has beforeDelete/afterDelete callback logic (e.g. clearing a cache keyed by setting), deleteAll() skips model callbacks entirely, which could leave stale cached values — needs to be checked before applying deleteAll().

**Refuted.** The candidate's own risk condition fails on inspection. UserSetting declares actsAs AuditLog (UserSetting.php:13), and AuditLogBehavior implements beforeDelete/afterDelete (lines 223-243) — beforeDelete even runs its own find('first') snapshot per delete to record the removed row. Cake2 deleteAll() defaults to callbacks=false, so switching to it silently drops the audit trail for user-setting deletions; passing callbacks=true makes Cake2's deleteAll fetch the ids and call delete() per row anyway, so there is no query saving available while preserving semantics. The per-row find('first') is likewise not the only query being removed, since AuditLog re-fetches the row regardless. The ACL check is also not purely in-memory as claimed: checkAccess() calls $this->User->isUserSiteAdmin() (UserSetting.php:415) for org admins, one query per row. Finally this is an admin UI bulk action over a page of checkbox selections, not a hot path — no caller exercises it at scale.

---

### 10. MispAttribute::saveAttributes - `Model/MispAttribute.php`

| | |
|---|---|
| Location | `app/Model/MispAttribute.php:2872` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 25% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | 5-50 attributes per object call typically (file/network-traffic/mutex objects); for a multi-hundred-object event pulled via sync, cumulative save() calls run into the thousands across the event add. |

**What is slow.** saveAttributes() calls $this->create()+$this->save($attribute) once per attribute in a foreach, each triggering full CakePHP validation/beforeSave/afterSave hooks (including per-row correlation and tag handling) instead of a single insertMulti/saveMany.

**Why it is on a hot path.** Called from MispObject::saveObject (app/Model/MispObject.php:555) for every object's attribute list, and MispObject::saveObject is called once per object from Event::_add's object-import loop (app/Model/Event.php:5429, via captureObject) during full event creation/sync-pull. A single imported event with 200 objects x 5 attributes means 1000 individual save() calls.

**Basis for the estimate.** The sibling fix in Galaxy::__createClusters (per-row save -> insertMulti) measured a 46% reduction in galaxy ingestion wall clock for a structurally identical pattern (per-row save with hooks vs batched insert). Attribute save has additional per-row work (tag capture, sighting handling) that is not eliminated by batching, so I conservatively estimate a smaller relative win here, applied to the attribute-saving portion of object/event import.

**Proposed fix.** Split saveAttributes into a validation pass (as already done in editAttributeBulk) that massages/validates each attribute, then a single saveMany()/insertMulti() call for the batch, followed by a single pass to call AttributeTag->handleAttributeTags for each successfully-saved row (tag handling still needs per-row logic but no longer needs a full model save each time).

**Implementation notes.** Current code assigns $this->id after each save() to pass to handleAttributeTags; a batched approach needs to recover generated ids after saveMany, e.g. by having saveMany return per-row ids (CakePHP saveMany does keep the model's data structure but not auto-populate ids the same way) - may need to fetch back by uuid after insert, similar to the updateLookupTable pattern already used in editAttributeBulk/editAttributePostProcessing in the same file.

**Risk.** Attribute save has side effects (onDemandEncrypt already applied before save, but also implicit behaviors like OnDemandCorrelationBehavior hooks that fire in afterSave) that assume one row at a time; batching must confirm those behaviors still fire correctly per row or be adapted to operate on the batch result set.

**Refuted.** Code matches (create()+save() per attribute), but the claimed hot path is wrong. Event::_add does NOT reach saveAttributes: Event.php:5429 calls MispObject::captureObject, and captureObject (MispObject.php:1191-1232) saves the object itself and then calls $this->Attribute->captureAttribute() per attribute - it never calls saveObject(). saveObject() (the only caller of saveAttributes at MispObject.php:555) is reached only from ObjectsController::add (one object per request, ~5-30 attributes), MispObject::groupAttributesIntoObject (MispObject.php:1478, one object), and the niche mactime import in EventsController.php:8347 (per-CSV-row object of 6 fixed attributes, where saveObject/object save dominates). MispObject.php:1175 passes a single-element array. So the '200 objects x 5 attributes during sync-pull' premise does not exist; sync-pull attribute saving goes through captureAttribute / editAttributeBulk instead. Additionally the fix is unsafe as sketched: Attribute save carries beforeValidate massaging, correlation (OnDemandCorrelation) and audit-log afterSave hooks that saveMany with validate=false would change, and ids must be recovered by uuid.

---

### 11. CollectionElementsController::add - `Controller/CollectionElementsController.php`

| | |
|---|---|
| Location | `app/Controller/CollectionElementsController.php:165` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | One fetchSimpleEvent() call per submitted UUID; a bulk add of 200 event UUIDs to a collection results in 200 separate authorized-lookup queries before the (separate) per-row save loop even begins. |

**What is slow.** Before saving a bulk list of Event UUIDs into a Collection, the controller validates each UUID by calling Event->fetchSimpleEvent() individually in a loop instead of a single batched lookup.

**Why it is on a hot path.** add() accepts a list of element_uuid values (via __normaliseElementUuids, line 20) which can be pasted/submitted in bulk when adding many events to a Collection at once; each entry triggers a separate fetchSimpleEvent() call, which itself runs a permission-scoped find with contain/joins.

**Basis for the estimate.** fetchSimpleEvent does a permission-checked find with joins, materially more expensive than a bare id lookup; consolidating to the already-existing batch method fetchSimpleEvents() (app/Model/Event.php:2862) for the same UUID list turns N authorized queries into 1, which should remove the majority of this validation loop's wall time - the overall add() call also has a second per-row save loop that this fix doesn't address, so I scope the estimate to roughly a fifth of the total add() request time for a large bulk add.

**Proposed fix.** Replace the per-uuid fetchSimpleEvent() loop with one call to Event->fetchSimpleEvents($user, ['conditions' => ['Event.uuid' => $elementUuids], 'fields' => ['Event.id', 'Event.uuid']]), then verify every requested uuid appears in the result set (throwing NotFoundException for the whole batch if any is missing/unauthorized, preserving current all-or-nothing behavior).

**Implementation notes.** Current code throws NotFoundException on the very first invalid/unauthorized uuid it hits, aborting the whole request; the batched version must replicate that all-or-nothing semantics (compare count/keys of returned events against $elementUuids) rather than silently dropping invalid ones.

**Risk.** Low - read-only validation logic; must confirm fetchSimpleEvents applies the same ACL scoping as fetchSimpleEvent so authorization behavior is unchanged for restricted users.

**Refuted.** The loop exists, but both the cost premise and the scale premise are false. fetchSimpleEvent (Event.php:1733-1750) is a find('first') with recursive=-1 and NO contain/joins - the caller even passes fields=>['Event.id']; its only extra work is createEventConditions, whose SharingGroup->authorizedIds is memoised per request (SharingGroup.php:505-510), so iterations 2..N add nothing but a single indexed uuid lookup each. Scale: element uuids come from the events-index multi-select (View/Themed/UiBeta/Events/index.ctp:375) bounded by the page size (~60), in a rare interactive action - not a hot path. ~60 sub-millisecond point lookups are a negligible share of a request that also performs 60 individual CollectionElement saves with duplicate-catching PDOException handling; the claimed ~20% of add() wall time is not supportable.

---

### 12. GalaxyElementsController::deleteSelection - `Controller/GalaxyElementsController.php`

| | |
|---|---|
| Location | `app/Controller/GalaxyElementsController.php:94` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | One find + one fetchIfAuthorized + one delete per selected element id; selecting and deleting 100-500 elements from a large cluster (galaxy clusters can have hundreds of elements/relations) runs into hundreds of sequential single-row queries. |

**What is slow.** Bulk-deleting a selection of GalaxyElement ids does a separate find('first') plus a separate delete() per id, and (when elements share a cluster) repeats GalaxyCluster->fetchIfAuthorized() redundantly for each element instead of once per distinct cluster before the loop.

**Why it is on a hot path.** deleteSelection is the bulk-delete action reachable from the galaxy cluster element list UI's multi-select 'delete selected' control, resolving ids via _resolveElementIds($id) which accepts an array of selected element ids from the request.

**Basis for the estimate.** find('first')+delete() per row can be replaced with one find('list') keyed by id plus one deleteAll(['GalaxyElement.id' => $authorizedIds]); fetchIfAuthorized is already partially deduplicated by cluster via $clustersToBump for the final bump loop but is still called once per element rather than once per distinct cluster during the authorization check itself - caching it in a per-request array keyed by clusterId removes the redundant calls. Estimate is conservative since fetchIfAuthorized cost (a permission-scoped find) dominates over the plain id lookups.

**Proposed fix.** First fetch all requested GalaxyElement rows in one find('all', ['conditions' => ['GalaxyElement.id' => $ids]]) call and group by galaxy_cluster_id; call fetchIfAuthorized once per distinct cluster_id (not per element); then issue a single deleteAll() for the ids belonging to authorized clusters.

**Implementation notes.** Preserve per-element authorization semantics (an element only gets deleted if its cluster is authorized) by grouping ids by cluster_id first, then only including ids from clusters whose fetchIfAuthorized succeeded in the final deleteAll() id list; $successes count and $clustersToBump logic can be derived from the grouped structure directly.

**Risk.** Medium - delete() on GalaxyElement may trigger model-level cleanup (e.g. cascading tag/relation updates via beforeDelete/afterDelete callbacks) that a bare deleteAll() bypasses; must confirm no such callbacks exist on GalaxyElement before switching to deleteAll, or fall back to a smaller-N loop (per authorized cluster rather than per element) as a safer intermediate.

**Refuted.** Code matches, but the fix breaks semantics and the win is negligible. GalaxyElement (app/Model/GalaxyElement.php:13) declares actsAs = ['AuditLog','Containable'], so replacing per-row delete() with a single deleteAll() silently drops the AuditLog entries the current path writes for every deleted element - the candidate only hedges this conditionally ('must confirm no such callbacks exist'), and the callback does exist. The remaining safe part (dedup fetchIfAuthorized per distinct cluster) saves little: fetchIfAuthorized is a fetchClusterById + one Tag find, while the loop already ends in one editCluster() per distinct cluster (GalaxyCluster re-save, tag/cache rebuild) which dominates by orders of magnitude and is already deduped. Scale is also not there: selection comes from the paginated element list (limit 20) as a one-off admin action, not a hot path.

---

### 13. AttributesController::attributeReplace - `Controller/AttributesController.php`

| | |
|---|---|
| Location | `app/Controller/AttributesController.php:2385` |
| Pattern | `quadratic_nested_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 25% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | outer loop over newValues (pasted textarea lines) x inner loop over oldAttributes (existing attributes of that category/type in the event); events with a few hundred attributes of one type and a replacement list of similar size gives on the order of 10^4-10^5 comparisons, plus one save()/delete() per line |

**What is slow.** The bulk 'replace values' action deduplicates newValues against oldAttributes with a nested foreach (O(n*m) string comparisons) and then does an individual Model::save() per new value and Model::delete() per removed value instead of one insertMulti/deleteAll pass.

**Why it is on a hot path.** attributeReplace($id) backs the 'Replace attribute values' modal on the event view (bulk replace all attributes of one category+type with a new value list) — a common bulk-edit workflow when re-importing or correcting a large set of IOCs of the same type in one event.

**Basis for the estimate.** Replacing the O(n*m) in_array-style dedup with a single isset() hash lookup (build oldAttributes as a value=>id map once) removes the quadratic term entirely; batching the creates via insertMulti and the deletes via deleteAll(id IN (...)) removes the per-row query overhead. Estimate is conservative since Event::unpublishEvent and per-row validation still run once, not per-row for creates in the new design.

**Proposed fix.** Build $oldByValue = array_column($oldAttributes, 'Attribute.id', 'Attribute.value') once (O(m)); for each newValue do an isset() check instead of the inner foreach (O(n)); collect all attributes to create and use insertMulti (or Model::saveMany) once; collect old-attribute ids to remove (whose value has no match in newValues, also via isset() on an array_flip($newValues)) and delete them with one deleteAll(['Attribute.id' => $ids]) instead of per-id delete().

**Implementation notes.** MispAttribute::delete() may have model-level afterDelete/beforeDelete logic (e.g. correlation cleanup) that a raw deleteAll bypasses — verify Attribute's Model.delete callbacks before switching to deleteAll; same caution for save() afterSave hooks vs insertMulti when batching creates. Keep the $results counters (created/deleted/createdFail/deletedFail/untouched) semantically identical for the UI message.

**Risk.** Medium: MISP's Attribute model commonly hooks correlation/index maintenance into save()/delete() callbacks; bulk insertMulti/deleteAll would skip those callbacks unless the surrounding code independently re-triggers correlation rebuild, so this needs verification against Attribute's afterSave/afterDelete before applying.

**Refuted.** The estimate attributes the win to the wrong cost, and the batching half of the fix is semantically unsafe. (1) The 'quadratic' term is plain PHP string comparison; even the claimed 10^4-10^5 comparisons is sub-millisecond and invisible next to a single DB write, so replacing it with a hash map wins essentially nothing. (2) The real cost is the per-row save()/delete(), and neither can be batched here: MispAttribute::afterSave (app/Model/MispAttribute.php:630) runs Correlation->beforeSaveCorrelation/afterSaveCorrelation/advancedCorrelationsUpdate plus ZMQ/Kafka/workflow publishing, and beforeDelete/afterDelete (lines 771/797) do the correlation and attachment cleanup — and CakePHP 2's Model::deleteAll($conditions, $cascade, $callbacks = false) skips callbacks BY DEFAULT, so the proposed deleteAll would leave stale correlations behind. The notes only say 'verify', they do not handle it. (3) Scope is bounded: one modal submission against the attributes of a single event limited to one category+type, not a repeated at-scale path.

---

### 14. EventTemplatesController::__collectObjectRelationSpecs - `Controller/EventTemplatesController.php`

| | |
|---|---|
| Location | `app/Controller/EventTemplatesController.php:688` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | one iteration per object_field element in definition['structure']; typical object-heavy templates have 5-30 such elements, complex ones more |

**What is slow.** For every object_field element in an event template's structure, a separate ObjectTemplate::find('first') (with ObjectTemplateElement contain) is issued to resolve the pinned template version, instead of resolving all needed (uuid, min-version) pairs in one query.

**Why it is on a hot path.** Called once (line 639) from the event-template form-build path, which renders on every load of the 'populate event from template' / template edit form for a MISP object-based event template. Templates that compose several MISP objects (e.g. a phishing or malware-report template referencing file, url, domain-ip, etc. objects) commonly define 5-30 object_field elements.

**Basis for the estimate.** Each iteration is a full find('first') with a contained association; collapsing the per-element lookups into one find('all') keyed by (uuid, minimum_version) and matching in memory removes N-1 round trips for a form-render path where this lookup is the only DB-bound part of the loop. Estimate is modest because template structures are rarely more than a few dozen elements, capping the absolute savings.

**Proposed fix.** Collect the distinct set of (uuid, minimum_version) pairs from all object_field elements first, issue one ObjectTemplate::find('all') with an OR-list of the uuid/version/active conditions (same contain), then group results by uuid and pick, per element, the lowest version >= that element's pinned minimum from the in-memory group instead of re-querying per element.

**Implementation notes.** Must preserve the 'ORDER BY version ASC, take first >= pinned' semantics per element in the in-memory selection, and keep the missing-template fallback ($out[$id] = ['missing'=>true, ...]) behavior for uuids with no matching row.

**Risk.** Low: read-only lookup used only to build form metadata; main risk is a subtle off-by-one in selecting 'the lowest qualifying version' when grouping in memory instead of relying on SQL ORDER BY + LIMIT 1 per query.

**Refuted.** Code is as described (a per-object_field ObjectTemplate::find('first') with ObjectTemplateElement contain), but there is no scale. The only callers are __renderUserForm(), reached from instantiate (GET) and preview() — a one-off form render per user click. N is bounded by the object_field elements in one template definition (the candidate itself says 5-30), each query is an indexed uuid lookup, so the absolute saving is a few milliseconds on a page that then renders a full form. The 20% figure is unsupported: the loop is nowhere near 20% of a form-render request. Candidate's own confidence is 'low' and the code does not justify raising it.

---

### 15. TemplatesController::populateEventFromTemplate (constructEvent) - `Controller/TemplatesController.php`

| | |
|---|---|
| Location | `app/Controller/TemplatesController.php:439` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | one save() per decoded attribute in the submitted 'Template.attributes' JSON; a template with 10-15 elements including a couple of multi-value fields commonly yields 20-50 attributes in one submission |

**What is slow.** When populating an event from a (legacy) Template, every attribute produced by the template's elements is inserted with an individual MispAttribute::create()+save() call inside a foreach, instead of a single batched insert.

**Why it is on a hot path.** This is the save step of the legacy 'populate event from template' workflow (Templates/populate -> constructEvent), which runs once per template submission; a template can define many attribute-producing elements and, worse, an attribute_multiple element that expands into several attributes each, so a single form submission can generate dozens of Attribute rows in this loop.

**Basis for the estimate.** Each iteration currently pays a full create()+save() round trip (plus, for malware-sample attributes, a handleMaliciousBase64 file-hash side path); batching the non-malware-sample rows via insertMulti/saveMany after validation removes N-1 of those round trips. Estimate kept modest because malware-sample attributes (file read + hash) and per-row validation still dominate when present.

**Proposed fix.** Validate each $attributes[$k] as today (mass-assignment guard, malware-sample data handling), but instead of calling MispAttribute::create()+save() inside the loop, accumulate the validated rows and insert them in one batch (Model::saveMany or insertMulti) after the loop, then recompute $fails from the batch result.

**Implementation notes.** Malware-sample handling (handleMaliciousBase64, file read/delete) must stay per-row since it depends on a temp file unique to that attribute; only the final save can be batched. Keep the event_id pinning / id-stripping mass-assignment guard per row before batching.

**Risk.** Medium: MispAttribute::save() likely has afterSave hooks (e.g., correlation engine, event free-text ratings) whose per-row triggering may be relied upon elsewhere; switching to a bulk insert would skip those unless the code separately re-runs whatever the hook does — needs verification against MispAttribute's Model callbacks before applying.

**Refuted.** The proposed fix would lose data, and the path is not hot. (1) Attachment persistence happens INSIDE MispAttribute::afterSave — the 'data'/'data_raw' -> saveAttachment() branch (app/Model/MispAttribute.php ~690) — so a bulk insertMulti would silently drop the malware-sample file payload this very loop prepares via handleMaliciousBase64, on top of skipping correlation (Correlation->afterSaveCorrelation) and beforeValidate/beforeSave massaging and validation. (2) The suggested alternative, saveMany/saveAll, does not batch anything in CakePHP 2 — it issues one save() per record inside a transaction — so it removes zero round trips. (3) Not a hot path: /templates/populateEventFromTemplate is registered as a deprecated endpoint in app/Controller/Component/DeprecationComponent.php, and it is one form submission producing on the order of 20-50 rows.

---

### 16. ServersController::pruneDuplicateUUIDs / removeDuplicateEvents - `Controller/ServersController.php`

| | |
|---|---|
| Location | `app/Controller/ServersController.php:2716` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 25% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | once per duplicate-uuid group found, times ~(occurrence-1) rows per group; realistic corrupted instances can have hundreds to low-thousands of duplicate rows across all groups |

**What is slow.** Both admin dedup actions find groups of duplicate-UUID rows, then for each group re-query all rows with that uuid and delete() every row past the first one individually (ServersController.php:2716-2784), instead of collecting the ids to remove and issuing one deleteAll(['id' => $ids]).

**Why it is on a hot path.** Confirmed callers: pruneDuplicateUUIDs (admin action, routed from 'administration' page, POST-only) and removeDuplicateEvents (same page). Both are one-off maintenance operations, but on an instance with real UUID corruption (e.g. after a bad sync or a bug in event/attribute creation) the duplicate count can run into the thousands of attributes/events, each incurring a full find('all') + delete() pair.

**Basis for the estimate.** Each individual delete() triggers Cake's full delete lifecycle (beforeDelete/afterDelete callbacks, cascading deletes) so it cannot be a bare SQL DELETE, but the per-group find('all') that re-fetches full rows just to read ids is pure overhead that a single find('list', ['fields' => ['id']]) grouped by uuid, or a window-function query, would remove; conservatively this eliminates roughly a quarter of the query volume in the loop.

**Proposed fix.** Fetch id lists per duplicate uuid in one lighter query (fields => id only) instead of full rows, and where cascading/audit behavior allows, batch the deletes for the trailing duplicates of each group via deleteAll(['id' => $idsToRemove]) rather than one delete() call per row.

**Implementation notes.** delete() is used deliberately here (not deleteAll) likely so per-row afterDelete hooks fire correctly (blocklist cleanup, log entries) — any batching must preserve those side effects or explicitly replicate them for the batched ids. Given this is an infrequent admin maintenance tool rather than a hot user-facing path, this is a lower-priority, lower-confidence candidate.

**Risk.** Medium: MispAttribute/Event delete() lifecycle likely does meaningful cleanup (correlation removal, log entries, blocklist entries for events); naive deleteAll would skip that, so this needs to keep per-row delete() and only tighten the redundant lookup queries, not the delete itself.

**Refuted.** Refuted on three grounds. (1) Not a hot path: both are POST-only admin maintenance actions run from the administration page, and each run finishes with $this->Server->updateDatabase('makeAttributeUUIDsUnique' / 'makeEventUUIDsUnique') — a schema migration whose cost dwarfs the loop entirely. (2) The core claim of a 'redundant re-query' is factually wrong: the first find() selects only Attribute.uuid plus count(*) with GROUP BY ... HAVING COUNT(*) > 1, so row ids are genuinely not available from it; the per-group second find is necessary, not redundant. Trimming it to fields => id saves almost nothing because delete() dominates (Cake re-reads the row anyway, then runs correlation cleanup, cascading deletes, audit log entries, and in removeDuplicateEvents an EventBlocklist deleteAll per row). (3) The headline fix (batch deleteAll) is conceded unsafe by the candidate's own implementation notes, which retreat to only tightening the lookup query — i.e. the proposal reduces to a micro-optimisation of the cheapest part of an infrequent admin path. No measurable win.

---

### 17. generateCorrelation - `Model/ShadowAttribute.php`

| | |
|---|---|
| Location | `app/Model/ShadowAttribute.php:634` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 30% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Once per pending proposal; MISP instances with active feed-to-proposal pipelines routinely accumulate thousands of open proposals, giving thousands of Attribute finds plus thousands of saveMany round trips for a single admin action. |

**What is slow.** generateCorrelation loops over every pending proposal and, for each one, __afterSaveCorrelation (line 229) runs a fresh Attribute->find('all') for each of the proposal's 1-2 correlating values, then does a separate ShadowAttributeCorrelation->saveMany() call per proposal instead of accumulating all correlations and writing them once.

**Why it is on a hot path.** generateCorrelation is invoked directly by ShadowAttributesController::generateCorrelation() (the admin 'regenerate correlations' action, app/Controller/ShadowAttributesController.php:1058) and by AdminShell (app/Console/Command/AdminShell.php:260) -- both process every non-deleted proposal in the instance. $proposals = $this->find('all', [...'ShadowAttribute.deleted'=>0, 'proposal_to_delete'=>0]) with no limit, so on an instance with an active proposal backlog this is thousands of rows.

**Basis for the estimate.** Same class of fix as the measured Galaxy::__createClusters change (N find+save round trips collapsed to a handful): here the per-proposal Attribute lookups can be merged into one or a few chunked IN() queries (batching all proposals' value1/value2 together, indexed by value), and the many small saveMany() calls collapsed into one/few chunked saveMany() calls at the end. Kept conservative because the per-value find is against Attribute.value1/value2 which is typically covered by an index, so each individual query is already fast -- the win is mostly in round-trip count, not per-query cost.

**Proposed fix.** Refactor __afterSaveCorrelation to stop querying and saving per proposal: first collect the full set of distinct correlating values across all $proposals, do one (or a few array_chunk'd) Attribute->find('all', ['conditions'=>['value1'=>$values OR 'value2'=>$values]]) queries and index the results by value, then iterate the proposals purely in memory to build the full $shadow_attribute_correlations array, and finally call ShadowAttributeCorrelation->saveMany() once (or in large chunks) after the loop instead of once per proposal.

**Implementation notes.** Loop is app/Model/ShadowAttribute.php:634 (foreach ($proposals as $k => $proposal) { $this->__afterSaveCorrelation($proposal['ShadowAttribute']); ... }); the per-proposal query+save is inside __afterSaveCorrelation at lines 229-283 (find at 244, saveMany at 281). Preserve the existing exclusion of MispAttribute::NON_CORRELATING_TYPES and the 'Attribute.event_id !=' self-exclusion condition when building the batched IN query. The job-progress reporting at line 636-638 (percent complete) must keep working against the original per-proposal loop count even though the correlation writes move outside it.

**Risk.** Medium: __afterSaveCorrelation currently re-queries the Attribute table fresh for each proposal, meaning it always sees correlations from attributes saved earlier within the same run; a fully batched single up-front query would miss cross-proposal correlations created by proposals processed earlier in this same job. Batching must therefore still account for proposal-to-proposal ordering, or accept a stated behavior change (correlations among proposals aren't relevant here since this only correlates proposals to live Attributes, not to each other, so this risk is likely small but should be verified).

**Refuted.** The loop and per-proposal find/saveMany exist as described, but the hot path does not. Proposal correlation is explicitly dead code: the only automatic writer, the __afterSaveCorrelation call in afterSave(), is commented out under the comment 'correlations are deprecated for proposals' (ShadowAttribute.php:299-307). The only live callers of generateCorrelation() are (a) the site-admin-only 'Recorrelate proposals' postLink (ShadowAttributesController::generateCorrelation, gated by _isSiteAdmin + POST) and (b) the 2.4.20 datamodel upgrade one-shot at ShadowAttribute.php:826 — a manual maintenance action, not a request path. Nothing ever reads the output either: grepping the whole app, ShadowAttributeCorrelation appears only at ShadowAttribute.php:225/226/237/281/624/625 (deleteAll + saveMany) — there is no ShadowAttributeCorrelation model file and no consumer of shadow_attribute_correlations anywhere in Model/, Controller/ or View/. The 'thousands of open proposals from feed-to-proposal pipelines' claim is unsupported speculation. Optimising round-trips in a deprecated, manually triggered, write-only path is not worthwhile; the real defect here is the nested 'foreach ($correlatingAttributes as $key => $cA)' inside the value loop, which re-emits value1's correlations when value2 exists (duplicate rows), and that is a correctness issue, not a perf candidate.

---

### 18. bulkAdd - `Controller/ObjectReferencesController.php`

| | |
|---|---|
| Location | `app/Controller/ObjectReferencesController.php:276` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 25% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Once per selected attribute per bulkAdd call; realistic use is tens of attributes for a manual multi-select, but a 'select all attributes of this event' selection can reach the low hundreds for larger events. |

**What is slow.** bulkAdd() calls $this->ObjectReference->create() + save() once per selected attribute inside a foreach, instead of building all rows and inserting them in one batched insertMulti().

**Why it is on a hot path.** bulkAdd() (app/Controller/ObjectReferencesController.php:213) is the AJAX endpoint behind the UI action that links a whole set of user-selected event attributes to one object as ObjectReferences in a single request; $selectedAttributeIDs comes straight from client-side multi-select (including a 'select all' path), so the loop at line 260 can run once per attribute chosen, each iteration issuing its own INSERT (plus CakePHP's validation query overhead) round-trip.

**Basis for the estimate.** Same class of fix as the measured Galaxy::__createClusters change (insertMulti replacing per-row save() cut that operation's wall clock by 46%); ObjectReference rows are simpler (fewer validated fields) so the per-row save() overhead ratio is likely similar or higher relative to total request time, hence a comparable but slightly more conservative estimate.

**Proposed fix.** Build the full list of ObjectReference rows in PHP (as already done per-iteration for $newRelationship), validate the shared fields once, then insert them with a single $db->insertMulti('object_references', [...], $valuesToInsert) call (chunked at e.g. 1000 rows) the same way Warninglist::__updateList already does for warninglist_entries.

**Implementation notes.** Each row currently sets referenced_id/referenced_uuid/relationship_type/comment/event_id/object_uuid/source_uuid/object_id/referenced_type/uuid; uuid must still be generated per row via CakeText::uuid(). After the batch insert, $this->ObjectReference->updateTimestamps($newRelationship) at line 282 and the subsequent REST/ajax response building (lines 283-299) reference the last-created relationship/object — keep those working by tracking the last row or refetching by object id as needed.

**Risk.** Low-medium: bypassing Model::save() skips its beforeSave/afterSave hooks and per-row validationErrors reporting (used at line 296/298 on failure) — need to replicate any required validation (e.g. duplicate/self-reference checks) before the bulk insert, and adjust the success/fail counting logic which currently increments per successful save().

**Refuted.** Code is as described (create()+save() at lines 275-276), but the proposed insertMulti fix breaks semantics the notes do not handle, and the hot path is not at scale. ObjectReference (app/Model/ObjectReference.php:9-16) carries the AuditLog and SysLogLogable behaviors and an afterSave() (line 77) that publishes ZMQ/Kafka object_reference notifications; a raw insertMulti silently drops the per-row audit trail and the notifications - in MISP the audit log is compliance-relevant, not incidental. The notes only mention 'validation' and beforeSave/afterSave in the abstract without addressing audit/notification loss. The REST branch at line 285 also reads $this->ObjectReference->id, which insertMulti never populates. The estimate basis is also mismatched: Galaxy::__bulkInsertClusters (app/Model/Galaxy.php:361) is a background galaxy-update job inserting thousands of rows with no per-row audit expectation, not a user-facing audited write. Scale: bulkAdd is a single manual AJAX multi-select action bounded by what a user clicks (tens of rows), in a request that already runs a full Event::fetchEvent with correlations and warninglists; 25% is far beyond plausible. Note also that afterSave's extra find() is gated behind ZMQ/Kafka config, so the per-row overhead claim overstates the default deployment.

---

### 19. bulkAdd - `Controller/ObjectReferencesController.php`

| | |
|---|---|
| Location | `app/Controller/ObjectReferencesController.php:233` |
| Pattern | `in_array_on_large_array` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 5% (revised by the verifier) |
| Confidence | high |
| Hot path confirmed | **no** |
| Realistic iterations | n = attributes in the event (can be 1,000+ for large events) times m = selected attribute IDs (tens to low hundreds), i.e. up to ~100k comparisons for a large event with a broad selection. |

**What is slow.** bulkAdd() filters an event's attributes down to the selected ones using `in_array($attribute['id'], $selectedAttributeIDs)` inside a foreach over every attribute of the event, instead of an isset() lookup on a flipped/keyed selection array.

**Why it is on a hot path.** Same bulkAdd() call as above; the outer loop runs once per attribute of the (already fetched) event — events routinely carry thousands of attributes — while the inner in_array scans $selectedAttributeIDs (which, for a 'select all' bulk action, can itself be in the hundreds), making this an O(n*m) scan on every bulkAdd invocation.

**Basis for the estimate.** in_array/isset difference is microseconds per call in PHP; at n*m in the tens-of-thousands range this is low-single-digit milliseconds against a request that already does multiple full-event fetches and (with the sibling save-loop fix) DB inserts, so the wall-clock share is small but the fix is essentially free.

**Proposed fix.** Flip $selectedAttributeIDs into a hash map once before the loop (`$selectedSet = array_flip($selectedAttributeIDs);`) and replace `in_array($attribute['id'], $selectedAttributeIDs)` with `isset($selectedSet[$attribute['id']])`.

**Implementation notes.** Selected IDs arrive as decoded JSON (array of ints/strings) at line 219; array_flip requires the values to be valid array keys (ints or strings), which id values satisfy.

**Risk.** Very low: pure micro-optimization with identical semantics as long as attribute ids and selectedAttributeIDs use consistent types (int vs numeric string) for the lookup — verify with a loose/strict comparison check before switching.

**Refuted.** Code confirmed (in_array($attribute['id'], $selectedAttributeIDs) inside the foreach at line 232-236) and the array_flip fix is semantics-safe (numeric-string ids canonicalize to int keys under array_flip, so the isset lookup matches in_array's loose behaviour here). Refuted on impact and hot path, not on correctness: this is the same rarely-invoked manual AJAX endpoint as the sibling candidate - one call per user click, not a per-request or ingestion path - and the candidate's own basis concedes low-single-digit milliseconds against a request that already performs a full Event::fetchEvent (attributes, objects, tags, correlations, warninglists) plus DB writes. 5% is not attainable; the real share is well under 1%, i.e. below measurement noise, so this is not a worthwhile optimisation.

---

### 20. import - `Model/TagCollection.php`

| | |
|---|---|
| Location | `app/Model/TagCollection.php:146` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 15% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Outer: once per TagCollection in the imported payload (typically 1, but export/import can bundle many). Inner: once per TagCollectionTag/GalaxyCluster entry inside each collection, which for a moderately sized tag collection can be dozens to low hundreds of tags. |

**What is slow.** TagCollection::import() does a find('first') existence check per collection, then (on success) a save() plus, per tag/galaxy-cluster entry inside that collection, a captureTag()/lookupTagIdFromName() lookup and a separate TagCollectionTag::create()+save() call — none of it batched.

**Why it is on a hot path.** Called from TagCollectionsController's import action (app/Controller/TagCollectionsController.php:110) when a user uploads a JSON export of one or more tag collections; each collection can bundle its own list of TagCollectionTag entries and/or Galaxy/GalaxyCluster references (lines 165-192), each becoming its own create()+save() round trip, and each import request can include multiple collections in one payload.

**Basis for the estimate.** Conservative: the inner per-tag save() calls are cheap single-row inserts, but each also does a preceding find (captureTag/lookupTagIdFromName), so a collection with e.g. 100 tags issues ~200+ queries; converting the TagCollectionTag inserts to insertMulti after resolving tag_ids removes half of that, a proportionally moderate but not dominant slice of the whole import request.

**Proposed fix.** Resolve all needed tag_ids first (captureTag/lookupTagIdFromName still needed per distinct tag name since tag capture can itself create new tags), then batch all resulting TagCollectionTag rows for one TagCollection with a single insertMulti() instead of one create()+save() per row.

**Implementation notes.** captureTag() and lookupTagIdFromName() are Tag model methods outside this file's scope and cannot trivially be batched (tag capture may need to create a new Tag row, itself an n+1 candidate in Tag.php, out of this review's file list); the safe, in-scope win here is only the final TagCollectionTag insert step at lines 168-172 and 185-189.

**Risk.** Low-medium: skips TagCollectionTag's own save-time validation/hooks; must confirm it has none of consequence before switching to insertMulti, and must preserve $this->id (the just-created TagCollection id) as tag_collection_id for every row in the batch.

**Refuted.** Code is roughly as described (find('first') existence check at line 146, per-tag captureTag + TagCollectionTag create()/save() at lines 165-172 and 176-190), but there is no hot path. The only caller is TagCollectionsController.php:110, a manual admin upload of a tag-collection JSON export - typically one collection with a handful of tags, run interactively and rarely, never in a loop or a sync/ingest path. The candidate's own confidence is 'low' and its implementation notes concede that the batchable slice is only the final TagCollectionTag insert (the captureTag/lookupTagIdFromName lookups, which are half the queries, cannot be batched because tag capture may create rows). Halving the query count of a rare, human-initiated import that already takes an HTTP round trip and file upload is not a worthwhile optimisation; 15% of a request nobody is waiting on at scale is not meaningful. Additionally, TagCollectionTag save() is the only place tag_collection_id/tag_id uniqueness is exercised, so an insertMulti would need duplicate handling the notes do not specify.

---

### 21. captureRelations - `Model/GalaxyClusterRelation.php`

| | |
|---|---|
| Location | `app/Model/GalaxyClusterRelation.php:437` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 25% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Realistic worst case: importing a large custom or ATT&CK-style galaxy with ~2,000 clusters averaging 2-3 relations each yields ~5,000 relations, i.e. ~10,000 find() calls + 5,000 save() calls purely for relation capture. |

**What is slow.** Each relation in a cluster's relation list triggers two find('first') lookups (referenced-cluster lookup at line 457 via fetchGalaxyClusters, duplicate-check at line 469) plus a save() at line 492 and a per-relation attachTags() call at line 505, all inside the foreach loop, instead of resolving/inserting the whole relation set in bulk.

**Why it is on a hot path.** Confirmed caller chain: GalaxyCluster::captureCluster (line 843) calls this once per cluster whenever the cluster carries GalaxyClusterRelation entries; captureCluster itself is called in a per-cluster loop by Galaxy::importGalaxyAndClusters (Galaxy.php:493, the manual/API bulk galaxy-and-clusters import path used by GalaxyiesController's import action) and by GalaxyCluster::__pullGalaxyCluster (GalaxyCluster.php:2030, invoked per cluster from the pullGalaxyClusters sync loop). A galaxy such as MITRE ATT&CK ships thousands of clusters, many carrying multiple relations (sub-technique/uses/mitigates links), so a single manual import can multiply into tens of thousands of find+find+save calls.

**Basis for the estimate.** The sibling method GalaxyClusterRelation::bulkSaveRelations (lines 363-420, in the same file) already demonstrates the batched replacement pattern for the exact same table: it prefetches uuid->id and tag-name->id maps once, calls saveMany() once, then batch-inserts tags via insertMulti. That method is the proven fix used by Galaxy::update()'s routine cron path, which is the direct analog to the __createClusters 46%-improvement case already measured. Applying the same batching to captureRelations would eliminate the two find()s and the per-row save() for each relation. I discount from that 46% figure because captureCluster/captureCluster's caller loop still performs several other unavoidable per-cluster queries (galaxy lookup, org/SG capture, element replace), so relation-capture is only part of the per-cluster cost; pull-driven calls are additionally bounded by per-cluster HTTP fetch latency. 20-30% of importGalaxyAndClusters wall clock is a conservative estimate for the manual-import path where DB work dominates.

**Proposed fix.** Refactor captureRelations to model bulkSaveRelations: (1) collect all relations across the loop, (2) do one find('list') to map referenced_galaxy_cluster_uuid -> id and one to map existing (galaxy_cluster_uuid, referenced_galaxy_cluster_uuid, referenced_galaxy_cluster_type) -> id for dedup, (3) build the full relation array in memory using those maps, (4) saveMany()/insertMulti() the relations in one call, (5) batch-attach tags the way bulkSaveRelations does (single Tag name->id map, single insertMulti into galaxy_cluster_relation_tags).

**Implementation notes.** captureRelations differs from bulkSaveRelations in that it must also (a) log failures per-relation via $this->Log->createLogEntry when fromPull is false, (b) apply Event::captureSGForElement per relation when distribution==4, and (c) preserve the existing 'skip if already exists and not fromPull' semantics. These need to be resolved in-memory against the batch id maps before the single saveMany() call, and per-relation log entries can still be emitted from the loop even though the DB write itself is deferred to the batch. Callers (GalaxyCluster.php:843) consume the returned ['success','imported','failed'] shape — preserve those semantics exactly.

**Risk.** Medium: must carefully preserve per-relation validation/log-entry behavior and the fromPull upsert-vs-reject semantics; a naive full-batch save could mask per-row failures that today are individually logged and counted in $results['failed'].

**Refuted.** Scale claim is misattributed and the fix is structurally wrong. (1) Large-galaxy loading (MITRE ATT&CK / misp-galaxy JSON) does NOT go through captureRelations: Galaxy::update() (Galaxy.php:409) calls __createClusters (line 425) and then GalaxyClusterRelation::bulkSaveRelations once for all relations (line 435) — that path is already batched. captureRelations is reached only from GalaxyCluster::captureCluster:843, whose callers are Galaxy::importGalaxyAndClusters (manual import / push of user-supplied JSON, GalaxiesController:367/402, and Event::convertGalaxyClustersToTags which is only reachable from Event::upload_stix's __handleGalaxiesAndClusters, a manual STIX-import action) and __pullGalaxyCluster:2030 (per-cluster HTTP pull, dominated by network latency and short-circuited earlier by the 'Remote version is not newer' check at GalaxyCluster.php:831-837). No scheduled/bulk caller. (2) The loop's N is one cluster's relations (typically 0-5), not the import's total; the real multiplier is the per-cluster caller loop, which the proposed fix does not restructure — batching inside a single captureRelations call saves almost nothing. (3) The proposed find('list') uuid->id map is a full galaxy_clusters table scan per call, a pessimization at N=2-3, and it bypasses the ACL that fetchGalaxyClusters applies via buildConditions($user) (GalaxyCluster.php:1009-1065) — a semantic change the implementation_notes do not address (they cover logging, SG capture and the fromPull skip, not access control). (4) The claimed duplicate-check cost barely exists: captureCluster deleteAll's that cluster's relations at line 842 immediately before calling captureRelations, so the find('first') dedup query almost always returns empty on this path.

---

### 22. admin_clean - `Controller/RegexpController.php`

| | |
|---|---|
| Location | `app/Controller/RegexpController.php:244` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | MISP instances with feed/sync ingestion routinely hold 1M+ attributes; even a modest 1-5% match rate against active regexp rules means tens of thousands of individual save() or delete() calls in one admin_clean run, each incurring full CakePHP validation/callback overhead (which for Attribute includes correlation and audit-log hooks). |

**What is slow.** Inside a 1000-row-chunked loop over every non-deleted attribute in the instance, each attribute whose value is changed by a regexp rule is persisted with an individual MispAttribute->save($item) call (line 244), and every attribute flagged for deletion is removed with an individual MispAttribute->delete($item) call in a second loop (line 252), instead of batching either operation.

**Why it is on a hot path.** admin_clean (lines 219-257) is an admin action that scans and rewrites/deletes every non-deleted attribute in the whole MISP instance against the full Regexp ruleset, paginating in chunks of 1000 (line 229-235) but still calling save()/delete() once per affected row inside the chunk loop (lines 236-248) and the deletable loop (lines 250-253).

**Basis for the estimate.** The delete loop (line 250-253) can be replaced with a single deleteAll(['Attribute.id' => $deletable]) call, removing N-1 round trips for that portion outright. The save loop cannot be fully collapsed to one query (each row gets a different new value), but batching the writes (e.g. chunked multi-row UPDATE via query()/updateAll with a CASE expression, or saveMany() with validate/callbacks disabled) removes per-call ORM overhead and network round-trips. I estimate this conservatively at ~20% of admin_clean's total wall clock because the dominant cost is likely the O(attributes x regexp-rules) regex matching itself (CPU-bound, in-memory, not addressed here) — the DB-write savings are a real but secondary component of the overall runtime.

**Proposed fix.** Replace the per-id delete() loop with one deleteAll(['Attribute.id' => $deletable]) call. For the modified-value loop, either (a) accumulate [id => new_value] pairs per chunk and issue one multi-row UPDATE (e.g. via query() with CASE WHEN id=... THEN ... or driver-specific batch update), or (b) at minimum call save() with 'callbacks' => false / a narrow fieldList and skip unnecessary validation for this trusted internal rewrite, cutting per-row overhead.

**Implementation notes.** Confirm whether Attribute::afterDelete/afterSave (correlation cleanup, audit logging) is required for correctness here — if deleteAll bypasses model callbacks that are relied upon (e.g. correlation table cleanup on attribute delete), those side effects must be reproduced explicitly (e.g. a bulk Correlation delete keyed by the same attribute ids) before switching to deleteAll/raw updateAll.

**Risk.** Medium: deleteAll() and raw batch UPDATE skip Model callbacks (afterDelete/afterSave), which for Attribute likely include correlation-table cleanup and audit logging; those side effects must be replicated explicitly or this fix will leave orphaned correlation rows / break audit trails.

**Refuted.** The code is not what the candidate describes. Regexp::replaceSpecific (app/Model/Regexp.php:84) takes $string BY VALUE and returns only 0/1/2 — the rewritten value never reaches $item, so the save() at line 244 re-saves the attribute UNCHANGED. There are no per-row new values to batch, which makes proposed fix (a) (multi-row UPDATE with CASE WHEN of new values) impossible without first fixing that pre-existing correctness bug; the right change here is a bug fix (propagate the value, or drop the pointless save), not batching. Secondary: admin_clean is a manual POST-only admin maintenance action with no scheduled or programmatic caller, so it is not a hot path at any frequency; and the 20% estimate is unverifiable because the dominant costs (chunked full-table scan, O(attributes x rules) regex matching, and the full Attribute save() callback chain fired on unchanged rows) are not what the fix addresses. The deleteAll-skips-callbacks concern is not counted against the candidate since the implementation_notes already flag it. Minor description error: 'deleted' => 0 at line 232 sits outside 'conditions' and is ignored by CakePHP, so the scan covers soft-deleted attributes too — it is not 'every non-deleted attribute'.

---

### 23. EventReport::attachReportCountsToEvents - `Model/EventReport.php`

| | |
|---|---|
| Location | `app/Model/EventReport.php:408` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 30% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | yes |
| Realistic iterations | Once per event on the current index page; default/typical page sizes run 25-60 rows, so 25-60 queries per page load (more if the user raises the page-size setting). |

**What is slow.** Runs one find('count') query against event_reports per event in the loop instead of one grouped COUNT query for all events at once.

**Why it is on a hot path.** Called from Controller/EventsController.php:1139 (__attachInfoToEvents, invoked on every /events/index page render when the 'report_count' column is enabled) and again at EventsController.php:2015 for single-event view. On the index page $events is the full page of results (user-configurable page size, commonly 25-60+ rows), so each page load issues 25-60+ extra sequential SELECT COUNT(*) queries.

**Basis for the estimate.** The same file's sibling helpers already establish the fix pattern: Event::attachSightingsCountToEvents and Event::attachObjectAndAttributeCountToEvents (both in Model/Event.php) replace the identical per-row find() with one grouped find('all', ['group'=>'event_id']) + Hash::combine lookup. Collapsing N count queries into 1 removes N-1 round trips; conservatively that cuts the wall clock of this specific attach step by the majority of its time, and since it is one of several 'attach' steps run per index page load, I estimate a 20-40% reduction of the enclosing __attachInfoToEvents/index-render call when the report_count column is active (not 90%+, since several other attach steps run regardless).

**Proposed fix.** Replace the per-event find('count') with a single find('all', ['fields'=>['EventReport.event_id','COUNT(EventReport.id) as count'], 'conditions'=>[...same ACL logic across all event ids...], 'group'=>['EventReport.event_id']]) and index the results by event_id (Hash::combine), then look up per event in the loop instead of querying.

**Implementation notes.** The per-event ACL condition (site-admin bypass vs. sharing-group-scoped OR-clause) is identical in shape for every event except Event.org_id/id, so it can be expressed as a single query with 'EventReport.event_id IN $eventIds' plus the same distribution/sharing_group OR-clause (sgids computed once, already hoisted above the loop). Preserve the exact semantics: events owned by the user's org see all reports; events not owned by the user's org only count reports with distribution in [1,2,3,5] or (distribution=4 AND sharing_group_id IN sgids). This likely needs two grouped queries (own-org events vs. other-org events) or a single query with a CASE/OR condition per event id, merged afterward. Default missing entries to 0 the same way attachObjectAndAttributeCountToEvents does.

**Risk.** Low-to-medium: the ACL condition varies per event (own org vs not), so a naive single grouped query without correctly preserving the per-event OR-clause could over- or under-count reports for cross-org events. Must be tested against org-owned vs non-org-owned events with sharing-group-restricted reports.

**Refuted.** Loop shape is real (per-event find('count') at EventReport.php:406-408, recursive defaults to 1 so each also JOINs events), but the proposed fix breaks semantics and the notes get the current semantics wrong. Lines 400-405 build ['EventReport.distribution' => [1,2,3,5], 'AND' => ['EventReport.distribution' => 4, ...]] with NO 'OR' key, so CakePHP ANDs them: distribution IN (1,2,3,5) AND distribution = 4 is unsatisfiable, i.e. cross-org events currently always report_count = 0. Compare the sibling buildACLConditions at EventReport.php:360-374, which does wrap the same clauses in 'OR' — the count helper is a mis-transcription. The candidate's implementation_notes assert the OR semantics ('reports with distribution in [1,2,3,5] or (distribution=4 AND sharing_group_id IN sgids)'), so an implementer following them silently changes ACL-visible output for every non-site-admin viewing another org's event (0 -> nonzero). That is a behaviour change the notes do not handle. Estimate is also inflated independently: the column only exists when MISP.showEventReportCountOnIndex is set, and it defaults to false (Server.php:6710-6713); when on, 25-60 indexed COUNTs are single-digit milliseconds against a full index render that also runs tags/correlations/sightings/proposals attaches plus the paginated event fetch — nowhere near 30%.

---

### 24. Noticelist::__updateList - `Model/Noticelist.php`

| | |
|---|---|
| Location | `app/Model/Noticelist.php:96` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 40% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Once per notice entry per updated list; MISP ships several dozen noticelists and individual lists can range from a handful of entries up into the hundreds, so a single 'Update Noticelists' run can issue several hundred to low-thousands of individual INSERT statements, each followed by an extra UPDATE because NoticelistEntry->belongsTo Noticelist has counterCache=true (so every save() also triggers a parent counter-cache UPDATE). |

**What is slow.** Every notice entry of a noticelist being imported/updated is inserted with an individual create()+save() call instead of one batched saveMany().

**Why it is on a hot path.** Called from Noticelist::update() (app/Model/Noticelist.php:44-65), which is the handler for the 'Update Noticelists' admin action / noticelist-update cron; it iterates every list under files/noticelists/lists and re-imports any list whose bundled version is newer than what's stored, deleting the old list (quickDelete) and re-inserting every entry one row at a time.

**Basis for the estimate.** Each loop iteration currently costs 2 round trips (INSERT + counterCache UPDATE) plus full CakePHP validate/save overhead per row. Batching with saveMany() removes per-call framework overhead and, if counterCache recalculation is deferred to a single UPDATE after the batch (or the noticelist_entries.data JSON encoding is pre-computed once), the number of round trips drops roughly in half or more. Conservative estimate given it's one part of the larger update() loop across all lists.

**Proposed fix.** Collect all $values for a list (already built at line 89-94) and pass them to $this->NoticelistEntry->saveMany($values, ['validate' => true]) once per list instead of looping create()+save(). If counterCache overhead is measurable, temporarily unset the counterCache association or set noticelist_id count directly after the batch.

**Implementation notes.** $values already carries the exact per-row structure NoticelistEntry->save() expects (with 'data' and 'noticelist_id' keys); saveMany() accepts the same array of rows, so this is close to a drop-in replacement. NoticelistEntry::beforeValidate() json_encodes 'data' per row already, which works unchanged under saveMany. Watch for validation error handling: __updateList currently doesn't check per-entry save results, so behavior on a bad entry is preserved either way, but saveMany's atomic-by-default option should be set to false to match current best-effort-per-row semantics if any entries are expected to legitimately fail validation.

**Risk.** Low: this path only runs during noticelist import which is idempotent (old list is deleted first), and saveMany with validate=true preserves the same validation as individual save() calls. Main risk is the counterCache column drifting if it's bypassed rather than recomputed.

**Refuted.** The stated mechanism is factually wrong: CakePHP 2's Model::saveMany (Lib/cakephp/lib/Cake/Model/Model.php:2313, body at 2344-2356) simply loops $this->create(null) + $this->save($record) per row. It emits no multi-row INSERT, so the N INSERTs remain N, and because each still goes through save(), the counterCache UPDATE on NoticelistEntry->belongsTo Noticelist (NoticelistEntry.php:19-25) fires exactly as often as today. Round trips do not drop 'roughly in half' — they do not drop at all. The only genuine win would be the single transaction from the default atomic=true (fewer InnoDB commits), and the candidate's own implementation_notes tell the implementer to set atomic=false to preserve best-effort-per-row semantics, forfeiting even that. Frequency also fails the at-scale bar: the only callers are NoticelistsController::update (admin button, line 39) and AdminShell line 596, and Noticelist::update() only calls __updateList when a bundled list.json version exceeds the stored one — a rare, version-gated import of a few dozen small lists, not a request-path or per-event loop. A real bulk fix would need $db->insertMulti plus a manual counter recompute, which is not what was proposed.

---

### 25. Feed::compareFeeds - `Model/Feed.php`

| | |
|---|---|
| Location | `app/Model/Feed.php:1762` |
| Pattern | `n_plus_1_query` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 45% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | O((feeds+servers)^2) redis round trips; MISP ships dozens of default feeds and any of them can have caching enabled, plus any configured sync servers, so with even 20-30 cache-enabled sources this is 400-900+ sequential synchronous redis calls per page load (each a separate TCP round trip since, unlike attachFeedCorrelations() elsewhere in this same file, no pipeline() is used here). |

**What is slow.** Computes pairwise overlap between every feed/server pair with a separate, unpipelined redis SINTER call per pair, inside an O(n^2) nested loop.

**Why it is on a hot path.** Called directly from Controller/FeedsController.php:1576 (the admin 'Compare feeds' page, FeedsController::compareFeeds), which is rendered on every visit to that page with no caching. The method (Feed.php lines 1712-1806) builds $feeds and $servers from all cache-enabled sources and then runs 4 nested loops (feeds x feeds, feeds x servers, servers x feeds, servers x servers) each issuing one $redis->sInter() call per pair.

**Basis for the estimate.** The same file's attachFeedCorrelations() (Feed.php:519-696) demonstrates the fix pattern already in use elsewhere in this class: wrap the per-item redis calls in $redis->pipeline()/exec() to collapse N round trips into 1. Since compareFeeds is 100% redis-call-bound (its own loop body does no other work), pipelining the O(n^2) sInter calls should remove the large majority of its wall-clock time; I estimate 40-60% reduction of the compareFeeds() call itself since result assembly (array_merge, round()) still happens per pair in PHP.

**Proposed fix.** Wrap each of the 4 nested-loop blocks in a redis pipeline: queue every sInter() call for a given outer item's inner loop (or the whole n^2 set) into one pipeline, call exec() once, then iterate the returned array in the same order to build overlap_count/overlap_percentage, mirroring the pipe pattern already used in attachFeedCorrelations() in this same file.

**Implementation notes.** sInter results are only used via count($intersect), so pipelining doesn't change any downstream logic — just batches the round trips. Need to preserve the exact key ordering between the queued commands and the exec() result array (index-aligned), same as the existing pipeline usage at Feed.php:625-629. Because this is 4 separate nested-loop blocks, either pipeline within each outer iteration's inner loop (n pipelines of size ~n each) or build one global pipeline of size ~4n^2 up front and slice the results back out — the former is simpler and still cuts round trips from n^2 to n.

**Risk.** Low: pure read-only redis calls, no side effects, and the pipelining is already a proven pattern in this exact file. Main risk is an indexing/ordering bug when demultiplexing pipelined results back onto the right feed/server pairs.

**Refuted.** The O(n^2) unpipelined sInter loops exist as described (Feed.php:1760-1794), but the diagnosis mis-attributes the cost, so the fix does not buy what is claimed. Redis executes pipelined commands serially server-side; pipelining removes only network round trips. SINTER's cost is dominated by O(smallest-set) server-side intersection work plus serialising and transferring every member of the result — feed caches hold hundreds of thousands to millions of hashes, so RTT is noise next to that. The cited precedent is not analogous: attachFeedCorrelations pipelines sIsMember (Feed.php:624-628), whose replies are single 0/1 integers; pipelining n full sInter member sets instead buffers all of them in PHP memory simultaneously, a plausible OOM the notes do not address, and the code only ever uses count($intersect). The correct fix is SINTERCARD (Redis 7) or SINTERSTORE+SCARD, which the candidate does not propose. Caller is also not at scale: FeedsController::compareFeeds (line 1573) is an on-demand admin page, and only feeds/servers with caching_enabled=1 AND an existing redis cache key survive the filters at Feed.php:1731-1762, which in practice is a handful, not the 20-30 assumed.

---

### 26. Module_tag_replacement_generic::replaceOnAttribute - `Model/WorkflowModules/action/Module_tag_replacement_generic.php`

| | |
|---|---|
| Location | `app/Model/WorkflowModules/action/Module_tag_replacement_generic.php:132` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 35% (revised by the verifier) |
| Confidence | n/a |
| Hot path confirmed | **no** |
| Realistic iterations | once per matching attribute in the triggering event; realistic events run from dozens to 10k+ attributes when this workflow module is configured with attribute/all scope (task brief's own reference point: 'events routinely have 10k+ attributes') |

**What is slow.** The workflow action loops over every matching attribute and, for each one, triggers a full per-attribute 'attach/detach tag + touch' write path (attachTagsToAttributeAndTouch / detachTagsFromAttributeAndTouch in MispAttribute.php), instead of resolving tag ids once and batch-writing AttributeTag rows plus a single bulk touch() for all affected attributes.

**Why it is on a hot path.** This is a MISP Workflow action module ('Tag Replacement Generic') that fires on triggers such as event_after_save or attribute_after_save when configured with scope='attribute' or 'all'. $matchingAttributes comes from Hash::extract($matchingItems, 'Event._AttributeFlattened.{n}') (line 88), i.e. every attribute of the event being processed. MISP events routinely carry hundreds to 10k+ attributes; any org running this module with attribute scope on event-level triggers runs this loop once per attribute in the event. Verified in MispAttribute.php:2619-2673: attachTagsToAttributeAndTouch does one captureTagWithCache + one attachTagToAttribute save per tag name, then one touch() UPDATE per attribute if anything changed; detachTagsFromAttributeAndTouch is symmetric.

**Basis for the estimate.** Each attribute currently costs at least one touch() UPDATE plus one save per newly-attached/detached tag; for a 1000-attribute event this is 1000+ individual write round trips serialized in a workflow (which is itself often synchronous/blocking for the triggering save). Grouping attributes by identical (add,remove) tag sets and using one updateAll()-based touch plus insertMulti() for AttributeTag rows removes the per-row round trip while preserving per-attribute tag semantics (each attribute may match different substitution tags, so full single-query collapse isn't always possible, hence a moderate rather than high estimate).

**Proposed fix.** In replaceOnAttribute, instead of calling __removeTagsFromAttributes([$attribute], ...) / __addTagsToAttributes([$attribute], ...) once per attribute, compute each attribute's add/remove tag set first, group attributes by identical sets, and pass each full group to the existing batch-capable methods (they already accept an array of attributes) in one call per group rather than N calls. This only removes the N-way *wrapper* call overhead; the deeper fix belongs in MispAttribute::attachTagsToAttributeAndTouch/detachTagsFromAttributeAndTouch (not in this file) which should batch-resolve tag ids via captureTagWithCache once per unique tag, insertMulti the AttributeTag rows, and issue a single updateAll(['timestamp'=>...], ['id' => $touchedAttributeIds]) instead of one touch() per attribute.

**Implementation notes.** Note for whoever implements: wrapping each attribute in its own single-element array before calling __addTagsToAttributes/__removeTagsFromAttributes does NOT by itself change query count -- those methods already foreach internally over whatever array they're given (Module_tag_operation.php:135-167), so calling them once with N attributes vs N times with 1 attribute produces the same number of underlying attachTagsToAttributeAndTouch calls today. The real win requires changing attachTagsToAttributeAndTouch/detachTagsFromAttributeAndTouch in app/Model/MispAttribute.php (outside this slice) to accept and batch multiple attribute ids, not just changing the calling loop here.

**Risk.** Medium: touch() semantics (bumping Attribute.timestamp, used for propagation/sync) must be preserved exactly per-attribute; a naive updateAll bulk-touch must only apply to attributes that actually changed (nothingToChange tracking currently done per-tag-per-attribute). Also affects roaming workflow data (_addTag/_removeTag update $roamingData incrementally per attribute) so any batching must still update roamingData per attribute even if the underlying DB write is batched.

**Refuted.** Refuted: the loop cannot execute at scale because it is broken code. replaceOnAttribute calls $this->__removeTagsFromAttributes([$attribute], $optionsRemove) and $this->__addTagsToAttributes([$attribute], $optionsAdd, $user), but the inherited signatures in app/Model/WorkflowModules/action/Module_tag_operation.php:152 and :135 are __removeTagsFromAttributes(array, array, WorkflowRoamingData) and __addTagsToAttributes(array, array, array, WorkflowRoamingData) -- the WorkflowRoamingData argument is required and is never passed, so the first call raises ArgumentCountError (fatal on PHP 7.1+; MISP runs 7.4/8.x). replaceOnEvent (113-127) has the identical defect against :181/:169, and Module_tag_replacement_tlp / Module_tag_replacement_pap inherit both with no overrides. The calls are guarded by !empty($options['tags']), so the module silently no-ops for every attribute whose tags do not match the substitution regex and fatals on the first one that does -- either way the claimed 1000-to-10k per-attribute write loop never runs. Second, independent refutation: the candidate's own implementation_notes concede that changing this file produces zero query reduction and that the real batching work belongs in MispAttribute::attachTagsToAttributeAndTouch/detachTagsFromAttributeAndTouch (app/Model/MispAttribute.php:2619/2648), a different file and line than the one reported. Third, the write volume is overstated even in a hypothetical working version: writes only occur for attributes carrying a tag that matches the module's regex, not for all attributes of the event.

---

### 27. User::resetAllSyncAuthKeys - `Model/User.php`

| | |
|---|---|
| Location | `app/Model/User.php:1267` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 25% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Once per sync/admin user on the instance; sync-heavy MISP communities can have dozens to low hundreds of sync users, so this is tens to hundreds of sequential find+update+log DB round trips triggered by a single admin click. |

**What is slow.** Resets auth keys for every sync/admin user one at a time via resetauthkey(), which does its own find() + updateField() + extralog() + optional sendEmail() per user, instead of a single bulk-update pass.

**Why it is on a hot path.** Called from User::adminAuthKeyReset-style flows (line 1238, `resetAllSyncAuthKeys`) for a site-wide 'reset all sync auth keys' admin action; the $affected_users result set (Role.perm_sync=1 OR perm_admin=1, excluding site admins, from the find at line 1244) is iterated at line 1267 and resetauthkey() (line 1295) is invoked per user, each doing its own find('first'), updateField(), and extralog() DB writes.

**Basis for the estimate.** Each iteration does at least 2 extra DB round trips beyond the unavoidable per-user work (fetching the row again inside resetauthkey() despite already having it in $affected_user, plus a separate extralog() write); batching the re-fetch away and writing the audit log entries in bulk would remove roughly a third of the per-iteration DB round trips. Conservative because the actual authkey generation, and the optional email send when Security.advanced_authkeys is off (not the default here) still have to happen per user regardless.

**Proposed fix.** Avoid the redundant find('first') inside resetauthkey() by passing the already-fetched $affected_user row in; batch the extralog() audit entries with a single saveMany() after the loop instead of one Log write per user.

**Implementation notes.** resetauthkey() is also called standalone (single-user path, line 1238) so any refactor needs to keep that call site working with an optional pre-fetched row parameter; the alert e-mail branch (line 1329) is inherently per-user and cannot be batched.

**Risk.** Medium -- resetauthkey() has authorization checks (site-admin / same-org-admin) that read from $updatedUser; passing in a row fetched earlier under a different context needs care not to skip a check that depended on re-fetching fresh state, and the audit log currently records one entry per reset synchronously, so batching it changes log entry timing/atomicity, which touches security-relevant audit behavior.

**Refuted.** Not a hot path and the described per-iteration cost is largely wrong. (a) It is a one-off admin action (UsersController::resetAllSyncAuthKeys, admin_index.ctp button); resetAllSyncAuthKeysRouter (line 1210) hands it to a background job when workers are available, so it does not block a request at all. (b) The loop calls resetauthkey($user, $affected_user['User']['id'], true) with $alert=true, i.e. an SMTP sendEmail per user -- that dominates any DB round trip by orders of magnitude, making the claimed 25% from removing a find/log unreachable. (c) With Security.advanced_authkeys enabled the updateField()+extralog() path described does not execute at all; it delegates to AuthKey->resetAuthKey. (d) The 'redundant' find('first') inside resetauthkey is exactly what the site-admin / same-org-admin authorization checks read ($updatedUser['Role']['perm_site_admin'], $updatedUser['User']['org_id']), so passing in a pre-fetched row changes security-relevant behaviour, and batching extralog() changes audit-log atomicity for an authkey reset. The candidate's own confidence is 'low' and its risk note concedes the auth concern.

---

### 28. MispObject::editObject - `Model/MispObject.php`

| | |
|---|---|
| Location | `app/Model/MispObject.php:1092` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 20% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Roughly once per attribute on the object being edited; MISP objects commonly carry 10-40 attributes, and some templates (e.g. file, network-traffic with reconstructed flows) go well beyond that. |

**What is slow.** editObject() saves modified/new/removed object attributes one row at a time via three separate per-attribute $this->Event->Attribute->save() calls (matched-update at line 1110, new-insert at 1143, soft-delete-of-removed at 1154) instead of batching the changes with saveMany()/updateAll().

**Why it is on a hot path.** editObject() is invoked from Model/Event.php whenever an Object is edited via the API/UI (object edit endpoint) and from the CLI object-edit commands (Console/Command/CLIShell/cli_objects.php). Every attribute of the object being edited triggers its own INSERT/UPDATE plus its own AttributeTag::handleAttributeTags() call; objects with dozens of attributes (e.g. file, x509, network-connection, or vendor-specific bulk objects) turn one logical object edit into dozens of individual save() round trips.

**Basis for the estimate.** Analogous, previously-measured pattern: replacing per-row Model::save() with a batched insert in Galaxy::__createClusters produced a confirmed 46% reduction in galaxy ingestion time. editObject()'s per-attribute save/validate/callback overhead is the same shape but attribute counts per object are typically smaller than cluster counts per galaxy import, so 20% of editObject()'s wall time is a conservative, scaled-down estimate rather than a direct transfer of the 46% figure.

**Proposed fix.** Split the matched/new/removed attribute sets exactly as today, but collect each into an array and issue one saveMany() per phase (updates, inserts, soft-deletes) instead of one save() per attribute; keep handleAttributeTags()/logDropped() calls per-row since they are keyed to the save result, but drive the loop bodies from the saveMany() result set rather than from individual save() calls.

**Implementation notes.** The three save() calls have different fieldLists/behaviors (line 1110 uses EDITABLE_FIELDS fieldList for an update, 1143 is a plain create+save for a new attribute, 1154 is a soft-delete via EDITABLE_FIELDS again) — a batching fix needs to keep these as three separate saveMany() batches (not one combined batch) to preserve per-phase fieldList semantics, and must preserve the per-row logDropped()/handleAttributeTags() side effects, which currently depend on each individual save()'s success/failure and on $this->Event->Attribute->id being set after create()+save() for new rows.

**Risk.** Medium — saveMany() changes error-handling granularity (a validation failure on one row behaves differently under atomic vs non-atomic batch saves than under independent save() calls), and the code currently relies on Attribute->id being populated per new-row save() to feed handleAttributeTags(); a batched rewrite must carefully thread per-row IDs and per-row success back out of saveMany()'s result.

**Refuted.** Three independent kills. (1) Wrong symbol: line 1092 is inside deltaMerge() (declared at 1041); editObject() starts at 1268 and contains none of the cited save() calls - it delegates per attribute to $this->Attribute->editAttribute() and then calls editAttributeBulk() (MispObject.php:1345-1356), i.e. the sync/API object-edit path is ALREADY batched. Event.php:5803 therefore does not reach the cited code. (2) No at-scale caller: deltaMerge's only caller is ObjectsController.php:466 (the interactive object edit action) - one object per HTTP request. The claimed CLI caller (cli_objects.php:478 __editObject) does not call deltaMerge either (grep for deltaMerge finds only the declaration and the controller). (3) The proposed fix is not a batch: CakePHP 2's Model::saveMany (app/Lib/cakephp/lib/Cake/Model/Model.php:2313-2353) simply loops create()+save() per record, running full validation, beforeSave/afterSave callbacks and one INSERT/UPDATE per row - it eliminates none of the claimed per-row overhead. The cited 46% Galaxy::__createClusters precedent came from a genuine multi-row INSERT, so the analogy and the 20% figure do not transfer.

---

### 29. Taxonomy::__updateTags - `Model/Taxonomy.php`

| | |
|---|---|
| Location | `app/Model/Taxonomy.php:409` |
| Pattern | `save_in_loop` |
| Estimated gain | **0%** of the enclosing operation |
| Original estimate | 15% (revised by the verifier) |
| Confidence | low |
| Hot path confirmed | **no** |
| Realistic iterations | Once per entry of each taxonomy being updated; large taxonomies commonly have several hundred entries, and a full 'update all taxonomies' admin action runs __updateTags() once per taxonomy (~180 taxonomies) in immediate succession. |

**What is slow.** After importing/updating a taxonomy, __updateTags() iterates every entry of the taxonomy and calls $this->Tag->save($temp['Tag']) once per changed tag instead of collecting the changed rows and issuing a single saveMany()/updateAll().

**Why it is on a hot path.** __updateTags() is called from __updateVocab() (line 187) on every taxonomy library update/import (admin 'Update Taxonomies' action, run for every taxonomy file on a full library refresh). Some shipped taxonomies (e.g. sector/threat-actor/large enumeration taxonomies) carry many hundreds to low thousands of entries; a full taxonomy library update iterates this for each of the ~180 taxonomy files MISP ships.

**Basis for the estimate.** Only entries whose colour/name/numerical_value actually changed trigger a save(), so the loop's cost is proportional to the diff size, not the full entry count; each triggered save() still pays full model validation/callback overhead per row though. 15% is a conservative estimate of the reduction in this admin action's wall time from batching, since the surrounding find()/getTagsForNamespace() calls (already single queries) dominate when few tags actually changed, and the save-in-loop only dominates on taxonomy version bumps that touch many entries (e.g. a full colour repalette).

**Proposed fix.** Accumulate the changed $temp['Tag'] rows into an array inside the loop and call $this->Tag->saveMany($changedTags) once after the loop instead of $this->Tag->save() per iteration.

**Implementation notes.** Straightforward — the per-entry work already fully computes $temp['Tag'] before the save() call, so only the save() call itself needs to move out of the loop body into a saveMany() after collecting all changed rows; no per-row side effects depend on the save() return value in this function today.

**Risk.** Low — no per-row logic depends on the individual save()'s result here, so batching should be behavior-preserving; verify saveMany()'s default validation/atomicity settings match save()'s per-call behavior (e.g. one bad row shouldn't silently drop all others if partial success is currently expected).

**Refuted.** The loop and the per-row $this->Tag->save() at line 429 exist as described, but the proposed fix cannot deliver the claimed win. CakePHP 2's saveMany (app/Lib/cakephp/lib/Cake/Model/Model.php:2313-2353) iterates the records and calls $this->save() per row, so every per-row validation, callback and single-row query is still executed - swapping save()-in-loop for saveMany() removes zero work. The only residual difference is that atomic saveMany wraps the rows in one transaction instead of N autocommits, which is a different mechanism from the one claimed and only measurable in the rare full-repalette case. On top of that the saves are diff-gated (only entries whose colour/name/numerical_value actually changed write at all - the candidate concedes this), the surrounding __getTaxonomy()/getTagsForNamespace() finds are the real cost, and the trigger is an occasional admin 'Update Taxonomies' action, not a request-path hot loop. Not a real, worthwhile optimisation as proposed.

---

## Scanner notes

- Reviewed EventsController.php (8714 lines, focused on save/create/find-in-loop grep hits and the addTag/pushProposals actions), AttributeTag.php in full, AnalystDataParentBehavior.php in full, AppModel.php::find() (read for context though outside the assigned slice) to trace the AnalystDataParentBehavior caller. Also scanned ModulesController.php, BetterCakeEventManager.php, ClusterRelationsTreeTool.php, and the Dashboard widget files (MispStatusWidget, CsseCovidWidget, OrganisationListWidget, MispAdminHealthWidget) for per-row query loops -- none showed a query/save inside a loop large enough to qualify (all in-memory foreach or single find() calls outside loops). Did not deeply review AuthKeysController.php, TaxiiServer.php, PubSubTool.php, cli_tags.php, OvermindPages.php, AdminSetting.php, the WorkflowModules action/logic/trigger files, DashboardURLValidator.php, MonitorSeriesStore.php, MermaidFlowchartTool.php, or LightPaginatorBehavior.php in the same depth after the grep pass found no save/find-in-foreach hits worth chasing in them; a couple (cli_tags.php, WorkflowModules) are CLI/rare-trigger code so lower priority even if something were there. countForAllTags() in AttributeTag.php (line 284) has a genuine loop-invariant duplicate-query bug (queries use the whole $tagIds array instead of the per-iteration $tagId, so N identical queries run for N tag ids) but was dropped as a candidate because its only current caller (countForTag) always passes a single-element array, so there is no live n+1 amplification in the present codebase.
- Reviewed all 27 assigned files. Most files besides Event.php had no qualifying loop-centred issues: Role.php/EventBlocklist.php/EventReportTag.php loops are over small bounded collections or already use isset()-hash lookups; RestSearchComponent.php, AdminCrudComponent.php, EventGraphController.php, GalaxyColour.php, Stix1Export.php, Stix2Export.php, HostsExport.php, Module_post_after_save.php, Module_event_distribution_operation.php, Module_webhook.php, Module_attribute_ids_flag_operation.php, PolynomialExtended.php, MispAdminSyncTestWidget.php, EventTemplateImportException.php, Note.php, WarninglistEntry.php, CryptGpgExtended.php had no save/find-in-loop or quadratic patterns on realistic hot paths. Two secondary candidates were found but not included in the top-6 (weaker evidence/impact than the three reported): (1) Event::processFreeTextData (app/Model/Event.php:8327) does Model::save() per parsed attribute during free-text import — a real but hard-to-batch pattern since each row goes through type-expansion/warninglist logic per item, so improvement is speculative without deeper Attribute::save() analysis outside this slice. (2) JobsController::deleteSelection (app/Controller/JobsController.php:92-104) does find('first')+delete() per selected job id instead of one find('all')+deleteAll() — real n+1 but Job rows are an admin housekeeping table, lower-value hot path. CsseCovidTrendsWidget::handler (app/Lib/Dashboard/CsseCovidTrendsWidget.php:67) calls fetchEvent() once per event id in a loop, matching the pattern, but it is a legacy/niche COVID dashboard widget with a 600s cache and was judged too low-value to report over the three included. StatisticsShell::orgEngagement (Console/Command/StatisticsShell.php) has a similar find-per-org loop but is an offline admin CLI report, not a request-path hot loop, so excluded.
- Reviewed all 34 assigned files. Most files in this slice (Console shells, small dashboard widgets, small Models like TemplateElementFile/ThreatLevel, trigger-only Workflow modules, ConfigLoadTask, MysqlObserverExtended) had no loop-centred query/save/quadratic issues worth reporting — either the collections iterated are small and fixed (role lists, module registries, SG org/server lists), or the code already uses bulk primitives (GalaxyElement::updateElements/captureElements use saveMany; DefaultCorrelationBehavior already batches via insertMulti and in-memory array_column indexing). AppModel.php's numerous one-time DB-migration/upgrade routines (__removeDuplicateUUIDsGeneric, removeDuplicateAttributeUUIDs, removeDuplicateEventUUIDs, __bumpReferences, __fixServerPullPushRules) contain per-row save()/delete() in loops but were excluded as candidates since they run at most once per upgrade, not on a recurring hot path. Module_tag_cve_from_enrichment.php has a per-attribute external HTTP call plus a redundant editAttribute()+editAttributeBulk([1 item]) double-save per iteration, but was excluded because the external HTTP latency to the CVE API dominates that loop's wall time, making the query-side saving a small fraction of the enclosing operation.
- Reviewed all 29 assigned files (via full read for the ones with candidates, targeted grep for foreach/find/save/hasAny/in_array/ClassRegistry patterns for the rest). Server.php (9090 lines) was scanned by listing every foreach and reading the surrounding code for each one that touched DB/model calls. No candidates met the bar in: SharingGroupBlueprintsController.php, AuditLogBehavior.php (per-save hook, not a loop itself), EventTemplateValidator.php, DistributionGraphTool.php, RPZExport.php, OpendataExport.php, APIShell.php (CLI doc generator, not hot path), Module_aggregate_comparator_if.php, Module_tag_if.php, ColourGradientTool.php, EventTemplateExporter.php, CpuLoadMonitorWidget.php, RecentSightingsWidget.php, FavouriteTagsController.php, MispSystemResourceWidget.php, EncryptedValue.php, ApacheSecureAuthComponent.php, ElasticSearchClient.php, EventTemplateDependencyMissingException.php, Module_attribute_after_save.php, News.php, TagCollectionTag.php, TrimBehavior.php. Also inspected SharingGroupOrg::updateOrgsForSG (save-in-loop + hasAny-per-row via beforeValidate) but dropped it: sharing-group org lists are realistically tens of rows, not hundreds/thousands, so the improvement would be marginal and speculative. Server.php's serverEventsOverlap() has an O(servers^2) nested loop but server counts (sync peers) are typically small (single/low double digits), so also dropped as not a real hot path.
- Reviewed all 35 assigned files for loop patterns; the strongest, best-evidenced candidates clustered in app/Model/MispAttribute.php (the slice's headline file) plus two controller bulk-action endpoints. Files reviewed in depth: MispAttribute.php, EventReportsController.php, Tag.php, IOCImportComponent.php, WorkflowBaseModule.php, ServerSyncTool.php, OnDemandCorrelationBehavior.php, Cerebrate.php, ObjectReference.php, CorrelationsController.php, CollectionElementsController.php, GalaxyElementsController.php. Remaining files (BroExport.php, AccessLogsController.php, ApacheAuthenticate.php, HttpSocketExtended.php, BookmarksController.php, MISPElementHTMLFormatterTool.php, Module_misp_module.php, AnalystDataBlocklistsController.php, EventBlocklistsController.php, QueueGlyph.php, Module_send_report_to_CTIInfoExtractor.php, ThresholdSightingsWidget.php, JsonExport.php, Module_threat_level_if.php, Template.php, PasswordShell.php, SightingdbOrg.php, AnalystDataBlocklist.php, Module_tag_replacement_tlp.php, Module_reload_full_event.php, Module_publish_event.php, TextExport.php, IOCExportComponent.php) were scanned via grep for foreach/save/find density but did not surface loop bodies with concrete, high-iteration DB/query work beyond what's already reported - mostly small fixed-size config loops or export formatting over already-fetched in-memory data, so I did not force weak candidates from them per the quality-over-quantity instruction. The GalaxyElementsController candidate has the lowest confidence of the five since GalaxyElement's delete-callback behavior wasn't fully traced (that model file was outside this slice).
- Reviewed all 35 assigned files (AttributesController.php, AnalystData.php, EventTemplatesController.php, TrendingWidget.php, CollectionsController.php, Organisation.php, UserShell.php, TemplatesController.php, PewPewMapWidget.php, MysqlExtended.php, RolesController.php, OverlapWithMyOrgWidget.php, APIActivityWidget.php, IOCExportTool.php, OrgEventsWidget.php, RecentEventReportsWidget.php, OpeniocExport.php, ClusterRelationsGraphTool.php, Module_assign_country_from_enrichment.php, MispAdminWorkerWidget.php, EnvSetting.php, Module_analyst_data_after_save.php, DistributionLevel.php, AppShell.php, Module_enrich_event.php, DecayingModelMappingController.php, Module_notify_user_toast.php, TemplateElementAttribute.php, Module_shadow_attribute_before_save.php, EventTemplateMarkdownRenderer.php, EventGraph.php, polyfill.php, OrgsContributorLastMonthWidget.php, ButtonWidget.php, Opinion.php, FavouriteTag.php). Several dashboard widgets (TrendingWidget, OverlapWithMyOrgWidget, PewPewMapWidget) were already carefully batched with in-memory hash indexes — a per-candidate-event correlation lookup remains in OverlapWithMyOrgWidget (line ~161) but was dropped as a candidate since getRelatedEventIds() appears inherently per-event with no simple batch alternative visible in this file. AnalystData::captureAnalystData's per-child recursive save loop (line ~736-748) was considered but dropped: each save carries complex per-item authorization/locking/blocklist logic that resists simple batching without deeper changes to captureAnalystData's contract. Module_assign_country_from_enrichment's per-attribute __addTagsToAttributes call (line 111-119) was flagged as suspicious (called once per attribute instead of batched) but its implementation lives outside this slice's files, so it could not be verified and was dropped per the no-speculation rule. RolesController's per-role User::find('count') loop and CollectionsController's per-uuid attach loop were reviewed but judged low-value (bounded by small admin-facing collections, typically tens of rows).
- Reviewed all 36 assigned files at varying depth; most (ACLComponent, cli_common/cli_users, ServerShell, WorkerShell, TasksController, GalaxyClusterRelationsController, EventTag, AuthKey, Bruteforce, NotificationLog, TemplateElement, TaxonomyEntry, most WorkflowModules, Export classes, small dashboard/tool files) either already hoist/batch their queries, operate over small fixed-size admin/config collections (servers, feeds, tasks, roles), or loop only over in-memory HTTP-fetched data with no DB calls in the loop body. The strongest finding (Module_attribute_edition_operation) required reading app/Model/MispAttribute.php, which is outside this slice's file list, to confirm the find-per-iteration; I did not otherwise review files outside the assigned 36. Did not deeply trace Ls22Shell.php's in_array-in-nested-loop uses (constant-size lookup lists, one-off analytics shell) or BenchmarksController's per-unique-user find (already memoized, admin-only diagnostics page) since both were judged weaker than the reported candidates.
- Deep-read (not just grep-scanned): app/Controller/UsersController.php (statistics/registrations/index/contact sections), app/Model/Sighting.php (captureSightings, pullSightingNewWay/OldWay, saveEmptyFetchedEvent, existing/existingOrganisations), app/Model/ShadowAttribute.php (correlation save path, generateCorrelation, capture, acceptProposal), app/Lib/Export/NidsExport.php, app/Lib/Tools/AttributeValidationTool.php, app/Lib/Tools/SecurityAudit.php, app/Controller/Component/CRUDComponent.php, app/Model/Dashboard.php, all six app/Lib/Dashboard/*Widget.php files, app/Controller/TaxiiServersController.php, app/Model/OrgBlocklist.php, app/Model/CorrelationExclusion.php, app/Model/WorkflowModules/action/Module_add_analyst_data.php.  Pattern-grepped only (foreach/find/ClassRegistry/save/in_array hits reviewed inline, no large loop-plus-DB-call found so not opened in full): app/Lib/Tools/EventTimelineTool.php, app/Lib/Tools/FinancialTool.php, app/Lib/Tools/CidrTool.php, app/Console/Command/DevShell.php, app/Model/WorkflowModules/logic/Module_generic_filter_data.php, app/Lib/Dashboard/Tools/LayoutFixup.php, app/Lib/Export/XmlExport.php, app/Lib/Tools/BackgroundJobs/Worker.php, app/Model/DecayingModelsFormulas/Sightings.php, app/Model/EventTemplateObjectDependency.php, app/Model/EventDelegation.php, app/Model/TaxonomyPredicate.php, app/Model/WorkflowModules/trigger/Module_event_before_delete.php, app/Model/EventReportTemplateVariable.php, app/Lib/Tools/RequestRearrangeTool.php, app/Controller/CommunitiesController.php, app/Controller/SightingdbController.php, app/Lib/Tools/CakeResponseFile.php -- these had zero or only trivially-small-N loop/DB combinations.  Two candidates investigated and rejected: (1) Sighting::saveEmptyFetchedEvent (lines 1518-1520, 1587-1589) does 2 Redis round trips per not-saved event uuid during pullSightings -- real pattern but the enclosing operation is dominated by remote HTTP fetches (per-chunk fetchSightingsForEvents / per-event fetchEvent), so thousands of local Redis round trips are a small fraction of a minutes-long, network-bound sync; did not meet the bar for a reportable candidate. (2) OrgBlocklist::afterFind (lines 54-62) does 2 Redis GETs per row via getBlockedData -- real pattern but only exercised by the paginated OrgBlocklistsController admin index (default pagination, ~25-60 rows), which is the kind of small, bounded collection the task explicitly excludes. Also checked ShadowAttribute::acceptProposal (lines 940-1030, has Attribute->find/save) at the advisor's suggestion: it operates on a single proposal per call (not a loop over proposals), so it is not a loop-centered candidate despite the find+save pattern.
- Slice 9 covers mostly controllers/dashboard widgets/export formatters that are already well-optimized (single query + in-memory foreach, cached ClassRegistry/Redis lookups, or insertMulti already in place — e.g. Warninglist.php's __updateList and attachWarninglistToAttributes are model examples of the *correct* pattern, not counted as findings). RestResponseComponent.php, DashboardsController.php, CanonicalTypeAdapter.php, TaxonomiesController.php, MysqlExtendedLogging.php, AttachmentTool.php, CerebratesController.php, and all files under ~150 lines were reviewed via targeted grep + read of every foreach/find/save/ClassRegistry hit and found to operate on small fixed collections or already batch their I/O — no reportable candidates there. GalaxyClustersController::deleteSelection (fetchIfAuthorized+deleteCluster per selected id, lines 651-659) was considered but dropped: deleteCluster/fetchIfAuthorized live in GalaxyCluster.php (out of this slice) and batching is complicated by the per-item authorization check, making it a weaker, harder-to-verify candidate than the four reported. ObjectTemplate::checkTemplateConformity's nested requirement/attribute loops were also considered and dropped as sub-quadratic in practice (MISP objects rarely exceed a few dozen attributes/template elements).
- Reviewed in full: GalaxyCluster.php, GalaxyClusterRelation.php, GalaxyClusterBlocklist.php, Correlation.php, Collection.php, Workflow.php, UserSetting.php, UserLoginProfile.php, RegexpController.php, ObjectTemplatesController.php, ThreadsController.php, CorrelationExclusionsController.php, EventTemplateImporter.php, AttachmentObjectBuilder.php, AttackWidget.php, TrendingAttributesWidget.php, EventEvolutionLineWidget.php, OrgEvolutionLineWidget.php, WidgetToolkit.php, WhoamiWidget.php, ProcessTool.php, MailLogTool.php, Module_attach_warninglist.php, Module_attribute_distribution_operation.php, Module_tag_attached_after_save.php, Module_log_after_save.php, cli_objects.php (partial, confirmed FK-prefetch pattern is already optimized), Task.php. Grep-only triage (no loop-plus-DB hits found, not fully read line-by-line): BenchmarkTool.php, SendEmailTemplate.php, DashboardShell.php, BetterSecurity.php, RateLimitComponent.php, HidsExport.php, CountExport.php, TrainingShell.php (admin CLI setup script, small fixed iteration counts), EventTemplateInstantiationException.php (exception class, no loops). Refuted candidate not reported: GalaxyCluster::generateMissingRelations (line 220-240) does an updateAll() per uuid in a loop, but its only caller (Galaxy::update(), line 362) runs it immediately after GalaxyClusterRelation::bulkSaveRelations, which already resolves referenced_galaxy_cluster_id correctly at insert time — the source comment literally says "Probably unnecessary anymore", meaning $missingRelations is expected to be empty on the routine path. Also considered and dropped: Correlation::__addAdvancedCorrelations (find() per correlatingAttribute at line 394) — real pattern but gated behind advancedCorrelationEnabled (likely off by default) and I could not fully confirm typical correlatingAttributes cardinality for its caller chain (correlateValue via EventShell/afterSaveCorrelation) within the review budget; the O(n²) pairwise loop at Correlation.php:387-393 is correctly excluded as inherent to the correlation feature's output shape, not a fixable inefficiency.
- Reviewed the full 35-file slice for loop-centred DB/query issues; most files (Controller/WorkflowsController.php, Lib/Tools/BackgroundJobsTool.php, Lib/Tools/WorkflowGraphTool.php, Console/Command/CLIShell/cli_attributes.php, Model/CorrelationRule.php, Model/Galaxy.php, Model/GalaxyClusterRelationTag.php, Model/SharingGroupBlueprint.php, Model/OverCorrelatingValue.php, Model/WorkflowModules/action/*, Model/WorkflowBlueprint.php, Model/Thread.php, Model/TemplateElementText.php, Console/Command/EventShell.php, Console/Command/RoleShell.php, Console/Command/UserInitShell.php, Console/Command/BaseurlShell.php, Controller/OrganisationsController.php, Controller/OrgBlocklistsController.php, Controller/PostsController.php, Lib/Dashboard/*, Lib/Tools/SyncTool.php, Lib/Tools/GitTool.php, Lib/Export/KunaiExport.php, Lib/Tools/JsonLogTool.php, Lib/Tools/TmpFileTool.php, Lib/MispTheme/MispTheme.php, Model/FuzzyCorrelateSsdeep.php, Model/NoticelistEntry.php) either had no DB/query calls inside loops, only operated on small fixed-size collections (settings, roles, a handful of orgs), or already used correct batched/hoisted patterns (Galaxy.php's __createClusters is already saveMany-chunked; Feed.php's attachFeedCorrelations, saveFreetextFeedData, and __cacheFreetextFeed already use pipelining/chunked saveMany). Feed.php was reviewed most thoroughly given its size and role as the slice's headline file; Galaxy.php was checked specifically for regressions/omissions around the already-fixed __createClusters. OverCorrelatingValue::generateOccurrences has a find('count') per row but each row needs a distinct LIKE-pattern count that isn't mergeable into one query, so it was not reported. Did not benchmark; estimates are analytical, based on comparable already-fixed sibling code in the same files/classes.
- Slice covered all 36 assigned files at directory-scan level (grep for foreach/find/save/ClassRegistry/in_array in every file) plus MispAttribute.php and Module_tag_operation.php read for supporting evidence (not counted in files_reviewed, which reflects the assigned slice). Considered and dropped: AttachmentScan::scan's per-attribute job->saveProgress() (app/Model/AttachmentScan.php:266) -- real per-iteration save but dominated by the external malware-scan I/O per file, negligible relative cost. ShadowAttributesController::acceptSelected/discardSelected loop over __accept/__discard per proposal (app/Controller/ShadowAttributesController.php:998,1042) -- each row requires distinct read-modify-decide logic with side effects (creating/deleting Attribute rows, workflow triggers), not a simple batchable save. TagCollectionsController::addTag per-tag find/find/save/log loop (app/Controller/TagCollectionsController.php:402-433) -- real n+1 shape but realistic N (tags added to a collection per request) is small, and ClassRegistry::init is a cheap registry lookup, not a re-instantiation; dropped as too weak to be worth reporting. CLIShell.php (3480 lines, largest file in slice) is interactive/administrative tooling; its per-record loops (record listing/formatting) are bounded by CLI usage patterns, not hot application paths. NoAclCorrelationBehavior.php was already well-optimized (bulk finds hoisted out of loops, chunked insertMulti for correlations) -- no candidate found there despite being the most correlation-heavy file in the slice. Nine small files (21-49 lines: AuthkeyShell, Module_shadow_attribute_after_save, Module_generic_filter_reset, Module_event_after_save_new_from_pull, Module_enrichment_before_query, OrgsUsingMitreWidget, OrgsUsingObjectsWidget, MysqlObserver, WarninglistType) contained no loop-driven DB or CPU work worth flagging.
- Reviewed all 37 assigned files at varying depth: deep read of app/Model/User.php, app/Controller/ObjectsController.php, app/Controller/FeedsController.php, app/Model/SharingGroup.php, app/Lib/Tools/CorrelationGraphTool.php, app/Model/Allowedlist.php, app/Model/CryptographicKey.php, app/Lib/Dashboard/OrgsContributorsGeneric.php (plus its two known subclasses, which are outside this slice, to confirm the N+1); grep-scanned the rest (app/Controller/AuditLogsController.php, app/Controller/WarninglistsController.php, app/Controller/TemplateElementsController.php, app/Lib/Tools/ServerSettingGroups.php, app/Model/EventTemplate.php, app/Console/Command/CLIShell/cli_organisations.php, app/Console/Command/LogShell.php, app/Lib/Dashboard/*, app/Lib/Tools/GpgTool.php, app/Lib/Tools/BackgroundJobs/BackgroundJob.php, app/Lib/Tools/SuricataRuleFormat.php, app/Lib/Export/AttackExport.php, app/Model/DecayingModelMapping.php, app/Model/WorkflowModules/*, app/Controller/Component/CompressedRequestHandlerComponent.php, app/Model/DecayingModelsFormulas/Polynomial.php, app/Console/cake.php, app/Controller/RestClientHistoryController.php) and found no additional loop-centred issues meeting the bar -- most were either bounded-size admin/CLI operations, already-batched (LogShell::export chunks 100k rows/query; SharingGroup::appendOrgsAndServers already batches org/server lookups by id), or in-memory-only transforms with no per-iteration I/O. Dropped app/Controller/ObjectsController.php's orphanedObjectDiagnostics() (line 1006-1159, N+1 Attribute/Log/ObjectReference finds per object) as a candidate: it is a rarely-invoked one-off diagnostic/repair tool, not a path exercised in normal operation, so it didn't make the top-6 cut against the stronger, more clearly-hot candidates reported.
- Reviewed all 35 files in slice 14, but concentrated deep review on the largest/most DB-active ones: MispObject.php, Taxonomy.php, GalaxiesController.php, AuditLog.php, ContextExport.php, EventGraphTool.php, JSONConverterTool.php, XMLConverterTool.php, Module_blocklist_action.php. Files skipped after a quick pass (no loop-centred DB work found, or loops over small fixed/in-memory collections only): SendEmail.php, Module_send_mail.php, Module_filter_timestamp.php, SightingsController.php, LogsController.php, Sightingdb.php, JSONConverterTool.php's other loops, FileAccessTool.php, Job.php, Post.php, CryptographicKeysController.php, CorrelationRulesController.php, EventTemplateDependencies.php, Module_add_to_warninglist.php, ObjectRelationship.php, UsersEvolutionWidget.php, ObjectTemplateElement.php, OrganisationShell.php, MispAdminResourceWidget.php, RestClientHistory.php, Module_tag_replacement_pap.php, EventWarningBehavior.php, Inbox.php, ObjectTemplateElementsController.php, TemplateTag.php. AdminShell.php and SearchPerformanceShell.php (30 and 25 loops respectively) were grep-scanned but not line-by-line read in full; they are admin/CLI diagnostic tools rather than request-hot paths so were deprioritized given the 6-candidate budget and stronger findings elsewhere. Could not verify exact largest-taxonomy entry counts (app/files/taxonomies not present in this worktree checkout) so the Taxonomy::__updateTags estimate is marked low confidence. The JSONConverterTool.php finding also looks like it may hide a correctness bug (wrong index used for RelatedAttribute assignment) in addition to the performance issue — flagged explicitly so a fix doesn't just optimize buggy behavior.


## Working one of these

The measurement recipe used for the galaxy fix, which any of these can reuse:

1. **Get a cold baseline.** Warm re-runs hide the cost - a second `cake Admin updateJSON` takes
   7s against 13m for the first, because the models short-circuit on unchanged versions. Build a
   scratch database from the *live* schema, not `INSTALL/MYSQL.sql` (that is the pre-migration
   baseline and is missing columns later migrations add):

   ```
   mysqldump --no-data misp > schema.sql
   mysql -e "CREATE DATABASE misp_cold"; mysql misp_cold < schema.sql
   ```

   Point `app/Config/database.php` at it, run the operation, restore the config with a shell
   `trap` so an interrupted run cannot leave the instance pointing at the scratch database.

2. **Fingerprint the output before and after.** A speed number is worthless without proof the
   result is unchanged. Use row counts plus an order-independent hash, so row ordering and
   auto-increment ids are free to differ:

   ```sql
   SELECT BIT_XOR(CONV(SUBSTRING(MD5(CONCAT_WS('|', col, col, ...)), 1, 15), 16, 10)) FROM t;
   ```

   Note the weakness: `BIT_XOR` cancels identical rows in pairs, so always pair it with the
   exact row count.

3. **Watch for identity assumptions.** The galaxy fix nearly broke on this: misp-galaxy ships
   1033 duplicate cluster uuids, 7 of them inside a single galaxy, so "insert in bulk, then map
   rows back by uuid" would have silently attached elements to the wrong cluster. Positional
   mapping off the auto-increment block was used instead. Before replacing a per-row save with a
   batch insert, check that whatever you plan to map rows back by is actually unique.

4. **Keep the failure semantics.** A per-row `save()` loop skips and logs one bad row; a batch
   insert fails the whole batch. Where the old behaviour mattered, fall back to row-at-a-time
   for a batch that fails, rather than dropping the batch.

5. **Check the callbacks before bypassing them.** `insertMulti` skips `beforeValidate`,
   `beforeSave` and `afterSave`. That was safe for galaxy clusters because ingestion already
   set `bulkEntry = true` (disabling the `afterSave` relation update) and passed
   `'validate' => false`. Several candidates in the refuted list died on exactly this point -
   their per-row saves were doing load-bearing work.

6. **If you map batch rows back by position, say why it is safe.** InnoDB allocates one
   contiguous block of auto-increment values to a single multi-row INSERT whose row count it
   knows up front (a "simple insert"), which holds under every `innodb_autoinc_lock_mode`
   setting. That is what makes `LAST_INSERT_ID() + offset` a valid mapping. It was confirmed
   empirically here on MariaDB 10.11 by the identical fingerprints, but state the guarantee
   explicitly in any PR that relies on it - it is the first thing a reviewer will question.
