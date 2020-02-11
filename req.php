<?php
date_default_timezone_set('Europe/Istanbul');
require_once dirname( __FILE__ ) . '/utils.php';

$req = file_get_contents("php://input");

if (strpos($req, 'payload=') === 0) {
	$req = urldecode(substr($req, 8));
}

$req = json_decode($req);

if($req->type === 'url_verification' and $req->token === VERIF_TOKEN) {
	die($req->challenge);
}

if($req->api_app_id !== APP_ID or $req->token !== VERIF_TOKEN) {
	die('auth!');
}

$input = str_replace(['“', '”'], '"', $req->event->text);

if(!isset($req->event->bot_id) and $req->type !== 'block_actions' and $req->type !== 'view_submission') {

	# list reminders
	if($input === 'l' or $input === 'list' or $input === 'la' or $input === 'list all' or $input === 'listall') {

		$all = ($input === 'la' or $input === 'list all' or $input === 'listall');

		$db = get_db();

		$select = 'select * from reminders where uid=? ';

		if(!$all) {
			$select .= 'and done=0 ';
		}

		$select .= 'order by alarm_time asc;';

		$res = $db->prepare($select);
		$res->execute(array($req->event->user));
		$res->setFetchMode(PDO::FETCH_OBJ);


		$resList = $res->fetchAll();

		$responseText = '{ "blocks": [ { "type": "context", "elements": [ { "type": "mrkdwn", "text": "*your reminders:*" } ] }, { "type": "divider" },';

		$ix = 0;
		foreach ($resList as $r) {
			if($ix>0 and $ix % 5 === 0) {
				$responseText = '{ "blocks": [';
			}

			$prefix = numberToSlackEmoji(1+$ix);
			$reminderDate = date('H:i D, j M Y ', strtotime($r->alarm_time));
			if(strtotime('now')>strtotime($r->alarm_time)) {
				$reminderDate .= ' *(past)*';
			}
			$responseText .= '{ "type": "section", "text": { "type": "mrkdwn", "text": "'.$prefix.' *' . $r->content . '* _(#' . $r->id_user . ')_\n'.$reminderDate.'" }}, {"type": "actions", "elements": [ { "type": "button", "text": { "type": "plain_text", "emoji": true, "text": "Complete" }, "style": "primary", "value": "complete_'.$r->id_user.'" }, { "type": "button", "confirm": { "title": { "type": "plain_text", "text": "Wait!" }, "text": { "type": "mrkdwn", "text": "Are you sure to permanently delete reminder #'.$r->id_user.'?" }, "confirm": { "type": "plain_text", "text": "Yes, delete" }, "deny": { "type": "plain_text", "text": "Back" } }, "text": { "type": "plain_text", "emoji": true, "text": "Delete" }, "style": "danger", "value": "del_'.$r->id_user.'" } ] },';

			if( ($ix>0 and ($ix+1) % 5 === 0) or sizeof($resList)===$ix+1 ) {
				$responseText = substr($responseText, 0, -1);
				$responseText .= '] }';
				$remindersContent = json_decode($responseText);
				$data = json_encode([
			    	"channel" => $req->event->channel,
			    	"blocks" => $remindersContent->blocks
			    ]);

				curlReq('chat.postMessage', $data);
			}

			$ix++;
		}

		if(empty($resList)) {
			$responseText = '{ "blocks": [ { "type": "context", "elements": [ { "type": "mrkdwn", "text": "*no reminders!*" } ] } ] }';
			$remindersContent = json_decode($responseText);
			$data = json_encode([
		    	"channel" => $req->event->channel,
		    	"blocks" => $remindersContent->blocks
		    ]);

			curlReq('chat.postMessage', $data);
		}


	# add new reminder
	} elseif(preg_match('/^".*" .*$/i', $input)) {

		$parts = explode('"', $input);
		$last = array_pop($parts);
		$parts = array(implode('"', $parts), $last);
		$content = ltrim($parts[0], '"');

		$reminderTime = convTime(trim($parts[1]), getDefaultHour($req->event->user));

		if($reminderTime !== FALSE) {
	        $db = get_db();
	        $insertQuery = $db->prepare('SET @v1 := (select 1+ifnull(max(id_user),0) from reminders where uid=?); insert into reminders(id_user, uid, cid, content, alarm_time) values(@v1, ?, ?, ?, ?);');

	        if ($insertQuery->execute(array($req->event->user, $req->event->user, $req->event->channel, $content, $reminderTime))) {
	            $responseText = ':white_check_mark: done! added the reminder at ' . date('H:i D, j M Y ', strtotime($reminderTime)) . '. type `list` or `l` for your upcoming reminders.';
	        } else {
	            $responseText = ':x: an error occured! please try again some time...';
	        }
		} else {
			$responseText = ':x: invalid date! type `help` for date formats.';
		}

		$data = json_encode([
	    	"channel" => $req->event->channel,
	    	"text" => $responseText
	    ]);

		curlReq('chat.postMessage', $data);


		#help menu
	} else if($input === 'help') {

		$helpContent = json_decode(getHelpMenuContent());
		$data = json_encode([
	    	"channel" => $req->event->channel,
	    	"blocks" => $helpContent->blocks
	    ]);

		curlReq('chat.postMessage', $data);

		# prefs help
	} else if($input === 'pref' or $input === 'prefs' or $input === 'pref snooze') {
		$responseText = "type `pref list` for your preferences.\ntype `pref hour [time]` to set your default hour.\ntype `pref snooze [time]` to set your snooze times.";

		$data = json_encode([
	    	"channel" => $req->event->channel,
	    	"text" => $responseText
	    ]);

		curlReq('chat.postMessage', $data);


		# prefs
	} else if(strpos($input, 'pref ')  === 0) {

		$p = preg_split('/\s+/', $input, 3);

		$prefOption = trim($p[1]);

		#default hour
		if($prefOption  === 'hour') {

			if(preg_match('/^\d{1,2}:\d{2}$/', trim($p[2]))) {
				$hhmm = str_pad(trim($p[2]), 5, "0", STR_PAD_LEFT);

				$db = get_db();
		        $upsertQuery = $db->prepare('INSERT INTO prefs (uid, cid, default_hour) VALUES(?,?,?) ON DUPLICATE KEY UPDATE default_hour=?');

		        if ($upsertQuery->execute(array($req->event->user, $req->event->channel, $hhmm, $hhmm))) {
		            $responseText = ':white_check_mark: ' . $hhmm . ' is set as your default hour.';
		        } else {
		            $responseText = ':x: an error occured! please try again some time...';
		        }
			
			} else {
				$responseText = ':x: default hour must be in `hh:mm` format';
			}


			#default snooze times
		} else if($prefOption  === 'snooze') {
			$times = explode(',', $p[2]);
			$responseText = '';
			$validTimes = array();
			foreach ($times as $t) {
				$tt = convTime(trim($t));
				if($tt > 0) {
					array_push($validTimes, trim($t));
				} else {
					if(strlen($responseText) === 0) {
						$responseText = ':x: ';
					}
					$responseText .= '`' . trim($t) . '`, ';
				}
			}

			if(strlen($responseText) > 0) {
				$responseText = substr($responseText, 0, -2);
				$responseText .= ' invalid. type `help` for valid times. ';
			}

			if(sizeof($validTimes) > 0) {
				$validTimesStr = implode(', ', $validTimes);
				
				$db = get_db();
		        $upsertQuery = $db->prepare('INSERT INTO prefs (uid, cid, snooze_times) VALUES(?,?,?) ON DUPLICATE KEY UPDATE snooze_times=?');
		        if ($upsertQuery->execute(array($req->event->user, $req->event->channel, $validTimesStr, $validTimesStr))) {
		            $responseText .= "\n" . ':white_check_mark: `' . $validTimesStr . '` is set as your default snooze times.';
		        } else {
		            $responseText = "\n" . 'x: an error occured! please try again some time...';
		        }
		    }

			#pref list
		} else if($prefOption  === 'list') {
			$db = get_db();
			
			$res = $db->prepare('select snooze_times, default_hour from prefs where uid=? limit 1;');
			$res->execute(array($req->event->user));
			$res->setFetchMode(PDO::FETCH_OBJ);
			
			$s = $res->fetch();
			$responseText = ':information_source: ';
			if($s->default_hour) {
				$responseText .= 'default hour: `' . $s->default_hour . '`, '; 
			} else {
				$responseText .= 'no default hour, ';
			}

			if($s->snooze_times) {
				$responseText .= 'snooze times: `' . $s->snooze_times . '`.'; 
			} else {
				$responseText .= 'no snooze times.';
			}
			
		} else {
			$responseText = ':x: invalid pref! type `help` for more';
		}

		$data = json_encode([
	    	"channel" => $req->event->channel,
	    	"text" => $responseText
	    ]);

		curlReq('chat.postMessage', $data);

	} else if(isset($req->event->message->bot_id)) {
		//ignore
 	} else {

		$data = json_encode([
	    	"channel" => $req->event->channel,
	    	"text" => ':x: invalid command! type `help` for more.'
	    ]);

		curlReq('chat.postMessage', $data);
	}

} else {

	# delete reminder
	if (strpos($req->actions[0]->value, 'del_') === 0) {

		$delId = intval(substr($req->actions[0]->value, 4));

		$db = get_db();
        $delQuery = $db->prepare('delete from reminders where uid=? and id_user=?;');

        if ($delQuery->execute(array($req->user->id, $delId))) {
        	if($delQuery->rowCount() > 0) {
            	$responseText = ':white_check_mark: reminder #' . $delId . ' has been deleted...';
            	  $updateData = json_encode([
			    	"channel" => $req->channel->id,
			    	"text" => '_#' . $delId . ' is deleted._',
			    	"blocks" =>  null,
			    	"ts" => $req->message->ts
			    ]);

				curlReq('chat.update', $updateData);
        	} else {
        		$responseText = ':information_source: no reminder found with id #' . $delId;
        	}
        } else {
            $responseText = ':x: an error occured! please try again some time...';
        }

		$data = json_encode([
	    	"channel" => $req->channel->id,
	    	"text" => $responseText
	    ]);

		curlReq('chat.postMessage', $data);
	


		#show popup for reminder date edit
	} else if (strpos($req->actions[0]->value, 'edit_popup_') === 0) {
		$editId = intval(substr($req->actions[0]->value, 11));


		$modalData = '{ "trigger_id": "'.$req->trigger_id.'", "view": { "type": "modal", "title": { "type": "plain_text", "text": "'.APP_NAME.'", "emoji": true }, "submit": { "type": "plain_text", "text": "Submit", "emoji": true }, "close": { "type": "plain_text", "text": "Cancel", "emoji": true }, "callback_id": "edit_'.$editId.'_'.$req->channel->id.'_'.$req->container->message_ts.'", "blocks": [ { "type": "input", "element": { "type": "plain_text_input" }, "label": { "type": "plain_text", "text": "Enter a valid date format to snooze the event #'.$editId.'", "emoji": true } } ] } }';
		curlReq('views.open', $modalData);


		#mark as complete
	} else if(strpos($req->actions[0]->value, 'complete_') === 0) {

		$updateId = intval(substr($req->actions[0]->value, 9));

		$uid = $req->user->id;
		$cid = $req->channel->id;

		$db = get_db();
	    $updateQuery = $db->prepare('update reminders set done=1 where uid=? and id_user=?;');

	    if ($updateQuery->execute(array($uid, $updateId))) {
	    	if($updateQuery->rowCount() > 0) {
	        	$responseText = ':white_check_mark: reminder #' . $updateId . ' has been marked as complete...';
    			$updateData = json_encode([
			    	"channel" => $cid,
			    	"text" => '_#' . $updateId . ' is completed._',
			    	"blocks" =>  null,
			    	"ts" => $req->message->ts
			    ]);

				curlReq('chat.update', $updateData);
	    	} else {
	    		$responseText = ':information_source: reminder #' . $updateId . ' is already completed.';
	    	}
	    } else {
	        $responseText = ':x: an error occured! please try again some time...';
	    }

		$data = json_encode([
	    	"channel" => $cid,
	    	"text" => $responseText
	    ]);

		curlReq('chat.postMessage', $data);
			


		#snooze
	} else if(strpos($req->actions[0]->selected_option->value, 'snooze_') === 0) {

		$input = explode('_', $req->actions[0]->selected_option->value);

		$updateId = intval($input[1]);
		$updateTime = $input[2];
		$uid = $req->user->id;
		$cid = $req->channel->id;

    	$updateData = json_encode([
	    	"channel" => $cid,
	    	"text" => '_#' . $updateId . ' is snoozed._',
	    	"blocks" =>  null,
	    	"ts" => $req->message->ts
	    ]);

		curlReq('chat.update', $updateData);

		snoozeReminder($updateId, $uid, $cid, $updateTime);


		#snooze with edit custom time menu
	} else if(strpos($req->view->callback_id, 'edit_') === 0) {

		$input = explode('_', $req->view->callback_id);
		$updateId = intval($input[1]);
		$cid = $input[2];
			
		$d = (array) $req->view->state->values;
		$d = (array) $d[array_keys($d)[0]];
		$d = $d[array_keys($d)[0]];

		$updateData = json_encode([
	    	"channel" => $cid,
	    	"text" => '_#' . $updateId . ' is snoozed._',
	    	"blocks" =>  null,
	    	"ts" => $input[3]
	    ]);

		curlReq('chat.update', $updateData);

		snoozeReminder($updateId, $req->user->id, $cid, $d->value);

	}

}


