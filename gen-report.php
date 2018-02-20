<?php

chdir(__DIR__);

require('vendor/autoload.php');
require('config.php');
$hpbl = new joshtronic\ProjectHoneyPot($honeypotKey);

$database = new PDO($dburl, $dbuser, $dbpass);

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
login();

$stmt = $database->query("select id, name, forwardedip, date from request where status = 'Open' and emailconfirm = 'Confirmed'");
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


$repBlacklist = fopen('blacklist.html', 'w');
$repBlocks = fopen('blocks.html', 'w');
$repSelfcreate = fopen('selfcreate.html', 'w');
$repDqBlacklist = fopen('dqblacklist.html', 'w');
$repCreate = fopen('create.html', 'w');
$repLog = fopen('log.html', 'w');

fwrite($repLog, '<ul>');

$localBlocks = [];

$dqlist = file_get_contents('https://en.wikipedia.org/w/index.php?title=User:DeltaQuad/UAA/Blacklist&action=raw');
$dqlistlines = explode("\n", $dqlist);

unset($dqlistlines[count($dqlistlines)-1]);
unset($dqlistlines[0]);
$dqpatterns = array();

foreach($dqlistlines as $line) {
    $ld = explode(':', $line);
    $pattern = substr($ld[0],1);
    $dqpatterns[] = $pattern;
}

