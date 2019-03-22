<?php

require('config.php');

const REJ_MULTIREQUEST = 'Rejected: Multiple requests detected from this IP/Email';
const REJ_LOCALBLOCK = 'Rejected: Locally blocked';
const REJ_HARDBLOCK = 'Rejected: HARD blocked';
const REJ_HASLOCALBLOCK = 'Rejected: Local blocks exist';
const REJ_SELFCREATE = 'Rejected: self-create';
const REJ_DQBLACKLIST = 'Rejected: DQ blacklist';
const REJ_BLACKLIST = 'Rejected: title blacklist';
const REJ_XFFPRESENT = 'Rejected: XFF data present';
const REJ_SULPRESENT = 'Rejected: global account present';
const REJ_RENAMED = 'Rejected: a user account was renamed from this name';

const API_META = 'https://meta.wikimedia.org/w/api.php';
const API_ENWIKI = 'https://en.wikipedia.org/w/api.php';
const API_STOPFORUMSPAM = 'https://api.stopforumspam.org/api';
const API_XTOOLS = 'https://xtools.wmflabs.org/api/user/simple_editcount/en.wikipedia/';

$database = new PDO($dburl, $dbuser, $dbpass);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cookieJar = tempnam("/tmp", "CURLCOOKIE");
$curlOpt = array(
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_USERAGENT => 'Wikipedia-Account-Creation-Tool/6.8 ReportScript/0.1 (+mailto:wikimedia@stwalkerster.co.uk)',
);

function apiQuery($base, array $params, array $substitutions, $post = false)
{
    global $curlOpt;

    $usableParams = [];

    foreach ($params as $k => $v) {
        $val = $v;

        foreach ($substitutions as $kid => $repl) {
            $val = str_replace('{' . $kid . '}', $repl, $val);
        }

        $usableParams[$k] = $val;
    }

    $usableParams['format'] = 'json';

    $queryString = http_build_query($usableParams);

    $url = $base;

    if (!$post) {
        $url .= '?' . $queryString;
    }

    $ch = curl_init();
    curl_setopt_array($ch, $curlOpt);
    curl_setopt($ch, CURLOPT_URL, $url);

    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
    }

    $data = curl_exec($ch);

    if (curl_errno($ch)) {
        die('cURL Error: ' . curl_error($ch));
    }

    return json_decode($data);
}

function webRequest($url)
{
    global $curlOpt;

    $ch = curl_init();
    curl_setopt_array($ch, $curlOpt);
    curl_setopt($ch, CURLOPT_URL, $url);

    $data = curl_exec($ch);

    if (curl_errno($ch)) {
        die('cURL Error: ' . curl_error($ch));
    }

    return $data;
}

function login()
{
    $tokenResult = apiQuery(API_ENWIKI, ['action' => 'query', 'meta' => 'tokens', 'type' => 'login'], ['', '', '']);

    $logintoken = $tokenResult->query->tokens->logintoken;

    global $wikiuser, $wikipass;

    apiQuery(API_ENWIKI, [
        'action' => 'login',
        'lgname' => $wikiuser,
        'lgpassword' => $wikipass,
        'lgtoken' => $logintoken,
    ], ['', '', ''], true);
}

function cidr($ip, $cidr)
{
    list($subnet, $mask) = explode('/', $cidr);

    if ((ip2long($ip) & ~((1 << (32 - $mask)) - 1) ) == ip2long($subnet))
    { 
        return true;
    }

    return false;
}

function writeFileHeader($h, $reportName = '')
{
    if($reportName != '') {
        $reportName = ' :: ' . $reportName;
    }

    fwrite($h, <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8" /><title>ACC Reporting$reportName</title><style type="text/css">
    body
    {
        background-color: #2b2b2b;
        color: #a9b7c6;
        font-family: DejaVu Sans Mono, monospace;
    }

    table
    {
        border-collapse: collapse;
    }

    tr.row-alternate
    {
        background-color: #404040;
    }

    td {
        border: 1px solid #a9b7c6;
        padding: 3px;
    }

    td ul {
        margin-bottom: 0px;
        margin-block-start: 0px;
    }

    a {
        color: #589df6;
    }

    a:visited {
        color: #986df6;
    }
</style></head><body>
HTML
    );
}

