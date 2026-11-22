# Command line

Eight command groups. Everything the admin screens can do, plus the things that
should not be done in a web request at all — indexing a large site, migrating a
network — because there is no web-server timeout to fight here.

```
wp braintum-search test        is the connection usable, and if not, why
wp braintum-search status      what the index and queue currently hold
wp braintum-search schema      compare and apply the schema contract
wp braintum-search index       full and resumable indexing
wp braintum-search rebuild     build a replacement index, then switch to it
wp braintum-search history     what has been indexed, and what failed
wp braintum-search backends    move between search backends
wp braintum-search network     the same, across a multisite network
```

Every command reports the same facts as the admin screen, because both read the
same services. `wp help braintum-search <command>` has the full argument list;
everything below is the shape of the work rather than a reprint of it.

## Getting connected

```bash
wp braintum-search test
wp braintum-search test --format=json
wp braintum-search status
```

`test` runs the same multi-step diagnosis the connection screen shows: address
validation, reachability, authentication, Solr version and mode, and the schema
comparison. Each step reports independently, so "authenticated but the schema is
incomplete" is distinguishable from "cannot connect".

## Schema

```bash
wp braintum-search schema            # what is missing
wp braintum-search schema apply      # apply it, after a confirmation
wp braintum-search schema export     # print the XML and change nothing
```

`apply` only ever adds `se_`-prefixed fields, never replaces or deletes one, and
stops at the first failure rather than continuing. `export` is the route for a Solr
you cannot modify from here — a managed service, or credentials that can read but
not write.

A backend that manages its own schema reports so and these become no-ops that say
why. See [schema](schema.md).

## Indexing

```bash
wp braintum-search index
wp braintum-search index --post-type=post,page
wp braintum-search index progress
wp braintum-search index resume
wp braintum-search index cancel
```

Resumable by design: progress is a stored cursor, so an interrupted run continues
from the last ID it finished rather than starting over. Batch size adapts to how
long each batch took and how much memory it used.

**This writes over the index that is answering searches.** On a large site, build
a replacement instead.

## Building a replacement index

```bash
wp braintum-search rebuild start
wp braintum-search rebuild start --target=wordpress_v2
wp braintum-search rebuild verify
wp braintum-search rebuild switch
wp braintum-search rebuild discard
wp braintum-search rebuild drop-previous
```

`start` runs to completion in this process and then verifies. `switch` refuses
until the new index has been checked against what WordPress holds — that refusal is
the point of the whole mechanism, so there is no flag to skip it.

The previous index is never deleted for you. `drop-previous` is how you say you no
longer need the way back. See [indexing](indexing.md#building-a-replacement-index).

## History

```bash
wp braintum-search history
wp braintum-search history --limit=50
wp braintum-search history --failures
```

The last fifty runs, and the objects that failed inside them. This is the answer to
"search is missing some posts": a count tells you it happened, `--failures` tells
you to which.

## Changing backend

```bash
wp braintum-search backends status
wp braintum-search backends start --to=<backend>
wp braintum-search index
wp braintum-search backends finish
wp braintum-search backends abort
```

Between `start` and `finish`, indexing writes to the new backend while the old one
keeps answering visitors. `finish` refuses while the new backend holds noticeably
fewer documents than WordPress says it should; `abort` puts indexing back where
searches never left and deletes nothing.

## Networks

```bash
wp braintum-search network status
wp braintum-search network enable 2 3 4
wp braintum-search network enable --all
wp braintum-search network disable 7
wp braintum-search network index --pause=30
wp braintum-search network schema
```

`index` and `schema` walk the enabled sites one at a time, in a separate process
per site. A site that fails is reported and the walk continues, because one
unreachable site should not block every site after it.

`--pause` waits between sites. One Solr cluster serving a whole network has other
work to do. See [multisite](multisite.md).

## Exit codes and output

Every command exits non-zero on failure, so it composes with `&&` and with CI.
`--format=json` is available where a command reports structured facts, for the same
reason.

Two habits worth having on a large site:

```bash
# Index every site in a network overnight, gently, with a log to read afterwards.
wp braintum-search network index --pause=60 2>&1 | tee braintum-search-index.log

# Find out what failed without reading the whole log.
wp braintum-search history --failures
```

## Debugging

Set `BRAINTUM_SEARCH_DEBUG` in `wp-config.php` to record why individual documents
failed. Set `BRAINTUM_SEARCH_SYNC_INDEXING` to index synchronously instead of through
the queue, which makes a failure happen in front of you rather than in a background
job. Both are for diagnosis, not for production.
