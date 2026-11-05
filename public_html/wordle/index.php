<?php
include 'db.php';

$isHttps = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_start(['cookie_httponly' => true, 'cookie_secure' => $isHttps, 'cookie_samesite' => 'Lax']);

// --- 1. Security: CSRF Token Generation ---
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validCsrf() {
	return hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''));
}

// --- 2. Helper Functions ---
function getWordleDate($gameNumber) {
	// Wordle #0 was 2021-06-19
	$startDate = new DateTime('2021-06-19');
	$startDate->modify("+" . $gameNumber . " days");
	return $startDate->format('Y-m-d');
}

// Player birthdays come from the config, keyed by name => 'MM-DD'
function getBirthdaysToday() {
	global $config;
	$birthdays = $config['birthdays'] ?? [];
	$today = date('m-d');
	$names = [];
	foreach ($birthdays as $name => $md) {
		if ($md === $today) { $names[] = $name; }
	}
	return $names;
}

function parseWordle($input) {
	if (preg_match('/Wordle\s+([\d,]+)\s+([1-6X])\/6/i', $input, $matches)) {
		$gameId = (int)str_replace(',', '', $matches[1]);
		$scoreRaw = strtoupper($matches[2]);
		$score = ($scoreRaw === 'X') ? 7 : intval($scoreRaw);
		$calculatedDate = getWordleDate($gameId);
		preg_match_all('/[\x{2B1B}\x{2B1C}\x{1F7E9}\x{1F7E8}\x{1F7E6}\x{1F7E7}]+/u', $input, $gridMatches);
		$grid = implode("\n", array_filter($gridMatches[0]));
		return ['score' => $score, 'grid' => $grid, 'date' => $calculatedDate, 'id' => $gameId];
	}
	return null;
}

// --- 3. Players (needed to validate submissions) ---
$players = [];
$playerRes = $conn->query("SELECT id, name FROM players ORDER BY name ASC");
while ($pRow = $playerRes->fetch_assoc()) { $players[(int)$pRow['id']] = $pRow['name']; }
$playerRes->free();

