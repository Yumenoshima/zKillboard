<?php

require_once '../init.php';

$failure = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiFailure', 300);
$guzzler = new Guzzler(30, 1);

$minute = date('Hi');
while ($minute == date('Hi')) {
    if ($redis->llen("zkb:char:pool") == 0) {
        $rows = $mdb->find("information", ['type' => 'characterID'], ['lastApiUpdate' => 1], 1000);
        foreach ($rows as $row) {
            $redis->rPush("zkb:char:pool", $row['id']);
        }
    }

    $id = $redis->lpop("zkb:char:pool");
    $row = $mdb->findDoc("information", ['type' => 'characterID', 'id' => (int) $id]);
    if ((time() - @$row['lastApiUpdate']->sec) < 86400) {
        if ($redis->llen("zkb:char:pool") == 0) break;
        continue;
    }
    $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now()] );

    $url = "https://esi.tech.ccp.is/v4/characters/$id/";
    $params = ['mdb' => $mdb, 'redis' => $redis, 'row' => $row];
    $guzzler->call($url, "updateChar", "failChar", $params);
    if ($failure->count() > 200) sleep(1);
}      
$guzzler->finish();

function failChar(&$guzzler, &$params, &$connectionException)
{
    $mdb = $params['mdb'];
    $redis = $params['redis'];
    $code = $connectionException->getCode();
    $row = $params['row'];
    $id = $row['id'];

    switch ($code) {
        case 0: // timeout
        case 503: // server error
        case 200: // timeout...
            $mdb->set("information", $row, ['lastApiUpdate' => $mdb->now(86400 * -2)]);
            break;
        default:
            Util::out("/eve/CharacterInfo failed for $id with code $code");
    }
    $xmllog = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiFailure', 300);
    $xmllog->add(uniqid());
}

function updateChar(&$guzzler, &$params, &$content)
{
    $mdb = $params['mdb'];
    $row = $params['row'];
    $json = json_decode($content, true);

    $id = $row['id'];
    $corpID = (int) $json['corporation_id'];

    $updates = [];
    compareAttributes($updates, "name", @$row['name'], (string) $json['name']);
    compareAttributes($updates, "corporationID", @$row['corporationID'], $corpID);
    compareAttributes($updates, "allianceID", @$row['allianceID'], (int) @$json['alliance_id']);
    compareAttributes($updates, "factionID", @$row['factionID'], 0);
    compareAttributes($updates, "secStatus", @$row['secStatus'], (double) $json['security_status']);

    $corpExists = $mdb->count('information', ['type' => 'corporationID', 'id' => $corpID]);
    if ($corpExists == 0) {
        $mdb->insertUpdate('information', ['type' => 'corporationID', 'id' => $corpID]);
    }

    if (sizeof($updates) > 0) {
        $mdb->set("information", $row, $updates);
    }
    $success = new \cvweiss\redistools\RedisTtlCounter('ttlc:esiSuccess', 300);
    $success->add(uniqid());
}

function compareAttributes(&$updates, $key, $oAttr, $nAttr) {
    if ($oAttr !== $nAttr) {
        $updates[$key] = $nAttr;
    }
}
