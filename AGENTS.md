# AGENTS.md — particle-academy/prism-memory

Persistent context and semantic recall: vector storage, `remember`/`recall`, and
token-budget-aware retrieval on top of Prism.

> **Read [the shared guide](https://github.com/Particle-Academy/prism-parity/blob/main/docs/AGENTS.md)
> first** — the boundary, the gates, the binding decisions, the review skills.
> This file is only what is true of *this* repository.

## What this package owns

Storage and retrieval. **No UI** — a memory browser is a Fancy component
consuming this package. **No changes to `prism` core** — two things this package
would have liked from core are recorded at the bottom of the README as findings
rather than reached for, and that is the correct handling.

**It does not own the conversation.** `prism-harness` owns threads; this reads
them and stores derived representations. There is no dependency either way —
they compose through Prism's own message value objects, so you can have memory
without sessions or sessions without memory. Adding a dependency in either
direction to make something convenient removes that.

## Ordering is a cache property, not a presentation choice

**A `Recollection` is chronological, not score-ordered.** This looks like a
missed opportunity to rank and is load-bearing.

Anthropic and OpenAI both price a cached **prefix** — a byte-identical run from
the start of the request — at a fraction of a fresh one, and recalled memories
sit inside it. Score order is unstable by nature: two memories a thousandth of a
cosine apart swap when a third arrives, when the query is reworded, when a
recency weight ticks over. Same set, different order, different prefix, missed
cache — and the usage numbers then report the request got *more* expensive
without saying why.

A memory's position in time never changes, so adding one appends rather than
reshuffles, which is the single mutation a prefix cache survives.

Ties break on record id, because `occurred_at` genuinely ties when a tool loop
writes several memories inside one second.

`ranked()` exists for looking at. It is not for building a prompt with.

## The two-form trap

`withSystemPrompt($relevant->asContext())` — **not**
`withMessages($relevant->asMessages())->withPrompt(...)`.

Prism refuses a request carrying both an explicit message list and a prompt,
deliberately, because there is no defensible order to merge them in. That makes
the second form nasty in an unusual way: it works in development against an
empty store and throws the first time recall actually returns something. There
is a test pinning this so the guidance cannot quietly stop being true — keep it.

`asMessages()` returns **one** system message, not one per memory. Replaying
recollections as prior user and assistant turns tells the model a fragment was
the last thing said, which is untrue about the conversation and invites it to
answer the recalled question instead of the one being asked.

## Writes are deferred, and that is stated rather than hidden

Embedding is a provider round trip, so a record is written immediately and
embedded through the queue afterwards. **A memory is not recallable until its
vector arrives.** `pending()` is how a caller watches that; `synchronously()` is
how a caller who must read its own write opts out — visibly, at the call site.

Nothing may assume a queue worker exists. Laravel's default connection runs jobs
inline, so a fresh install behaves as though embedding were synchronous.

Writing is idempotent: the record id is a digest of content and role, so a
conversation re-recorded after a retry does not double the store. The same
sentence said by the user and echoed by the assistant stays two memories — a
claim and a confirmation are two different facts.

## Forgetting is a hard delete

"Forget this" is a request a person is entitled to make about what a system
knows of them, and a soft delete answers it with a row that still exists. Do not
convert `forget()` to a flag for convenience, and keep it returning a count —
that assertion is one somebody may later have to evidence, and a void return is
not evidence.

`forgetBefore()` cuts on when the remembered thing **occurred**, not when the
row was written. The store method is named `purgeOccurredBefore()` for that
reason; a bare `purge($collection, $before)` reads the other way and the
difference only ever surfaces as missing data.

Retention is off by default and expiry is enforced **on read**, so a memory past
its window is never recalled just because nothing pruned it.

## Fresh installs

`composer require` plus `php artisan migrate`. No Redis, no vector database, no
queue worker, no published config — and `tests/StoreConfigurationTest.php` reads
the **shipped** config to prove it. That test exists because a sibling package
shipped a broken default with a green suite; see decision
[0012](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0012-test-the-shipped-configuration.md).

## Gates

```sh
composer test && composer types && composer format
```

CI runs `tests`, `phpstan`, `formatting`, `require-checker`.
