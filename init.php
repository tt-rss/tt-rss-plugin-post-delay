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

	private function cache_pull_older(int $feed_id, int $delay, DOMDocument $doc, DOMXPath $xpath) {
		$sth = $this->pdo->prepare("SELECT id, link, item, orig_ts
			FROM ttrss_plugin_reddit_delay_cache
			WHERE feed_id = ? AND orig_ts < NOW() - INTERVAL '$delay hours'");
		$sth->execute([$feed_id]);

		$dsth = $this->pdo->prepare("DELETE FROM ttrss_plugin_reddit_delay_cache WHERE id = ?");

		$target = $xpath->query("//atom:feed")->item(0);

		while ($row = $sth->fetch()) {
			$reddit_json = UrlHelper::fetch(["url" => $row["link"] . "/.json"]);

			if ($reddit_json) {
				Debug::log(sprintf("[delay] pulling from cache: %s [%s]",
					$row["link"], $row["orig_ts"]), Debug::$LOG_VERBOSE);

				$tmpdoc = new DOMDocument();

				if ($tmpdoc->loadXML($row["item"])) {
					$tmpxpath = new DOMXPath($tmpdoc);

					$imported_entry = $doc->importNode($tmpxpath->query("//entry")->item(0), true);

					$dsth->execute([$row["id"]]);
				}
			} else {
				Debug::log(sprintf("[delay] json fetch failed, post deleted? removing: %s [%s]",
					$row["link"], $row["orig_ts"]), Debug::$LOG_VERBOSE);

				$dsth->execute([$row["id"]]);
			}

		}
	}

	function hook_feed_fetched($feed_data, $fetch_url, $owner_uid, $feed_id) {
		$delay = (int) $this->host->get($this, "delay");

		if (strpos($fetch_url, ".reddit.com") !== false) {

			$doc = new DOMDocument();

			if ($doc->loadXML($feed_data)) {
				$xpath = new DOMXPath($doc);
				$xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

				$entries = $xpath->query("//atom:entry");

				foreach ($entries as $entry) {

					$item = new FeedItem_Atom($entry, $doc, $xpath);

					$cutoff_timestamp = time() - ($delay * 60 * 60);

					Debug::log(sprintf("[delay] %s [%s vs %s]",
									$item->get_link(),
									date("Y-m-d H:i:s", $item->get_date()),
									date("Y-m-d H:i:s", $cutoff_timestamp)), Debug::$LOG_VERBOSE);

					if ($item->get_date() > $cutoff_timestamp) {
						if ($this->cache_exists($feed_id, $item->get_link())) {
							Debug::log("[delay] article is too new, already cached.", Debug::$LOG_VERBOSE);
						} else {
							Debug::log("[delay] article is too new, delaying it.", Debug::$LOG_VERBOSE);

							$this->cache_push($feed_id, $item, $entry);
						}

						$entry->parentNode->removeChild($entry);
					}
				}

				$this->cache_pull_older($feed_id, $delay, $doc, $xpath);

				return $doc->saveXML();
			}
		}

		return $feed_data;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

			$delay = (int) $this->host->get($this, "delay");
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

				<hr/>
				<?= \Controls\submit_tag(__("Save")) ?>
			</form>
		</div>

		<?php
	}

	function save() {
		$delay = (int) ($_POST["delay"] ?? 0);

		$this->host->set($this, "delay", $delay);

		echo __("Configuration saved");
	}

	function api_version() {
		return 2;
	}

}
