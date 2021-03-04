## This plugin delays posts in Reddit feeds by a configurable delay (in hours)

Optionally, it can filter out posts that were deleted (i.e. by Reddit moderators), while delayed.

https://community.tt-rss.org/t/suggestions-for-how-to-delay-a-feed/4425

### Installation

- Git clone to `plugins.local/reddit_delay`
- Set delay amount in Preferences &rarr; Feeds &rarr; Plugins

### Notes

- Posts are stored in the backlog no longer than `Config::CACHE_MAX_DAYS`.
- If Reddit JSON API returns 404 for the post, it is considered deleted.
