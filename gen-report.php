<?php

chdir(__DIR__);

require('vendor/autoload.php');
require('config.php');
require('functions.php');
$hpbl = new joshtronic\ProjectHoneyPot($honeypotKey);

$database = new PDO($dburl, $dbuser, $dbpass);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cookieJar = tempnam("/tmp", "CURLCOOKIE");
$curlOpt = array(
    CURLOPT_COOKIEFILE => $cookieJar,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_USERAGENT => 'Wikipedia-Account-Creation-Tool/6.8 ReportScript/0.1 (+mailto:wikimedia@stwalkerster.co.uk)',

);

const API_META = 'https://meta.wikimedia.org/w/api.php';
const API_ENWIKI = 'https://en.wikipedia.org/w/api.php';

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

function initialiseDeltaQuadBlacklist()
{
    $dqpatterns = array();
    $dqlist = file_get_contents('https://en.wikipedia.org/w/index.php?title=User:DeltaQuad/UAA/Blacklist&action=raw');
    $dqlistlines = explode("\n", $dqlist);

    unset($dqlistlines[count($dqlistlines) - 1]);
    unset($dqlistlines[0]);


    foreach ($dqlistlines as $line) {
        $ld = explode(':', $line);
        $pattern = substr($ld[0], 1);
        $dqpatterns[] = $pattern;
    }

    return $dqpatterns;
}

function l($request, $message, $data = null)
{
    global $requestData;

    if (!isset($requestData[$request])) {
        $requestData[$request] = [];
    }

    $requestData[$request][] = array('m' => $message, 'd' => $data);
    echo "  " . $message . "\n";
}

login();

$stmt = $database->query("SELECT id, name, forwardedip, date FROM request WHERE status = 'Open' AND emailconfirm = 'Confirmed' AND reserved = 0");
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

$resultCount = count($result);

// {0} = Forwarded IP
// {1} = 2017-10-01T00%3A00%3A00.000Z
// {2} = Username

$usernameBlacklistQuery = [
    'action' => 'titleblacklist',
    'tbaction' => 'new-account',
    'tbtitle' => 'User:{2}',
];

$enwikiGeneralQuery = [
    'action' => 'query',
    'list' => 'blocks|abuselog|usercontribs|logevents|users',

    'bkip' => '{0}',

    'afluser' => '{0}',
    'aflstart' => '{1}',
    'afldir' => 'newer',

    'ucuser' => '{0}',
    'ucstart' => '{1}',
    'ucdir' => 'newer',

    'letitle' => 'User:{0}',
    'letype' => 'block',

    'usprop' => 'registration',
    'ususers' => '{2}',

    'titles' => 'User talk:{0}|User:{2}',
];

$metaGeneralQuery = [
    'action' => 'query',
    'list' => 'globalblocks|logevents',
    'meta' => 'globaluserinfo',

    'bgip' => '{0}',

    'letitle' => 'User:{0}',
    'letype' => 'gblblock',

    'guiuser' => '{2}',
];

$repLog = fopen('log.html', 'w');

$requestData = [];

$dqpatterns = initialiseDeltaQuadBlacklist();

