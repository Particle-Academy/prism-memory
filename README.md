# Prism Memory

Persistent context and semantic recall for Laravel — vector storage, `remember`/`recall`, and
token-budget-aware retrieval on top of [Prism](https://github.com/Particle-Academy/prism).

> **Status: the first slice works end to end.** A vector store, a `remember`/`recall` pair, one
> storage driver, queued batch embedding, and forgetting. **Three questions are deliberately
> unanswered** — what gets stored, whether recall ever runs a model, and what forgetting means for
> derived memories. They are open on purpose and recorded below rather than settled quietly.

An agent's useful context outgrows its context window. `prism-harness` gives a conversation a
thread, and a thread replayed whole is the crudest possible memory: it grows without bound, costs
tokens linearly, and eventually stops fitting. This stores what was said and retrieves **only the
parts that matter now**.

```php
$memory = PrismMemory::for($user, scope: 'support');

$memory->remember($response->messages);

$relevant = $memory->recall('billing address', budget: 1500);

Prism::text()
    ->using(Provider::Anthropic, 'claude-sonnet-4-5')
    ->withSystemPrompt($instructions)
    ->withSystemPrompt($relevant->asContext())
    ->withPrompt($question)
    ->asText();
```

## Handing a recollection to Prism

**Use `withSystemPrompt($relevant->asContext())`.** Not
`withMessages($relevant->asMessages())->withPrompt(...)`.

Prism refuses a request carrying both an explicit message list and a prompt — deliberately, because
there is no defensible order to merge them in. That makes the second form a trap of an unusually
nasty shape: it works in development against an empty store, and throws the first time recall
actually returns something. There is a test pinning it so this guidance cannot quietly stop being
true.

`asMessages()` exists for callers assembling a whole message list themselves. It returns **one**
system message, not one per memory — replaying recollections as prior user and assistant turns
tells the model a fragment was the last thing said, which is a claim about the conversation that is
not true, and it invites the model to answer the recalled question instead of the one being asked.

## What it does not do

**No UI.** Storage and retrieval. A memory browser is a Fancy component consuming this package.

**No changes to `prism` core.** Two things this package would have liked from core are recorded at
the bottom of this file as findings rather than reached for.

**It does not own the conversation.** `prism-harness` owns threads. This reads them and stores
derived representations. There is no dependency on the harness either — the two compose through
Prism's own message value objects, so you can use memory without sessions, sessions without memory,
or both.

## Installation

```bash
composer require particle-academy/prism-memory
php artisan migrate
```

Nothing else. No Redis, no vector database, no queue worker, no published config — the package
works on a fresh install, and [there is a test](tests/StoreConfigurationTest.php) that reads the
*shipped* config to prove it. That test exists because `prism-harness` shipped a Redis default that
broke a fresh install on any machine without one, and its suite could not have caught it: every
case set the stores explicitly, so none of them exercised what an installing application receives.

## remember()

Takes `$response->messages` — the whole exchange including tool steps — or a string, or a single
message.

```php
$ids = $memory->remember($response->messages);
```

**It does not embed inside the request.** A record is written immediately and embedded afterwards
through the application's queue, because embedding is a provider round trip and doing it inline
means every turn waits for the previous turn's bookkeeping before it can answer.

The consequence is stated rather than hidden: **a memory is not recallable until its vector
arrives.** `$memory->pending()` is how you watch that, and `$memory->synchronously()` is how a
caller who must read its own write opts out — visibly, at the call site.

Nothing assumes a queue worker exists. Laravel's default connection runs jobs inline, so a fresh
install behaves as though embedding were synchronous, and an application with a worker gets the
latency back without changing a line.

A turn producing six memories costs **one** provider call, not six.

Writing is idempotent. The record id is derived from a digest of the content and the role, so a
conversation re-recorded after a retry does not double the store, and a duplicate cannot occupy two
slots in one recall's results. The same sentence said by the user and echoed by the assistant stays
two memories — a claim and a confirmation are two different facts about a conversation.

## recall()

```php
$relevant = $memory->recall(
    'billing address',
    budget: 1500,                                    // fill a token budget, not a fixed k
    weighting: new Weighting(relevance: 0.7, recency: 0.3),
    filter: ['role' => 'user'],
);
```

**Token budgets.** The caller knows their context window; a memory layer that returns five passages
whether or not they fit has left the hard part to them. `budget` fills up to an estimated token
count instead of taking a fixed number, and it does not stop at the first memory that does not fit
— that would hand back one memory where the budget had room for six.

**Relevance and recency are separate axes.** Pure similarity returns the most *similar* memory,
which is not the most *useful* one: "what is my billing address" matches every previous time the
address came up, including the one that has since been superseded. Weights are normalised, so a
`minScore` threshold means the same thing at any mix. The default is relevance alone.

Each result keeps its score **broken into parts** — `similarity`, `recency`, `score`. One blended
number tells you a memory ranked third and nothing about whether it was a close match that was old
or a weak match that was fresh, and those call for opposite fixes.

**Query embeddings are cached** for five minutes by default. It is the single biggest lever on
recall latency, because embedding the query is a network round trip in front of every turn, and
applications ask the same question far more often than seems likely.

## Prompt caching

Anthropic and OpenAI both price a cached prefix at a fraction of a fresh one, and both cache a
**prefix** — a byte-identical run from the start of the request. Recalled memories sit inside it.

So a `Recollection` is **chronological, not score-ordered**. Score order is unstable by nature: two
memories 0.001 of cosine apart swap places when a third arrives, when the query is reworded, when a
recency weight ticks over. The set can be identical and the order different, which produces a
different prefix, which misses the cache — and the usage numbers then say the request got *more*
expensive without saying why. A memory's position in time never changes, so adding a memory appends
rather than reshuffles, which is the one mutation a prefix cache survives.

Ties break on record id, because `occurred_at` genuinely ties when a tool loop writes several
memories inside one second.

`$relevant->digest()` fingerprints the exact bytes the recollection contributes. Log it, and a
change in the provider's cache-read token count stops being a mystery: either the digest moved and
the miss is explained, or it did not and the cause is elsewhere.

`ranked()` gives you score order, for looking at rather than for building a prompt.

**Where the caching story stops.** Placing an actual cache *breakpoint* is provider-specific in
Prism today — Anthropic takes `cacheType` through `providerOptions` on a message and there is no
portable notion of one. This package therefore gives you stable bytes and a way to see them change,
and leaves the breakpoint to you. Put memory *after* your static instructions and mark the static
block, so a change in recall cannot invalidate the part that never changes. See the findings below.

## Forgetting

```php
$memory->forget($ids);              // specific memories
$memory->forget();                  // this owner, this scope, all of it
$memory->forgetBefore($date);       // everything older than a cut-off
```

**A hard delete, not a flag.** "Forget this" is a request a person is entitled to make about what a
system knows of them, and a soft delete answers it with a row that still exists — the wrong answer
to give and an expensive one to be found giving. `forget()` returns how many rows went, because
that assertion is one somebody may later have to evidence and a void return is not evidence.

Retention (`memory.retention`) is off by default, and expiry is enforced **on read** — a memory
past its window is never recalled just because nothing has pruned it. Null is the default because a
package that quietly expires things is worse than one that keeps them: an agent that has forgotten
what it was told behaves exactly like an agent that was never told.

## Storage

Defaults to the database, because that is what every Laravel app already has.

Register a real vector database when you outgrow it:

```php
app(VectorStoreManager::class)->extend('pgvector', fn ($config, $app) => new PgVectorStore(...));
```

`prism-rag` resolves the same store through the same manager, so that is one registration for both.

**A store that reports itself volatile is refused**, loudly, at resolve time. `prism-harness` makes
the same check, and memory's version of the mistake is quieter still: a flushed memory store does
not error and does not degrade to a default. The agent simply stops knowing something a user told
it and carries on confidently, which from the outside is indistinguishable from never having been
told. Set `memory.require_durable` to false if a disposable working set is genuinely what you meant.

### How a search stays affordable

Not by reading fewer rows — by reading much smaller ones.

Each vector carries a 256-bit signature of its direction. A search ranks the whole collection on
those, then reads the full vectors of only the best few hundred and scores **those** exactly. Every
score you see is a real cosine; what is approximate is which memories were considered.

Measured, in [`tests/RetrievalQualityTest.php`](tests/RetrievalQualityTest.php), and reproduced by
running the suite:

| | |
|---|---|
| Bytes read per row while ranking, at 1536 dimensions | **64** vs 16,384 — 0.39% |
| Full vectors scored per search | capped at `candidates`, regardless of collection size |

Recall@8 against an exhaustive search, over 1000 memories whose true neighbours sit at cosine 0.61:

| Candidates | Recall |
|---|---|
| 8 | 31.8% |
| 32 | 72.4% |
| 128 | 100% |
| **256** (default) | **100%** |

### What is NOT proven

Recorded here rather than left for a green tick to be over-read.

- **This is not sublinear.** The ranking pass reads one small row per memory in the collection.
  Past roughly twenty thousand memories in a single collection, register a real vector database.
  Collections are per-owner-per-scope, so that ceiling is further away than it sounds.
- **Bucketed LSH was tried and rejected**, with numbers. The probability two vectors agree on one
  random hyperplane is `1 - θ/π`, and text embeddings put genuinely relevant pairs at a cosine
  around 0.4–0.6. Requiring a band of bits to agree is that raised to the width of the band:
  6 bands × 12 bits reads 1.9% of rows and recalls **18%**; loosening to 6 × 6 recalls 87% and
  reads **66%** of the collection, which is a full scan wearing an index's clothes. There is no
  setting that is both bounded and correct. Sublinear search over embeddings needs HNSW or IVF,
  which needs a real vector database — which is what the store contract is an interface for.
- **The recall figures above come from synthetic clustered vectors**, not from a real embedding
  model over real text. The shape of the curve should hold; the exact percentages are not a
  promise about your corpus.
- **Token budgets are an estimate.** The default `TokenCounter` is four characters to a token,
  which is wrong for code, wrong for JSON, wrong for languages without spaces, and wrong by a
  different amount per model family. Bind your own implementation of the contract when the budget
  has to hold exactly. It has not been validated against a real tokeniser.
- **Cosine guarantees its range, not its last bit.** Similarity is always within `[-1, 1]`; a
  vector's similarity to itself is *usually* exactly 1.0 and is not guaranteed to be. Do not build
  duplicate detection on `>= 1.0` — this package uses a content digest, where it belongs.
- **No provider has actually been billed.** Every test runs against a deterministic fake embedder.
  The batching, the space identity and the count guard are exercised; real provider behaviour is
  not.

### Round-tripping

The corpus category for round trips exists partly because of this package, so it is tested as a
first-class concern rather than an afterthought.

- Vectors are base64 of `pack('e*')` — IEEE 754 doubles, explicitly little-endian. Not float32,
  which loses nine significant digits and makes a stored memory score differently than it did when
  written, silently. Not JSON, which depends on `serialize_precision` — an ini setting the host
  application owns. Not `'d'`, which is machine byte order, and a database routinely outlives the
  machine that wrote to it.
- The float round-trip test asserts with `===` on values chosen to break a lossy implementation,
  and separately on the **bytes**, because `-0.0 === 0.0` is true in PHP and a value comparison
  cannot see a dropped sign bit.
- Metadata is restricted to scalars and null, and empty metadata is written as `{}` rather than
  `[]`. PHP cannot tell the two apart, so nothing inside PHP can catch that — it surfaces when
  `prism-ts` or `prism-py` reads the row and gets a list where a map belongs. That is finding F-3
  in the conformance corpus and this is the fix it recommends.
- Absent-vs-null, ints, floats, bools and enums each have a test asserting identity rather than
  presence.

Every guard in this package has been made to fail on purpose. The suite has also been run against
eight deliberate mutations of the implementation — float32 packing, a dropped `(object)` cast,
expiry not enforced, score ordering, `text()` instead of `content`, no embedding count guard, an
unbounded candidate slice, and a missing embedding-space filter — and each one turns it red.

## Configuration

`config/memory.php` is commented at length; the headline settings:

| Key | Default | |
|---|---|---|
| `embeddings.model` | `text-embedding-3-small` | changing it invalidates every stored vector |
| `recall.limit` | 8 | |
| `recall.overfetch` | 8 | candidates the store returns before weighting picks |
| `recall.query_cache` | 300 | seconds an identical query's embedding is reused |
| `retention` | `null` | keep until deliberately removed |
| `store` | `database` | |
| `drivers.database.index.strategy` | `signature` | or `exact` |
| `drivers.database.index.candidates` | 256 | see the recall table above |

**Changing the embedding model is not a free upgrade.** Every memory written under the previous one
lives in a different coordinate system, and a similarity computed across the two is a number
without a meaning. The store refuses the comparison and says so, because the alternative symptom is
recall getting *worse* — not empty, not broken — and being blamed on the embeddings.

## The three open questions

These are not gaps. They are decisions that bind the ecosystem, and
[decision 0008](https://github.com/Particle-Academy/prism-parity/blob/specs/ecosystem-packages/docs/decisions/0008-consensus-among-agents.md)
says an agent that finds an open question in its spec raises it rather than resolving it privately.

**What is stored — turns, summaries, or extracted facts?** This slice stores **observations**: text
that was said, with its provenance. That is the substrate all three candidate answers share, so
nothing stored today has to move when the question is answered. `MemoryKind` has exactly one case
for that reason — adding `Summary` and `Fact` before the decision would settle it by accident.

**Does recall ever run a model?** Today: no, beyond the embedding call semantic search requires by
definition. Neither `remember()` nor `recall()` is a billable *generative* operation, and if that
changes it has to change visibly in the signature rather than inside a config flag.

**Forgetting.** A delete path exists and is hard rather than soft, because a memory package without
one is a compliance problem waiting to be found by somebody else. What is open is what forgetting
*means* for derived memories — a summary containing a fact whose source was erased — and that
cannot be answered before the first question is.

**Tool results** are currently not remembered, and that is part of the first question rather than a
separate one. A tool result is often the most factual thing in a turn; it is also structured data
whose useful memory form is probably not the raw payload.

## Findings against `prism` core

Filed rather than reached for. Core stays a provider API shuttle.

**`Embedding::toArray()` and `Embedding::fromArray()` do not compose.** `toArray()` emits
`['embedding' => [...]]` and `fromArray()` takes the bare list, so
`Embedding::fromArray($e->toArray())` builds an embedding whose components are a single nested
array. It fails at the first arithmetic rather than at the round trip. Worked around here by never
using the pair — this package converts through its own `Vector`.

**`Embedding::$embedding` is typed `array<int, int|string|float>` and is not readonly.** Numeric
strings from a provider are inside the contract, so every consumer has to normalise. Done here in
`Vector::of()`, which also refuses NAN, INF and non-numeric strings — a single NAN component makes
every similarity computed against that record NAN, and because NAN comparisons are always false the
record silently stops being retrievable rather than failing.

**There is no portable notion of a prompt-cache breakpoint.** `cacheType` is Anthropic-specific and
rides in `providerOptions`. A memory layer can keep its own bytes stable but cannot tell a provider
where the cacheable prefix ends, so the one thing that would make cache-aware memory automatic is
the one thing it cannot do. This is a cross-cutting question — `prism-harness` threads feed the
same prefix — and is escalated rather than worked around.

**`UserMessage::text()` is not the message's text.** The constructor appends a `Text` part built
from `content`, and `text()` concatenates every part — so on a message carrying its own parts it
returns the extra parts *plus* the content. This package reads `->content`. It is the same
constructor trap that doubled thread messages in `prism-harness`, met from a different direction.

## License

MIT.
