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
    $hour = array( 'tempoRTE' => array(0, 10, 11, 12, 14));
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
      if(isset($hour[$datasource]) && !in_array(date('H'), $hour[$datasource])) {
        continue;
      }
// message::add(__CLASS__, __FUNCTION__ .' ' .$datasource .' ' .date('H:i:s'));
      $rteEcowatt->updateInfo(0);
    }
  }
  public static function pullDataEcowatt() {
    $recup = 1;
        // MAJ tous les équipements // Fetch RTE 1 seule fois
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      if($rteEcowatt->getConfiguration('datasource') == 'ecowattRTE') {
        $demo = $rteEcowatt->getConfiguration('demoMode',0);
        if(!$demo) {
          $rteEcowatt->updateInfo($recup);
          $recup = 0;
        }
      }
    }
  }

  public static function setCronDataEcowatt($create) {
    if($create == 1) {
      $cron = cron::byClassAndFunction(__CLASS__, 'pullDataEcowatt');
      if(!is_object($cron)) {
        $cron = new cron();
        $cron->setClass(__CLASS__);
        $cron->setFunction('pullDataEcowatt');
        $cron->setEnable(1);
        $cron->setDeamon(0);
        $cron->setSchedule(rand(1,59) .' * * * *');
        $cron->save();
      }
    }
    else {
      $cron = cron::byClassAndFunction(__CLASS__, 'pullDataEcowatt');
      if(is_object($cron)) {
        $cron->remove();
      }
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
    $header = array("Authorization: Bearer {$params['tokenRTE']}");
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $api, CURLOPT_HTTPHEADER => $header,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true));
    $response = curl_exec($curl);
    if ($response === false)
      log::add(__CLASS__,'error', "Failed curl_error: " .curl_error($curl));
    $curlHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
