<?php
class Post_Delay extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return array(null,
			"Delays posts in RSS feeds",
			"fox",
			false,
			"https://community.tt-rss.org/t/suggestions-for-how-to-delay-a-feed/4425");
	}

	function init($host) {
		$this->host = $host;

		$migrations = new Db_Migrations();
		$migrations->initialize_for_plugin($this);
		if ($migrations->migrate()) {
			$host->add_hook(PluginHost::HOOK_FEED_FETCHED, $this);
			$host->add_hook(PluginHost::HOOK_PREFS_TAB, $this);
			$host->add_hook(PluginHost::HOOK_PREFS_EDIT_FEED, $this);
			$host->add_hook(PluginHost::HOOK_PREFS_SAVE_FEED, $this);
		}
	}

	/**
	 * @param int $feed_id
	 * @param string $link
	 * @return ORM|false
	 */
	private function cache_exists(int $feed_id, string $link) {
		$entry = ORM::for_table('ttrss_plugin_post_delay_cache')
			->where('feed_id', $feed_id)
			->where('link', $link)
			->find_one();

		return $entry;
	}

	private function cache_push(int $feed_id, FeedItem $item, DOMNode $node) : bool {
		$entry = ORM::for_table('ttrss_plugin_post_delay_cache')->create();

		$entry->set([
			'feed_id' => $feed_id,
			'link' => $item->get_link(),
			'comments' => $item->get_comments_url(),
			'item' => $node->ownerDocument->saveXML($node),
			'orig_ts' => date("Y-m-d H:i:s", $item->get_date())
		]);

		return $entry->save();
	}

	/** force-remove all leftover data from cache */
	private function cache_cleanup() : void {
		$max_days = (int) Config::get(Config::CACHE_MAX_DAYS);

		if (Config::get(Config::DB_TYPE) == "pgsql") {
			$interval_query = "orig_ts < NOW() - INTERVAL '$max_days days'";
		} else /*if (Config::get(Config::DB_TYPE) == "mysql") */ {
			$interval_query = "orig_ts < DATE_SUB(NOW(), INTERVAL $max_days DAY)";
		}

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_post_delay_cache
			WHERE $interval_query");
		$sth->execute([]);
	}

	/**
	 * @param int $feed_id
	 * @param int $delay
	 * @param DOMDocument $doc
	 * @param DOMXPath $xpath
	 * @return array<int>
	 * @throws PDOException
	 */
	private function cache_pull_older(int $feed_id, int $delay, DOMDocument $doc, DOMXPath $xpath) : array {
		$skip_removed = $this->host->get($this, "skip_removed");

		if (Config::get(Config::DB_TYPE) == "pgsql") {
			$interval_query = "(orig_ts < NOW() - INTERVAL '$delay hours')";
		} else /*if (Config::get(Config::DB_TYPE) == "mysql") */ {
			$interval_query = "(orig_ts < DATE_SUB(NOW(), INTERVAL $delay HOUR))";
		}

		$entries = ORM::for_table('ttrss_plugin_post_delay_cache')
			->where('feed_id', $feed_id)
			->where_raw($interval_query)
			->find_many();

		$target = $xpath->query("//atom:feed|//channel")->item(0);

		$num_pulled = 0;
		$num_deleted = 0;
		$num_skipped = 0;

		foreach ($entries as $entry) {
			$skip_post = false;
			$delete_post = false;

			Debug::log(sprintf("[delay] pulling from cache: %s [c:%s] [%s]",
								$entry->link, $entry->comments, $entry->orig_ts), Debug::LOG_EXTENDED);

			if ($skip_removed) {
				UrlHelper::fetch(["url" => $entry->item]);

				if (UrlHelper::$fetch_last_error_code >= 400) {
					$skip_post = "[link:4xx-or-5xx]";
					$delete_post = true;
				}

				if (strlen($entry->comments) > 0) {
					UrlHelper::fetch(["url" => $entry->comments]);

					if (UrlHelper::$fetch_last_error_code >= 400) {
						$skip_post = "[comments:4xx-or-5xx]";
						$delete_post = true;
					}
				}
			}

			if (!$skip_post) {
				$tmpdoc = new DOMDocument();

				if ($tmpdoc->loadXML($entry->item)) {
					$tmpxpath = new DOMXPath($tmpdoc);
					$imported_entry = $doc->importNode($tmpxpath->query("//entry|//item")->item(0), true);
					$target->appendChild($imported_entry);

					$entry->delete();

					++$num_pulled;
				}
			} else {
				if ($delete_post) {
					Debug::log(sprintf("[delay] deleting %s: %s [%s]",
						$skip_post, $entry->link, $entry->orig_ts), Debug::LOG_EXTENDED);

					$entry->delete();

					++$num_deleted;

				} else {
					Debug::log(sprintf("[delay] skipping %s: %s [%s]",
						$skip_post,  $entry->link, $entry->orig_ts), Debug::LOG_EXTENDED);

					++$num_skipped;
				}
			}
		}

		return [$num_pulled, $num_deleted, $num_skipped];
	}

	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed_id) {
		$delay = (int) $this->host->get($this, "delay");
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");

		if (in_array($feed_id, $enabled_feeds) && $delay > 0) {

			$doc = new DOMDocument();

			if ($doc->loadXML($feed_data)) {
				$xpath = new DOMXPath($doc);

				// refs. FeedParser->init()
				// some of those like dc: are needed for timestamps
				$xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
				$xpath->registerNamespace('atom03', 'http://purl.org/atom/ns#');
				$xpath->registerNamespace('media', 'http://search.yahoo.com/mrss/');
				$xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
				$xpath->registerNamespace('slash', 'http://purl.org/rss/1.0/modules/slash/');
				$xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
				$xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
				$xpath->registerNamespace('thread', 'http://purl.org/syndication/thread/1.0');

				$entries = $xpath->query("//atom:entry|//channel/item");

				$num_delayed = 0;

				foreach ($entries as $entry) {
					/** @var DOMElement $entry */

					if ($entry->tagName == "item")
						$item = new FeedItem_RSS($entry, $doc, $xpath);
					else
						$item = new FeedItem_Atom($entry, $doc, $xpath);

					$cutoff_timestamp = time() - ($delay * 60 * 60);

					// get_date() may not return anything for broken feeds
					$item_timestamp = (int)$item->get_date();

					if (!$item_timestamp) $item_timestamp = time();

					if ($item_timestamp > $cutoff_timestamp) {
						Debug::log(sprintf("[delay] %s [c: %s] [%s vs %s]",
							$item->get_link(),
							$item->get_comments_url(),
							date("Y-m-d H:i:s", $item->get_date()),
							date("Y-m-d H:i:s", $cutoff_timestamp)), Debug::LOG_EXTENDED);

						if ($this->cache_exists($feed_id, $item->get_link())) {
							Debug::log("[delay] already stored.", Debug::LOG_EXTENDED);
						} else {
							Debug::log("[delay] storing in the backlog.", Debug::LOG_EXTENDED);

							$this->cache_push($feed_id, $item, $entry);
						}

						$entry->parentNode->removeChild($entry);
						++$num_delayed;
					}
				}

				list ($num_pulled, $num_deleted, $num_skipped) = $this->cache_pull_older($feed_id, $delay, $doc, $xpath);

				Debug::log("[delay] {$num_delayed} delayed, {$num_pulled} pulled, {$num_deleted} deleted, {$num_skipped} skipped.",
					Debug::LOG_VERBOSE);

				$this->cache_cleanup();

				return $doc->saveXML();
			}
		}

		return $feed_data;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

			$delay = (int) $this->host->get($this, "delay");
			$skip_removed = $this->host->get($this, "skip_removed");
		?>

		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>extension</i> <?= __('Delay posts settings (post_delay)') ?>">

			<form dojoType='dijit.form.Form'>

				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						Notify.progress('Saving data...', true);
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<fieldset class='narrow'>
					<label>
						<?= __("Delay posts by this amount (hours, 0 - disables):") ?>
					</label>
					<input dojoType="dijit.form.NumberSpinner" name="delay" value="<?= $delay ?>">
				</fieldset>

				<fieldset class='narrow'>
					<label class='checkbox'>
						<?= \Controls\checkbox_tag("skip_removed", $skip_removed) ?>
						<?= __("Skip removed and deleted posts") ?>
					</label>
				</fieldset>

				<hr/>
				<?= \Controls\submit_tag(__("Save")) ?>
			</form>

			<hr/>

			<?php
				$sth = $this->pdo->prepare("SELECT COUNT(c.id) AS count
					FROM ttrss_plugin_post_delay_cache c, ttrss_feeds f
					WHERE f.id = c.feed_id AND f.owner_uid = ?");
				$sth->execute([$_SESSION["uid"]]);

				$row = $sth->fetch();
				$total_delayed = $row["count"];
			?>

			<h3><?= T_sprintf("Currently delayed posts (by feed, %d total):", $total_delayed) ?></h3>

			<?php
				$sth = $this->pdo->prepare("SELECT COUNT(c.id) AS count, f.title, f.id AS feed_id
					FROM ttrss_plugin_post_delay_cache c, ttrss_feeds f
					WHERE f.id = c.feed_id AND f.owner_uid = ?
					GROUP BY f.title, f.id
					ORDER BY count DESC, f.title");
				$sth->execute([$_SESSION["uid"]]);
			?>

			<ul class="panel panel-scrollable">
			<?php while ($row = $sth->fetch()) { ?>
				<li>
					<i class='material-icons'>rss_feed</i>
					<a href='#'	onclick="CommonDialogs.editFeed(<?= $row["feed_id"] ?>)">
						<?= $row["title"] ?>
					</a>(<?= $row["count"] ?>)
				</li>
			<?php } ?>
			</ul>

			<?php
			$enabled_feeds = $this->filter_unknown_feeds($this->host->get_array($this, "enabled_feeds"));

			$this->host->set($this, 'enabled_feeds', array_column($enabled_feeds, 'id'));

			if (count($enabled_feeds) > 0) { ?>
				<h3><?= __("Enabled for these feeds:") ?></h3>

				<ul class='panel panel-scrollable list list-unstyled'>
				<?php foreach ($enabled_feeds as $f) { ?>
					<li><i class='material-icons'>rss_feed</i> <a href='#' onclick="CommonDialogs.editFeed(<?= $f['id'] ?>)">
						<?= htmlspecialchars(Feeds::_get_title($f['id'], $f['owner_uid'])) ?></a></li>
				<?php } ?>
				</ul>
			<?php	} ?>
		</div>

		<?php
	}

	function save() : void {
		$delay = (int) ($_POST["delay"] ?? 0);
		$skip_removed = checkbox_to_sql_bool($_POST["skip_removed"] ?? "");

		$this->host->set($this, "delay", $delay);
		$this->host->set($this, "skip_removed", $skip_removed);

		echo __("Configuration saved");
	}

	function hook_prefs_edit_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");
		?>
		<header><?= $this->__("Delay posts") ?></header>
		<section>
			<fieldset>
				<label class='checkbox'>
					<?= \Controls\checkbox_tag("delay_posts_enabled", in_array($feed_id, $enabled_feeds)) ?>
					<?= $this->__('Enable for this feed') ?></label>
			</fieldset>
		</section>
		<?php
	}

	function hook_prefs_save_feed($feed_id) {
		$enabled_feeds = $this->host->get_array($this, "enabled_feeds");

		$enable = checkbox_to_sql_bool($_POST["delay_posts_enabled"] ?? "");
		$key = array_search($feed_id, $enabled_feeds);

		if ($enable) {
			if ($key === false) {
				array_push($enabled_feeds, $feed_id);
			}
		} else {
			if ($key !== false) {
				unset($enabled_feeds[$key]);
			}
		}

		$this->host->set($this, "enabled_feeds", $enabled_feeds);
	}

	/**
	 * @param array<int> $enabled_feeds
	 * @return array<int, array{id: int, owner_uid: int}>
	 * @throws PDOException
	 */
	private function filter_unknown_feeds(array $enabled_feeds) : array {
		return ORM::for_table('ttrss_feeds')
			->select_many('id', 'owner_uid')
			->where_in('id', $enabled_feeds)
			->find_array();
	}

	function api_version() {
		return 2;
	}

}