function snoozeReminder($updateId, $uid, $cid, $updateTime) {

    $reminderTime = convTime(trim($updateTime), getDefaultHour($uid));

    if(($reminderTime === FALSE) or strtotime($reminderTime) < strtotime('now')) {

    	$responseText = ':x: invalid snooze time!';

    } else {

    	$db = get_db();
	    $updateQuery = $db->prepare('update reminders set alarm_sent=0, done=0, alarm_time=? where uid=? and id_user=?;');

	    if ($updateQuery->execute(array($reminderTime, $uid, $updateId))) {
	    	if($updateQuery->rowCount() > 0) {
	        	$responseText = ':white_check_mark: reminder #' . $updateId . ' has been snoozed until ' . date('H:i D, j M Y ', strtotime($reminderTime));
	    	} else {
	    		$responseText = ':information_source: reminder #' . $updateId . ' not found!';
	    	}
	    } else {
	        $responseText = ':x: an error occured! please try again some time...';
	    }

	}

	$data = json_encode([
    	"channel" => $cid,
    	"text" => $responseText
    ]);

	curlReq('chat.postMessage', $data);

}


function getHelpMenuContent() {
	return '{ "blocks": [ { "type": "divider" }, { "type": "section", "text": { "type": "mrkdwn", "text": ":calendar:   *'.APP_NAME.'*" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`list` or `l` for listing incomplete reminders\n\n`list all` or `la` for listing all reminders\n\n" } }, { "type": "divider" }, { "type": "section", "text": { "type": "mrkdwn", "text": "`prefs` for setting preferences\n\n" } }, { "type": "divider" }, { "type": "section", "text": { "type": "mrkdwn", "text": "Add your reminder with the following syntax:\n\n`\"My new reminder\" dec 19 17:15`\n\n" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "\n\n\n" } }, { "type": "divider" }, { "type": "section", "text": { "type": "mrkdwn", "text": "\n\n\n" } }, { "type": "section", "text": { "type": "mrkdwn", "text": ":information_source:   *Date formats*" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "\n\n\n" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`dec 19` - _reminder at default hour_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`dec 19 12:15` - _reminder at specified hour_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`dec 19 21` - _reminder at the top of the specified hour (21:00)_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`16:30` - _reminder at the specified hour for today_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`16` - _reminder at the top of the specified hour for today_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`tomorrow` or `tm` - _reminder at default hour for tomorrow_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`tomorrow 14` or `tm 14` - _reminder at the top of the specified hour for tomorrow (14:00)_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`tomorrow 14:15` or `tm 14:15` - _reminder at the specified hour for tomorrow_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`mon` to `sun` - _reminder at the default hour in the next occurrence of the specified day_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`mon 14` - _reminder at the top of the specified hour in the next occurrence of the specified day (14:00)_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`mon 14:15` - _reminder at the specified hour in the next occurrence of the specified day_" } }, { "type": "section", "text": { "type": "mrkdwn", "text": "`in 3h` or `in 30m` or `in 1h 30m` - _relative times to now_" } } ] }';
}

