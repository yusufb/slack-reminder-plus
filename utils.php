<?php

require_once dirname( __FILE__ ) . '/constants.php';

function logx($i) {
	$req_dump = print_r($i, TRUE);
	$fp = fopen('request.log', 'a');
	fwrite($fp, $req_dump);
	fclose($fp);
}

function get_db() {
    require_once(dirname( __FILE__ ) . '/../db-config.php');
    $db = new PDO('mysql:host='. DB_HOST . ';dbname=' . DB_NAME_SLACK_REMINDER . ';charset=utf8mb4', DB_USER, DB_PASS);
    $db->exec("set names utf8mb4");
    return $db;
}

function curlReq($url, $data, $contentType='application/json; charset=utf-8') {

	$url = SLACK_API_BASE_URL . $url;

	$ch = curl_init($url);                                                                      
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);                                                                  
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);                                                                      
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(              
		'Authorization: Bearer ' . AUTH_TOKEN,                                                            
	    'Content-Type: ' . $contentType,
	    'Content-Length: ' . strlen($data))                                                                       
	);                                                                                                                   
	                                                                                                                     
	$result = curl_exec($ch);
	curl_close($ch);
	return $result;
}

function getSnoozeTimes($uid) {
	$db = get_db();
	$res = $db->prepare('select snooze_times from prefs where uid=? limit 1;');
	$res->execute(array($uid));
	$res->setFetchMode(PDO::FETCH_OBJ);
	$s = $res->fetch()->snooze_times;
	$snoozeTimes = array();
	if($s) {
		$ss = explode(',', $s);
		foreach($ss as $st) {
			if(convTime(trim($st)) > 0) {
				array_push($snoozeTimes, trim($st));
			}
		}	
	} else {
		$ss = explode(',', DEFAULT_SNOOZE_TIMES);
		foreach($ss as $st) {
			array_push($snoozeTimes, trim($st));
		}	
	}

	return $snoozeTimes;
}

function getDefaultHour($uid) {
	$db = get_db();
	$res = $db->prepare('select default_hour from prefs where uid=? limit 1;');
	$res->execute(array($uid));
	$res->setFetchMode(PDO::FETCH_OBJ);
	return $res->fetch()->default_hour;
}

function numberToSlackEmoji($n) {
	$digits = array('zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine');
	$ds = str_split($n);
	$r = '';
	foreach ($ds as $d) {
		$r .= ':'.$digits[$d].':';
	}
	return $r;
}

