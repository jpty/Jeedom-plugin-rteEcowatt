<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../../core/php/core.inc.php';

class rteEcowatt extends eqLogic {
	/*     * *************************Attributs****************************** */
	public static $_widgetPossibility = array('custom' => true, 'custom::layout' => false);

	public static function cronHourly() {
// message::add(__CLASS__, __FUNCTION__ .' ' .date('H:i:s'));
		$hour = array( 'tempoRTE' => array(0, 11, 12, 14));
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
      if(isset($hour[$datasource]) && !in_array(date('H'), $hour[$datasource])) {
        continue;
      }
// message::add(__CLASS__, __FUNCTION__ .' ' .$datasource .' ' .date('H:i:s'));
      $rteEcowatt->updateInfo(0);
    }
  }
	public static function cron() { // TODO creation d'un cron pour recup données
    $minute = config::byKey('execGetDataEcowattRTE', __CLASS__,40);
    if(date('i') == $minute) {
// message::add(__CLASS__, __FUNCTION__ .' ' .date('H:i:s'));
      $recup = 1;
      // MAJ tous les équipements
      foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
        if($rteEcowatt->getConfiguration('datasource') == 'ecowattRTE') {
          $rteEcowatt->updateInfo($recup);
          $recup = 0;
        }
      }
    }
  }

	public static function setMinuteGetDataRte() {
    $minuteExec = config::byKey('execGetDataEcowattRTE', __CLASS__,70);
    if($minuteExec == 70) { // cle non existante
      $minute = rand(1,59);
      config::save('execGetDataEcowattRTE', $minute, __CLASS__);
    }
  }

  public static function initParamRTE($datasource) {
    $params = [ "IDclientSecretB64" => '', "tokenRTE" => '', "tokenExpires" => 0 ];
    $params['IDclientSecretB64'] = trim(config::byKey('IDclientSecretB64', __CLASS__));
    $params['tokenRTE'] = config::byKey('tokenRTE', __CLASS__, '');
    $params['tokenExpires'] = config::byKey('tokenRTEexpires', __CLASS__, 0);
    if(time() > $params['tokenExpires'] || $params['tokenRTE'] == '' || $params['tokenRTE'] == null) {
      self::getTokenRTE($params);
      log::add(__CLASS__, 'debug', __FUNCTION__ ." $datasource NEW token. Expires: " .date('H:i:s',$params['tokenExpires']));
    }
    else log::add(__CLASS__, 'debug', __FUNCTION__ ." $datasource ReUSE token till: " .date('H:i:s',$params['tokenExpires']));
    $params['lastcall'] = config::byKey('lastcall-'.$datasource, __CLASS__, 0);
    return($params);
  }
  public static function getTokenRTE(&$params) { 
    $token_url ="https://digital.iservices.rte-france.com/token/oauth/";
    $header = array("Content-Type: application/x-www-form-urlencoded",
      "Authorization: Basic " .$params['IDclientSecretB64']);
    $curloptions = array(
      CURLOPT_URL => $token_url, CURLOPT_HTTPHEADER => $header, CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true);
    $curl = curl_init();
    curl_setopt_array($curl, $curloptions);
    $response = curl_exec($curl);
    curl_getinfo($curl);
    $curlHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      $curl_error = curl_error($curl);
    curl_close($curl);
    if ($response === false)
      log::add(__CLASS__,'error', "Failed curl_error: " .$curl_error);
    else if (!empty(json_decode($response)->error)) {
      log::add(__CLASS__,'info', "Error: AuthCode : $authorization_code Response $response");
    }
    else
    { log::add(__CLASS__,'debug',__FUNCTION__ ." $response");
      $params['tokenRTE'] = json_decode($response)->access_token;
      config::save('tokenRTE', $params['tokenRTE'], __CLASS__);
        // expire 20s avant
      $params['tokenExpires'] = time() + json_decode($response)->expires_in - 20;
      config::save('tokenRTEexpires', $params['tokenExpires'], __CLASS__);
    }
    return(0);
  }

  public static function getResourceRTE($params, $api) {
    log::add(__CLASS__,'debug',"----- CURL ".__FUNCTION__ ." URL: $api");
    if(strstr($api,"sandbox"))
      log::add(__CLASS__,'debug',"----- Données pas à jour SANDBOX -----");
    $header = array("Authorization: Bearer {$params['tokenRTE']}");
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $api, CURLOPT_HTTPHEADER => $header,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true));
    $response = curl_exec($curl);
    if ($response === false)
      log::add(__CLASS__,'error', "Failed curl_error: " .curl_error($curl));
    $curlHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if($curlHttpCode != 200) {
      if($curlHttpCode == 400) $message = " $response";
      else $message = '';
      log::add(__CLASS__,'error',__FUNCTION__ ." ----- CURL return code: $curlHttpCode URL: $api $message");
    }
    log::add(__CLASS__,'debug',$response);
    curl_close($curl);
    return ($response);
  }

  public static function valueFromUrl($_url) {
    $request_http = new com_http($_url);
    $request_http->setUserAgent('Wget/1.20.3 (linux-gnu)'); // User-Agent idem HA
    $dataUrl = $request_http->exec();
    if (!is_json($dataUrl)) {
        return;
    }
    return json_decode($dataUrl, true);
  }

	public function preSave() {
		$this->setCategory('energy', 1);
	}

	public function postSave() {
    $message = "Start postsave Liste des commandes de l'équipement: ";
		foreach ($this->getCmd() as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      $message .= "ID: ".$cmd->getId() ." $cmdLogicalId,";
    }
    log::add(__CLASS__,'debug', $message);
    $message = "DataSource: ". $this->getConfiguration('datasource');
    $message .= " ID: ". $this->getId();
    $message .= " Name: ". $this->getName();

		$cmd_list = array();
    if ($this->getConfiguration('datasource') == 'ecowattRTE') {
      $cmd_list = array(
        'datenowTS' => array(
					'name' => __('Maintenant timestamp', __FILE__),
					'subtype' => 'numeric',
					'order' => 2,
        ),
        'valueNow' => array(
					'name' => __('Valeur maintenant', __FILE__),
					'subtype' => 'numeric',
					'order' => 3,
        ),
        'nextAlertValue' => array(
					'name' => __('Valeur prochaine alerte', __FILE__),
					'subtype' => 'numeric',
					'order' => 4,
        ),
        'nextAlertTS' => array(
					'name' => __('Timestamp de la prochaine alerte', __FILE__),
					'subtype' => 'numeric',
					'order' => 5,
        ),
        'dataHoursJson' => array(
					'name' => __('Données horaires Json', __FILE__),
					'subtype' => 'string',
					'order' => 6,
        ),
      );
      $order = 10;
      for($i=0;$i<4;$i++) {
        $cmd_list["messageD$i"] = array('name' => "Message J$i", 'subtype' => 'string','order'=> $order++);
        $cmd_list["dayTimestampD$i"] = array('name' => "Jour J$i", 'subtype' => 'numeric','order'=> $order++);
        $cmd_list["dayValueD$i"] = array('name' => "Valeur J$i", 'subtype' => 'numeric','order'=> $order++);
        $cmd_list["dataHourD$i"] = array('name' => "Données horaires J$i", 'subtype' => 'string','order'=> $order++);
      }
    }
    else if ($this->getConfiguration('datasource') == 'tempoRTE') {
			$cmd_list = array(
				'today' => array(
					'name' => __('Aujourd\'hui', __FILE__),
					'subtype' => 'string',
					'order' => 1,
				),
				'tomorrow' => array(
					'name' => __('Demain', __FILE__),
					'subtype' => 'string',
					'order' => 2,
				),
				'blue-remainingDays' => array(
					'name' => __('Jours Bleus restants', __FILE__),
					'subtype' => 'numeric',
					'order' => 3,
				),
				'blue-totalDays' => array(
					'name' => __('Total jours Bleus', __FILE__),
					'subtype' => 'numeric',
					'order' => 4,
				),
				'white-remainingDays' => array(
					'name' => __('Jours Blancs restants', __FILE__),
					'subtype' => 'numeric',
					'order' => 5,
				),
				'white-totalDays' => array(
					'name' => __('Total jours Blancs', __FILE__),
					'subtype' => 'numeric',
					'order' => 6,
				),
				'red-remainingDays' => array(
					'name' => __('Jours Rouges restants', __FILE__),
					'subtype' => 'numeric',
					'order' => 7,
				),
				'red-totalDays' => array(
					'name' => __('Total jours Rouges', __FILE__),
					'subtype' => 'numeric',
					'order' => 8,
				),
			);
		}
    /* TODO crash si suppression ancienne commande quand chgt type
		foreach ($this->getCmd() as $cmd) { // Chgt type => suppression commandes type precedent
      $cmdLogicalId = $cmd->getLogicalId();
			if (!isset($cmd_list[$cmdLogicalId]) && $cmdLogicalId != 'refresh') {
				$cmd->remove();
        $message .= " --$cmdLogicalId,";
			}
    }
     */
		foreach ($cmd_list as $key => $cmd_info) { // ajout nouvelles commandes
			$cmd = $this->getCmd(null, $key);
			if (!is_object($cmd)) {
				$cmd = new rteEcowattCmd();
				$cmd->setLogicalId($key);
				$cmd->setIsVisible(1);
				$cmd->setName($cmd_info['name']);
				$cmd->setOrder($cmd_info['order']);
        $message .= " ++$key,";
			}
      else
        $message .= " ==$key,";
			$cmd->setType('info');
			$cmd->setSubType($cmd_info['subtype']);
			$cmd->setEqLogic_id($this->getId());
			$cmd->save();
		}

		$refresh = $this->getCmd(null, 'refresh');
		if (!is_object($refresh)) {
			$refresh = new rteEcowattCmd();
			$refresh->setName(__('Rafraichir', __FILE__));
        $message .= " ++refresh";
      // $refresh->setIsVisible(0);
		}
		$refresh->setEqLogic_id($this->getId());
		$refresh->setLogicalId('refresh');
		$refresh->setType('action');
		$refresh->setSubType('other');
		$refresh->setOrder(99);
		$refresh->save();
    $message .= " 1 Liste des commandes de l'équipement: ";
		foreach ($this->getCmd() as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      $message .= "ID: ".$cmd->getId() ." $cmdLogicalId,";
    }
log::add(__CLASS__ ,'debug',__FUNCTION__ ." $message");

		$this->updateInfo(0);
	}

  public function fetchDataEcowattRTE() {
    // $demo = config::byKey('demoMode', __CLASS__, 0);
    $demo = $this->getConfiguration('demoMode',0);
// message::add(__CLASS__,"Appel ".__FUNCTION__ ." ecowattRTE ".date('H:i:s'));
    $params = self::initParamRTE('ecowattRTE');
    if($demo) { // mode demo. Données du bac à sable RTE
      $api = "https://digital.iservices.rte-france.com/open_api/ecowatt/v4/sandbox/signals";
      $fileEcowatt = __DIR__ ."/../../data/ecowattRTEsandbox.json";
    }
    else {
      $api = "https://digital.iservices.rte-france.com/open_api/ecowatt/v4/signals";
      $fileEcowatt = __DIR__ ."/../../data/ecowattRTE.json";
    }
    log::add(__CLASS__, 'debug', 'Lastcall: '.$params['lastcall'] .'s');
    // limitation des requetes 15 minutes pour l'API ecowatt
    if($demo || ($demo==0 && time() - $params['lastcall'] > 900)) { // plus d'un quart d'heure depuis derniere requete
      $response = self::getResourceRTE($params, $api);
        // TODO test du contenu de la réponse
      if(!$demo) config::save('lastcall-ecowattRTE', time(), __CLASS__);
      $hdle = fopen($fileEcowatt, "wb");
      if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
    }
    else {
      log::add(__CLASS__, 'error', '15 minutes entre 2 demandes de mise à jour minimum. Réessayez aprés: ' .date('H:i:s',$params['lastcall']+900));
      if(file_exists($fileEcowatt)) {
        $response = file_get_contents($fileEcowatt);
        if($response != '') log::add(__CLASS__, 'debug', 'Mise à jour de l\'interface avec les données de la requête précédente.');
        else return false;
      }
      else return false;
    }
    return $response;
  }

	public function updateInfo($fetch) {
		$datasource = $this->getConfiguration('datasource');
// message::add(__CLASS__, __FUNCTION__ ." DataSource $datasource Fetch: $fetch");
		switch ($datasource) {
      case 'ecowattRTE':
        // $demo = config::byKey('demoMode', __CLASS__, 0);
        $demo = $this->getConfiguration('demoMode',0);
        if($demo) {
          $fileEcowatt = __DIR__ ."/../../data/ecowattRTEsandbox.json";
          $nowTS = strtotime('2022-06-03 ' .date('H:i:s')); // date dans la plage du bac à sable
          // $nowTS = strtotime('2022-06-06 01:00:00');
// message::add(__CLASS__,"Now: ".date('d/m/Y H:i:s',$nowTS));
        }
        else {
          $fileEcowatt = __DIR__ ."/../../data/ecowattRTE.json";
          $nowTS = time();
        }
        if(file_exists($fileEcowatt) && (!$fetch || $demo)) {
          $response = file_get_contents($fileEcowatt);
          log::add(__CLASS__, 'debug', "Using existing file $fileEcowatt " .date('H:i:s',filemtime($fileEcowatt)));
        }
        else {
          log::add(__CLASS__, 'debug', "Fetching new data ".date('d/m H:i:s'));
          $response = $this->fetchDataEcowattRTE();
        }
        $foundNowTS = 0;
        if($response === false) {
          log::add(__CLASS__, 'debug', 'Pas de données de RTE');
          for($i=0;$i<4;$i++) {
            $this->checkAndUpdateCmd("dayTimestampD$i", $nowTS+$i*86400);
            $this->checkAndUpdateCmd("dayValueD$i", 0);
            $this->checkAndUpdateCmd("messageD$i", "Erreur récupération données RTE");
            $this->checkAndUpdateCmd("dataHourD$i", substr(str_repeat('0,',24),0,-1));
          }
          $this->checkAndUpdateCmd("dataHoursJson", substr(str_repeat('0,',49),0,-1));
        }
        else {
          $dec = json_decode($response,true);
          $data = array();
          foreach($dec['signals'] as $signal) {
            $ts =strtotime($signal['jour']);
            $val = array();
            // Init du tableau. Trous dans les datas de la sandbox
            for($i=0;$i<24;$i++) $val[] = 0;
            foreach($signal['values'] as $value) {
              $val[$value['pas']] = $value['hvalue'];
            }
            $data[] = array('jour' => $ts, 'dvalue' => $signal['dvalue'],
                            'message' => $signal['message'], 'value' => $val);
            unset($val);
          }
          sort($data); // les données de la sandbox ne sont pas dans l'ordre chronologique
          $start = -1;
          $valueAlertNow = 0;
          $nextAlertTS = 0; $nextAlertValue = 0; $firstAlert = 0;
          $valHours = array();
          for($day=0;$day<4;$day++) {
            if(!isset($data[$day])) {
              $this->checkAndUpdateCmd("dayTimestampD$day", $nowTS+$day*86400);
              $this->checkAndUpdateCmd("dayValueD$day", 0);
              $this->checkAndUpdateCmd("messageD$day", "Erreur récupération données RTE");
              $this->checkAndUpdateCmd("dataHourD$day", substr(str_repeat('0,',24),0,-1));
              log::add(__CLASS__, 'debug', "Data for day $day not set");
            }
            else {
              $tsDay = $data[$day]['jour'];
              $this->checkAndUpdateCmd("dayTimestampD$day", $tsDay);
              $this->checkAndUpdateCmd("dayValueD$day", $data[$day]['dvalue']);
              $this->checkAndUpdateCmd("messageD$day", $data[$day]['message']);
              $this->checkAndUpdateCmd("dataHourD$day", implode(',',$data[$day]['value']));
              for($i=0;$i<24;$i++) {
                if($nowTS >= $tsDay && $nowTS < $tsDay + 3600) {
                  $start = 0;
// log::add(__CLASS__, 'debug', __FUNCTION__." Cmd now OK Val:".$data[$day]['value'][$i] ." " .date('Y-m-d H:i',$tsDay));
                  $foundNowTS = 1;
                  $nowTS = $tsDay;
                  $valueAlertNow = $data[$day]['value'][$i];
                }
                if($start >= 0) {
                  $hValue = $data[$day]['value'][$i];
                  if($hValue > 1) {
                    if($firstAlert == 1) {
                      $nextAlertTS = $tsDay;
                      // $nextAlertValue = $hValue;
                    }
                    $firstAlert++;
                  }
                  $valHours[date('c',$tsDay)] = array("TS" => $tsDay,"hValue" => $hValue);
                }
                $tsDay += 3600;
                if($start >= 0) $start++;
              }
            }
          }
          $this->checkAndUpdateCmd("dataHoursJson", json_encode($valHours));
          unset($data);
        }
        $startAlert = 0; $endAlert = 0;
        if($foundNowTS) {
          $found = 0;
          foreach($valHours as $valH) { // parcours pour recherche alertes
            if($startAlert && $endAlert) break;
            if($valH['TS'] == $nowTS) {
              $found = 1;
            }
            if($found) {
              if($valueAlertNow == 0 || $valueAlertNow == 1) { // pas d'alerte en cours
                if(!$startAlert && ($valH['hValue'] == 2 || $valH['hValue'] == 3)) {
                  $startAlert = $valH['TS'];
                  $nextAlertValue = $valH['hValue'];
                }
                else if($startAlert && ($valH['hValue'] == 0 || $valH['hValue'] == 1)) {
                  $endAlert = $valH['TS'];
                }
              }
              else { // Alerte en cours
                if(!$startAlert && ($valH['hValue'] == 2 || $valH['hValue'] == 3)) {
                  $startAlert = $valH['TS'];
                }
                else if($startAlert && ($valH['hValue'] == 0 || $valH['hValue'] == 1)) {
                  $endAlert = $valH['TS'];
                }
              }
            }
          }
        }
// message::add(__CLASS__, "startAlert : " .date('d/m H:i:s',$startAlert)." endAlert: ".date('d/m H:i:s',$endAlert) ." valueAlertNow: $valueAlertNow nextAlertValue: $nextAlertValue");
        $this->checkAndUpdateCmd("nextAlertValue", $nextAlertValue);
        if($valueAlertNow == 0 || $valueAlertNow == 1)
          $this->checkAndUpdateCmd("nextAlertTS", $startAlert);
        else
          $this->checkAndUpdateCmd("nextAlertTS", $endAlert);
        $this->checkAndUpdateCmd("valueNow", $valueAlertNow);
        $this->checkAndUpdateCmd("datenowTS", (($foundNowTS)?$nowTS:0));
        break;
      case 'tempoRTE':
        $params = self::initParamRTE($datasource);
        $t = time();
        if(date('m',$t)<9) { // Avant 1er septembre, L'année en cours est-elle bissextile?
          $ts = mktime(0,0,0,9,1,(date('Y',$t)-1)); // 1er septembre de l'année précédente
          $bisext = date('L',$t);
        }
        else { // Après septembre, l'année prochaine est-elle bissextile?
          $ts = mktime(0,0,0,9,1,date('Y')); // 1er septembre de cette année
          $t2 = mktime(12,0,0,1,1,date('Y',$t)+1);
          $bisext = date('L',$t2);
        }
        $start_date = date('Y-m-d\TH:i:sP',$ts); // "20xx-09-01T00:00:00+02:00";
        // TODO stocker les nombres de jours passés pour ne pas redemander tout depuis 1er septembre
        $ts = strtotime("tomorrow midnight")+86400;
        $end_date = date('Y-m-d\TH:i:sP',$ts); // "20xx-09-03T00:00:00+02:00";
        log::add(__CLASS__, 'debug', "Tempo date $start_date / $end_date");
        // $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/sandbox/tempo_like_calendars";
        $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/tempo_like_calendars?start_date=$start_date&end_date=$end_date";
        $response = self::getResourceRTE($params, $api);
/*
$file = __DIR__ ."/../../data/ecowattTempo.json";
$hdle = fopen($file, "wb");
if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
*/
        config::save('lastcall-'.$datasource, time(), __CLASS__);
// message::add(__CLASS__,$response);
        $dec = json_decode($response,true);
        $nbBlue = $nbWhite = $nbRed = 0;
        $todayOK = $tomorrowOK = 0;
        $today = time();
        $tomorrow = $today + 86400;
        if(isset($dec['tempo_like_calendars']['values'])) {
          foreach($dec['tempo_like_calendars']['values'] as $value) {
            $color = $value['value'];
            if($color == 'RED') $nbRed++;
            else if($color == 'WHITE') $nbWhite++;
            else if($color == 'BLUE') $nbBlue++;
            if($todayOK == 0 || $tomorrowOK == 0) {
              $deb= strtotime($value['start_date']);
              $fin= strtotime($value['end_date']);
              if($todayOK == 0) {
                if($today >= $deb && $today < $fin) {
                  // message::add(__CLASS__,"TODAY found");
                  $this->checkAndUpdateCmd('today', "$color");
                  $todayOK = 1;
                }
              }
              if($tomorrowOK == 0) {
                if($tomorrow >= $deb && $tomorrow < $fin) {
                  // message::add(__CLASS__,"TOMORROW found");
                  $this->checkAndUpdateCmd('tomorrow', "$color");
                  $tomorrowOK = 1;
                }
              }
            }
          }
        }
        if($todayOK == 0) $this->checkAndUpdateCmd('today', "UNDEFINED");
        if($tomorrowOK == 0) $this->checkAndUpdateCmd('tomorrow', "UNDEFINED");

        // Recup du nombre de jours blanc ou rouge dans les params du plugin
        // afin de pouvoir les modifier si variation coté RTE/EDF 
        $nbTotWhite = config::byKey('totalTempoWhite', __CLASS__, 43);
        $nbTotRed = config::byKey('totalTempoRed', __CLASS__, 22);
        $nbTotBlue = 365 + $bisext - $nbTotWhite - $nbTotRed;
          // Nb jours restants
        $this->checkAndUpdateCmd('blue-remainingDays', $nbTotBlue - $nbBlue); // Reste bleu
        $this->checkAndUpdateCmd('white-remainingDays', $nbTotWhite - $nbWhite); // Reste blanc
        $this->checkAndUpdateCmd('red-remainingDays', $nbTotRed - $nbRed);   // Reste rouge
          // Nb jours total
        $this->checkAndUpdateCmd('blue-totalDays', $nbTotBlue); // Total bleu
        $this->checkAndUpdateCmd('white-totalDays', $nbTotWhite); // Total blanc
        $this->checkAndUpdateCmd('red-totalDays', $nbTotRed);   // Total rouge
        break;
      }
      $this->refreshWidget();
    }

    public function fillValue($_logicalId, $_value, $_data, $_default = 'N/A') {
      $result = $_default;
      foreach (explode('::', $_value) as $key) {
        if (isset($_data[$key])) {
          $_data = $_data[$key];
        } else {
          $_data = null;
          break;
        }
      }
      if (!is_array($_data) && $_data !== null) {
        $result = $_data;
      }
      $this->checkAndUpdateCmd($_logicalId, $result);
    }

    // remplacement de strftime pour des foremats simples $format est le meme que strftime
    public static function datePlugin($format,$timestamp=null) {
      if($timestamp === null) $timestamp = time();
      $resu = $format;
      $daysFull = array( 1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche');
      $monthsFull = array( 1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet',
        'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',);
			$daysShort = array( 1 => 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim');
			$monthsShort = array( 1 => 'Janv.', 'Févr.', 'Mars', 'Avril', 'Mai', 'Juin',
				'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.',);
      if(strstr($resu,'%A')) { // Jour de la semaine complet
        $repl = ucfirst($daysFull[date('N',$timestamp)]);
        $resu = str_replace('%A',$repl,$resu);
      }
      if(strstr($resu,'%a')) { // Jour de la semaine réduit
        $repl = ucfirst($daysShort[date('N',$timestamp)]);
        $resu = str_replace('%a',$repl,$resu);
      }
      if(strstr($resu,'%e')) { // jour du mois 1 à 31
        $repl = date('j',$timestamp);
        $resu = str_replace('%e',$repl,$resu);
      }
      if(strstr($resu,'%B')) { // Mois complet
        $repl = lcfirst($monthsFull[date('n',$timestamp)]);
        $resu = str_replace('%B',$repl,$resu);
      }
      if(strstr($resu,'%b')) { // Mois réduit
        $repl = lcfirst($monthsShort[date('n',$timestamp)]);
        $resu = str_replace('%b',$repl,$resu);
      }
      if(strstr($resu,'%H')) { // Heure
        $repl = date('G',$timestamp);
        $resu = str_replace('%H',$repl,$resu);
      }
      if(strstr($resu,'%M')) { // Minute
        $repl = date('i',$timestamp);
        $resu = str_replace('%M',$repl,$resu);
      }
      if(strstr($resu,'%S')) { // Seconde
        $repl = date('s',$timestamp);
        $resu = str_replace('%S',$repl,$resu);
      }
      if(strstr($resu,'%G')) { // Seconde
        $repl = date('Y',$timestamp);
        $resu = str_replace('%G',$repl,$resu);
      }
      return($resu); 
  }

    public function toHtml($_version = 'dashboard') {
      if($this->getConfiguration('usePluginTemplate','1') == '0')
        return parent::toHtml($_version);
      // if($_version != 'dashboard') return parent::toHtml($_version); // Pas de template mobile
      $t0 = -microtime(true);
      $replace = $this->preToHtml($_version, array('#background-color#' => '#bdc3c7'));
      if (!is_array($replace)) {
        return $replace;
      }
      $version = jeedom::versionAlias($_version);
      $color[0] = '#95a5a6'; // gris
      $color[1] = '#02F0C6'; // vert
      $color[2] = '#f2790F'; // orange
      $color[3] = '#e63946'; // rouge
      for($i=0;$i<4;$i++) $replace["#color$i#"] = $color[$i];
      $nextAlertTS = 0; $nextAlertValue = 0; $valueNow = 0;
        // Recup de quelques valeurs de commande
      $cmd = $this->getCmd(null,'datenowTS');
      $datenowTS = (is_object($cmd))? $cmd->execCmd() : time();
      $cmd = $this->getCmd(null,'dayTimestampD0');
      $dayTS[0] = (is_object($cmd))? $cmd->execCmd() : time();
      $cmd = $this->getCmd(null,'dayTimestampD1');
      $dayTS[1] = (is_object($cmd))? $cmd->execCmd() : $dayTS[0] + 86400;
      $cmd = $this->getCmd(null,'dayTimestampD2');
      $dayTS[2] = (is_object($cmd))? $cmd->execCmd() : $dayTS[1] + 86400;
      $cmd = $this->getCmd(null,'dayTimestampD3');
      $dayTS[3] = (is_object($cmd))? $cmd->execCmd() : $dayTS[2] + 86400;

      foreach ($this->getCmd('info') as $cmd) {
        $cmdLogicalId = $cmd->getLogicalId();
        if(substr($cmdLogicalId,0,13) == 'dayTimestampD') {
          $idx = substr($cmdLogicalId,13);
          $replace["#date$idx#"] = self::datePlugin('%A %e %B',$cmd->execCmd());
          $replace["#date${idx}dm#"] = self::datePlugin('%e %B',$cmd->execCmd());
        }
        else if($cmdLogicalId == 'valueNow') {
          $valueNow = $cmd->execCmd();
          /* la punaise de couleur
            $replace['#valueNow#'] =
              '<i class="fa fa-circle fa-lg" style="color: '.$color[$valueNow] .'"></i>';
           */
          // La carte de France
          $svg = file_get_contents(__DIR__ ."/../template/images/franceRegions.svg");
          $svg = str_replace('#fbfaf9',$color[$valueNow],$svg);
          $replace['#valueNow#'] = $svg;
          if(!$valueNow) $replace['#curAlertColor#'] = $color[0];
          else $replace['#curAlertColor#'] = $color[$valueNow];
        }
        else if($cmdLogicalId == 'datenowTS') {
          $val = $cmd->execCmd();
          if($val == 0) $replace['#datenow#'] = "Valeur actuelle inconnue.";
          else $replace['#datenow#'] = self::datePlugin('%A %e %B %Hh-',$val) .date('G',$val+3600).'h';
        }
        else if(substr($cmdLogicalId,0,9) == 'dataHourD') {
          $idx = substr($cmdLogicalId,9);
          $datas = explode(',',$cmd->execCmd());
          $dataHCpieAM = $dataHCpiePM = '';
          $tab = '<table width=100% style="margin-top: 3px"><tr>';
          $i = 0; $icurH = -1;
          $tabHCcolumn = ''; $tabHCbar = '';
          foreach($datas as $data) {
            $title = $i ."h-" .($i+1) ."h";
            $tab .= '<td title=' .$title .' width=4% style="font-size:8px!important;';
            if($dayTS[$idx] + $i * 3600 == $datenowTS) {
              $tabHCcolumn .= '{ y:2, name: "'.$title .'", color: "' .$color[$data] .'"},';
              $tabHCbar .= '{ data: [1], name: "'.$title .'", pointWidth: 30, color: "' .$color[$data] .'"},';
            if($i % 2 && $i != 23) $tab .= 'border-right: 1px solid #000;';
              $tab .= ' text-align:center"><i class="fa fa-circle fa-lg" style="color: '.$color[$data] .'"></i> '; 
            }
            else {
              $tabHCcolumn .= '{ y:1, name: "'.$title .'", color: "' .$color[$data] .'"},';
            if($i % 2 && $i != 23) $tab .= 'border-right: 1px solid #000;';
              $tab .= 'background-color:' .$color[$data] .';">&nbsp;';
              
              $tabHCbar .= '{ data: [1], name: "'.$title .'", color: "' .$color[$data] .'"},';
            }
            $tab .= '</td>';
            $dataHighcharts = "{ name: '${i}h-" .($i+1) ."h', y: 15, color: '" .$color[$data] ."'";
            if($dayTS[$idx] + $i * 3600 == $datenowTS) {
              $dataHighcharts .= ", sliced:true, selected: true";
              $icurH = $i;
              $curHcolor = $color[$data];
            }
            $dataHighcharts .= "},";
            if($i<12) $dataHCpieAM .= $dataHighcharts;
            else $dataHCpiePM .= $dataHighcharts;
            $i++;
          }
          $tab .= '</tr><tr>'; // 2eme ligne pour afficher les heures
          for($i=0;$i<6;$i++) {
            $tab .= '<td style="font-size:10px!important" colspan="4">' .($i*4) .'h</td>';
          }
          $tab .= "</tr></table>";
          $replace["#dataHourD$idx#"] = "$tab";
          $replace["#dataHour${idx}HCpieAM#"] = $dataHCpieAM;
          $replace["#dataHour${idx}HCpiePM#"] = $dataHCpiePM;
          $replace["#dataHour${idx}HCcolumn#"] = $tabHCcolumn;
          $replace["#dataHour${idx}HCbar#"] = $tabHCbar;

        }
        else if($cmdLogicalId == 'dataHoursJson') {
          $numCmdsHour = $this->getConfiguration('numCmdsHour',49);
          if($numCmdsHour > 49) $numCmdsHour = 49;
          $datas = json_decode($cmd->execCmd(),true);
          $numCmdsHour = min(count($datas),$numCmdsHour);
          $tab = '';
          $i = 0;
          foreach($datas as $data) {
            if($i >= $numCmdsHour) break;
            $tab .= '<td title="' .date('d/m G',$data['TS']) .'h-' .date('G',$data['TS']+3600) .'h" style="background-color:' .$color[$data['hValue']] .'; font-size:8px!important;';
            if(date('G',$data['TS']) % 2 && $i != $numCmdsHour) $tab .= 'border-right: 1px solid #000;';
            $tab .= '">&nbsp;</td>';
            $i++;
          }
          $replace['#dataHoursJson#'] = (($tab!='')?"<table width=100%><tr>$tab</tr></table>":'Pas de données.');
        }
        else if($cmdLogicalId == 'nextAlertTS') {
          $nextAlertTS = $cmd->execCmd();
        }
        else if($cmdLogicalId == 'nextAlertValue') {
          $nextAlertValue = $cmd->execCmd();
        }
        else if(substr($cmdLogicalId,0,9) == 'dayValueD') { 
          $idx = substr($cmdLogicalId,9);
          $colD = $cmd->execCmd();
          $replace['#' .$cmdLogicalId .'#'] = $cmd->execCmd();
          $replace["#dataDay${idx}HC#"] = "{ name: 'Jour', y: 360, color: '" .$color[$colD] ."'}";
        }
        else $replace['#' .$cmdLogicalId .'#'] = $cmd->execCmd();

/*
        $replace['#' .$cmdLogicalId .'_history#'] = '';
        $replace['#' .$cmdLogicalId .'_id#'] = $cmd->getId();
        $replace['#' .$cmdLogicalId .'_uid#'] =	'cmd' . $this->getId() . eqLogic::UIDDELIMITER . mt_rand() . eqLogic::UIDDELIMITER;
        $replace['#' .$cmdLogicalId .'_collect#'] = $cmd->getCollectDate();
        $replace['#' .$cmdLogicalId .'_display#'] = $cmd->getIsVisible();
        $replace['#' .$cmdLogicalId .'_name_display#'] = $cmd->getName();
        if ($cmd->getDisplay('showNameOn' . $_version, 1) == 0) {
          $replace['#' .$cmdLogicalId .'_hide_name#'] = 'hidden';
        }
        else $replace['#' .$cmdLogicalId .'_hide_name#'] = '';
        if ($cmd->getIsHistorized() == 1) {
          $replace['#' .$cmdLogicalId .'_history#'] = 'history cursor';
        }
*/
      }
      if(!$datenowTS) {
        $replace['#nextAlert#'] = '';
      }
      else if(!$nextAlertTS) {
        $replace['#nextAlert#'] = 'Pas d\'alerte Ecowatt prévue.';
      }
      else {
        if($valueNow == 0 || $valueNow == 1) { // Pas d'alerte en cours
          $replace['#nextAlert#'] = "Prochaine alerte:  ".'<i class="fa fa-circle fa-lg" style="color: '.$color[$nextAlertValue] .'"></i> ' .lcfirst(self::datePlugin('%a. %e %b %Hh',$nextAlertTS));
        }
        else {
          $replace['#nextAlert#'] = "Fin de l'alerte en cours " .lcfirst(self::datePlugin('%a. %e %b à %Hh',$nextAlertTS));
        }
      }


      $demo = $this->getConfiguration('demoMode',0);
      // $demo = config::byKey('demoMode', __CLASS__, 0);
      if($demo) // mode demo. Données du bac à sable RTE
        $file = __DIR__ ."/../../data/ecowattRTEsandbox.json";
      else $file = __DIR__ ."/../../data/ecowattRTE.json";
      $lastcallEcoTS = config::byKey('lastcall-ecowattRTE', __CLASS__, 0);
      if(file_exists($file)) {
        $fileTS = filemtime($file);
        // $tokenExpires = config::byKey('tokenRTEexpires', __CLASS__, 0);
        if($demo)
          $replace['#dataActuEcowatt#'] = 'Données RTE SANDBOX '.date('d/m/Y',$fileTS);
        else
          $replace['#dataActuEcowatt#'] = 'Données RTE du '.date('d/m/Y H:i:s',$fileTS);
          // .'. tokenExpires '.date('H:i:s',$tokenExpires) 
        $replace['#dataActuEcowatt#'] .= '. Affichage: '.date('H:i:s');
        if($demo) $replace['#dataActuEcowatt#'] .= ' en '.round($t0+microtime(true),3).'s';
      }
      else {
        $replace['#dataActuEcowatt#'] = 'Dernière requête RTE le '.date('d/m/Y H:i:s',$lastcallEcoTS);
      }

      $refresh = $this->getCmd(null, 'refresh');
      if (is_object($refresh) && $refresh->getIsVisible() == 1) {
        $replace['#refresh_id#'] = $refresh->getId();
      } else {
          $replace['#refresh_id#'] = '';
      }
      
      /*
$fileReplace = __DIR__ ."/../../data/ecowattReplace.json";
$hdle = fopen($fileReplace, "wb");
if($hdle !== FALSE) { fwrite($hdle, json_encode($replace)); fclose($hdle); }
       */
      if ($this->getConfiguration('datasource') == 'ecowattRTE') $template = 'rte_ecowatt';
      else $template = 'rte_tempo';
      return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, $template, __CLASS__)));
    }
}

class rteEcowattCmd extends cmd {
	public function execute($_options = array()) {
		if ($this->getLogicalId() == 'refresh') {
			$eqLogic = $this->getEqLogic();
			$eqLogic->updateInfo(1);
		}
	}
}
