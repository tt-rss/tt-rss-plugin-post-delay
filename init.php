<?php
class Reddit_Delay extends Plugin {

	/** @var PluginHost $host */
	private $host;

	function about() {
		return array(1.0,
			"Delay posts in Reddit feeds",
			"fox");
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook(PluginHost::HOOK_FEED_FETCHED, $this);
		$host->add_hook(PluginHost::HOOK_PREFS_TAB, $this);
	}

	private function cache_exists(int $feed_id, string $link) {
		$sth = $this->pdo->prepare("SELECT id FROM ttrss_plugin_reddit_delay_cache WHERE feed_id = ? AND link = ?");
		$sth->execute([$feed_id, $link]);

		if ($sth->fetch()) {
			return true;
		}

		return false;
	}

	private function cache_push(int $feed_id, FeedItem $item, DOMNode $entry) {
		$entry_xml = $entry->ownerDocument->saveXML($entry);

		$sth = $this->pdo->prepare("INSERT INTO ttrss_plugin_reddit_delay_cache
				(feed_id, link, item, orig_ts)
			VALUES
				(?, ?, ?, ?)");

		$sth->execute([$feed_id, $item->get_link(), $entry_xml, date("Y-m-d H:i:s", $item->get_date())]);
	}

	// force-remove all leftover data from cache
	private function cache_cleanup() {
		$max_days = (int) Config::get(Config::CACHE_MAX_DAYS);

		$sth = $this->pdo->prepare("DELETE FROM ttrss_plugin_reddit_delay_cache
			WHERE orig_ts < NOW() - INTERVAL '$max_days days'");
		$sth->execute([]);
	}

	private function cache_pull_older(int $feed_id, int $delay, DOMDocument $doc, DOMXPath $xpath) {
		$skip_removed = $this->host->get($this, "skip_removed");

		$sth = $this->pdo->prepare("SELECT id, link, item, orig_ts
			FROM ttrss_plugin_reddit_delay_cache
			WHERE feed_id = ? AND orig_ts < NOW() - INTERVAL '$delay hours'");
		$sth->execute([$feed_id]);

		$dsth = $this->pdo->prepare("DELETE FROM ttrss_plugin_reddit_delay_cache WHERE id = ?");

		$target = $xpath->query("//atom:feed")->item(0);

		$num_pulled = 0;

		while ($row = $sth->fetch()) {
			$skip_post = false;

			Debug::log(sprintf("[delay] pulling from cache: %s [%s]",
				$row["link"], $row["orig_ts"]), Debug::$LOG_EXTENDED);

			if ($skip_removed) {
				$matches = [];

				if (preg_match("/\/comments\/([^\/]+)\//", $row["link"], $matches)) {
					$post_id = $matches[1];
					$post_api_url = "https://api.reddit.com/api/info/?id=t3_${post_id}";

					Debug::log("[delay] API url: ${post_api_url}", Debug::$LOG_EXTENDED);

					$json_data = UrlHelper::fetch(["url" => $post_api_url]);

					if ($json_data) {
						$json = json_decode($json_data, true);

						if ($json) {
							if (count($json["data"]["children"]) == 0) {
								$skip_post = "[json:no-children]";
							} else {
								foreach ($json["data"]["children"] as $child) {
									if (empty($child["data"]["is_robot_indexable"])) {
										$skip_post = "[removed]";
									} else if (empty($child["data"]["author"])) {
										$skip_post = "[deleted]";
									}
								}
							}
						}
					} else if (UrlHelper::$fetch_last_error_code == 404) {
						$skip_post = "[json:404]";
					}
				}
			}

			if (!$skip_post) {
				$tmpdoc = new DOMDocument();

				if ($tmpdoc->loadXML($row["item"])) {
					$tmpxpath = new DOMXPath($tmpdoc);
					$imported_entry = $doc->importNode($tmpxpath->query("//entry")->item(0), true);
					$target->appendChild($imported_entry);

					$dsth->execute([$row["id"]]);

					++$num_pulled;
				}
			} else {
				Debug::log(sprintf("[delay] skipping %s: %s [%s]",
					$skip_post, $row["link"], $row["orig_ts"]), Debug::$LOG_EXTENDED);
			}
		}

		return $num_pulled;
	}

	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed_id) {
		$delay = (int) $this->host->get($this, "delay");

		if (strpos($fetch_url, ".reddit.com") !== false && $delay > 0) {

			$doc = new DOMDocument();

			if ($doc->loadXML($feed_data)) {
				$xpath = new DOMXPath($doc);
				$xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

				$entries = $xpath->query("//atom:entry");

				$num_delayed = 0;

				foreach ($entries as $entry) {

					$item = new FeedItem_Atom($entry, $doc, $xpath);

					$cutoff_timestamp = time() - ($delay * 60 * 60);

					Debug::log(sprintf("[delay] %s [%s vs %s]",
									$item->get_link(),
									date("Y-m-d H:i:s", $item->get_date()),
									date("Y-m-d H:i:s", $cutoff_timestamp)), Debug::$LOG_EXTENDED);

					if ($item->get_date() > $cutoff_timestamp) {
						if ($this->cache_exists($feed_id, $item->get_link())) {
							Debug::log("[delay] article is too new, already cached.", Debug::$LOG_EXTENDED);
						} else {
							Debug::log("[delay] article is too new, delaying it.", Debug::$LOG_EXTENDED);

							$this->cache_push($feed_id, $item, $entry);
						}

						$entry->parentNode->removeChild($entry);
						++$num_delayed;
					}
				}

				$num_pulled = $this->cache_pull_older($feed_id, $delay, $doc, $xpath);

				Debug::log("[delay] delayed ${num_delayed} reddit posts, pulled ${num_pulled} from backlog.", Debug::$LOG_VERBOSE);

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
			title="<i class='material-icons'>extension</i> <?= __('Delay Reddit posts (reddit_delay)') ?>">

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
		</div>

		<?php
	}

	function save() {
		$delay = (int) ($_POST["delay"] ?? 0);
		$skip_removed = checkbox_to_sql_bool($_POST["skip_removed"] ?? "");

		$this->host->set($this, "delay", $delay);
		$this->host->set($this, "skip_removed", $skip_removed);

		echo __("Configuration saved");
	}

	function api_version() {
		return 2;
	}

}
