<?php
// Wordle Tracker settings: copy this file to wordle-config.php and fill it in.
// Keep the real file OUTSIDE public_html: it sits beside public_html here and on the server,
// where db.php (in public_html/wordle) looks for it two folders up.
// Never commit the real file. .gitignore already excludes it.
return [
	'db' => [
		'host' => 'localhost',
		'user' => 'your_db_user',
		'pass' => 'your_db_password',
		'name' => 'your_db_name',
	],

	// Only needed for backup.php
	'dropbox' => [
		'app_key' => 'your_dropbox_app_key',
		'app_secret' => 'your_dropbox_app_secret',
		'refresh_token' => 'your_dropbox_refresh_token',
	],

	// Optional: adds a 🎂 line to the shared leaderboard. Player name => 'MM-DD'
	'birthdays' => [
		'Alice' => '01-31',
		'Bob' => '12-25',
	],
];
