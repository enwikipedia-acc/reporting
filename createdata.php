<?php
function writeCreateData($requestData)
{
    $idList = [];

    $criteria = [
        'create' => [
            'defaultinclude' => true,
            'filter' => function($data, &$includeThis) {}
        ],
        'create-auto' => [ // no comment
            'defaultinclude' => false,
            'filter' => function($data, &$includeThis) {
                if(trim($data['comment']) == '') {
                    $includeThis['create-auto'] = true;
                    $includeThis['create'] = false;
                }
            }
        ],
        'create-email' => [ // odd email domain
            'defaultinclude' => false,
            'filter' => function($data, &$includeThis) {
                $domainpart = strtolower(explode("@", $data['email'])[1]);
                if (!in_array($domainpart, getEmailDomainList())) {
                    $includeThis['create'] = false;
                    $includeThis['create-auto'] = false;
                    $includeThis['create-email'] = true;
                }
            }
        ],
        'create-captcha' => [ // comment indicating captcha problems
            'defaultinclude' => false,
            'filter' => function($data, &$includeThis) {
                if ($data['comment'] !== '') {
                    if(preg_match('/captcha|blind|captche|can\'t see|can\'t read|capcha|verification|capatcha|catche|caphctha|caption|capture/i', $data['comment'])) {
                        $includeThis['create'] = false;
                        $includeThis['create-auto'] = false;
                        $includeThis['create-captcha'] = true;
                    }
                }
            }
        ],
        'create-editathon' => [ // comment indicating editathon attendance
            'defaultinclude' => false,
            'filter' => function($data, &$includeThis) {
                if ($data['comment'] !== '') {
                    if(preg_match('/editathon|meetup/i', $data['comment'])) {
                        $includeThis['create'] = false;
                        $includeThis['create-auto'] = false;
                        $includeThis['create-editathon'] = true;
                    }
                }
            }
        ],
        'create-block' => [ // comment indicating block problems
            'defaultinclude' => false,
            'filter' => function($data, &$includeThis) {
                if ($data['comment'] !== '') {
                    if(preg_match('/blocked|unblock|banned|vandalism/i', $data['comment'])) {
                        $includeThis['create'] = false;
                        $includeThis['create-auto'] = false;
                        $includeThis['create-block'] = true;
                    }
                }
            }
        ],
        'create-sorry' => [ // comment indicating sorry
            'defaultinclude' => false,
            'filter' => function($data, &$includeThis) {
                if ($data['comment'] !== '') {
                    if(preg_match('/sorry/i', $data['comment'])) {
                        $includeThis['create'] = false;
                        $includeThis['create-auto'] = false;
                        if($includeThis['create-block'] ==false) {
                            $includeThis['create-sorry'] = true;
                        }
                    }
                }
            }
        ],
        'create-coi' => [ // comment indicating possible COIs
            'defaultinclude' => false,
            'filter' => function($data, &$includeThis) {
                if ($data['comment'] !== '') {
                    if(preg_match('/company|client|marketing|career|verified|insta|owner/i', $data['comment'])) {
                        $includeThis['create'] = false;
                        $includeThis['create-auto'] = false;
                        $includeThis['create-coi'] = true;
                    }
                }
            }
        ],
        'create-ok' => [ // comment indicating "ok"
            'defaultinclude' => false,
            'filter' => function($data, &$includeThis) {
                if ($data['comment'] !== '') {
                    if(preg_match('/^\s*(none|nil|no|na|nothing|yes|hi|help|help me|hello|ok|okay|please|plz|thank ?you|thanks|ok thanks|ty|no comments|good|request|like|password)\s*\.?\s*$/i', $data['comment'])) {
                        $includeThis['create'] = false;
                        $includeThis['create-auto'] = false;
                        $includeThis['create-ok'] = true;
                    }
                }
            }
        ],
    ];

    foreach (array_keys($criteria) as $key) {
        $criteria[$key]['html'] = fopen($key.'.html', 'w');

        $criteria[$key]['hasitems'] = false;

        writeFileHeader($criteria[$key]['html']);
        fwrite($criteria[$key]['html'], '<table>');
        fwrite($criteria[$key]['html'], '<tr><th>Request date</th><th>ID</th><th>Name</th><th>Email</th><th>Search</th><th>comment</th></tr>');
    }

    global $database;
    $stmt = $database->prepare('SELECT name, date, email, comment FROM request WHERE id = :id');

    $alternate = true;
    $alternateSwitched = false;
    $lastDate = '';

    foreach ($requestData as $id => $data) {
        if (count($data) === 0) {
            $includeThis = [];
            foreach ($criteria as $key => $value) {
                $includeThis[$key] = $value['defaultinclude'];
            }
            
            $lineOutput = '';

            $stmt->execute([':id' => $id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            // row alternate colours
            $dateFlag = explode(' ', $data['date']);
            if($lastDate != $dateFlag[0]) {
                $alternate = !$alternate;
                $alternateSwitched = true;
            }
            $lastDate = $dateFlag[0];

            $idList[] = $id;
            $lineOutput .= ('<tr' . ($alternate ? ' class="row-alternate"' : '') . '>');
            $lineOutput .= ('<td style="white-space: nowrap;">' . $data['date'] . '</td>');
            $lineOutput .= ('<td style="white-space: nowrap;">' . $id . '</td>');
            $lineOutput .= ('<td style="white-space: nowrap;"><a href="https://accounts.wmflabs.org/internal.php/viewRequest?id=' . $id . '">' . $data['name'] . '</a></td>');

            $domainpart = strtolower(explode("@", $data['email'])[1]);
            if (!in_array($domainpart, getEmailDomainList())) {
                $lineOutput .= ('<td style="white-space: nowrap;">' . $data['email'] . '</td>');
            } else {
                $lineOutput .= ('<td></td>');
            }

            $lineOutput .= ('<td style="white-space: nowrap;"><a href="https://accounts.wmflabs.org/redir.php?tool=google&data=' . urlencode($data['name']) . '">Search</a></td>');

            if ($data['comment'] !== '') {
                $lineOutput .= ('<td style="background-color:midnightblue; mso-data-placement:same-cell; white-space:pre; font-family:monospace;overflow: auto; mso-data-placement:same-cell;">' . $data['comment'] . '</td>');
            }
            
            $lineOutput .= ('</tr>');

            foreach (array_keys($criteria) as $key) {
                $func = $criteria[$key]['filter'];
                $func($data, $includeThis);
            }

            foreach (array_keys($criteria) as $key) {
                if($includeThis[$key]) {
                    $criteria[$key]['hasitems'] = true;
                    fwrite($criteria[$key]['html'], $lineOutput);
                }
            }
        }

        $alternateSwitched = false;
    }

    file_put_contents("create.js", json_encode($idList));

    foreach (array_keys($criteria) as $key) {
        fwrite($criteria[$key]['html'], '</table>');
        fclose($criteria[$key]['html']);

        // no items; nuke file content.
        if(!$criteria[$key]['hasitems']) {
            fclose(fopen($key.'.html', 'w'));
        }
    }
}
