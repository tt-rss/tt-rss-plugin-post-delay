drop table if exists ttrss_plugin_reddit_delay_cache;

create table ttrss_plugin_reddit_delay_cache (id integer not null primary key auto_increment,
   feed_id integer not null REFERENCES ttrss_feeds(id) on DELETE cascade,
   link text not null,
   item text not NULL,
   orig_ts datetime not null) ENGINE=InnoDB DEFAULT CHARSET=UTF8;

create index ttrss_plugin_reddit_delay_cache_link_idx on ttrss_plugin_reddit_delay_cache(link);
create index ttrss_plugin_reddit_delay_cache_feed_id_idx on ttrss_plugin_reddit_delay_cache(feed_id);

create unique index ttrss_plugin_reddit_delay_cache_idx on ttrss_plugin_reddit_delay_cache(feed_id, link);
