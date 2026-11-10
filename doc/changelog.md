# Change log

## Upcoming Changes

 - **Bug Fix:** loading failed with a timeout (and HTTP 500 from NCBI) because consortium papers carry thousands of authors: summaries are now loaded in chunks of 20 with retry, long author lists are shortened (first 20, the authors of the search filter, last author), and page views never wait for PubMed anymore (refresh runs in the background via WP-Cron).
 - **New Feature:** the cache duration is configurable in the admin page (hours, default 8).

## v 1.1.0
released **2026-11-10**, including:
 - **New Feature:** PubMed blocks automated access to its web pages (reCAPTCHA), so the list is now loaded via the official NCBI E-utilities API. Collections are configured in the new admin page "Beiträge > PubMed (KornSW)" (internal id, search filter with legend, optional external link, exclusions via a compact hit list with keyword highlighting, optional NCBI API key). `[include_pubmed collection="..."]` refers to the internal id - after the update, create a collection per existing shortcode (using the old id keeps the shortcodes unchanged).
 - **New Feature:** persistent cache - the last good list is served indefinitely while PubMed is unreachable, the admin gets an alarm mail (and a mail on recovery), editors see a notice on the page.
 - **Bug Fix:** the attribute `highlightAuthor` now works (was ignored due to lower-cased attribute names).


## v 1.0.3
released **2026-10-23**, including:
 - moved **MVP** from internal Repo to here...


## v 1.0.2
