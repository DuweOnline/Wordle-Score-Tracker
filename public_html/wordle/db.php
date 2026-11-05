<?php
// Settings (DB login, Dropbox keys) live outside the web root:
// public_html/wordle/db.php → ../../wordle-config.php. WORDLE_CONFIG can point elsewhere.
$configPath = getenv('WORDLE_CONFIG') ?: dirname(__DIR__, 2) . '/wordle-config.php';
if (!is_file($configPath)) {
	error_log("Wordle config not found at $configPath");
	die("The scoreboard is currently resting. Please try again later.");
}
$config = require $configPath;

// Throw on any mysqli error (connection or query)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Anything uncaught: log it and show a friendly message instead of a blank page
set_exception_handler(function (Throwable $e) {
	error_log((string)$e);
	if (PHP_SAPI !== 'cli') { http_response_code(500); }
	exit("The scoreboard is currently resting. Please try again later.\n");
});

$conn = new mysqli($config['db']['host'], $config['db']['user'], $config['db']['pass'], $config['db']['name']);

// CRITICAL: Set charset to utf8mb4 for Wordle emojis
$conn->set_charset("utf8mb4");
