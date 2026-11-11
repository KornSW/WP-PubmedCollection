# Change log

## Upcoming Changes

*(none)*

## v 1.5.0
released **2026-11-11**, including:
 - **New Feature:** two-level model instead of a live search result: each collection keeps a locally stored list. Level 1 is the auto import (WP-Cron, every N days, default 3): publications newly added to PubMed that match the auto import query are imported and announced by a review mail with direct links. Level 2 is the admin UI with the tabs "Liste" / "Ausgeschlossen" (search by PMID or full text, bulk exclude / restore, deep link `&pmid=`), "Manuell hinzufügen" (live search with three modes: PMIDs, query builder without PubMed syntax, raw query; newest 30 per page, take over selected hits) and "Einstellungen". Page views never query PubMed. Existing collections are migrated (filter -> auto import query, cached hits -> list, exclusions -> excluded list).
 - **New Feature:** the highlighted author (blue / bold on the website) is a setting per collection (comma separated names; existing collections keep "Korn S"); the shortcode attribute `highlightAuthor` still overrides it. Highlighted authors and full-name authors of the query (`[fau]`) are kept when long author lists are shortened.


## v 1.4.0
released **2026-11-10**, including:
 - **New Feature:** the hit list in the admin page shows how PubMed interpreted the search filter (query translation, total hit count, warnings about ignored phrases or unknown fields), to find out why e.g. a `NOT` does not take effect.


## v 1.3.1
released **2026-11-10**, including:
 - Merge branch 'master' of https://github.com/KornSW/WP-PubmedCollection
 - optimierung api aufruf


## v 1.3.0
released **2026-11-10**, including:
 - **New Feature:** diagnostics page ("Diagnose & Protokoll"): environment overview, on-demand connection tests to NCBI (DNS, TCP per address, HTTPS via WordPress and via cURL forced to IPv4 / IPv6 with verbose log, comparison targets, automatic assessment), test mail, and a log of the latest 50 refresh attempts. Optional setting "Nur IPv4" as workaround for servers with a broken IPv6 route. Error messages now include the failing chunk and the elapsed time.
 - **Bug Fix:** NCBI's gateway aborts esummary requests after ~20 s (HTTP 500 "error forwarding request") when several consortium papers meet in one chunk: chunks now start with 10 PMIDs and are split adaptively on timeouts / HTTP 5xx down to single PMIDs; a single PMID that still fails is skipped (its cached version is kept) instead of failing the whole list.


## v 1.2.0
released **2026-11-10**, including:
 - **New Feature:** the cache duration is configurable in the admin page (hours, default 8).
 - **Bug Fix:** loading failed with a timeout (and HTTP 500 from NCBI) because consortium papers carry thousands of authors: summaries are now loaded in chunks of 20 with retry, long author lists are shortened (first 20, the authors of the search filter, last author), and page views never wait for PubMed anymore (refresh runs in the background via WP-Cron).


## v 1.1.0
released **2026-11-10**, including:
 - **New Feature:** PubMed blocks automated access to its web pages (reCAPTCHA), so the list is now loaded via the official NCBI E-utilities API. Collections are configured in the new admin page "Beiträge > PubMed (KornSW)" (internal id, search filter with legend, optional external link, exclusions via a compact hit list with keyword highlighting, optional NCBI API key). `[include_pubmed collection="..."]` refers to the internal id - after the update, create a collection per existing shortcode (using the old id keeps the shortcodes unchanged).
 - **New Feature:** persistent cache - the last good list is served indefinitely while PubMed is unreachable, the admin gets an alarm mail (and a mail on recovery), editors see a notice on the page.
 - **Bug Fix:** the attribute `highlightAuthor` now works (was ignored due to lower-cased attribute names).


## v 1.0.3
released **2026-10-23**, including:
 - moved **MVP** from internal Repo to here...


## v 1.0.2
