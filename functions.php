<?php

const REJ_LOCALBLOCK = 'Rejected: Locally blocked';
const REJ_HASLOCALBLOCK = 'Rejected: Local blocks exist';
const REJ_SELFCREATE = 'Rejected: self-create';
const REJ_DQBLACKLIST = 'Rejected: DQ blacklist';
const REJ_BLACKLIST = 'Rejected: title blacklist';

function writeBlockData($requestData)
{
    $localBlocks = [];

    foreach ($requestData as $id => $logs) {
        foreach ($logs as $data) {
            if ($data['m'] === REJ_LOCALBLOCK) {
                if (!isset($localBlocks[$data['d']])) {
                    $localBlocks[$data['d']] = [];
                }

                $localBlocks[$data['d']][] = $id;
            }
        }
    }

    $repBlocks = fopen('blocks.html', 'w');

    foreach ($localBlocks as $reason => $values) {
        fwrite($repBlocks, '<h3>' . htmlentities($reason) . '</h3><ul>');

        foreach ($values as $v) {
            fwrite($repBlocks, '<li><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $v . '">' . $v . '</a><ul>');

            foreach ($requestData[$v] as $logData) {
                // skip the block information, we already know!
                if ($logData['m'] == REJ_HASLOCALBLOCK || $logData['m'] == REJ_LOCALBLOCK) {
                    continue;
                }

                fwrite($repBlocks, '<li>' . $logData['m'] . '</li>');
            }

            fwrite($repBlocks, '</ul></li>');
        }

        fwrite($repBlocks, '</ul>');
    }

    fclose($repBlocks);
}

function writeLog($requestData)
{
    $total = count($requestData);
    $i = 0;

    $repLog = fopen('log.html', 'w');
    fwrite($repLog, '<ul>');

    foreach ($requestData as $id => $logData) {
        fwrite($repLog, '<li>Processing #' . $id . ' - ' . ++$i . ' / ' . $total . '<ul>');
        foreach ($logData as $datum) {
            if ($datum['d'] !== null) {
                $fullMessage = $datum['m'] . ' - <code>' . htmlentities($datum['d']) . '</code>';
            } else {
                $fullMessage = $datum['m'];
            }

            fwrite($repLog, '<li>' . $fullMessage . '</li>');
        }
        fwrite($repLog, '</ul></li>');
    }

    fwrite($repLog, '</ul>');
    fclose($repLog);
}

function writeCreateData($requestData)
{
    $repCreate = fopen('create.html', 'w');

    fwrite($repCreate, '<ul>');

    global $database;
    $stmt = $database->prepare('SELECT name FROM request WHERE id = :id');

    foreach ($requestData as $id => $data) {
        if (count($data) === 0) {
            $stmt->execute([':id' => $id]);
            $name = $stmt->fetchColumn();
            $stmt->closeCursor();

            fwrite($repCreate, '<li><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">' . $name . "</a></li>");
        }
    }

    fwrite($repCreate, '</ul>');

    fclose($repCreate);
}

function writeSelfCreateData($requestData)
{
    $repSelfcreate = fopen('selfcreate.html', 'w');

    fwrite($repSelfcreate, '<table>');

    global $database;
    $stmt = $database->prepare('SELECT date, name FROM request WHERE id = :id');

    foreach ($requestData as $id => $data) {
        foreach ($data as $datum) {
            if ($datum['m'] === REJ_SELFCREATE) {
                $stmt->execute([':id' => $id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                $regDate = new DateTime($datum['d']);
                $reqDate = new DateTime($req['date']);
                $reason = 'Unknown';
                if ($regDate < $reqDate) {
                    $reason = 'Taken';
                }

                if ($reqDate < $regDate) {
                    $reason = 'Self-create';
                }

                fwrite($repSelfcreate, '<tr><td><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">' . $req['name'] . "</a></td><td>" . $datum['d'] . "</td><td>" . $req['date'] . "</td><td>" . $reason . "</td></tr>");
            }
        }
    }

    fwrite($repSelfcreate, '</table>');

    fclose($repSelfcreate);
}

function writeDqBlacklistData($requestData)
{
    $repDqBlacklist = fopen('dqblacklist.html', 'w');

    fwrite($repDqBlacklist, '<ul>');

    global $database;
    $stmt = $database->prepare('SELECT name FROM request WHERE id = :id');

    foreach ($requestData as $id => $data) {
        foreach ($data as $datum) {
            if ($datum['m'] === REJ_DQBLACKLIST) {
                $stmt->execute([':id' => $id]);
                $req = $stmt->fetchColumn();
                $stmt->closeCursor();

                fwrite($repDqBlacklist, '<li><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">#' . $id . " (" . htmlentities($req) . ") matches: " . htmlentities($datum['d']) . "</a></li>");
            }
        }
    }

    fwrite($repDqBlacklist, '</ul>');

    fclose($repDqBlacklist);
}

function writeBlacklistData($requestData)
{
    $repBlacklist = fopen('blacklist.html', 'w');

    fwrite($repBlacklist, '<ul>');

    global $database;
    $stmt = $database->prepare('SELECT name FROM request WHERE id = :id');

    foreach ($requestData as $id => $data) {
        foreach ($data as $datum) {
            if ($datum['m'] === REJ_BLACKLIST) {
                $stmt->execute([':id' => $id]);
                $req = $stmt->fetchColumn();
                $stmt->closeCursor();

                fwrite($repBlacklist, '<li><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">#' . $id . " (" . htmlentities($req) . ") matches: " . htmlentities($datum['d']) . "</a></li>");
            }
        }
    }

    fwrite($repBlacklist, '</ul>');

    fclose($repBlacklist);
}