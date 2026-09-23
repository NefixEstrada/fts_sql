<!--
SPDX-FileCopyrightText: 2026 Néfix Estrada

SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Benchmark

The quality stage (DESIGN.md, "Background") indexes the fixed corpus —
5,000 Wikipedia opening paragraphs, a third each in Catalan, Spanish and
English, under `benchmark/corpus/` — through the platform's own interface
and scores query sets whose answers are known by construction: a
distinctive word of a document's title has to find that document in the
top ten, and a word the corpus does not hold has to find nothing.

```console
$ docker exec -u www-data <nextcloud-container> \
    php /var/www/html/apps-extra/fts_sql/benchmark/quality.php
```

It runs against whatever engine that instance uses, cleans up after
itself (everything is indexed under the `benchmark` provider and
removed), and ends with one JSON line — keep it under
`benchmark/results/` to compare a later change against today's
measurement (PostgreSQL 16: precision@10 0.9524, index 33 s).

The extraction stage measures the extractors on the same footing: the
same corpus, packed at run time into containers shaped like the real
applications write them, extracted through `ExtractionService` — no
database involved, so it also runs in `nix develop`:

```console
$ docker exec -u www-data <nextcloud-container> \
    php /var/www/html/apps-extra/fts_sql/benchmark/extraction.php
```

One batch scenario (a full corpus of corpus-sized documents — the shape
of a real indexing run) and one file per extractor and per boundary:
within the budget, over it (the sink fills and the walk stops early),
and past the entry read cap (the refusal boundary, documented rather
than hidden). The PDF adds its own scenarios — a many-page compressed
document, the same shape encrypted under an empty user password, and
the 756-page ISO 32000-1 the route decision measured, when its path is
passed (`--pdf=…`; the file is not in the repository). Peaks are
marginal, measured under the 512 MB ceiling Nextcloud documents.
Today's numbers: 0.9 ms per document in the batch, a 1 MiB-text docx
complete at +6 MiB peak, the over-budget docx cut at the budget with
+14.7 MiB, and the ISO at a ~70 MiB peak where the library route cost
704.3 MiB and a fatal — the memory claim, decided. On speed the host
decides which designed bound stops the walk: the dev shell reaches the
full 2 MiB budget cut in ~7 s, the slower container PHP meets the 10 s
wall-clock first with ~577 KiB extracted — either way the document is
findable by what survived, with the cause recorded
(`benchmark/results/2026-09-16-pdf.json` keeps both sides and both
hosts).

The scale and access-filter stages ("Not sequenced" in DESIGN.md) answer
what stays fast beyond the quality stage's 5,000: `benchmark/scale.php`
indexes `--count=N` corpus-cycled documents and scores the same known-by-
construction queries at that size (latency percentiles included), and
`benchmark/access.php` spreads documents over owners and groups so every
sampled viewer's total is computable by inclusion-exclusion — the token
EXISTS' cost and its fail-closed correctness under load, plus a viewer with
no token against anything that must find nothing:

```console
$ docker exec -u www-data <nextcloud-container> \
    php /var/www/html/apps-extra/fts_sql/benchmark/scale.php --count=100000
$ docker exec -u www-data <nextcloud-container> \
    php /var/www/html/apps-extra/fts_sql/benchmark/access.php --count=20000
```
