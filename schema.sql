drop table ttrss_plugin_reddit_delay_cache;

create table ttrss_plugin_reddit_delay_cache (id serial not null primary key,
   feed_id integer not null REFERENCES ttrss_feeds(id) on DELETE cascade,
   link text not null,
   item text not NULL,
   orig_ts timestamp not null);

create unique index ttrss_plugin_reddit_delay_cache_idx on ttrss_plugin_reddit_delay_cache(feed_id, link);
create index ttrss_plugin_reddit_delay_cache_link_idx on ttrss_plugin_reddit_delay_cache(link);
create index ttrss_plugin_reddit_delay_cache_feed_id_idx on ttrss_plugin_reddit_delay_cache(feed_id);
