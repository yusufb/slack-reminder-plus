<?php
(PHP_SAPI !== 'cli' || isset($_SERVER['HTTP_USER_AGENT'])) && die();

date_default_timezone_set('Europe/Istanbul');
require_once dirname( __FILE__ ) . '/utils.php';

$db = get_db();

$select = 'select * from reminders where alarm_time<=now() and alarm_sent=0 and done=0 order by alarm_time asc;';
$res = $db->prepare($select);
$res->execute(array());
$res->setFetchMode(PDO::FETCH_OBJ);


while ($r = $res->fetch()) {

	$snoozeTimes = getSnoozeTimes($r->uid);
	$snoozeContent = '';
	foreach ($snoozeTimes as $st) {
		$snoozeContent .= '{ "text": { "type": "plain_text", "text": "'.$st.'", "emoji": true }, "value": "snooze_'.$r->id_user.'_'.$st.'" },';
	}
	$snoozeContent = trim($snoozeContent, ',');

	$contentText = ':bell: *' . $r->content . '* _at ' . date('H:i D, j M Y', strtotime($r->alarm_time)) . '_';

	$responseText = '{ "blocks": [ { "type": "section", "text": { "type": "plain_text", "text": "\n\n\n", "emoji": true } }, { "type": "section", "text": { "type": "mrkdwn", "text": "'.$contentText.'" } }, { "type": "section", "text": { "type": "plain_text", "text": "\n\n\n", "emoji": true } }, { "type": "divider" }, { "type": "actions", "elements": [ { "type": "button", "text": { "type": "plain_text", "emoji": true, "text": "Complete" }, "style": "primary", "value": "complete_'.$r->id_user.'" }, { "type": "button", "confirm": { "title": { "type": "plain_text", "text": "Wait!" }, "text": { "type": "mrkdwn", "text": "Are you sure to permanently delete reminder #'.$r->id_user.'?" }, "confirm": { "type": "plain_text", "text": "Yes, delete" }, "deny": { "type": "plain_text", "text": "Back" } }, "text": { "type": "plain_text", "emoji": true, "text": "Delete" }, "style": "danger", "value": "del_'.$r->id_user.'" }, { "type": "static_select", "placeholder": { "type": "plain_text", "text": "Snooze", "emoji": true }, "options": [ '.$snoozeContent.' ] } ] }, { "type": "divider" }, { "type": "actions", "elements": [ { "type": "button", "text": { "type": "plain_text", "text": "... or edit the date", "emoji": true }, "value": "edit_popup_'.$r->id_user.'" } ] } ] }';

	$remindersContent = json_decode($responseText);
	$data = json_encode([
		"channel" => $r->cid,
		"text" => ':bell: ' . $r->content,
		"blocks" => $remindersContent->blocks
	]);

	curlReq('chat.postMessage', $data);


	$updateQuery = $db->prepare('update reminders set alarm_sent=1 where uid=? and id_user=?;');

	if ($updateQuery->execute(array($r->uid, $r->id_user))) {
		// ok!
	} else {
		$responseText = ':x: an error occured while sending you the reminder notification...';
		$data = json_encode([
			"channel" => $r->cid,
			"text" => $responseText
		]);

		curlReq('chat.postMessage', $data);
	}



}