function writeBlockData($requestData)
{
    $idList = array();

    $localBlocks = [];

    foreach ($requestData as $id => $logs) {
        foreach ($logs as $data) {
            if ($data['m'] === REJ_LOCALBLOCK) {
                // create empty array for this block as it doesn't exist yet
                if (!isset($localBlocks[$data['d'][0]])) {
                    $localBlocks[$data['d'][0]] = [];
                }

                $sortValue = uniqid(ip2long(explode('/', $data['d'][1])[0]));

                $localBlocks[$data['d'][0]][$sortValue] = [$id, $data['d'][1]];
            }
        }
    }

    ksort($localBlocks);

    $repBlocks = fopen('blocks.html', 'w');

    writeFileHeader($repBlocks, 'Blocks');

    foreach ($localBlocks as $reason => $req) {
        fwrite($repBlocks, '<h3>' . htmlentities($reason) . '</h3><table>');
        ksort($req);

        foreach ($req as $v) {
            fwrite($repBlocks, '<tr><td><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $v[0] . '">' . $v[0] . '</a></td>');
            $idList[] = $v[0];

            fwrite($repBlocks, '<td>'.$v[1].'</td>');

            fwrite($repBlocks, '<td><ul>');
            foreach ($requestData[$v[0]] as $logData) {
                // skip the block information, we already know!
                if ($logData['m'] == REJ_HASLOCALBLOCK || $logData['m'] == REJ_LOCALBLOCK) {
                    continue;
                }

                if( $logData['m'] == REJ_HARDBLOCK ) {
                    fwrite($repBlocks, '<li>' . $logData['m'] . ' (' . $logData['d'][1] . ')</li>');
                } else {
                    fwrite($repBlocks, '<li>' . $logData['m'] . '</li>');
                }
            }
            fwrite($repBlocks, '</ul></td>');

            fwrite($repBlocks, '</tr>');
        }

        fwrite($repBlocks, '</table>');
    }

    file_put_contents("blocks.js", json_encode($idList));
    fclose($repBlocks);
}

function writeLog($requestData)
{
    $total = count($requestData);
    $i = 0;

    $repLog = fopen('log.html', 'w');
    writeFileHeader($repLog);
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
    $idList = array();

    $repCreate = fopen('create.html', 'w');

    writeFileHeader($repCreate);
    fwrite($repCreate, '<table>');
    fwrite($repCreate, '<tr><th>Request date</th><th>ID</th><th>Name</th><th>Email</th><th>Search</th><th>Reserve</th><th>comment</th></tr>');

    global $database;
    $stmt = $database->prepare('SELECT name, date, email, comment FROM request WHERE id = :id');

    $alternate = true;
    $lastDate = '';

    foreach ($requestData as $id => $data) {
        if (count($data) === 0) {
            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            // row alternate colours
            $dateFlag = explode(' ', $data['date']);
            if($lastDate != $dateFlag[0]) { 
                $alternate = !$alternate;
            }
            $lastDate = $dateFlag[0];

            $idList[] = $id;
            fwrite($repCreate, '<tr' . ($alternate ? ' class="row-alternate"' : '') . '>');
            fwrite($repCreate, '<td style="white-space: nowrap;">' . $data['date'] . '</td>');
            fwrite($repCreate, '<td style="white-space: nowrap;">' . $id . '</td>');
            fwrite($repCreate, '<td style="white-space: nowrap;"><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">' . $data['name'] . '</a></td>');

            $domainpart = strtolower(explode("@", $data['email'])[1]);
            if (!in_array($domainpart, getEmailDomainList())) {
                fwrite($repCreate, '<td style="white-space: nowrap;">' . $data['email'] . '</td>');
            } else {
                fwrite($repCreate, '<td></td>');
            }


            fwrite($repCreate, '<td style="white-space: nowrap;"><a href="https://accounts.wmflabs.org/redir.php?tool=google&data=' . urlencode($data['name']) . '">Search</a></td>');
            fwrite($repCreate, '<td style="white-space: nowrap;"><a href="https://accounts.wmflabs.org/acc.php?action=reserve&resid=' . $id . '">Reserve</a></td>');

            if ($data['comment'] !== '') {
                fwrite($repCreate, '<td style="background-color:midnightblue; mso-data-placement:same-cell; white-space:pre; font-family:monospace;overflow: auto; mso-data-placement:same-cell;">' . $data['comment'] . '</td>');
            }

            fwrite($repCreate, '</tr>');
        }
    }

    file_put_contents("create.js", json_encode($idList));
    fwrite($repCreate, '</table>');

    fclose($repCreate);
}

function writeSelfCreateData($requestData)
{
    $repSelfcreate = fopen('selfcreate.html', 'w');
    writeFileHeader($repSelfcreate, 'Self-creations');
    fwrite($repSelfcreate, '<table>');
    fwrite($repSelfcreate, '<tr><th>Request</th><th>Registration</th><th>Request</th><th>Result</th></tr>');

    global $database;
    $stmt = $database->prepare('SELECT date, name FROM request WHERE id = :id');

    foreach ($requestData as $id => $data) {
        $scMessage = null;

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

                $scMessage = '<tr><td><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">' . $req['name'] . "</a></td><td>" . $datum['d'] . "</td><td>" . $req['date'] . "</td><td>" . $reason . "</td></tr>";
            }

            if ($datum['m'] === REJ_SULPRESENT && $scMessage === null) {
                $stmt->execute([':id' => $id]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmt->closeCursor();

                $reason = 'Global account present';

                $scMessage = '<tr><td><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">' . $req['name'] . "</a></td><td></td><td>" . $req['date'] . "</td><td>" . $reason . "</td></tr>";
            }
        }

        if ($scMessage !== null) {
            fwrite($repSelfcreate, $scMessage);
        }
    }

    fwrite($repSelfcreate, '</table>');

    fclose($repSelfcreate);
}