// message::add(__CLASS__,"HeaderOut: ".json_encode($curlHeaderOut));
    if($curlHttpCode != 200) {
      if($curlHttpCode == 400 || $curlHttpCode == 429) $msg = " $response";
      else $msg = '';
      log::add(__CLASS__,'error',__FUNCTION__ ." ----- CURL return code: $curlHttpCode URL: $api $msg");
    }
    log::add(__CLASS__,'debug',$response);
    curl_close($curl);
    return ($response);
  }

  public function fetchDataConsumptionRTE() {
    // $demo = config::byKey('demoMode', __CLASS__, 0);
// message::add(__CLASS__,"Appel ".__FUNCTION__ ." consumptionRTE ".date('H:i:s'));
    $params = self::initParamRTE('consumptionRTE');
    /*
    if($demo) { // mode demo. Données du bac à sable RTE
      $api = "https://digital.iservices.rte-france.com/open_api/consumption/v1/short_term";
      $fileConsumption = __DIR__ ."/../../data/consumptionRTEsandbox.json";
    }
    else {
    }
     */
    $api = "https://digital.iservices.rte-france.com/open_api/consumption/v1/short_term"; // ?type=<valeur(s)>&start_date=<valeur>&end_date=<valeur>";
    $fileConsumption = __DIR__ ."/../../data/consumptionRTE.json";
    $response = self::getResourceRTE($params, $api);
    $hdle = fopen($fileConsumption, "wb");
    if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
    /*
    log::add(__CLASS__, 'debug', 'Lastcall: '.$params['lastcall'] .'s');
    // limitation des requetes 15 minutes pour l'API consumption
    if($demo || (!$demo && time() - $params['lastcall'] > 900)) { // plus d'un quart d'heure depuis derniere requete
      $response = self::getResourceRTE($params, $api);
        // TODO test du contenu de la réponse
      if(json_decode($response,true)) {
        if(!$demo) config::save('lastcall-consumptionRTE', time(), __CLASS__);
        $hdle = fopen($fileEcowatt, "wb");
        if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
      }
      else {
        message::add(__CLASS__,"Erreur json_decode: " .json_last_error_msg());
      }
    }
    else {
      log::add(__CLASS__, 'warning', '15 minutes entre 2 demandes de mise à jour minimum. Réessayez aprés: ' .date('H:i:s',$params['lastcall']+900));
      if(file_exists($fileEcowatt)) {
        $response = file_get_contents($fileEcowatt);
        if($response != '') log::add(__CLASS__, 'debug', 'Mise à jour de l\'interface avec les données de la requête précédente.');
        else return false;
      }
      else return false;
    }
    return $response;
*/
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
    if($demo || (!$demo && time() - $params['lastcall'] > 900)) { // plus d'un quart d'heure depuis derniere requete
      $response = self::getResourceRTE($params, $api);
        // TODO test du contenu de la réponse
      if(json_decode($response,true)) {
        if(!$demo) config::save('lastcall-ecowattRTE', time(), __CLASS__);
        $hdle = fopen($fileEcowatt, "wb");
        if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
      }
      else {
        log::add(__CLASS__,'warning',"Erreur json_decode: " .json_last_error_msg());
      }
    }
    else {
      log::add(__CLASS__, 'warning', '15 minutes entre 2 demandes de mise à jour minimum. Réessayez aprés: ' .date('H:i:s',$params['lastcall']+900));
      if(file_exists($fileEcowatt)) {
        $response = file_get_contents($fileEcowatt);
        if($response != '') log::add(__CLASS__, 'debug', 'Mise à jour de l\'interface avec les données de la requête précédente.');
        else return false;
      }
      else return false;
    }
    return $response;
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

  public function preInsert() {
    $this->setCategory('energy', 1);
  }

  public function postSave() {
    $msg = "Start postsave Liste des commandes de l'équipement: ";
    foreach ($this->getCmd() as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      $msg .= "ID: ".$cmd->getId() ." $cmdLogicalId,";
    }
    log::add(__CLASS__,'debug', $msg);
    $msg = "DataSource: ". $this->getConfiguration('datasource');
    $msg .= " ID: ". $this->getId();
    $msg .= " Name: ". $this->getName();

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
        'todayTS' => array(
          'name' => __('Aujourd\'hui timestamp', __FILE__),
          'subtype' => 'string',
          'order' => 1,
        ),
        'tomorrowTS' => array(
          'name' => __('Demain timestamp', __FILE__),
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
    else if ($this->getConfiguration('datasource') == 'consumptionRTE') {
    }
    /* TODO crash si suppression ancienne commande quand chgt type
    foreach ($this->getCmd() as $cmd) { // Chgt type => suppression commandes type precedent
      $cmdLogicalId = $cmd->getLogicalId();
      if (!isset($cmd_list[$cmdLogicalId]) && $cmdLogicalId != 'refresh') {
        $cmd->remove();
        $msg .= " --$cmdLogicalId,";
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
        $msg .= " ++$key,";
      }
      else
        $msg .= " ==$key,";
      $cmd->setType('info');
      $cmd->setSubType($cmd_info['subtype']);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
    }

    /* TODO pas d'historique
    $cmd = $this->getCmd(null, 'dataHoursJson');
    if(is_object($cmd)) {
      $cmd->setIsHistorized(0); // Pas d'historique sur cette commande trop volumineuse
      $cmd->save();
    }
     */

    $refresh = $this->getCmd(null, 'refresh');
    if (!is_object($refresh)) {
      $refresh = new rteEcowattCmd();
      $refresh->setName(__('Rafraichir', __FILE__));
        $msg .= " ++refresh";
      // $refresh->setIsVisible(0);
    }
    $refresh->setEqLogic_id($this->getId());
    $refresh->setLogicalId('refresh');
    $refresh->setType('action');
    $refresh->setSubType('other');
    $refresh->setOrder(99);
    $refresh->save();

    $msg .= " 1 Liste des commandes de l'équipement: ";
    foreach ($this->getCmd() as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      $msg .= "ID: ".$cmd->getId() ." $cmdLogicalId,";
    }
log::add(__CLASS__ ,'debug',__FUNCTION__ ." $msg");

    $this->updateInfo(0);
  }

  public function updateInfo($fetch) {
    $datasource = $this->getConfiguration('datasource');
// message::add(__CLASS__, __FUNCTION__ ." DataSource $datasource Fetch: $fetch");
    switch ($datasource) {
      case 'consumptionRTE': $this->updateInfoConsumption($fetch); break;
      case 'ecowattRTE': $this->updateInfoEcowatt($fetch); break;
      case 'tempoRTE': $this->updateInfoTempo($fetch); break;
    }
    $this->refreshWidget();
  }

  public function updateInfoConsumption($fetch) {
    $response = $this->fetchDataConsumptionRTE();
  }

  public function updateInfoTempo($fetch) {
    $params = self::initParamRTE($datasource);
    $t = time();
    $cmd = $this->getCmd(null,'tomorrow');
    if(is_object($cmd)) $tomorrow = $cmd->execCmd();
    else $tomorrow = 'UNDEFINED';
    if(date('G',$t) == 0 && $tomorrow != 'UNDEFINED') { // minuit Transfert tomorrow vers today et tomorrow = UNDEFINED si tomorrow est défini
      $this->checkAndUpdateCmd('today', $tomorrow);
      $this->checkAndUpdateCmd('todayTS', $t);
      $summerToday = date('I',$t);
      $ts = strtotime("tomorrow midnight");
      $summerTomorrow =date('I',$ts);
      $ts += ($summerToday - $summerTomorrow)*3600;
      $this->checkAndUpdateCmd('tomorrowTS', $ts);
      $this->checkAndUpdateCmd('tomorrow', "UNDEFINED");
    }
    else if($tomorrow == 'UNDEFINED' || $tomorrow == '') {
      if(date('m',$t)<9) { // Avant 1er septembre
        $ts = mktime(0,0,0,9,1,(date('Y',$t)-1)); // Debut saison 1er septembre année précédente
        $leapYear = date('L',$t); // L'année en cours est-elle bissextile?
      }
      else { // Après 1er septembre
        $ts = mktime(0,0,0,9,1,date('Y')); // Debut saison 1er septembre de cette année
        $t2 = mktime(12,0,0,1,1,date('Y',$t)+1); // l'année prochaine est-elle bissextile?
        $leapYear = date('L',$t2);
      }
      $start_date = date('Y-m-d\TH:i:sP',$ts); // "20xx-09-01T00:00:00+02:00";
      // TODO stocker les nombres de jours passés pour ne pas redemander tout depuis 1er septembre
      $summerToday = date('I');
      $ts = strtotime("tomorrow midnight")+86400;
      $summerTomorrow =date('I',$ts);
      $ts += ($summerToday - $summerTomorrow)*3600;
      $end_date = date('Y-m-d\TH:i:sP',$ts); // "20xx-09-03T00:00:00+02:00";
      log::add(__CLASS__, 'debug', "Tempo date $start_date / $end_date");
  // message::add(__CLASS__, "Tempo date $start_date / $end_date");
      // $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/sandbox/tempo_like_calendars";
      $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/tempo_like_calendars?start_date=$start_date&end_date=$end_date";
      $response = self::getResourceRTE($params, $api);
  /*
  */
  $file = __DIR__ ."/../../data/ecowattTempo.json";
  $hdle = fopen($file, "wb");
  if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
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
                $this->checkAndUpdateCmd('todayTS', $today);
                $todayOK = 1;
              }
            }
            if($tomorrowOK == 0) {
              if($tomorrow >= $deb && $tomorrow < $fin) {
                // message::add(__CLASS__,"TOMORROW found");
                $this->checkAndUpdateCmd('tomorrow', "$color");
                $this->checkAndUpdateCmd('tomorrowTS', $tomorrow);
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
      $nbTotBlue = 365 + $leapYear - $nbTotWhite - $nbTotRed;
        // Nb jours restants
      $this->checkAndUpdateCmd('blue-remainingDays', $nbTotBlue - $nbBlue); // Reste bleu
      $this->checkAndUpdateCmd('white-remainingDays', $nbTotWhite - $nbWhite); // Reste blanc
      $this->checkAndUpdateCmd('red-remainingDays', $nbTotRed - $nbRed);   // Reste rouge
        // Nb jours total
      $this->checkAndUpdateCmd('blue-totalDays', $nbTotBlue); // Total bleu
      $this->checkAndUpdateCmd('white-totalDays', $nbTotWhite); // Total blanc
      $this->checkAndUpdateCmd('red-totalDays', $nbTotRed);   // Total rouge
    }
    else log::add(__CLASS__,'debug',date('d/m H:i:s') ." Tempo RTE tomorrow already: $tomorrow");
  }

  public function updateInfoEcowatt($fetch) {
    // $demo = config::byKey('demoMode', __CLASS__, 0);
    $demo = $this->getConfiguration('demoMode',0);
    if($demo) {
      $fileEcowatt = __DIR__ ."/../../data/ecowattRTEsandbox.json";
      $mod = str_pad((date('j') % 3)+3,2,'0',STR_PAD_LEFT);
        // date dans plage du bac à sable. Jour entre 3 et 5
      $nowTS = strtotime("2022-06-$mod " .date('H:i:s'));
      // $nowTS = strtotime("2022-06-04 " .date('H:i:s'));
    }
    else {
      $fileEcowatt = __DIR__ ."/../../data/ecowattRTE.json";
      $nowTS = time();
      // $nowTS = strtotime("2022-10-30 00:00:12"); // .date('H:i:s'));
    }
    if(file_exists($fileEcowatt) && (!$fetch || $demo)) {
      $response = file_get_contents($fileEcowatt);
      log::add(__CLASS__, 'debug', "Using existing file $fileEcowatt " .date('H:i:s',filemtime($fileEcowatt)));
    }
    else {
      log::add(__CLASS__, 'debug', "Fetching new Ecowatt data ".date('j/m H:i:s'));
      $response = $this->fetchDataEcowattRTE();
    }
    $foundNowTS = 0; $nextAlertValue = 0; $valueAlertNow = 0;
    if($response === false) {
      log::add(__CLASS__, 'debug', 'Pas de données de RTE');
      for($i=0;$i<4;$i++) {
        $this->checkAndUpdateCmd("dayTimestampD$i", $nowTS+$i*86400);
        $this->checkAndUpdateCmd("dayValueD$i", 0);
        $this->checkAndUpdateCmd("messageD$i", "Erreur récupération données RTE");
        $this->checkAndUpdateCmd("dataHourD$i", substr(str_repeat('0,',24),0,-1));
      }
      $this->checkAndUpdateCmd("dataHoursJson", substr(str_repeat('0,',72),0,-1));
    }
    else {
      $dec = json_decode($response,true);
      $data = array();
      // if($demo == 0) $dec['signals'][1]['values'][0]['hvalue'] = 3;
      $modNowTS = mktime(0,0,0,date('m',$nowTS),date('d',$nowTS),date('Y',$nowTS));
      foreach($dec['signals'] as $signal) {
        $ts =strtotime($signal['jour']);
        $val = array();
        // Init du tableau. Trous dans les datas de la sandbox
        for($i=0;$i<24;$i++) $val[] = 0;
        foreach($signal['values'] as $value) {
          $val[$value['pas']] = $value['hvalue'];
        }
        if($ts >= $modNowTS) {
          $data[] = array('jour' => $ts, 'dvalue' => $signal['dvalue'],
                        'message' => $signal['message'], 'value' => $val);
        }
        unset($val);
      }
      sort($data); // les données de la sandbox ne sont pas dans l'ordre chronologique
      $start = -1;
      $nextAlertTS = 0; $firstAlert = 0;
      $valHours = array();
      for($day=0;$day<4;$day++) {
        if(!isset($data[$day])) {
          $this->checkAndUpdateCmd("dayTimestampD$day", $nowTS+$day*86400);
          $this->checkAndUpdateCmd("dayValueD$day", 0);
          $this->checkAndUpdateCmd("messageD$day", "Erreur récupération données RTE");
          $this->checkAndUpdateCmd("dataHourD$day", substr(str_repeat('0,',24),0,-1));
          if($demo == 0 && $day != 3) log::add(__CLASS__, 'debug', "Data for day $day not set");
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
  // message::add(__CLASS__, "startAlert : " .date('j/m H:i:s',$startAlert)." endAlert: ".date('j/m H:i:s',$endAlert) ." valueAlertNow: $valueAlertNow nextAlertValue: $nextAlertValue");
    $this->checkAndUpdateCmd("nextAlertValue", $nextAlertValue);
    if($valueAlertNow == 0 || $valueAlertNow == 1)
      $this->checkAndUpdateCmd("nextAlertTS", $startAlert);
    else
      $this->checkAndUpdateCmd("nextAlertTS", $endAlert);
    $this->checkAndUpdateCmd("valueNow", $valueAlertNow);
    $this->checkAndUpdateCmd("datenowTS", (($foundNowTS)?$nowTS:0));
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

    // remplacement de strftime pour des formats simples $format est le meme que strftime
  public static function myStrftime($format,$timestamp=null) {
    if($timestamp === null) $timestamp = time();
    $resu = $format;
    $language = config::byKey('language', 'core', 'fr_FR');
    if($language == 'fr_FR') {
      $daysFull = array( 1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche');
      $daysShort = array( 1 => 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim');
      $monthsFull = array( 1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet',
        'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre',);
      $monthsShort = array( 1 => 'Janv.', 'Févr.', 'Mars', 'Avril', 'Mai', 'Juin',
        'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.',);
    }
    else {
      $daysFull = array( 1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
      $daysShort = array( 1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun');
      $monthsFull = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December',);
      $monthsShort = array( 1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',);
    }
    // Construction tableaux des remplacements
              // Jour de la semaine complet
    $search = array('%A'); $replace = array(ucfirst($daysFull[date('N',$timestamp)]));
              // Jour de la semaine réduit
    $search[] = '%a'; $replace[] = ucfirst($daysShort[date('N',$timestamp)]);
              // jour du mois 01 à 31
    $search[] = '%d'; $replace[] = str_pad(date('j',$timestamp),2,'0',STR_PAD_LEFT);
              // jour du mois 1 à 31 avec un espace au début si 1 seul chiffre
    $search[] = '%e'; $replace[] = str_pad(date('j',$timestamp),2,' ',STR_PAD_LEFT);
              // Mois complet
    $search[] = '%B'; $replace[] = lcfirst($monthsFull[date('n',$timestamp)]);
              // Mois réduit
    $search[] = '%b'; $replace[] = lcfirst($monthsShort[date('n',$timestamp)]);
              // Mois sur 2 chiffres
    $search[] = '%m'; $replace[] = date('m',$timestamp);
              // Heure 00 à 23
    $search[] = '%H'; $replace[] = str_pad(date('G',$timestamp),2,'0',STR_PAD_LEFT);
              // Heure 0 à 23 avec un espace au début si 1 seul chiffre
    $search[] = '%k'; $replace[] = str_pad(date('G',$timestamp),2,' ',STR_PAD_LEFT);
              // Minute 00 à 59
    $search[] = '%M'; $replace[] = date('i',$timestamp);
              // Seconde 00 à 59
    $search[] = '%S'; $replace[] = date('s',$timestamp);
              // Année 4 chiffres
    $search[] = '%Y'; $replace[] = date('Y',$timestamp);
              // %% en %
    $search[] = '%%'; $replace[] = '%';
      // Remplacement
    $resu = str_replace($search,$replace,$resu);
    return($resu);
  }

  public function toHtml($_version = 'dashboard') {
    $templateFile = '';
    $t0 = -microtime(true);
    $replace = $this->preToHtml($_version, array('#background-color#' => '#bdc3c7'));
    if (!is_array($replace)) {
      return $replace;
    }
    $version = jeedom::versionAlias($_version);
    if ($this->getConfiguration('datasource') == 'ecowattRTE') {
      $templateF = $this->getConfiguration('templateEcowatt','plugin');
      if($templateF == 'none') return parent::toHtml($_version);
      else if($templateF == 'plugin') $templateFile = 'rte_ecowatt';
      else if($templateF == 'custom') $templateFile = 'custom.rte_ecowatt';
      else $templateFile = substr($templateF,0,-5);
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
          $replace["#date$idx#"] = self::myStrftime('%A %e %B',$cmd->execCmd());
          $replace["#date${idx}dm#"] = self::myStrftime('%e %B',$cmd->execCmd());
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
          else $replace['#datenow#'] = self::myStrftime('%A %e %B %kh-',$val) .date('G',$val+3600).'h';
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
            $tab .= '<td title=' .$title .' width=4% style="font-size:8px!important;background-color:' .$color[$data] .';';
            if($dayTS[$idx] + $i * 3600 == $datenowTS) { // heure actuelle
              $tabHCcolumn .= '{ y:2, name: "'.$title .'", color: "' .$color[$data] .'"},';
              $tabHCbar .= '{ data: [1], name: "'.$title .'", pointWidth: 30, color: "' .$color[$data] .'"},';
              if($i % 2 && $i != 23) $tab .= 'border-right: 1px solid #000;';
              $tab .= ' text-align:center;vertical-align: top"><i class="fa fa-circle fa-lg" style="color: rgb(var(--bg-color));font-size: 7px"></i>';
            }
            else {
              $tabHCcolumn .= '{ y:1, name: "'.$title .'", color: "' .$color[$data] .'"},';
              if($i % 2 && $i != 23) $tab .= 'border-right: 1px solid #000;';
              $tab .= '">&nbsp;';

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
            $tab .= '<td style="font-size:10px!important;background-color: rgb(var(--bg-color));color: var(--txt-color)" colspan="4">' .($i*4) .'h</td>';
          }
          $tab .= "</tr></table>";
          $replace["#dataHourD$idx#"] = "$tab";
          $replace["#dataHour${idx}HCpieAM#"] = $dataHCpieAM;
          $replace["#dataHour${idx}HCpiePM#"] = $dataHCpiePM;
          $replace["#dataHour${idx}HCcolumn#"] = $tabHCcolumn;
          $replace["#dataHour${idx}HCbar#"] = $tabHCbar;

        }
        else if($cmdLogicalId == 'dataHoursJson') {
          $numCmdsHour = $this->getConfiguration('numCmdsHour',24);
          if($numCmdsHour > 72) $numCmdsHour = 72;
          $datas = json_decode($cmd->execCmd(),true);
          $tab = '';
          if($datas !== null) {
            $numCmdsHour = min(count($datas),$numCmdsHour);
            $replace['#numCmdsHour#'] = $numCmdsHour;
            $i = 0;
            $w = round(100/$numCmdsHour,2);
            foreach($datas as $data) {
              if($i >= $numCmdsHour) break;
              $tab .= '<td width='.$w.'% title="' .self::myStrftime('%A %e %B %kh-',$data['TS']) .date('G',$data['TS']+3600) .'h" style="background-color:' .$color[$data['hValue']] .'; font-size:8px!important;';
              if(date('G',$data['TS']) % 2 && $i != $numCmdsHour) $tab .= 'border-right: 1px solid #000;';
              if($i == 0)
                $tab .= ' text-align:center;vertical-align: top"><i class="fa fa-circle fa-lg" style="color: rgb(var(--bg-color));font-size: 7px"></i></td>';
              else $tab .= '">&nbsp;</td>';
              $i++;
            }
            $tab .= '</tr><tr>'; // 2eme ligne pour afficher les heures
            $i = 0; $mod = 0; $col = 0;
            foreach($datas as $data) {
              if($i > $numCmdsHour) break;
              $hCur = date('G',$data['TS']);
              $mod = $hCur % 4;
              if(!($mod)) {
                $reste = $numCmdsHour - $col;
                $tab .= '<td width='.$w.'% style="font-size:10px!important;background-color: rgb(var(--bg-color));color: var(--txt-color)';
                if($hCur == 0) $tab .= ';border-left: 1px solid #000;';
                $tab .= '" colspan="' .(($reste>= 4)?4:$reste) .'">';
                if($reste >= 2 ) {
                  if($hCur == 0) $tab .= date('j/m',$data['TS']);
                  else if(!$mod) $tab .= $hCur .'h';
                }
                $tab .= '</td>';
                $col +=4;
              }
              else if($i == 0) {
                $tab .= '<td width='.$w.'% style="font-size:10px!important;background-color: rgb(var(--bg-color));color: var(--txt-color)" colspan="'.(4-$mod).'">';
                $tab .= '</td>';
                $col += 4-$mod;
              }
              $i++;
            }
          }
          else $replace['#numCmdsHour#'] = '--';
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
          $replace["#dayColor${idx}#"] = $color[$colD];
        }
        else $replace['#' .$cmdLogicalId .'#'] = $cmd->execCmd();

/*
        $replace['#' .$cmdLogicalId .'_history#'] = '';
        $replace['#' .$cmdLogicalId .'_id#'] = $cmd->getId();
        $replace['#' .$cmdLogicalId .'_uid#'] = 'cmd' . $this->getId() . eqLogic::UIDDELIMITER . mt_rand() . eqLogic::UIDDELIMITER;
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
          $replace['#nextAlert#'] = "Prochaine alerte:  ".'<i class="fa fa-circle fa-lg" style="color: '.$color[$nextAlertValue] .'"></i> ' .lcfirst(self::myStrftime('%a. %e %b %kh',$nextAlertTS));
        }
        else {
          $replace['#nextAlert#'] = "Fin de l'alerte en cours " .lcfirst(self::myStrftime('%a. %e %b à %kh',$nextAlertTS));
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
          $replace['#dataActuEcowatt#'] = 'Données RTE SANDBOX '.date('j/m/Y',$fileTS);
        else
          $replace['#dataActuEcowatt#'] = 'Données RTE du '.date('j/m/Y H:i:s',$fileTS);
          // .'. tokenExpires '.date('H:i:s',$tokenExpires)
        $replace['#dataActuEcowatt#'] .= '. Affichage: '.date('H:i:s');
        if($demo) $replace['#dataActuEcowatt#'] .= ' en '.round($t0+microtime(true),3).'s';
      }
      else {
        $replace['#dataActuEcowatt#'] = 'Dernière requête RTE le '.date('j/m/Y H:i:s',$lastcallEcoTS);
      }

      $refresh = $this->getCmd(null, 'refresh');
      if (is_object($refresh) && $refresh->getIsVisible() == 1) {
        $replace['#refresh_id#'] = $refresh->getId();
      } else {
          $replace['#refresh_id#'] = '';
      }
      if (!isset($replace['#innerSizeAM#'])) $replace['#innerSizeAM#'] = '75%';
      if (!isset($replace['#innerSizePM#'])) $replace['#innerSizePM#'] = '75%';
      $fileReplace = __DIR__ ."/../../data/ecowattReplace.json";
      $template = 'rte_ecowatt';
    }
    else if ($this->getConfiguration('datasource') == 'tempoRTE') {
      if($this->getConfiguration('usePluginTemplate','1') == '0')
        return parent::toHtml($_version);
      $color['BLUE'] = '#005BBB';
      $color['WHITE'] = '#DFDFDF';
      $color['RED'] = '#F34B32';
      $color['UNDEFINED'] = '#FFA02F';
      $color['ERROR'] = '#95A5A6';
      while ($col = current($color)) {
        $key = key($color);
        $replace["#color$key#"] = $col;
        next($color);
      }
      foreach ($this->getCmd('info') as $cmd) {
        $cmdLogicalId = $cmd->getLogicalId();
        if($cmdLogicalId == 'today') {
          $replace['#colorToday#'] = $color[$cmd->execCmd()];
        }
        else if($cmdLogicalId == 'tomorrow') {
          $replace['#colorTomorrow#'] = $color[$cmd->execCmd()];
        }
        else if($cmdLogicalId == 'todayTS') {
          $ts = $cmd->execCmd();
          $replace['#todayDate#'] = self::myStrftime('%A %e %B',$ts);
          if(date('m',$ts)<9) { // Avant 1er septembre
            $replace['#endSeason#'] = date('Y');
            $replace['#startSeason#'] = $replace['#endSeason#']-1;
          }
          else {
            $replace['#startSeason#'] = date('Y');
            $replace['#endSeason#'] = $replace['#startSeason#']+1;
          }
        }
        else if($cmdLogicalId == 'tomorrowTS') {
          $replace['#tomorrowDate#'] = self::myStrftime('%A %e %B',$cmd->execCmd());
        }
        else $replace['#' .$cmdLogicalId .'#'] = $cmd->execCmd();
      }
      $lastcallEcoTS = config::byKey('lastcall-tempoRTE', __CLASS__, 0);
      $replace['#dataActuTempo#'] = 'Dernière requête RTE le '.date('j/m/Y H:i:s',$lastcallEcoTS);
      $replace['#dataActuTempo#'] .= '. Affichage: '.date('H:i:s');
      $replace['#dataActuTempo#'] .= ' en '.round($t0+microtime(true),3).'s';
      $fileReplace = __DIR__ ."/../../data/tempoReplace.json";
      $template = 'rte_tempo';
    }
    else {
      if($this->getConfiguration('usePluginTemplate','1') == '0')
        return parent::toHtml($_version);
      $fileReplace = __DIR__ ."/../../data/consumptionReplace.json";
      $template = 'rte_tempo';
    }
    /*
$hdle = fopen($fileReplace, "wb");
if($hdle !== FALSE) { fwrite($hdle, json_encode($replace)); fclose($hdle); }
     */

    if($templateFile == '' || $version == 'mobile') {
      if (file_exists( __DIR__ ."/../template/$_version/custom.${template}.html")) {
        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, "custom." .$template, __CLASS__)));
      }
      else {
        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, $template, __CLASS__)));
      }
    }
    else {
      $loglevel = log::convertLogLevel(log::getLogLevel(__CLASS__));
      if (file_exists( __DIR__ ."/../template/$_version/${templateFile}.html")) {
        if($loglevel == 'debug') $replace['#dataActuEcowatt#'] .= " Template :  " .$templateFile;
        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, $templateFile, __CLASS__)));
      }
      else {
        log::add(__CLASS__,'debug',"TemplateFile: ".__DIR__ ."/../template/$_version/$templateFile.html");
        $replace['#dataActuEcowatt#'] .= " Template [$templateFile] non trouvé.";
        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, $template, __CLASS__)));
      }
    }
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
