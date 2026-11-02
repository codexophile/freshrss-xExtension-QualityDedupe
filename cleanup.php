<?php

declare(strict_types=1);

/**
 * One-time cleanup: marks lower-quality duplicate unread items as read.
 * Items are grouped by (feed, link). In each group the highest-scoring
 * item is kept, and ties go to the item that arrived first. Kept items
 * in duplicate groups get the ✔ title prefix. Nothing is deleted.
 *
 * Usage (dry run by default):
 *   php cleanup.php --user=NAME [--feeds=1,2,3] [--apply]
 *
 * Assumes the default SQLite storage (data/users/NAME/db.sqlite).
 */

/** @var string Title prefix marking items the extension kept. */
const MARKER = '✔ ';

/**
 * Scores a release title by its quality tags. Higher is better.
 * Must stay in sync with the extension's scoring.
 *
 * @param string $title Full item title.
 * @return int Quality score.
 */
function score(string $title): int
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
 * Entry point. Parses CLI options, finds duplicate groups, and either
 * prints the planned changes (dry run) or applies them in a transaction.
 *
 * @return int Process exit code (0 on success, 1 on error).
 */
function main(): int
{
	$opts = getopt('', ['user:', 'feeds::', 'apply']);
	$user = $opts['user'] ?? null;
	if (!is_string($user) || $user === '') {
		fwrite(STDERR, "Missing --user=NAME\n");
		return 1;
	}
	$apply = isset($opts['apply']);
	$feedFilter = [];
	if (isset($opts['feeds']) && is_string($opts['feeds'])) {
		$feedFilter = array_map('intval', explode(',', $opts['feeds']));
	}

	$dbPath = "/var/www/FreshRSS/data/users/{$user}/db.sqlite";
	if (!is_file($dbPath)) {
		fwrite(STDERR, "Database not found: {$dbPath}\n");
		return 1;
	}

	$pdo = new PDO("sqlite:{$dbPath}");
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	// Unread items whose (feed, link) appears more than once.
	$sql = 'SELECT e.id, e.id_feed, e.link, e.title FROM entry e '
		. 'WHERE e.is_read = 0 AND e.link != \'\' AND EXISTS ('
		. 'SELECT 1 FROM entry o WHERE o.id_feed = e.id_feed AND o.link = e.link '
		. 'AND o.is_read = 0 AND o.id != e.id) ORDER BY e.id_feed, e.link, e.id';
	$groups = [];
	foreach ($pdo->query($sql, PDO::FETCH_ASSOC) as $row) {
		if ($feedFilter !== [] && !in_array((int) $row['id_feed'], $feedFilter, true)) {
			continue;
		}
		$groups[$row['id_feed'] . '|' . $row['link']][] = $row;
	}

	$markRead = [];
	$keepers = [];
	foreach ($groups as $rows) {
		$best = $rows[0]; // Rows are ordered by id, so ties keep the first arrival.
		foreach ($rows as $row) {
			if (score($row['title']) > score($best['title'])) {
				$best = $row;
			}
		}
		$keepers[] = $best;
		echo "KEEP  {$best['title']}\n";
		foreach ($rows as $row) {
			if ($row['id'] !== $best['id']) {
				$markRead[] = $row['id'];
				echo "  read  {$row['title']}\n";
			}
		}
	}

	printf("\n%d duplicate groups, %d items to mark read.\n", count($groups), count($markRead));
	if (!$apply) {
		echo "Dry run only. Re-run with --apply to make these changes.\n";
		return 0;
	}

	$pdo->beginTransaction();
	$read = $pdo->prepare('UPDATE entry SET is_read = 1 WHERE id = :id');
	foreach ($markRead as $id) {
		$read->execute([':id' => $id]);
	}
	$mark = $pdo->prepare('UPDATE entry SET title = :t WHERE id = :id');
	foreach ($keepers as $row) {
		if (!str_starts_with($row['title'], MARKER)) {
			$mark->execute([':t' => MARKER . $row['title'], ':id' => $row['id']]);
		}
	}
	// Refresh the cached per-feed unread counters FreshRSS displays.
	$pdo->exec('UPDATE feed SET cache_nbUnreads = ('
		. 'SELECT COUNT(*) FROM entry WHERE entry.id_feed = feed.id AND entry.is_read = 0)');
	$pdo->commit();

	echo "Done.\n";
	return 0;
}

exit(main());