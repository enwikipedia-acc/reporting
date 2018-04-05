<?php

const REJ_LOCALBLOCK = 'Rejected: Locally blocked';
const REJ_HASLOCALBLOCK = 'Rejected: Local blocks exist';
const REJ_SELFCREATE = 'Rejected: self-create';
const REJ_DQBLACKLIST = 'Rejected: DQ blacklist';
const REJ_BLACKLIST = 'Rejected: title blacklist';
const REJ_XFFPRESENT = 'Rejected: XFF data present';

function writeBlockData($requestData)
{
    $localBlocks = [];

    foreach ($requestData as $id => $logs) {
        foreach ($logs as $data) {
            if ($data['m'] === REJ_LOCALBLOCK) {
                if (!isset($localBlocks[$data['d'][0]])) {
                    $localBlocks[$data['d'][0]] = [];
                }

                $localBlocks[$data['d'][0]][] = [$id, $data['d'][1]];
            }
        }
    }

    $repBlocks = fopen('blocks.html', 'w');

    fwrite($repBlocks, '<style>table { border-collapse: collapse; } td {border: 1px solid black; padding: 3px; } td ul { margin-bottom: 0px; }</style>');

    foreach ($localBlocks as $reason => $req) {
        fwrite($repBlocks, '<h3>' . htmlentities($reason) . '</h3><table>');

        foreach ($req as $v) {
            fwrite($repBlocks, '<tr><td><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $v[0] . '">' . $v[0] . '</a></td>');

            fwrite($repBlocks, '<td>'.$v[1].'</td>');

            fwrite($repBlocks, '<td><ul>');
            foreach ($requestData[$v[0]] as $logData) {
                // skip the block information, we already know!
                if ($logData['m'] == REJ_HASLOCALBLOCK || $logData['m'] == REJ_LOCALBLOCK) {
                    continue;
                }

                fwrite($repBlocks, '<li>' . $logData['m'] . '</li>');
            }
            fwrite($repBlocks, '</ul></td>');

            fwrite($repBlocks, '</tr>');
        }

        fwrite($repBlocks, '</table>');
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
                if (is_array($datum['d'])) {
                    $fullMessage = $datum['m'] . ' - <code>' . htmlentities($datum['d'][0]) . '</code>';
                } else {
                    $fullMessage = $datum['m'] . ' - <code>' . htmlentities($datum['d']) . '</code>';
                }
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

    fwrite($repCreate, '<style>table { border-collapse: collapse; } td {border: 1px solid black; padding: 3px; }</style><table>');

    global $database;
    $stmt = $database->prepare('SELECT name, date FROM request WHERE id = :id');

    foreach ($requestData as $id => $data) {
        if (count($data) === 0) {
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            fwrite($repCreate, '<tr><td>' . $data['date'] . '</td><td><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">' . $data['name'] . "</a></td></tr>");
        }
    }

    fwrite($repCreate, '</table>');

    fclose($repCreate);
}

function writeSelfCreateData($requestData)
{
    $repSelfcreate = fopen('selfcreate.html', 'w');

    fwrite($repSelfcreate, '<style>table { border-collapse: collapse; } td {border: 1px solid black; padding: 3px; }</style><table>');

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

function writeXffReport($requestData)
{
    $repBlacklist = fopen('xff.html', 'w');

    fwrite($repBlacklist, '<ul>');

    global $database;
    $stmt = $database->prepare('SELECT name FROM request WHERE id = :id');

    foreach ($requestData as $id => $data) {
        foreach ($data as $datum) {
            if ($datum['m'] === REJ_XFFPRESENT) {
                $stmt->execute([':id' => $id]);
                $req = $stmt->fetchColumn();
                $stmt->closeCursor();

                fwrite($repBlacklist, '<li><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">#' . $id . " (" . htmlentities($req) . ")</a></li>");
            }
        }
    }

    fwrite($repBlacklist, '</ul>');

    fclose($repBlacklist);
}

function writeEmailReport($requestData)
{
    global $database;

    $stmt = $database->query(<<<SQL
SELECT substring_index(email, '@', -1) domain, id FROM request WHERE status = 'Open' AND emailconfirm = 'Confirmed' AND reserved = 0
AND substring_index(email, '@', -1) NOT IN (
    'gmail.com'
  , 'googlemail.com'
  , 'yahoo.com'
  , 'hotmail.com'
  , 'hotmail.co.uk'
  , 'outlook.com'
  , 'icloud.com'
  , 'me.com'
  , 'aol.com'
  , 'live.com'
  , 'att.net'
  , 'att.com'
  , 'comcast.net'
  , 'blueyonder.co.uk'
  , 'earthlink.net'
  , 'rocketmail.com'
  , 'yahoo.co.uk'
  , 'yahoo.in'
  , 'yahoo.de'
  , 'yahoo.com.ph'
  , 'ymail.com'
  , 'bigpond.net.au'
  , 'bigpond.com'
  , 'eastlink.ca'
  , 'shaw.ca'
  , 'gmx.com'
  , 'gmx.co.uk'
  , 'gmx.de'
  , 'talktalk.net'
  , 'virginmedia.com'
  , 'mail.com'
  , 'mailbox.org'
  , 'protonmail.com'
  , 'msn.com'
  , 'qq.com'
)
ORDER BY 1 ASC
SQL
    );

    $groups = $stmt->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP);
    $stmt->closeCursor();

    $stmt = $database->prepare('SELECT name FROM request WHERE id = :id');

    $repEmail = fopen('email.html', 'w');

    foreach ($groups as $k => $idList) {
        fwrite($repEmail, '<h3>' . $k . '</h3><ul>');

        foreach ($idList as $id) {
            $stmt->execute([':id' => $id]);
            $name = $stmt->fetchColumn();
            $stmt->closeCursor();

            fwrite($repEmail, '<li><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">' . $id . '</a> ' . $name);

            if (isset($requestData[$id])) {
                fwrite($repEmail, '<ul>');
                foreach ($requestData[$id] as $logData) {
                    fwrite($repEmail, '<li>' . $logData['m'] . '</li>');
                }
                fwrite($repEmail, '</ul>');
            } else {
                fwrite($repEmail, ' - <em>No data for this request</em>');
            }

            fwrite($repEmail, '</li>');
        }

        fwrite($repEmail, '</ul>');
    }

    fclose($repEmail);
}