$i = 0;
foreach($result as $req) {
        echo "Processing #" . $req['id'] . " - " . ++$i . " / $resultCount\n";
		fwrite($repLog, "<li>Processing #" . $req['id'] . " - $i / $resultCount<ul>");
        $create = true;

        $substitutions = [
            0 => $req['forwardedip'],
            1 => '2017-10-01T00:00:00.000Z',
            2 => $req['name'],
        ];

        // DQ UAA PATTERNS
        foreach($dqpatterns as $p) {
            if(preg_match(':'.$p.':', $req['name'])) {
                fwrite($repDqBlacklist, '<a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $req['id'] . '">' . $req['id'] . " matches: " . htmlentities($p) . "</a><br />");
                echo "  Rejected: DQ blacklist\n";
				fwrite($repLog, '<li>Rejected: DQ blacklist</li>');
                $create = false;
                break;
            }
        }

        // TITLE BLACKLIST
        $usernameBlacklistResult = apiQuery(API_META, $usernameBlacklistQuery, $substitutions);
        if($usernameBlacklistResult->titleblacklist->result == 'blacklisted') {
                fwrite($repBlacklist, '<a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $req['id'] . '">' . $req['id'] . " matches: " . htmlentities($usernameBlacklistResult->titleblacklist->line) . "</a><br />");
                echo "  Rejected: title blacklist\n";
				fwrite($repLog, '<li>Rejected: title blacklist</li>');
                $create = false;
        }


        $enwikiGeneralResult = apiQuery(API_ENWIKI, $enwikiGeneralQuery, $substitutions);

        // FORWARDED IP STUFF
        if(!preg_match('/, /', $req['forwardedip'])) {

            // LOCAL BLOCKS
            if(count($enwikiGeneralResult->query->blocks) > 0) {
	                echo "  Rejected: local block\n";
                    $create = false;
					foreach($enwikiGeneralResult->query->blocks as $b) {
	                    if(strpos($b->reason,'{{anonblock}}') !== false) continue;
		                if(strpos($b->reason,'{{school block}}') !== false) continue;
		                if(strpos($b->reason,'{{schoolblock}}') !== false) continue;
		                if(strpos(strtolower($b->reason),'acc ignore') !== false) continue;
		                if(!isset($b->nocreate)) continue;

						if(!isset($localBlocks[$b->reason])) {
							$localBlocks[$b->reason] = [];
						}

						$localBlocks[$b->reason][] = $req['id'];

						fwrite($repLog, '<li>Rejected: Locally blocked - <code>'.$b->reason.'</code></li>');
					}
            }

            // ABUSE LOG
            if(count($enwikiGeneralResult->query->abuselog) > 0) {
                echo "  Rejected: abuse log\n";
				fwrite($repLog, '<li>Rejected: Abuse log</li>');
                $create = false;
            }

            // LOCAL CONTRIBS
            if(count($enwikiGeneralResult->query->usercontribs) > 0) {
                echo "  Rejected: local contribs \n";
				fwrite($repLog, '<li>Rejected: Has local contributions</li>');
                $create = false;
            }

            // LOCAL BLOCK LOG
            if(count($enwikiGeneralResult->query->logevents) > 0) {
                echo "  Rejected: local block log\n";
				fwrite($repLog, '<li>Rejected: Local block log</li>');
                $create = false;
            }

            // GLOBAL BLOCKS
            $obj = apiQuery(API_META, $metaGeneralQuery, $substitutions);
            if(count($obj->query->globalblocks) > 0) {
                echo "  Rejected: global block\n";
				fwrite($repLog, '<li>Rejected: Globally blocked</li>');
                $create = false;
            }

            // GLOBAL BLOCK LOG
            if(count($obj->query->logevents) > 0) {
                echo "  Rejected: global block log\n";
				fwrite($repLog, '<li>Rejected: Global Block Log</li>');
                $create = false;
            }

            // GLOBAL ACCOUNT
            if(!isset($obj->query->globaluserinfo->missing)) {
                echo "  Rejected: global account present\n";
				fwrite($repLog, '<li>Rejected: Global account exists</li>');
                $create = false;
            }

            // PROJECT HONEYPOT
            if($hpbl->query($req['forwardedip']) !== false) {
                echo "  Rejected: project honeypot\n";
				fwrite($repLog, '<li>Rejected: Project Honeypot</li>');
                $create = false;
            }

            // PAGE EXISTENCE
            foreach($enwikiGeneralResult->query->pages as $pageId => $page) {
                if(substr($page->title, 0, strlen("User talk:")) == "User talk:") {
                    if(isset($page->pageid)) {
                        echo "  Rejected: IP Talk page\n";
			    	    fwrite($repLog, '<li>Rejected: IP Talk page</li>');
                        $create = false;
                    }
                }

                if(substr($page->title, 0, strlen("User:")) == "User:") {
                    if(isset($page->pageid)) {
                        echo "  Rejected: User page\n";
			    	    fwrite($repLog, '<li>Rejected: User page</li>');
                        $create = false;
                    }
                }
            }

        } else {
            echo "  Rejected: xff\n";
			fwrite($repLog, '<li>Rejected: XFF data present</li>');
			$substitutions[0] = '127.255.255.255'; // force this to nothing
			$enwikiGeneralResult = apiQuery(API_ENWIKI, $enwikiGeneralQuery, $substitutions);
            $create = false;
        }

        // SELF-CREATES
        if(!isset($enwikiGeneralResult->query->users[0]->missing)) {
                $regDate = new DateTime(($enwikiGeneralResult->query->users[0]->registration));
                $reqDate = new DateTime($req['date']);
                $reason = 'Unknown';
                if( $regDate < $reqDate ) {
                        $reason = 'Taken';
                }

                if( $reqDate < $regDate ) {
                        $reason = 'Self-create';
                }

                fwrite($repSelfcreate, '<a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $req['id'] . '">' . $req['name'] . "|" . ($enwikiGeneralResult->query->users[0]->registration) . "|" . $req['date'] . "|" . $reason . "</a><br />");
                echo "  Rejected: self create\n";
				fwrite($repLog, '<li>Rejected: Self-created</li>');
                $create = false;
        }



        if($create) {
                fwrite($repCreate, '<a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $req['id'] . '">' . $req['name'] . "</a><br />");
        }

		fwrite($repLog, '</ul></li>');
}

foreach($localBlocks as $reason => $values) {
	fwrite($repBlocks, '<h3>' . htmlentities($reason) . '</h3><ul>');
	foreach($values as $v) {
		fwrite($repBlocks, '<li><a href="https://accounts.wmflabs.org/acc.php?action=zoom&id=' . $v . '">' . $v . "</a></li>");	
	}
	fwrite($repBlocks, '</ul>');
}


fclose($repBlacklist);
fclose($repBlocks);
fclose($repSelfcreate);
fclose($repDqBlacklist);
fclose($repCreate);
fclose($repLog);

unlink($cookieJar);

