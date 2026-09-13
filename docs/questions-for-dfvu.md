# Questions for DFVU (kadrovska@dfvu.org)

Sent on: _(fill in)_

1. When a landing disappears from `GET /landings`, should its testimonials be hidden, kept as-is, or deleted? (Current assumption: the landing is soft-deleted and hidden; testimonials are preserved.)
2. For "random" ratings — should the admin API return an already-resolved value, or does the landing page resolve it at render time? (Current assumption: we store `NULL` and return a `rating_display` resolved between 4.0 and 5.0 per response, so either consumer works.)
3. Are the localised `title`/`description` fields needed in the admin UI, or is the English master's text enough for the product list? (Current assumption: stored for every landing, searched on the master's.)
4. Is there a preferred thumbnail size or aspect ratio for the list view? (Current assumption: 300 px on the longest side, aspect preserved.)