function writeHardblockData($requestData)
{
    $repHardblocks = fopen('hardblock.html', 'w');
    writeFileHeader($repHardblocks, 'Hard blocks');
    fwrite($repHardblocks, '<table>');
    fwrite($repHardblocks, '<tr><th>Request</th><th>Log</th></tr>');

    foreach ($requestData as $id => $data) {
        foreach ($data as $datum) {
            if ($datum['m'] === REJ_HARDBLOCK) {

                fwrite($repHardblocks, '<tr><td><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">' . $id . "</a></td>");
                fwrite($repHardblocks, '<td><ul>');
                foreach ($data as $logData) {
                    /*if ($logData['m'] == REJ_HASLOCALBLOCK || $logData['m'] == REJ_LOCALBLOCK) {
                        continue;
                    }*/

                    if( $logData['m'] == REJ_HARDBLOCK ) {
                        fwrite($repHardblocks, '<li>' . $logData['m'] . ' (' . $logData['d'][1] . ')</li>');
                    } else {
                        fwrite($repHardblocks, '<li>' . $logData['m'] . '</li>');
                    }
                }
                fwrite($repHardblocks, '</ul></td>');
                fwrite($repHardblocks, '</tr>');
            }
        }
    }

    fwrite($repHardblocks, '</table>');

    fclose($repHardblocks);
}

function writeDqBlacklistData($requestData)
{
    $repDqBlacklist = fopen('dqblacklist.html', 'w');
    writeFileHeader($repDqBlacklist);

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
    writeFileHeader($repBlacklist);
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
    writeFileHeader($repBlacklist);

    $idList = array();

    fwrite($repBlacklist, '<ul>');

    global $database;
    $stmt = $database->prepare('SELECT name FROM request WHERE id = :id');

    foreach ($requestData as $id => $data) {
        foreach ($data as $datum) {
            if ($datum['m'] === REJ_XFFPRESENT) {
                $stmt->execute([':id' => $id]);
                $req = $stmt->fetchColumn();
                $stmt->closeCursor();

                $idList[] = $id;
                fwrite($repBlacklist, '<li><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $id . '">#' . $id . " (" . htmlentities($req) . ")</a></li>");
            }
        }
    }

    file_put_contents("xff.js", json_encode($idList));

    fwrite($repBlacklist, '</ul>');

    fclose($repBlacklist);
}

function writeEmailReport($requestData, $requestState)
{
    global $database;

    $emailDomainList = "'" . implode("','", getEmailDomainList()) . "'";
    $stmt = $database->query(<<<SQL
SELECT substring_index(email, '@', -1) domain, id FROM request WHERE status = '${requestState}' AND emailconfirm = 'Confirmed' AND reserved = 0
AND substring_index(email, '@', -1) NOT IN (${emailDomainList})
ORDER BY 1 ASC
SQL
    );

    $groups = $stmt->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP);
    $stmt->closeCursor();

    $stmt = $database->prepare('SELECT name FROM request WHERE id = :id');

    $repEmail = fopen('email.html', 'w');
    writeFileHeader($repEmail, 'Email domains');

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

function getEmailDomainList() {
    return [
        'gmail.com'
        , 'googlemail.com'
        , 'yahoo.com'
        , 'hotmail.com'
        , 'hotmail.co.uk'
        , 'outlook.com'
        , 'live.co.uk'
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
        , 'btinternet.com'
        , 'telstra.com'
        , 'sky.com'
        , '163.com'
        , 'rediffmail.com'
        , 'bell.net'
        , 'bellsouth.net'
        , 'cox.net'
        , 'yahoo.co.id'
        , 'hotmail.co.nz'
        , 'tampabay.rr.com'
        , 'yahoo.co.in'
        , 'hotmail.co.in'
        , 'free.fr'
        , 'hotmail.ca'
        , 'aol.co.uk'
        , 'outlook.de'
        , 'charter.net'
    ];
}
