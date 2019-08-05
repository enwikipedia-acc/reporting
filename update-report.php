<?php

chdir(__DIR__);

require('vendor/autoload.php');
require('functions.php');

$stmt = $database->query("SELECT id FROM request WHERE status = '" . $targetSection . "' AND emailconfirm = 'Confirmed' AND reserved = 0");
$result = $stmt->fetchAll(PDO::FETCH_COLUMN);
$stmt->closeCursor();

if (!file_exists('rqdata.dat')) {
    die("Data file doesn't exist. Run gen-report.php instead.");
}

$oldRequestData = unserialize(file_get_contents('rqdata.dat'));

$requestData = [];

foreach ($result as $id) {
    if(isset($argv[1])) {
        if($id == $argv[1]) { continue; }
    }

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
writeEmailReport($requestData, $targetSection);
writeXffReport($requestData);
writeHardblockData($requestData);
writeGlobalBlockData($requestData);

echo "Done.\n";
