<?php

	// set the defines here, and the mysqli info on line 24
	// make sure you have an temps table with floats for temp1 and temp2 and created_at (timestamp)
	// make sure you have an alerts table with floats for avgtemp1 and avgtemp2, open (boolean, default: true) and created_at (timestamp)

	define("PASSWORD","PASSWORD");
	define("THRESHOLD1", 30.0); // max temp for sensor 1
	define("THRESHOLD2", 22.0); // max temp for sensor 2
	define("THRESHOLDCHECKINTERVAL", 10); // number of minutes the temp needs to be exceeded before the alert mail will be sent
	define("MANAGEREMAIL", 'test@example.com'); // alert mail will go to
	define("MONITORURL", 'http://URL'); // address of webpage for the stats
	session_start();
	$mysqli = initDB();
	
	if($_GET['step'] == 'nonce') {
		getNonce();
	} else if (isset($_POST['response']) && isset($_POST['temp1']) && isset($_POST['temp2'])) {
		checkAuthenticationResponce();
		processEntry($mysqli);
	}
	
	$mysqli->close();
	
	function initDB() {
		$mysqli = new mysqli("HOST", "USER", "PASSWORD", "DATABASE");
		/* check connection */
		if ($mysqli->connect_errno) {
			header("HTTP/1.0 500 Internal Server Error");
			exit();
		}
		return $mysqli;
	}
	
	function checkAuthenticationResponce() {
		if(!isset($_SESSION['tempNonce']) || hash('sha256', $_SESSION['tempNonce'] . PASSWORD . $_POST['temp1'] . $_POST['temp2']) != $_POST['response']) {
			header("HTTP/1.0 401 Authorization Required");
			exit;
		} else {
			unset($_SESSION['tempNonce']);
		}
	}

	function getNonce() {
		$_SESSION['tempNonce'] = hash('sha256', 'enter some random stuff here' . time());
		echo $_SESSION['tempNonce'];
	}
	
	function getAVGTempsThresholdCheckInterval($mysqli) {
		$stmt = $mysqli->prepare("SELECT AVG(temp1), AVG(temp2) FROM temps WHERE created_at >= SYSDATE() - INTERVAL ". THRESHOLDCHECKINTERVAL ." MINUTE");
		$stmt->execute();
		$stmt->bind_result($res['temp1'], $res['temp2']);
		$stmt->fetch();
		return $res;
	}
	
	function processEntry($mysqli) {

		$temp1 = floatval($_POST['temp1']);
		$temp2 = floatval($_POST['temp2']);
		if($temp1 > THRESHOLD1 || $temp2 > THRESHOLD2) {
			$avgs = getAVGTempsThresholdCheckInterval($mysqli);
			if((($avgs['temp1'] == NULL && $avgs['temp1'] == NULL) || $avgs['temp1'] > THRESHOLD1 || $avgs['temp2'] > THRESHOLD2) && !hasOpenAlerts($mysqli)) {
				// mail the manager that something is awefully wrong
				alertAboutTemperatures($mysqli, $avgs);
			}
		} else {
			$avgs = getAVGTempsThresholdCheckInterval($mysqli);
			if(($avgs['temp1'] != NULL && $avgs['temp1'] != NULL && $avgs['temp1'] <= THRESHOLD1 - 1 && $avgs['temp2'] <= THRESHOLD2 - 1) && hasOpenAlerts($mysqli)) {
				// mail the manager that everything is solved
				revokePreviousTemperatureAlert($mysqli, $avgs);
			}
		}
		
		$stmt = $mysqli->prepare("INSERT INTO temps (temp1, temp2) VALUES(?,?)");
		$stmt->bind_param('dd', $temp1, $temp2);

		if ($stmt->execute() === true) {
			echo "added";
		} else {
			header("HTTP/1.0 500 Internal Server Error");
		}
	}
	
	function hasOpenAlerts($mysqli) {
		$stmt = $mysqli->prepare("SELECT * FROM alerts WHERE created_at >= SYSDATE() - INTERVAL 1 DAY AND open = true");
		$stmt->execute();
		$stmt->store_result();
		return $stmt->num_rows > 0;
	}
	
	function revokePreviousTemperatureAlert($mysqli, $avgs) {
		$to = MANAGEREMAIL;
		$subject = "Temperatuur weer in orde";
		$body = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" /></head><body>LS, <br />\r\n<br />\r\n De temperatuur van de koeling en vriezer zijn nu weer onder de ingestelde grenswaarden: <br />\r\n<br />\r\n Sensor 1 geeft (gedurende ". THRESHOLDCHECKINTERVAL ." minuten gemiddeld) aan: ". ($avgs['temp1'] ? number_format($avgs['temp1'], 1) . '°C' : '<i>Onbekend</i>') .".<br />\n\rSensor 2 geeft (gedurende ". THRESHOLDCHECKINTERVAL ." minuten gemiddeld) aan: ". ($avgs['temp2'] ? number_format($avgs['temp2'], 1) . '°C' : '<i>Onbekend</i>') .".<br />\r\n<br />\r\n Kijk op <a href=\"http://". MONITORURL ."\">". MONITORURL ."</a> om de temperatuurverloop van de laatste uren te bekijken. <br />\r\n<br />\r\n Groeten, <br />\r\n<br />\r\n Frambozentaart</body></html>";
		$headers = "From: MEH!\r\n";
		$headers .= "MIME-Version: 1.0\r\n";
		$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
		mail($to, $subject, $body, $headers);
		
		$stmt = $mysqli->prepare("UPDATE alerts SET open = false WHERE created_at >= SYSDATE() - INTERVAL 1 DAY");
		$stmt->execute();
	}
	
	function alertAboutTemperatures($mysqli, $avgs) {
		$to = MANAGEREMAIL;
		$subject = "Waarschuwing! Temperatuur te hoog";
		$body = "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" /></head><body>LS, <br />\r\n<br />\r\n Er schijnt iets mis te zijn met de koeling of vriezer: <br />\r\n<br />\r\n Sensor 1 geeft (gedurende ". THRESHOLDCHECKINTERVAL ." minuten gemiddeld) aan: ". ($avgs['temp1'] ? number_format($avgs['temp1'], 1) . '°C' : '<i>Onbekend</i>') .", waar het maximaal ". THRESHOLD1 ."°C mag zijn.<br />\r\nSensor 2 geeft (gedurende ". THRESHOLDCHECKINTERVAL ." minuten gemiddeld) aan: ". ($avgs['temp2'] ? number_format($avgs['temp2'], 1) . '°C' : '<i>Onbekend</i>') .", waar het maximaal ". THRESHOLD2 ."°C mag zijn.<br />\r\n<br />\r\n Kijk op <a href=\"http://". MONITORURL ."\">". MONITORURL ."</a> om de temperatuurverloop van de laatste uren te bekijken. <br />\r\n<br />\r\n Groeten, <br />\r\n<br />\r\n Frambozentaart</body></html>";
		$headers = "From: MEH!\r\n";
		$headers .= "MIME-Version: 1.0\r\n";
		$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
		mail($to, $subject, $body, $headers);
		
		$stmt = $mysqli->prepare("INSERT INTO alerts (avgtemp1, avgtemp2) VALUES(?,?)");
		$stmt->bind_param('dd', $avgs['temp1'], $avgs['temp2']);
		$stmt->execute();
	}

?>