// --- 4. Secure Submission Logic ---
// mysqli is in strict mode, so database errors arrive as exceptions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	if (!validCsrf()) {
		$_SESSION['flash'] = ["type" => "error", "msg" => "Security error: Invalid CSRF token."];
	} else {
		try {
			// Action: Save New Score
			if (isset($_POST['save_scores'])) {
				$player_id = (int)($_POST['player_id'] ?? 0);
				$parsed    = parseWordle(trim($_POST['paste_data'] ?? ''));

				if (!isset($players[$player_id])) {
					$_SESSION['flash'] = ["type" => "error", "msg" => "Please select a player."];
				} elseif (!$parsed) {
					$_SESSION['flash'] = ["type" => "error", "msg" => "Could not parse Wordle share text."];
				} elseif ($parsed['date'] > date('Y-m-d', strtotime('+1 day'))) {
					$_SESSION['flash'] = ["type" => "error", "msg" => "Wordle #" . $parsed['id'] . " hasn't been released yet."];
				} else {
					// The date comes from the puzzle number, so a late submission still lands on the right day.
					// scores.player_name is a legacy NOT NULL column; fill it so strict SQL mode can't reject the insert.
					$stmt = $conn->prepare("INSERT INTO scores (player_id, player_name, game_date, attempts, board_grid) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE player_name = VALUES(player_name), attempts = VALUES(attempts), board_grid = VALUES(board_grid)");
					$stmt->bind_param("issis", $player_id, $players[$player_id], $parsed['date'], $parsed['score'], $parsed['grid']);
					$stmt->execute();
					$stmt->close();
					$_SESSION['flash'] = ["type" => "success", "msg" => "Score saved for Wordle #" . $parsed['id'] . " (" . date('D jS M', strtotime($parsed['date'])) . ")"];
					setcookie("last_player_id", $player_id, ['expires' => time() + (86400 * 30), 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
				}

			// Action: Update Existing Score
			} elseif (isset($_POST['update_score'])) {
				$score_id = (int)($_POST['score_id'] ?? 0);
				$new_player_id = (int)($_POST['new_player_id'] ?? 0);
				if (!isset($players[$new_player_id])) {
					$_SESSION['flash'] = ["type" => "error", "msg" => "Unknown player."];
				} else {
					$stmt = $conn->prepare("UPDATE scores SET player_id = ?, player_name = ? WHERE id = ?");
					$stmt->bind_param("isi", $new_player_id, $players[$new_player_id], $score_id);
					$stmt->execute();
					$stmt->close();
					$_SESSION['flash'] = ["type" => "success", "msg" => "Board reassigned successfully."];
				}

			// Action: Delete Score
			} elseif (isset($_POST['delete_score'])) {
				$score_id = (int)($_POST['score_id'] ?? 0);
				$stmt = $conn->prepare("DELETE FROM scores WHERE id = ?");
				$stmt->bind_param("i", $score_id);
				$stmt->execute();
				$stmt->close();
				$_SESSION['flash'] = ["type" => "success", "msg" => "Record deleted."];
			}
		} catch (mysqli_sql_exception $e) {
			error_log($e->getMessage());
			// 1062 = duplicate key: the player already has a board for that day
			$msg = ($e->getCode() === 1062) ? "That player already has a score for that day." : "Database error.";
			$_SESSION['flash'] = ["type" => "error", "msg" => $msg];
		}
	}
	header("Location: index.php" . (isset($_GET['year']) ? "?year=" . (int)$_GET['year'] : ""));
	exit;
}

$message = ""; $msgType = "";
if (isset($_SESSION['flash'])) {
	$message = $_SESSION['flash']['msg']; $msgType = $_SESSION['flash']['type'];
	unset($_SESSION['flash']);
}

// --- 5. Data Fetching Functions ---
$currentYear  = (int)date('Y');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$prefillWordle = isset($_GET['wordle']) ? trim($_GET['wordle']) : '';

function fetchLeaderboard($conn, $where, $withStdDev = false) {
	$stdDev = $withStdDev ? ", ROUND(STDDEV(s.attempts), 2) AS std_dev" : "";
	$res = $conn->query(
		"SELECT p.name AS player_name, AVG(s.attempts) AS avg_score, COUNT(*) AS games$stdDev
		 FROM scores s JOIN players p ON s.player_id = p.id
		 WHERE $where
		 GROUP BY p.id ORDER BY avg_score ASC, games DESC"
	);
	$rows = $res->fetch_all(MYSQLI_ASSOC);
	$res->free();
	return $rows;
}

function fetchDistribution($conn, $where) {
	$distribution = array_fill(1, 7, 0);
	$res = $conn->query("SELECT attempts, COUNT(*) AS qty FROM scores WHERE $where GROUP BY attempts");
	while ($row = $res->fetch_assoc()) {
		$distribution[(int)$row['attempts']] = (int)$row['qty'];
	}
	$res->free();
	return $distribution;
}

function fetchStats($conn, $timeframe) {
	$whereMap = [
		'weekly'  => "game_date >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)",
		'monthly' => "MONTH(game_date) = MONTH(CURRENT_DATE()) AND YEAR(game_date) = YEAR(CURRENT_DATE())",
		'yearly'  => "YEAR(game_date) = YEAR(CURRENT_DATE())",
		'all'     => "1=1",
	];
	$titleMap = [
		'weekly'  => "*" . date('jS M Y') . "*",
		'monthly' => "*" . date('M Y') . "*",
		'yearly'  => "*" . date('Y') . "*",
		'all'     => "",
	];
	$where = $whereMap[$timeframe] ?? "1=1";
	$playersData = fetchLeaderboard($conn, $where, true);

	return [
		'players'       => $playersData,
		'distribution'  => fetchDistribution($conn, $where),
		'shareHeader'   => "*🏆 Wordle Leaderboard*",
		'shareSections' => [['title' => $titleMap[$timeframe] ?? "", 'data' => $playersData]],
	];
}

function fetchCurrentStats($conn) {
	$weekWhereSql  = "game_date >= DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY)";
	$monthWhereSql = "MONTH(game_date) = MONTH(CURRENT_DATE()) AND YEAR(game_date) = YEAR(CURRENT_DATE())";
	$yearWhereSql  = "YEAR(game_date) = YEAR(CURRENT_DATE())";

	// --- Display: current week's scores ---
	$playersData = fetchLeaderboard($conn, $weekWhereSql);

	// --- Smart share: every period that closes today gets its own section ---
	// Order: week, then month, then year. On an ordinary day, share the week so far.
	$today       = new DateTime();
	$dayOfWeek   = (int)$today->format('N'); // 1 Mon … 7 Sun
	$dayOfMonth  = (int)$today->format('j');
	$daysInMonth = (int)$today->format('t');
	$month       = (int)$today->format('n');

	$sections   = [];
	$labelParts = [];
	if ($dayOfWeek === 7) {
		$sections[]   = ['where' => $weekWhereSql, 'title' => "*Week of " . $today->format('jS M') . " — Final*"];
		$labelParts[] = "Week 🏁";
	}
	if ($dayOfMonth === $daysInMonth) {
		$sections[]   = ['where' => $monthWhereSql, 'title' => "*" . $today->format('M Y') . " — Month End*"];
		$labelParts[] = "Month 📅";
	}
	if ($month === 12 && $dayOfMonth === 31) {
		$sections[]   = ['where' => $yearWhereSql, 'title' => "*" . $today->format('Y') . " — Year in Review*"];
		$labelParts[] = "Year 🎉";
	}

	if (empty($sections)) {
		$sections[] = ['where' => $weekWhereSql, 'title' => "*" . $today->format('jS M Y') . "*"];
		$shareLabel = "Share This Week";
	} elseif (count($sections) === 1) {
		$singleLabels = ["Week 🏁" => "Share Week Final 🏁", "Month 📅" => "Share Month Final 📅", "Year 🎉" => "Share Year in Review 🎉"];
		$shareLabel   = $singleLabels[$labelParts[0]];
	} else {
		$shareLabel = "Share " . implode(" + ", $labelParts) . " Finals";
	}

	// The week is already loaded; only month/year sections need a query
	foreach ($sections as $i => $sec) {
		$sections[$i]['data'] = ($sec['where'] === $weekWhereSql) ? $playersData : fetchLeaderboard($conn, $sec['where']);
	}

	// Shared header; on a player's birthday, add a 🎂 line to it
	$shareHeader = "*🏆 Wordle Leaderboard*";
	foreach (getBirthdaysToday() as $birthdayName) {
		$shareHeader .= "\n🎂 " . $birthdayName;
	}

	return [
		'players'       => $playersData,
		'distribution'  => fetchDistribution($conn, $weekWhereSql),
		'shareHeader'   => $shareHeader,
		'shareSections' => $sections,
		'shareLabel'    => $shareLabel,
	];
}

function renderStatBlock($data) {
	$medals     = ['🥇', '🥈', '🥉'];
	$hasDisplay = !empty($data['players']);
	$hasShare   = (bool)array_filter($data['shareSections'], fn($sec) => !empty($sec['data']));

	if (!$hasDisplay && !$hasShare) {
		echo "<p class='text-center py-8 text-slate-300'>No games recorded.</p>";
		return;
	}

	// --- 1. Display leaderboard rows ---
	if ($hasDisplay) {
		echo "<div class='mb-6 space-y-2'>";
		$displayRank = 0;
		$lastAvg     = -1;
		foreach ($data['players'] as $idx => $s) {
			if ($idx >= 5) break;
			$currentAvg = number_format($s['avg_score'], 2);
			if ($currentAvg !== $lastAvg) { $displayRank = $idx + 1; }
			$lastAvg    = $currentAvg;
			$rankIcon   = ($displayRank <= 3) ? $medals[$displayRank - 1] : "#" . $displayRank;
			$gamesLabel = ((int)$s['games'] === 1) ? '1 game' : $s['games'] . ' games';
			$stdDevHtml = (isset($s['std_dev']) && (int)$s['games'] > 1 && (float)$s['std_dev'] >= 0)
				? "<span class='text-slate-500 text-sm font-normal'> &plusmn;" . $s['std_dev'] . "</span>"
				: "";
			echo "
			<div class='flex items-center justify-between p-3 bg-slate-900 border border-slate-700 rounded-xl'>
				<div class='flex items-center gap-3'>
					<span class='text-lg'>$rankIcon</span>
					<span class='font-bold text-slate-200'>" . htmlspecialchars($s['player_name']) . "</span>
				</div>
				<div class='text-right'>
					<div class='text-lg font-black text-emerald-400'>$currentAvg$stdDevHtml</div>
					<div class='text-[10px] text-slate-300 uppercase font-bold'>$gamesLabel</div>
				</div>
			</div>";
		}
		echo "</div>";
	} else {
		echo "<p class='text-center py-4 text-slate-400 text-xs uppercase tracking-widest'>No scores today yet</p>";
	}

	// --- 2. Build share text ---
	if ($hasShare) {
		$buildLines = function (array $rows) use ($medals) {
			$lines   = [];
			$rank    = 0;
			$lastAvg = -1;
			foreach ($rows as $idx => $s) {
				if ($idx >= 5) break;
				$sAvg = number_format($s['avg_score'], 2);
				if ($sAvg !== $lastAvg) { $rank = $idx + 1; }
				$lastAvg    = $sAvg;
				$rankIcon   = ($rank <= 3) ? $medals[$rank - 1] : "#" . $rank;
				$paddedName = str_pad($s['player_name'] . ":", 10, " ", STR_PAD_RIGHT);
				$lines[]    = "$rankIcon `$paddedName $sAvg`";
			}
			return $lines;
		};

		// One header, then a block per section (on the Current tab: week → month → year)
		$blocks = [];
		foreach ($data['shareSections'] as $sec) {
			$lines = $buildLines($sec['data']);
			if (empty($lines)) continue;
			$blocks[] = ($sec['title'] !== "" ? $sec['title'] . "\n\n" : "") . implode("\n", $lines);
		}
		$newShareStr = $data['shareHeader'] . "\n\n" . implode("\n\n", $blocks);
		$shareAttr  = htmlspecialchars($newShareStr, ENT_QUOTES, 'UTF-8');
		$shareLabel = htmlspecialchars($data['shareLabel'] ?? 'Share Summary');
		echo "<button data-share-text=\"$shareAttr\" onclick=\"shareResult(this.dataset.shareText)\" class='w-full mb-8 py-4 bg-emerald-600 text-white rounded-xl text-xs font-black uppercase tracking-widest hover:bg-emerald-500 transition-all flex items-center justify-center gap-2'>$shareLabel</button>";
	}

	// --- 3. Distribution bars ---
	$dist     = $data['distribution'];
	$maxCount = max($dist) ?: 1;
	$colors   = [1 => 'bg-green-600', 2 => 'bg-emerald-600', 3 => 'bg-lime-600', 4 => 'bg-yellow-600', 5 => 'bg-orange-600', 6 => 'bg-rose-600', 7 => 'bg-red-600'];
	echo '<div class="space-y-2">';
	foreach ($dist as $att => $count) {
		$width = ($count / $maxCount) * 100;
		$barBg = ($count > 0) ? $colors[$att] : 'bg-slate-900';
		echo "
		<div class='flex items-center gap-2'>
			<div class='w-4 text-xs font-bold text-slate-300'>" . ($att == 7 ? 'X' : $att) . "</div>
			<div class='flex-1 h-5 bg-slate-900 rounded overflow-hidden'>
				<div class='h-full $barBg transition-all duration-500 rounded flex items-center justify-end px-2 text-[10px] font-bold text-white' style='width: {$width}%'>" . ($count > 0 ? $count : '') . "</div>
			</div>
		</div>";
	}
	echo '</div>';
}

function calculateTrends($conn, $currYear) {
	$prevYear = (int)$currYear - 1;
	$sql = "SELECT curr.name as player_name, (prev.avg_score - curr.avg_score) as diff
			FROM (SELECT player_id, p.name, AVG(attempts) as avg_score FROM scores s JOIN players p ON s.player_id = p.id WHERE YEAR(game_date) = ? GROUP BY player_id HAVING COUNT(*) >= 3) curr
			JOIN (SELECT player_id, AVG(attempts) as avg_score FROM scores WHERE YEAR(game_date) = ? GROUP BY player_id HAVING COUNT(*) >= 3) prev
			ON curr.player_id = prev.player_id ORDER BY diff DESC";
	$stmt = $conn->prepare($sql);
	$stmt->bind_param("ii", $currYear, $prevYear);
	$stmt->execute();
	$result = $stmt->get_result();
	$stmt->close();
	return $result;
}

function fetchAnnualSummary($conn, $year) {
	$stmt = $conn->prepare("SELECT p.name as player_name, AVG(s.attempts) as avg_score, COUNT(*) as games FROM scores s JOIN players p ON s.player_id = p.id WHERE YEAR(game_date) = ? GROUP BY p.id HAVING games >= 3 ORDER BY avg_score ASC");
	$stmt->bind_param("i", $year);
	$stmt->execute();
	return $stmt->get_result();
}

function fetchChartData($conn, $year, $players) {
	$data = [];
	foreach ($players as $name) { $data[$name] = array_fill(0, 12, null); }
	$sql = "SELECT p.name, MONTH(s.game_date) as m, AVG(s.attempts) as avg_score FROM scores s JOIN players p ON s.player_id = p.id WHERE YEAR(s.game_date) = ? GROUP BY p.id, m ORDER BY p.name, m ASC";
	$stmt = $conn->prepare($sql);
	$stmt->bind_param("i", $year);
	$stmt->execute();
	$res = $stmt->get_result();
	while ($row = $res->fetch_assoc()) {
		$data[$row['name']][(int)$row['m'] - 1] = round($row['avg_score'], 2);
	}
	$res->free();
	return $data;
}

function fetchBestDay($conn) {
	// Group by the same expression we select, so ONLY_FULL_GROUP_BY accepts it
	$res = $conn->query(
		"SELECT p.name, DAYNAME(s.game_date) AS day_name, AVG(s.attempts) AS avg_score
		 FROM scores s JOIN players p ON s.player_id = p.id
		 GROUP BY p.id, DAYNAME(s.game_date)
		 HAVING COUNT(*) >= 3
		 ORDER BY p.name ASC, avg_score ASC"
	);
	$byPlayer = [];
	while ($row = $res->fetch_assoc()) {
		$byPlayer[$row['name']][] = $row;
	}
	$res->free();

	$result = [];
	foreach ($byPlayer as $name => $days) {
		// Already ASC by avg_score: first = best day, last = worst day
		$result[$name] = [
			'best'  => $days[0]['day_name'],
			'worst' => end($days)['day_name'],
		];
	}
	return $result;
}

$chartData   = fetchChartData($conn, $selectedYear, $players);
$bestDayData = fetchBestDay($conn);
$recentFeed  = $conn->query("SELECT s.id as score_id, s.attempts, s.game_date, s.board_grid, s.player_id, p.name as player_name FROM scores s JOIN players p ON s.player_id = p.id ORDER BY game_date DESC, s.id DESC LIMIT 24");
$yearListRes = $conn->query("SELECT DISTINCT YEAR(game_date) as yr FROM scores ORDER BY yr DESC");

// Existing player+date combos, used client-side to warn before overwriting a score
$existingScores = [];
$existingRes = $conn->query("SELECT player_id, game_date FROM scores");
while ($r = $existingRes->fetch_assoc()) {
	$existingScores[] = $r['player_id'] . '|' . $r['game_date'];
}
$existingRes->free();
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Duwe Wordle</title>
	<script src="https://cdn.tailwindcss.com/3.4.16"></script>
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
	<style>
		.tab-content { display: none; } .tab-content.active { display: block; }
		.tab-btn.active { border-bottom: 2px solid #10b981; color: #10b981; background: rgba(16, 185, 129, 0.1); }
		body { background-color: #0f172a; }
	</style>
</head>
<body class="text-slate-200 min-h-screen font-sans pb-12">

<nav class="bg-slate-950 text-white p-6 shadow-md mb-8 border-b border-slate-800">
	<div class="container mx-auto flex justify-between items-center">
		<h1 class="text-xl font-black uppercase tracking-widest"><span class="text-red-600">Duwe</span> Wordle</h1>
		<div class="text-[10px] font-mono text-slate-400 bg-slate-900 px-3 py-1 rounded-full uppercase"><?php echo date('l, M jS'); ?></div>
	</div>
</nav>

<div class="container mx-auto px-4 max-w-5xl space-y-8">
	<?php if($message): 
		$flashBorder = ($msgType === 'error') ? 'border-rose-500' : 'border-emerald-500';
	?>
		<div class="p-4 bg-slate-800 border-l-4 <?php echo $flashBorder; ?> rounded-r-xl shadow-sm flex justify-between items-center">
			<span><?php echo htmlspecialchars($message); ?></span>
			<button onclick="this.parentElement.remove()" class="opacity-50 hover:opacity-100">&times;</button>
		</div>
	<?php endif; ?>

	<?php $birthdaysToday = getBirthdaysToday(); if (!empty($birthdaysToday)): ?>
		<div class="p-4 bg-gradient-to-r from-pink-600/20 to-amber-500/20 border border-pink-500/40 rounded-xl text-center">
			<span class="text-lg font-black text-slate-100">🎂 Happy Birthday <?php echo htmlspecialchars(implode(' & ', $birthdaysToday)); ?>!</span>
		</div>
	<?php endif; ?>

	<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
		<div class="bg-slate-800 rounded-2xl border border-slate-700 shadow-sm overflow-hidden">
			<div class="px-6 py-4 border-b border-slate-700 font-black bg-slate-950/50 text-slate-200 text-xs uppercase tracking-widest">Submit Score</div>
			<div class="px-6 pb-6">
				<form method="POST" id="score-form" onsubmit="return checkDuplicate();" class="space-y-6">
					<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
					<!-- No date field: the game date comes from the Wordle number in the pasted text -->
					<select name="player_id" class="w-full h-12 rounded-xl border-slate-700 border px-4 bg-slate-900 text-base md:text-sm font-bold text-slate-100 outline-none focus:ring-2 focus:ring-emerald-500" required>
						<option value="">Select Player...</option>
						<?php foreach($players as $id => $name): ?>
							<option value="<?php echo $id; ?>" <?php echo ((int)($_COOKIE['last_player_id'] ?? 0) === $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
						<?php endforeach; ?>
					</select>
					<textarea name="paste_data" id="paste_data" rows="4" placeholder="Paste Wordle text here..." class="w-full p-4 text-base md:text-xs border border-slate-700 rounded-xl bg-slate-900 text-slate-100 font-mono outline-none focus:ring-2 focus:ring-emerald-500" required><?php echo htmlspecialchars($prefillWordle); ?></textarea>
					<button type="submit" name="save_scores" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white font-black py-4 rounded-xl transition shadow-lg uppercase tracking-widest text-xs">Record Score</button>
				</form>
			</div>
		</div>

		<div class="bg-slate-800 rounded-2xl border border-slate-700 shadow-sm overflow-hidden">
			<div class="flex border-b border-slate-700 bg-slate-950/50">
				<button onclick="openTab(event, 'current')" class="tab-btn active flex-1 py-4 text-[10px] font-black uppercase tracking-widest">Current</button>
				<button onclick="openTab(event, 'all_time')" class="tab-btn flex-1 py-4 text-[10px] font-black uppercase tracking-widest">All Time</button>
			</div>
			<div class="p-6">
				<div id="current" class="tab-content active"><?php renderStatBlock(fetchCurrentStats($conn)); ?></div>
				<div id="all_time" class="tab-content"><?php renderStatBlock(fetchStats($conn, 'all')); ?></div>
			</div>
		</div>
	</div>

	<?php if (!empty($bestDayData)): ?>
	<div class="bg-slate-800 rounded-2xl border border-slate-700 shadow-sm overflow-hidden">
		<div class="px-6 py-4 border-b border-slate-700 font-black bg-slate-950/50 text-slate-200 text-xs uppercase tracking-widest">Player Stats</div>
		<div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
			<?php foreach ($players as $id => $pName):
				$bestDay = $bestDayData[$pName] ?? null;
			?>
			<div class="bg-slate-900 border border-slate-700 rounded-xl p-4 space-y-3">
				<div class="font-black text-slate-200 text-sm uppercase tracking-widest"><?php echo htmlspecialchars($pName); ?></div>

				<?php if ($bestDay): ?>
				<div class="border-t border-slate-700 pt-3 flex justify-between text-xs">
					<div>
						<div class="text-[10px] text-emerald-400 uppercase tracking-widest font-bold mb-1">Best day</div>
						<div class="font-black text-slate-200"><?php echo htmlspecialchars($bestDay['best']); ?></div>
					</div>
					<div class="text-right">
						<div class="text-[10px] text-rose-400 uppercase tracking-widest font-bold mb-1">Worst day</div>
						<div class="font-black text-slate-200"><?php echo htmlspecialchars($bestDay['worst']); ?></div>
					</div>
				</div>
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<div class="bg-slate-800 rounded-2xl border border-slate-700 shadow-sm overflow-hidden">
		<div class="px-6 py-4 border-b border-slate-700 font-black bg-slate-950/50 text-slate-200 flex justify-between items-center">
			<span class="text-xs uppercase tracking-widest"><?php echo $selectedYear; ?> Review</span>
			<form method="GET">
				<select name="year" onchange="this.form.submit()" class="text-[10px] font-black border border-slate-600 rounded-lg px-2 py-1 bg-slate-900 text-slate-100 outline-none focus:ring-2 focus:ring-emerald-500">
					<?php if($yearListRes): while($y = $yearListRes->fetch_assoc()): $yr = (int)$y['yr']; ?>
						<option value="<?php echo $yr; ?>" <?php if($yr === $selectedYear) echo 'selected'; ?>><?php echo $yr; ?></option>
					<?php endwhile; $yearListRes->free(); endif; ?>
				</select>
			</form>
		</div>
		<div class="p-4 md:p-8 space-y-8">
			<div class="bg-slate-900/40 border border-slate-700 rounded-2xl p-4 md:p-8">
				<div class="grid grid-cols-1 md:grid-cols-3 gap-3 md:gap-6 mb-10">
					<?php 
					$annualRes = fetchAnnualSummary($conn, $selectedYear);
					// Same tie ranking as the leaderboards: two tied for 🥇 are followed by 🥉
					$rank = 0; $pos = 0; $lastS = -1; $meds = ['🥇', '🥈', '🥉'];
					if($annualRes): while($p = $annualRes->fetch_assoc()):
						$pos++; $sStr = number_format($p['avg_score'], 2); if ($sStr !== $lastS) $rank = $pos; $lastS = $sStr; if($rank > 3) break;
					?>
						<div class="flex justify-between items-center p-3 md:p-5 rounded-xl bg-slate-800 shadow-sm border border-slate-700">
							<div class="flex items-center gap-3"><span class="text-xl"><?php echo $meds[$rank-1]; ?></span><span class="font-black text-slate-200 text-xs md:text-sm"><?php echo htmlspecialchars($p['player_name']); ?></span></div>
							<div class="text-right"><div class="font-mono font-black text-emerald-400 text-base md:text-xl leading-none"><?php echo $sStr; ?></div></div>
						</div>
					<?php endwhile; endif; ?>
				</div>

				<div class="bg-slate-800 p-4 md:p-6 rounded-xl border border-slate-700 shadow-sm mb-6 h-64">
					<canvas id="yearlyChart"></canvas>
				</div>

				<?php 
				$trendRes = calculateTrends($conn, $selectedYear);
				$tdata = $trendRes->fetch_all(MYSQLI_ASSOC); $trendRes->free();
				// diff = last year's average − this year's, so positive means fewer guesses.
				// Only show a side when it's true, so one player can't be both.
				$improved = (!empty($tdata) && $tdata[0]['diff'] > 0) ? $tdata[0] : null;
				$slipped  = (!empty($tdata) && end($tdata)['diff'] < 0) ? end($tdata) : null;
				if ($improved || $slipped):
				?>
					<div class='mt-6 pt-6 border-t border-slate-700 grid grid-cols-2 gap-4'>
						<div>
							<?php if ($improved): ?>
							<div class='text-[10px] text-emerald-400 font-black uppercase tracking-widest'>Improved ↑</div>
							<div class='text-sm font-black text-slate-100'><?php echo htmlspecialchars($improved['player_name']); ?></div>
							<div class='text-xs text-slate-300 font-mono'>-<?php echo number_format($improved['diff'], 2); ?></div>
							<?php endif; ?>
						</div>
						<div class='text-right'>
							<?php if ($slipped): ?>
							<div class='text-[10px] text-rose-400 font-black uppercase tracking-widest'>Slipped ↓</div>
							<div class='text-sm font-black text-slate-100'><?php echo htmlspecialchars($slipped['player_name']); ?></div>
							<div class='text-xs text-slate-300 font-mono'>+<?php echo number_format(abs($slipped['diff']), 2); ?></div>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="bg-slate-800 rounded-2xl border border-slate-700 shadow-sm overflow-hidden">
		<div class="px-6 py-4 border-b border-slate-700 font-black bg-slate-950/50 text-slate-200 text-xs uppercase tracking-widest">Recent Boards</div>
		<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 divide-x divide-y divide-slate-700">
			<?php while($row = $recentFeed->fetch_assoc()): ?>
			<div class="p-6 flex flex-col justify-between hover:bg-slate-700/50 transition group relative">
				<div class="flex justify-between items-start mb-4">
					<div class="text-sm">
						<div class="text-[10px] font-mono text-slate-300 mb-1"><?php echo date('M d', strtotime($row['game_date'])); ?></div>
						<div class="font-black text-slate-200"><?php echo htmlspecialchars($row['player_name']); ?></div>
						<div class="font-black text-emerald-400 text-xs"><?php echo ($row['attempts'] == 7 ? 'X' : $row['attempts']); ?>/6</div>
					</div>
					<div class="bg-slate-950 border border-slate-700 p-2 rounded-lg text-[8px] font-mono whitespace-pre text-slate-400"><?php echo htmlspecialchars($row['board_grid']); ?></div>
				</div>

				<button onclick="toggleEdit(<?php echo $row['score_id']; ?>)" class="text-[9px] font-bold text-slate-300 hover:text-emerald-400 uppercase tracking-tighter self-start">Edit ✎</button>

				<div id="edit-form-<?php echo $row['score_id']; ?>" class="hidden absolute inset-0 bg-slate-800 p-4 flex flex-col justify-center items-center z-10 border-2 border-emerald-500 rounded-2xl shadow-xl">
					<form method="POST" class="w-full space-y-3" onsubmit="return confirmAction(event, this);">
						<input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
						<input type="hidden" name="score_id" value="<?php echo $row['score_id']; ?>">
						<div class="flex justify-between items-center">
							<label class="text-[10px] font-black uppercase text-slate-300 tracking-widest">Reassign Board</label>
							<button type="submit" name="delete_score" class="text-rose-500 hover:text-rose-400 transition" title="Delete Score">
								<svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
							</button>
						</div>
						<select name="new_player_id" class="w-full text-base md:text-xs border border-slate-600 rounded-lg p-2 font-bold bg-slate-900 text-slate-100 outline-none focus:border-emerald-500 transition">
							<?php foreach($players as $id => $name): ?>
								<option value="<?php echo $id; ?>" <?php echo ($row['player_id'] == $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
							<?php endforeach; ?>
						</select>
						<div class="flex gap-2 w-full">
							<button type="submit" name="update_score" class="flex-[2] bg-emerald-600 hover:bg-emerald-500 text-white text-[10px] font-black py-2 rounded-lg shadow-sm transition">Save</button>
							<button type="button" onclick="toggleEdit(<?php echo $row['score_id']; ?>)" class="flex-1 bg-slate-700 text-slate-300 text-[10px] font-black py-2 rounded-lg transition">Cancel</button>
						</div>
					</form>
				</div>
			</div>
			<?php endwhile; $recentFeed->free(); ?>
		</div>
	</div>
</div>

<script>
	// Same puzzle-number → date maths as parseWordle() in PHP (Wordle #0 was 2021-06-19)
	function parseShare(text) {
		const m = text.match(/Wordle\s+([\d,]+)\s+([1-6X])\/6/i);
		if (!m) return null;
		const num = parseInt(m[1].replace(/,/g, ''), 10);
		const date = new Date(Date.UTC(2021, 5, 19) + num * 86400000).toISOString().slice(0, 10);
		return { num, score: m[2].toUpperCase(), date };
	}

	// Warn before overwriting a score that already exists for this player + date
	const existingScores = new Set(<?php echo json_encode($existingScores, JSON_HEX_TAG | JSON_HEX_AMP); ?>);
	function checkDuplicate() {
		const f = document.getElementById('score-form');
		const player = f.querySelector('select[name="player_id"]').value;
		const share  = parseShare(f.querySelector('textarea[name="paste_data"]').value);
		if (player && share && existingScores.has(player + '|' + share.date)) {
			return confirm('A score for this player on this date already exists. Overwrite it?');
		}
		return true;
	}

	// Arriving from the iOS Shortcut: prefilled, then one tap to confirm.
	// The confirm stops a crafted ?wordle= link from saving a score without you noticing.
	const prefillWordle = <?php echo json_encode($prefillWordle, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
	if (prefillWordle) {
		const form       = document.getElementById('score-form');
		const playerSel  = form.querySelector('select[name="player_id"]');
		const share      = parseShare(prefillWordle);
		form.scrollIntoView({ behavior: 'smooth', block: 'center' });
		if (playerSel.value && share) {
			const playerName = playerSel.options[playerSel.selectedIndex].text;
			setTimeout(() => {
				// requestSubmit (unlike submit) sends the save_scores button and runs checkDuplicate
				if (confirm(`Record Wordle ${share.num.toLocaleString()} (${share.score}/6) for ${playerName}?`)) {
					form.requestSubmit(form.querySelector('[name="save_scores"]'));
				}
			}, 400);
		} else {
			// No player remembered — focus the dropdown so they just tap & go
			playerSel.focus();
		}
	}

	function shareResult(text) {
		if (navigator.share) {
			navigator.share({ text: text }).catch(() => {});
		} else {
			window.open('whatsapp://send?text=' + encodeURIComponent(text));
		}
	}

	function openTab(evt, tabName) {
		document.querySelectorAll(".tab-content").forEach(c => c.style.display = "none");
		document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
		document.getElementById(tabName).style.display = "block";
		evt.currentTarget.classList.add("active");
	}

	function toggleEdit(scoreId) {
		const form = document.getElementById('edit-form-' + scoreId);
		form.classList.toggle('hidden');
	}

	function confirmAction(event, form) {
		if (event.submitter && event.submitter.name === 'delete_score') {
			return confirm("Are you sure you want to delete this score forever?");
		}
		return true;
	}

	// Chart Configuration
	const ctx = document.getElementById('yearlyChart').getContext('2d');
	const chartData = <?php echo json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
	const palette = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6'];
	
	new Chart(ctx, {
		type: 'line',
		data: {
			labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
			datasets: Object.keys(chartData).map((player, idx) => ({
				label: player, 
				data: chartData[player],
				borderColor: palette[idx % palette.length], 
				tension: 0.4, 
				spanGaps: true,
				borderWidth: 2,
				pointRadius: 3
			}))
		},
		options: {
			responsive: true, 
			maintainAspectRatio: false,
			scales: { 
				y: { reverse: true, min: 1, max: 7, ticks: { color: '#94a3b8' }, grid: { color: '#334155' } },
				x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
			},
			plugins: { 
				legend: { position: 'bottom', labels: { boxWidth: 10, color: '#94a3b8', font: { size: 10, weight: 'bold' } } } 
			}
		}
	});
</script>
</body>
</html>