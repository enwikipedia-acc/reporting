<?php

chdir(__DIR__);

require('vendor/autoload.php');
require('config.php');
require('functions.php');

@unlink('blocks.html');
@unlink('create.html');
@unlink('blacklist.html');
@unlink('dqblacklist.html');
@unlink('log.html');
@unlink('selfcreate.html');
@unlink('email.html');

$database = new PDO($dburl, $dbuser, $dbpass);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $database->query("SELECT id FROM request WHERE status = 'Open' AND emailconfirm = 'Confirmed' AND reserved = 0");
$result = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stmt->closeCursor();

if (!file_exists('rqdata.dat')) {
    die("Data file doesn't exist. Run gen-report.php instead.");
}

$oldRequestData = unserialize(file_get_contents('rqdata.dat'));

$requestData = [];

foreach ($result as $id) {
    if (isset($oldRequestData[$id])) {
        $requestData[$id] = $oldRequestData[$id];
        unset($oldRequestData[$id]);
    }
}

echo "Discarding " . count($oldRequestData) . " requests as no longer open.\n";

file_put_contents('rqdata.dat', serialize($requestData));

writeBlockData($requestData);
writeLog($requestData);
writeCreateData($requestData);
writeSelfCreateData($requestData);
writeDqBlacklistData($requestData);
writeBlacklistData($requestData);
writeEmailReport($requestData);

echo "Done.\n";