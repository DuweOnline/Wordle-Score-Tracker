<?php
// Daily database backup to Dropbox. Command line only, e.g. from cron:
//   php ~/public_html/wordle/backup.php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

// 1. DB CONNECTION (db.php loads the config and sets utf8mb4 so emoji grids survive)
include __DIR__ . '/db.php';

// 2. DROPBOX REFRESH
$ch = curl_init("https://api.dropbox.com/oauth2/token");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
	'grant_type' => 'refresh_token',
	'refresh_token' => $config['dropbox']['refresh_token'],
	'client_id' => $config['dropbox']['app_key'],
	'client_secret' => $config['dropbox']['app_secret']
]));
$response = json_decode(curl_exec($ch), true);
$accessToken = $response['access_token'] ?? null;
curl_close($ch);

if (!$accessToken) {
	fwrite(STDERR, "Could not get Dropbox access token. Check app key/secret and refresh token.\n");
	exit(1);
}

// 3. BUILD SQL DUMP: schema + data for every table, restorable as-is
$sql  = "-- Wordle Backup " . date('Y-m-d H:i') . "\n";
$sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n";
foreach (array_column($conn->query("SHOW TABLES")->fetch_all(), 0) as $table) {
	$create = $conn->query("SHOW CREATE TABLE `$table`")->fetch_row()[1];
	$sql .= "\nDROP TABLE IF EXISTS `$table`;\n$create;\n";

	$res  = $conn->query("SELECT * FROM `$table`");
	$cols = implode(", ", array_map(fn($f) => "`{$f->name}`", $res->fetch_fields()));
	while ($row = $res->fetch_row()) {
		$vals = array_map(fn($v) => $v === null ? "NULL" : "'" . $conn->real_escape_string((string)$v) . "'", $row);
		$sql .= "INSERT INTO `$table` ($cols) VALUES (" . implode(", ", $vals) . ");\n";
	}
	$res->free();
}
$sql .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

// 4. UPLOAD
$backupName = 'wordle_' . date('Y-m-d') . '.sql';
$ch = curl_init("https://content.dropboxapi.com/2/files/upload");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
	"Authorization: Bearer $accessToken",
	"Content-Type: application/octet-stream",
	"Dropbox-API-Arg: " . json_encode(["path" => "/$backupName", "mode" => "overwrite"])
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $sql);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$result = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status !== 200) {
	fwrite(STDERR, "Dropbox upload failed. Status: $status | Response: $result\n");
	exit(1);
}
echo "Backed up to /$backupName (" . strlen($sql) . " bytes)\n";
