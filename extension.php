<?php

declare(strict_types=1);

/**
 * Marks lower-quality duplicate releases as read so only the best
 * unread item per link remains. Items are grouped by their URL; the
 * item that is kept gets a visible title prefix so you can tell the
 * extension processed it. Only feeds selected in the extension's
 * configuration (directly or via their category) are processed.
 */
final class QualityDedupeExtension extends Minz_Extension
{
	/**
	 * Prefix added to the title of every item the extension keeps.
	 * Change this to any string you like (e.g. '[BEST] ').
	 *
	 * @var string
	 */
	private const MARKER = '✔ ';

	/**
	 * Registers the hook that runs before each new entry is inserted.
	 *
	 * @return void
	 */
	#[\Override]
	public function init(): void
	{
		$this->registerHook('entry_before_insert', [$this, 'handleEntry']);
	}

	/**
	 * Handles the configuration form submission and stores the selected
	 * category and feed IDs in the user's extension configuration.
	 *
	 * @return void
	 */
	#[\Override]
	public function handleConfigureAction(): void
	{
		if (Minz_Request::isPost()) {
			$this->setUserConfiguration([
				'categories' => array_values(array_map('intval', Minz_Request::paramArray('categories'))),
				'feeds' => array_values(array_map('intval', Minz_Request::paramArray('feeds'))),
			]);
		}
	}

	/**
	 * Returns the IDs of the categories selected in the configuration.
	 *
	 * @return array<int,int> Selected category IDs.
	 */
	public function getSelectedCategoryIds(): array
	{
		$value = $this->getUserConfigurationValue('categories', []);
		return is_array($value) ? array_map('intval', $value) : [];
	}

	/**
	 * Returns the IDs of the individual feeds selected in the configuration.
	 *
	 * @return array<int,int> Selected feed IDs.
	 */
	public function getSelectedFeedIds(): array
	{
		$value = $this->getUserConfigurationValue('feeds', []);
		return is_array($value) ? array_map('intval', $value) : [];
	}

	/**
	 * Loads all categories with their feeds, for rendering the config form.
	 *
	 * @return array<int,FreshRSS_Category> Categories, each with feeds populated.
	 */
	public function getCategoriesWithFeeds(): array
	{
		return FreshRSS_Factory::createCategoryDao()->listSortedCategories(true, false);
	}

	/**
	 * Tells whether an entry's feed is selected, either directly or
	 * through its category. With nothing selected, nothing is processed.
	 *
	 * @param FreshRSS_Entry $entry The entry being inserted.
	 * @return bool True if the extension should process this entry.
	 */
	private function isInScope(FreshRSS_Entry $entry): bool
	{
		if (in_array($entry->feedId(), $this->getSelectedFeedIds(), true)) {
			return true;
		}
		$feed = $entry->feed();
		return $feed !== null
			&& in_array($feed->categoryId(), $this->getSelectedCategoryIds(), true);
	}

	/**
	 * Scores a release title by its quality tags. Higher is better.
	 * Resolution dominates; codec, bit depth and audio act as tie-breakers.
	 *
	 * @param string $title Full item title.
	 * @return int Quality score.
	 */
	private static function score(string $title): int
	{
		$score = 0;
		if (preg_match('/\b(2160|1080|720|480)p\b/i', $title, $m)) {
			$score += (int) $m[1] * 10;
		}
		if (stripos($title, 'x265') !== false) {
			$score += 100;
		}
		if (stripos($title, '10bit') !== false) {
			$score += 50;
		}
		if (strpos($title, '5.1') !== false) {
			$score += 10;
		}
		return $score;
	}

	/**
	 * Hook callback. Skips entries outside the configured scope. Otherwise
	 * marks the new entry read if a better-or-equal unread item with the
	 * same link exists; if not, marks existing weaker items read and
	 * prefixes the new entry's title with the marker.
	 *
	 * @param FreshRSS_Entry $entry The entry about to be inserted.
	 * @return FreshRSS_Entry The (possibly modified) entry.
	 */
	public function handleEntry(FreshRSS_Entry $entry): FreshRSS_Entry
	{
		if (!$this->isInScope($entry)) {
			return $entry;
		}
		$link = $entry->link();
		if ($link === '') {
			return $entry;
		}
		$newScore = self::score($entry->title());

		// Anonymous subclass gives access to the protected PDO handle.
		$db = new class extends Minz_ModelPdo {
			/**
			 * Finds unread entries in a feed that share an exact link.
			 *
			 * @param int $feedId Feed ID.
			 * @param string $link Entry URL.
			 * @return array<int,array{id:string,title:string}> Matching rows.
			 */
			public function findUnreadByLink(int $feedId, string $link): array
			{
				$sql = 'SELECT id, title FROM `_entry` WHERE id_feed = :f '
					. 'AND is_read = 0 AND link = :l';
				$st = $this->pdo->prepare($sql);
				$st->execute([':f' => $feedId, ':l' => $link]);
				return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
			}
		};

		$weaker = [];
		foreach ($db->findUnreadByLink($entry->feedId(), $link) as $row) {
			if (self::score($row['title']) >= $newScore) {
				$entry->_isRead(true); // A better (or equal) one already exists.
				return $entry;
			}
			$weaker[] = $row['id'];
		}

		if ($weaker !== []) {
			FreshRSS_Factory::createEntryDao()->markRead($weaker, true);
		}

		// This entry is the keeper: flag it so it's obvious the extension ran.
		if (!str_starts_with($entry->title(), self::MARKER)) {
			$entry->_title(self::MARKER . $entry->title());
		}
		return $entry;
	}
}