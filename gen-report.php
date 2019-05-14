#!/usr/bin/env php
<?php

chdir(__DIR__);

require('vendor/autoload.php');
require('functions.php');
$hpbl = new joshtronic\ProjectHoneyPot($honeypotKey);

$options = getopt('s:r:u');

$dbParam = [];
$dbParam[':filterRequest'] = 0;
$dbParam[':request'] = 0;
$dbParam[':status'] = 'Open';

$updateMode = false;
$requestData = [];

if(isset($options['s'])) {
    $dbParam[':status'] = $options['s'];
}

if (isset($options['r'])) {
    $dbParam[':filterRequest'] = 1;
    $dbParam[':request'] = $options['r'];
}

if (isset($options['u'])) {
    $updateMode = true;
    $requestData = unserialize(file_get_contents('rqdata.dat'));
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

$stmt = $database->prepare("SELECT id, name, forwardedip, date, email FROM request WHERE status = :status AND emailconfirm = 'Confirmed' AND reserved = 0 AND (:filterRequest = 0 OR :request = id)");
$stmt->execute($dbParam);
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

$alternatesstmt = $database->prepare("SELECT COUNT(*) FROM request WHERE (email = :email OR forwardedip LIKE CONCAT('%', :ip, '%')) AND email <> 'acc@toolserver.org' AND ip <> '127.0.0.1'");

$resultCount = count($result);

// {0} = Forwarded IP
// {1} = 2017-10-01T00%3A00%3A00.000Z  (~ 3m from oldest; hardcoded)
// {2} = Username

$usernameBlacklistQuery = [
    'action' => 'titleblacklist',
    'tbaction' => 'new-account',
    'tbtitle' => 'User:{2}',
];

$enwikiGeneralQuery = [
    'action' => 'query',
    'list' => 'blocks|abuselog|usercontribs|logevents|users|alldeletedrevisions',

    'bkip' => '{0}',

    'afluser' => '{0}',
    'aflstart' => '{1}',
    'afldir' => 'newer',

    'ucuser' => '{0}',
    'ucstart' => '{1}',
    'ucdir' => 'newer',

    'letitle' => 'User:{0}',
    'letype' => 'block',
    'lestart' => '{1}',
    'ledir' => 'newer',

    'usprop' => 'registration',
    'ususers' => '{2}',

    // /w/api.php?action=query&format=json&list=alldeletedrevisions&adrprop=timestamp&adrlimit=1&adruser=Stwalkerster&adrdir=older
    'adrprop' => 'timestamp',
    'adrlimit' => 1,
    'adruser' => '{0}',
    'adrstart' => '{1}',
    'adrdir' => 'newer',

    'titles' => 'User talk:{0}|User:{2}',
    'prop' => 'info',
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

$dqpatterns = initialiseDeltaQuadBlacklist();

$i = 0;
foreach ($result as $req) {
    $id = $req['id'];
    echo "Processing #" . $id . " - " . ++$i . " / $resultCount\n";

    if ($updateMode && $dbParam[':filterRequest'] == 0) {
        if (isset($requestData[$id])) {
            echo "  Skipping due to analysis already completed.\n";
            continue;
        }
    }

    $requestData[$id] = [];
    $create = true;

    $substitutions = [
        0 => $req['forwardedip'],
        1 => '2018-08-01T00:00:00.000Z', // three months from last request. We should probably autodiscover this.
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
    if (preg_match('/, /', $req['forwardedip'])) {
        $operamini = [
            '37.228.104.0/21',
            '58.67.157.0/24',
            '59.151.95.128/25',
            '59.151.98.128/27',
            '59.151.106.224/27',
            '59.151.120.32/27',
            '82.145.208.0/20',
            '91.203.96.0/22',
            '107.167.96.0/19',
            '116.58.209.128/27',
            '123.103.58.0/24',
            '141.0.8.0/21',
            '185.26.180.0/22',
            '195.189.142.0/23',
            '209.170.68.0/24'
        ];

        $xffcomponents = explode(',', $req['forwardedip']);
        if (count($xffcomponents) > 2) {
            l($id, REJ_XFFPRESENT);
            $create = false;
            continue;
        }

        $okay = false;
        foreach ($operamini as $range) {
            if (cidr(trim($xffcomponents[1]), $range)) {
                $okay = true;
                break;
            }
        }

        if ($okay) {
            $substitutions[0] = trim($xffcomponents[1]);
            $req['forwardedip'] = trim($xffcomponents[1]);
            $enwikiGeneralResult = apiQuery(API_ENWIKI, $enwikiGeneralQuery, $substitutions);
            $metaQuery = apiQuery(API_META, $metaGeneralQuery, $substitutions);
        } else {
            l($id, REJ_XFFPRESENT);
            $create = false;
            continue;
        }
    }

    if (isset($enwikiGeneralResult->error)) {
        l($id, 'ERROR: Encountered API error during call to enwiki - skipping remaining checks');

        var_dump($enwikiGeneralResult->error);
        continue;
    }

    if (isset($metaQuery->error)) {
        l($id, 'ERROR: Encountered API error during call to meta - skipping remaining checks');
        continue;
    }

    // SELF-CREATES
    if (!isset($enwikiGeneralResult->query->users[0]->missing) && !isset($enwikiGeneralResult->query->users[0]->invalid)) {
        l($id, REJ_SELFCREATE, $enwikiGeneralResult->query->users[0]->registration);
        $create = false;
        continue;
    }

    {
        // MULTIPLE REQUESTS
        $alternatesstmt->execute([ ':ip' => $req['forwardedip'], ':email' => $req['email'] ]);
        $altresult = $alternatesstmt->fetchColumn();
        $alternatesstmt->closeCursor();
        if ($altresult > 1) {
            l($id, REJ_MULTIREQUEST);
            $create = false;
        }


        // LOCAL BLOCKS
        if (count($enwikiGeneralResult->query->blocks) > 0) {
            l($id, REJ_HASLOCALBLOCK);
            $create = false;
            foreach ($enwikiGeneralResult->query->blocks as $b) {
                if (!isset($b->anononly)) {
                    l($id, REJ_HARDBLOCK, [$b->reason, $b->user]);
                }

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

        // PROJECT HONEYPOT
        if ($hpbl->query($req['forwardedip']) !== false) {
            l($id, 'Rejected: project honeypot');
            $create = false;
        }

        // STOP FORUM SPAM
        $sfsResult = apiQuery(API_STOPFORUMSPAM, ['ip' => $req['forwardedip'], 'json' => '', 'expiry' => 90], []);
        if ($sfsResult->success != 1) {
            l($id, 'ERROR: StopForumSpam API call failed');
            $create = false;
        }
        if ($sfsResult->ip->appears != 0) {
            l($id, 'Rejected: StopForumSpam hit', 'last seen ' . $sfsResult->ip->lastseen . '  frequency: ' . $sfsResult->ip->frequency . '  confidence: ' . $sfsResult->ip->confidence);
            $create = false;
        }

        // DELETED CONTRIBS
        if (count($enwikiGeneralResult->query->alldeletedrevisions) > 0) {
            l($id, 'Rejected: Has local deleted contribs', count($enwikiGeneralResult->query->alldeletedrevisions));
            $create = false;
        }

        // PAGE EXISTENCE
        foreach ($enwikiGeneralResult->query->pages as $pageId => $page) {
            if (substr($page->title, 0, strlen("User talk:")) == "User talk:") {
                if (isset($page->pageid)) {
                    if ($page->touched > $substitutions[1]) {
                        l($id, 'Rejected: IP Talk recently touched');
                        $create = false;
                    }
                }
            }

            if (substr($page->title, 0, strlen("User:")) == "User:") {
                if (isset($page->pageid)) {
                    l($id, 'Rejected: User page exists');
                    $create = false;
                }
            }
        }

        // GLOBAL ACCOUNT
        if (!isset($metaQuery->query->globaluserinfo->missing) && !isset($metaQuery->query->globaluserinfo->invalid)) {
            l($id, REJ_SULPRESENT, [$metaQuery->query->globaluserinfo->registration, $metaQuery->query->globaluserinfo->home]);
            $create = false;
        }

        // GLOBAL RENAME - blocked wmfphab:T193671
        $pageresult = webRequest("https://meta.wikimedia.org/w/index.php?title=Special%3ALog&type=gblrename&oldname=" . urlencode($req['name']));
        if (strpos($pageresult, 'No matching items in log.') === false) {
            l($id, REJ_RENAMED);
            $create = false;
        }
    }

    if ($i % 100 == 0) {
        writeBlockData($requestData);
        writeCreateData($requestData);
        writeSelfCreateData($requestData);
        writeXffReport($requestData);
        writeHardblockData($requestData);
    }

}

writeBlockData($requestData);
writeCreateData($requestData);
writeSelfCreateData($requestData);
writeDqBlacklistData($requestData);
writeBlacklistData($requestData);
writeLog($requestData);
writeEmailReport($requestData, $dbParam[':status']);
writeXffReport($requestData);
writeHardblockData($requestData);

file_put_contents('rqdata.dat', serialize($requestData));


unlink($cookieJar);

echo "Done.\n";