$i = 0;
foreach ($result as $req) {
    $id = $req['id'];
    echo "Processing #" . $id . " - " . ++$i . " / $resultCount\n";

    $requestData[$id] = [];
    $create = true;

    $substitutions = [
        0 => $req['forwardedip'],
        1 => '2017-10-01T00:00:00.000Z',
        2 => $req['name'],
    ];

    // DQ UAA PATTERNS
    foreach ($dqpatterns as $p) {
        if (preg_match(':' . $p . ':', $req['name'])) {
            l($id, REJ_DQBLACKLIST, $p);
            $create = false;
            break;
        }
    }

    // TITLE BLACKLIST
    $usernameBlacklistResult = apiQuery(API_META, $usernameBlacklistQuery, $substitutions);
    if ($usernameBlacklistResult->titleblacklist->result == 'blacklisted') {
        l($id, REJ_BLACKLIST, $usernameBlacklistResult->titleblacklist->line);
        $create = false;
    }

    $enwikiGeneralResult = apiQuery(API_ENWIKI, $enwikiGeneralQuery, $substitutions);
    $metaQuery = apiQuery(API_META, $metaGeneralQuery, $substitutions);


    // FORWARDED IP STUFF
    if (!preg_match('/, /', $req['forwardedip'])) {

        // LOCAL BLOCKS
        if (count($enwikiGeneralResult->query->blocks) > 0) {
            l($id, REJ_HASLOCALBLOCK);
            $create = false;
            foreach ($enwikiGeneralResult->query->blocks as $b) {
                if (strpos($b->reason, 'evasion') === false) {
                    if (strpos($b->reason, '{{anonblock}}') !== false) {
                        continue;
                    }
                    if (strpos($b->reason, '{{school block}}') !== false) {
                        continue;
                    }
                    if (strpos($b->reason, '{{schoolblock}}') !== false) {
                        continue;
                    }
                }

                if (strpos(strtolower($b->reason), 'acc ignore') !== false) {
                    continue;
                }
                if (!isset($b->nocreate)) {
                    continue;
                }

                l($id, REJ_LOCALBLOCK, [$b->reason, $b->user]);
            }
        }

        // ABUSE LOG
        if (count($enwikiGeneralResult->query->abuselog) > 0) {
            l($id, 'Rejected: Abuse log', count($enwikiGeneralResult->query->abuselog));
            $create = false;
        }

        // LOCAL CONTRIBS
        if (count($enwikiGeneralResult->query->usercontribs) > 0) {
            l($id, 'Rejected: Has local contribs', count($enwikiGeneralResult->query->usercontribs));
            $create = false;
        }

        // LOCAL BLOCK LOG
        if (count($enwikiGeneralResult->query->logevents) > 0) {
            l($id, 'Rejected: Local block log', count($enwikiGeneralResult->query->logevents));
            $create = false;
        }

        // GLOBAL BLOCKS
        if (count($metaQuery->query->globalblocks) > 0) {
            l($id, 'Rejected: globally blocked', count($metaQuery->query->globalblocks));
            $create = false;
        }

        // GLOBAL BLOCK LOG
        if (count($metaQuery->query->logevents) > 0) {
            l($id, 'Rejected: Global block log', count($metaQuery->query->logevents));
            $create = false;
        }

        // GLOBAL ACCOUNT
        if (!isset($metaQuery->query->globaluserinfo->missing)) {
            l($id, 'Rejected: global account present');
            $create = false;
        }

        // PROJECT HONEYPOT
        if ($hpbl->query($req['forwardedip']) !== false) {
            l($id, 'Rejected: project honeypot');
            $create = false;
        }

        // PAGE EXISTENCE
        foreach ($enwikiGeneralResult->query->pages as $pageId => $page) {
            if (substr($page->title, 0, strlen("User talk:")) == "User talk:") {
                if (isset($page->pageid)) {
                    l($id, 'Rejected: IP Talk page');
                    $create = false;
                }
            }

            if (substr($page->title, 0, strlen("User:")) == "User:") {
                if (isset($page->pageid)) {
                    l($id, 'Rejected: User page');
                    $create = false;
                }
            }
        }

    } else {
        l($id, 'Rejected: XFF data present');
        $substitutions[0] = '127.255.255.255'; // force this to nothing
        $enwikiGeneralResult = apiQuery(API_ENWIKI, $enwikiGeneralQuery, $substitutions);
        $create = false;
    }

    // SELF-CREATES
    if (!isset($enwikiGeneralResult->query->users[0]->missing)) {
        l($id, REJ_SELFCREATE, $enwikiGeneralResult->query->users[0]->registration);
        $create = false;
    }
}

writeBlockData($requestData);
writeCreateData($requestData);
writeSelfCreateData($requestData);
writeDqBlacklistData($requestData);
writeBlacklistData($requestData);
writeLog($requestData);
writeEmailReport($requestData);

file_put_contents('rqdata.dat', serialize($requestData));


unlink($cookieJar);

echo "Done.\n";