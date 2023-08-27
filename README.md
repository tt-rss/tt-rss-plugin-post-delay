## This plugin delays posts in RSS feeds by a configurable delay (in hours)

Optionally, it can filter out posts that were deleted (either article or comment link goes nowhere)
while delayed.

https://community.tt-rss.org/t/suggestions-for-how-to-delay-a-feed/4425

### Installation

- Git clone to `plugins.local/post_delay`
- Set delay amount in Preferences &rarr; Feeds &rarr; Plugins

### Notes

- Posts are stored in the backlog no longer than `Config::CACHE_MAX_DAYS`.
