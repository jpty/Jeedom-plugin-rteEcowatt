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
		$hour = array( 'tempoRTE' => array(0, 11, 12, 14, 20));
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
      if (isset($hour[$datasource]) && !in_array(date('H'), $hour[$datasource])) {
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

  public static function initParamRTE($datasource) {
    $params = [ "IDclientSecretB64" => '', "tokenRTE" => '', "tokenExpires" => 0 ];
    $params['IDclientSecretB64'] = config::byKey('IDclientSecretB64', __CLASS__);
    $params['tokenRTE'] = config::byKey('tokenRTE', __CLASS__, '');
    $params['tokenExpires'] = config::byKey('tokenRTEexpires', __CLASS__, 0);
    if(time() > $params['tokenExpires']) {
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
    if ($response === false)
      log::add(__CLASS__,'error', "Failed curl_error: " .curl_error($curl));
    else if (!empty(json_decode($response)->error)) {
      log::add(__CLASS__,'info', "Error: AuthCode : $authorization_code Response $response");
    }
    curl_close($curl);
    log::add(__CLASS__,'debug',$response);
    $params['tokenRTE'] = json_decode($response)->access_token;
    config::save('tokenRTE', $params['tokenRTE'], __CLASS__);
      // expire 20s avant
    $params['tokenExpires'] = time() + json_decode($response)->expires_in - 20;
    config::save('tokenRTEexpires', $params['tokenExpires'], __CLASS__);
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
    if($curlHttpCode != 200)
      log::add(__CLASS__,'error',__FUNCTION__ ." ----- CURL return code: $curlHttpCode URL: $api");
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
        /*
        'dataHours' => array(
					'name' => __('Données horaires', __FILE__),
					'subtype' => 'string',
					'order' => 4,
        ),
         */
      );
      $order = 10;
      for($i=0;$i<4;$i++) {
        $cmd_list["messageD$i"] = array('name' => "Message J$i", 'subtype' => 'string','order'=> $order++);
        $cmd_list["dayTimestampD$i"] = array('name' => "Jour J$i", 'subtype' => 'numeric','order'=> $order++);
        $cmd_list["dayValueD$i"] = array('name' => "Valeur J$i", 'subtype' => 'numeric','order'=> $order++);
        $cmd_list["dataHourD$i"] = array('name' => "Données horaires J$i", 'subtype' => 'string','order'=> $order++);
      }
        // TODO cmds pour prochain état dégradé
      /*
      for($i=0;$i<49;$i++) { // TODO réduire le nombre de cmd 49 ?
        $cmd_list["valueH$i"] = array('name' => "Valeur H+$i", 'subtype' => 'numeric','order'=> $order++);
      }
       */
      
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
    /* TODO crash si suppression ancienne commande si chgt type
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
      $refresh->setIsVisible(0);
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

  public static function fetchDataEcowattRTE() {
    $demo = config::byKey('demoMode', __CLASS__, 0);
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
        $demo = config::byKey('demoMode', __CLASS__, 0);
        if($demo) {
          $fileEcowatt = __DIR__ ."/../../data/ecowattRTEsandbox.json";
          $nowTS = strtotime('2022-06-04 ' .date('H:i:s')); // date dans la plage du bac à sable
        }
        else {
          $fileEcowatt = __DIR__ ."/../../data/ecowattRTE.json";
          $nowTS = time();
        }
        if(!$fetch && file_exists($fileEcowatt)) { // && time() > (filemtime($fileEcowatt)+7140)) {
          $response = file_get_contents($fileEcowatt);
// message::add(__CLASS__, "Using file $fileEcowatt " .date('H:i:s',filemtime($fileEcowatt)));
        }
        else {
// message::add(__CLASS__, "Fetching data");
          $response = self::fetchDataEcowattRTE();
        }
        $foundTS = 0;
        if($response === false) {
          log::add(__CLASS__, 'debug', 'Pas de données de RTE');
          for($i=0;$i<4;$i++) {
            $this->checkAndUpdateCmd("dayTimestampD$i", $nowTS+$i*86400);
            $this->checkAndUpdateCmd("dayValueD$i", 0);
            $this->checkAndUpdateCmd("messageD$i", "Erreur récupération données RTE");
            $this->checkAndUpdateCmd("dataHourD$i", substr(str_repeat('0,',24),0,-1));
            $this->checkAndUpdateCmd("dataHours", substr(str_repeat('0,',24),0,-1));
          }
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
          $valueNow = 0;
          $valHour = array();
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
log::add(__CLASS__, 'debug', __FUNCTION__." Cmd now OK Val:".$data[$day]['value'][$i] ." " .date('Y-m-d H:i',$tsDay));
                  $foundTS = 1;
                  $nowTS = $tsDay;
                  $valueNow = $data[$day]['value'][$i];
                }
                if($start >= 0) {
                  $hValue = $data[$day]['value'][$i];
                  $valHour[] = array("TS" => $tsDay, "hValue" => $hValue);
                  $this->checkAndUpdateCmd("valueH$start", $hValue);
                }
                $tsDay += 3600;
                if($start >= 0) $start++;
              }
            }
          }
          $this->checkAndUpdateCmd("dataHours", json_encode($valHour));
          unset($data);
        }
        $this->checkAndUpdateCmd("valueNow", $valueNow);
        $this->checkAndUpdateCmd("datenowTS", (($foundTS)?$nowTS:0));
        break;
      case 'tempoRTE':
        $params = self::initParamRTE($datasource);
        // TODO la date de début
        // TODO stocker les nombres de jours passés pour ne pas redemander tout depuis 1er septembre
        $start_date = "2022-09-01T00:00:00+02:00";
        $ts = strtotime("tomorrow midnight")+86400;
        $end_date = date('Y-m-d\TH:i:sP',$ts); // "2022-09-03T00:00:00+02:00";
        // $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/sandbox/tempo_like_calendars";
        $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/tempo_like_calendars?start_date=$start_date&end_date=$end_date";
        $response = self::getResourceRTE($params, $api);
        config::save('lastcall-'.$datasource, time(), __CLASS__);
// message::add(__CLASS__,$response);
        $dec = json_decode($response,true);
        $nbBlue = $nbWhite = $nbRed = 0;
        $todayOK = $tomorrowOK = 0;
        $today = time();
        $tomorrow = $today + 86400;
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
        if($todayOK == 0)
          $this->checkAndUpdateCmd('today', "UNDEFINED");
        if($tomorrowOK == 0)
          $this->checkAndUpdateCmd('tomorrow', "UNDEFINED");

        $t = time();
        if(date('m',$t)<9) { // Avant 1er septembre, L'année en cours est-elle bissextile?
          $bisext = date('L',$t);
        } else { // Après septembre, l'année prochaine est-elle bissextile?
          $t2 = mktime(12,0,0,1,1,date('Y',$t)+1);
          $bisext = date('L',$t2);
        }
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
      setlocale(LC_TIME,"fr_FR.utf8"); // TODO remplacement strftime obsolete 8.1
      $color[0] = '#95a5a6'; // gris
      $color[1] = '#02F0C6'; // vert
      $color[2] = '#f2790F'; // orange
      $color[3] = '#e63946'; // rouge
      for($i=0;$i<4;$i++) $replace["#color$i#"] = $color[$i];
      foreach ($this->getCmd('info') as $cmd) {
        $cmdLogicalId = $cmd->getLogicalId();
        if(substr($cmdLogicalId,0,13) == 'dayTimestampD') {
          $idx = substr($cmdLogicalId,13);
          $replace["#date$idx#"] = ucfirst(strftime('%A %e %B',$cmd->execCmd()));
          $replace["#date${idx}dm#"] = ucfirst(strftime('%e %b',$cmd->execCmd()));
        }
        else if($cmdLogicalId == 'datenowTS') {
          $val = $cmd->execCmd();
          if($val == 0) $replace['#datenow#'] = "Valeur actuelle inconnue. ".date('d/m/Y H:i:s');
          else $replace['#datenow#'] = ucfirst(strftime('%A %e %B %H',$val)).'h - '.date('H',$val+3600).'h';
        }
        else if(substr($cmdLogicalId,0,9) == 'dataHourD') {
          $idx = substr($cmdLogicalId,9);
          $datas = explode(',',$cmd->execCmd());
          $dataHCpieAM = $dataHCpiePM = '';
          $tab = '<table width=100%><tr>';
          $i = 0; $icurH = -1;
          $tabHCcolumn = ''; $tabHCbar = '';
          foreach($datas as $data) {
            $title = "$i-" .($i+1) ."h";
            $tab .= '<td title=' .$title .' width=4% style="font-size:8px!important;';
              // TODO l'heure dans le bon jour et pas forcement le premier graph highChart
            if($idx==0 && $i == date('G')) {
              $tabHCcolumn .= '{ y:2, name: "'.$title .'", color: "' .$color[$data] .'"},';
              $tabHCbar .= '{ data: [1], name: "'.$title .'", pointWidth: 30, color: "' .$color[$data] .'"},';
            }
            else {
              $tabHCcolumn .= '{ y:1, name: "'.$title .'", color: "' .$color[$data] .'"},';
              $tab .= 'background-color:' .$color[$data] .';';
              $tabHCbar .= '{ data: [1], name: "'.$title .'", color: "' .$color[$data] .'"},';
            }
            if($i % 2 && $i != 23) $tab .= 'border-right: 1px solid #000;';
            $tab .= '">&nbsp;</td>';
            $dataHighcharts = "{ name: '$i-" .($i+1) ."h', y: 15, color: '" .$color[$data] ."'";
              // TODO l'heure dans le bon jour et pas forcement le premier graph highChart
            if($idx==0 && $i == date('G')) {
              $dataHighcharts .= ", sliced:true, selected: true";
              $icurH = $i;
              $curHcolor = $color[$data];
            }
            $dataHighcharts .= "},";
            if($i<12) $dataHCpieAM .= $dataHighcharts;
            else $dataHCpiePM .= $dataHighcharts;
            $i++;
          }
          $tab .= '</tr><tr>';
          for($i=0;$i<24;$i++) {
            if($i % 4) {
              $tab .= "<td";
              if($icurH == $i) $tab .= ' style="background-color: ' .$curHcolor .'"';
              $tab .= "></td>";
            }
            else $tab .= '<td style="font-size:10px!important">' .$i .'h</td>';
          }
          $tab .= "</tr></table>";
          $replace["#dataHourD$idx#"] = "$tab";
          $replace["#dataHour${idx}HCpieAM#"] = $dataHCpieAM;
          $replace["#dataHour${idx}HCpiePM#"] = $dataHCpiePM;
          $replace["#dataHour${idx}HCcolumn#"] = $tabHCcolumn;
          $replace["#dataHour${idx}HCbar#"] = $tabHCbar;

        }
        else if($cmdLogicalId == 'dataHours') {
          $numCmdsHour = $this->getConfiguration('numCmdsHour',24);
          if($numCmdsHour > 72) $numCmdsHour = 72;
          $datas = json_decode($cmd->execCmd(),true);
          $tab = '';
          for($i=0;$i<$numCmdsHour;$i++) {
            $tab .= '<td title="' .date('d/m G',$datas[$i]['TS']) .'h - ' .date('G',$datas[$i]['TS']+3600) .'h" width=4% style="background-color:' .$color[$datas[$i]['hValue']] .'; font-size:8px!important;';
            if(date('G',$datas[$i]['TS']) % 2 && $i != $numCmdsHour) $tab .= 'border-right: 1px solid #000;';
            $tab .= '">&nbsp;</td>';
          }
          $replace['#dataHours#'] = (($tab!='')?"<table width=100%><tr>$tab</tr></table>":'');
        }
        else if(substr($cmdLogicalId,0,9) == 'dayValueD') { 
          $idx = substr($cmdLogicalId,9);
          $colD = $cmd->execCmd();
          $replace['#' .$cmdLogicalId .'#'] = $cmd->execCmd();
          $replace["#dataDay${idx}HC#"] = "{ name: 'Jour', y: 360, color: '" .$color[$colD] ."'}"; // TODO la couleur du centre
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

      $file = __DIR__ ."/../../data/ecowattRTE.json";
      $lastcallEcoTS = config::byKey('lastcall-ecowattRTE', __CLASS__, 0);
      if(file_exists($file)) {
        $fileTS = filemtime($file);
        $replace['#dataActuEcowatt#'] = 'Données RTE téléchargées le '.date('d/m/Y H:i:s',$fileTS) 
          .'. Requête RTE à '.date('H:i:s',$lastcallEcoTS) 
          .'. Affichage: '.date('H:i:s')
          .' en '.round($t0+microtime(true),3).'s';

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