function convTime($i, $default_hour=DEFAULT_DEFAULT_HOUR) {

	$i = strtolower($i);

	if(!$default_hour) {
		$default_hour = DEFAULT_DEFAULT_HOUR;
	}

	$months = array('jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec');
	$days = array('mon'=>'Monday', 'tue'=>'Tuesday', 'wed'=>'Wednesday', 'thu'=>'Thursday', 'fri'=>'Friday', 'sat'=>'Saturday', 'sun'=>'Sunday');


	# "thu"
	if(array_key_exists($i, $days)) {

		$t = strtotime('next ' . $days[$i] . ' ' . $default_hour . ':00');


	# "thu 19:45"
	} else if(preg_match('/^[a-z]{3} \d{1,2}:\d{2}$/i', $i)) {
		$p = explode(' ', $i);
		if(array_key_exists($p[0], $days)) {
			$t = strtotime('next ' . $days[$p[0]] . ' ' . $p[1]);
		}




	# "dec 19" / "thu 19"
	} else if(preg_match('/^[a-z]{3} \d{1,2}$/i', $i)) {

		$p = explode(' ', $i);
		$s = array_search(strtolower($p[0]), $months);

		if($s === FALSE) {
			$t = -1;

			if(array_key_exists($p[0], $days)) {
				$t = strtotime('next ' . $days[$p[0]] . ' ' . $p[1] . ':00');
			}

		} else {
			$month = 1 + $s;
			$day = $p[1];
			$t = strtotime(date('Y') . '-' . $month . '-' . $day . ' ' . $default_hour . ':00');

			if($t < strtotime('now')) {
				$t = strtotime(1+date('Y') . '-' . $month . '-' . $day . ' ' . $default_hour . ':00');
			}

		}



		# "dec 19 12:15"
	} else if(preg_match('/^[a-z]{3} \d{1,2} \d{1,2}:\d{2}$/i', $i)) {

		$p = explode(' ', $i);
		$s = array_search(strtolower($p[0]), $months);

		if($s === FALSE) {
			$t = -1;
		} else {
			$month = 1 + $s;
			$day = $p[1];
			$t = strtotime(date('Y') . '-' . $month . '-' . $day . ' ' . $p[2]);

			if($t < strtotime('now')) {
				$t = strtotime(1+date('Y') . '-' . $month . '-' . $day . ' ' . $p[2]);
			}

		}

		# "dec 19 21"
	} else if(preg_match('/^[a-z]{3} \d{1,2} \d{1,2}$/i', $i)) {

		$p = explode(' ', $i);
		$s = array_search(strtolower($p[0]), $months);

		if($s === FALSE) {
			$t = -1;
		} else {
			$month = 1 + $s;
			$day = $p[1];
			$t = strtotime(date('Y') . '-' . $month . '-' . $day . ' ' . $p[2] . ':00');

			if($t < strtotime('now')) {
				$t = strtotime(1+date('Y') . '-' . $month . '-' . $day . ' ' . $p[2] . ':00');
			}

		}


		# "tomorrow" / "tm"
	} else if($i === 'tomorrow' or $i === 'tm') {
		$t = strtotime('tomorrow ' . $default_hour.':00');

		# "tomorrow 14" / "tm 14"
	} else if(preg_match('/^(tomorrow|tm) \d{1,2}$/i', $i)) {
		$p = explode(' ', $i);
		$t = strtotime('tomorrow ' . $p[1].':00');

		# "tomorrow 14:30" / "tm 14:30"
	} else if(preg_match('/^tomorrow|tm \d{1,2}:\d{1,2}$/i', $i)) {
		$p = explode(' ', $i);
		$t = strtotime('tomorrow ' . $p[1]);


		# (today) "16"
	} else if(preg_match('/^\d{1,2}$/i', $i)) {
		$t = strtotime(date('Y-m-d '.$i.':00', strtotime('now')));

		if($t < strtotime('now')) {
			$t = strtotime('tomorrow ' . $i.':00');
		}

		# (today) "16:30"
	} else if(preg_match('/^\d{1,2}:\d{1,2}$/i', $i)) {
		$t = strtotime(date('Y-m-d '.$i, strtotime('now')));

		if($t < strtotime('now')) {
			$t = strtotime('tomorrow ' . $i);
		}


		# "in 1h" / "in 30m" / "in 1h 30m" 
	} else if (strpos($i, 'in ') === 0) {
		$expression = trim(substr($i, 3));

		if(preg_match('/^\d{1,2}h$/i', $expression)) {
			$t = strtotime('+' . rtrim($expression, 'h') . ' hours');
		} else if(preg_match('/^\d{1,2}m$/i', $expression)) {
			$t = strtotime('+' . rtrim($expression, 'm') . ' minutes');
		} else if(preg_match('/^\d{1,2}h \d{1,2}m$/i', $expression)) {

			$exp = explode(' ', $expression);

			$t = strtotime('+' . rtrim($exp[0], 'h') . ' hours +' . rtrim($exp[1], 'm') . ' minutes');
		}

	}



	else {
		print 'no date match! ';
		$t = -1;
	}

	if($t !== -1 and $t < strtotime('now')) {
		print 'previous date! ';
		$t = -1;
	}

/*
	if(strtotime($i) < strtotime('now')) {
		$t = strtotime($i . '+1 days');
	} else {
		$t = strtotime($i);
	}
*/
	return $t===-1 ? FALSE : date('Y-m-d H:i:s', $t);
}
