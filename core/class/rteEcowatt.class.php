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

  public static $_colTempo = array("HCJB"=>"#46A1ED", "HPJB"=>"#00518B",
                 "HCJW"=>"#DFDFDF", "HPJW"=>"#FFFFFF",
                 "HCJR"=>"#F34B32", "HPJR"=>"#C81640",
                 "UNDEFINED"=>"#7A7A7A", "ERROR"=>"#000000");

  // public static function backupExclude() { return(array('data','desktop','plugin_info')); }
  public static function postConfig_IDclientSecretB64($_value) {
    config::save('tokenRTEexpires', 0, __CLASS__); // expires token if IDs RTE were changed
    config::save('tokenRTE', '', __CLASS__); // unset token
    // message::add(__CLASS__,__FUNCTION__ . " change $_value");
  }
  
  public static function postConfig_HPJR($_value) {
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
      if($datasource == 'tempoRTE') {
        $rteEcowatt->updateInfoTempoRTE(0,null);
        $rteEcowatt->refreshWidget();
      }
    }
    // message::add(__CLASS__,__FUNCTION__ . " change $_value");
  }

  public static function extractValueFromJsonTxt($cmdValue, $request) {
    $txtJson = str_replace('&quot;','"',$cmdValue);
    $json =json_decode($txtJson,true);
    if($json !== null) {
      $tags = explode('>', $request);
      foreach ($tags as $tag) {
        $tag = trim($tag);
        if (isset($json[$tag])) {
          $json = $json[$tag];
        } elseif (is_numeric(intval($tag)) && isset($json[intval($tag)])) {
          $json = $json[intval($tag)];
        } elseif (is_numeric(intval($tag)) && intval($tag) < 0 && isset($json[count($json) + intval($tag)])) {
          $json = $json[count($json) + intval($tag)];
        } else {
          $json = "Request error: tag[$tag] not found in " .json_encode($json);
          break;
        }
      }
      return (is_array($json)) ? json_encode($json) : $json;
    }
    return ("*** Unable to decode JSON: " .substr($txtJson,0,20));
  }

  public static function getJsonInfo($cmd_id, $request) {
    $id = cmd::humanReadableToCmd('#' .$cmd_id .'#');
    $cmd = cmd::byId(trim(str_replace('#', '', $id)));
    if(is_object($cmd)) {
      return self::extractValueFromJsonTxt($cmd->execCmd(), $request);
    }
    else log::add(__CLASS__, 'debug', "Command not found: $cmd");
    return(null);
  }

  public static function cron() { // Update remainingTime command
    $t = time(); $h = date('Gm',$t);
    if($h <600) $diff = strtotime('today 06:00:00') - $t;
    else if($h <2200) $diff = strtotime('today 22:00:00') - $t;
    else $diff = strtotime('tomorrow 06:00:00') - $t;
      
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
      if($datasource == 'tempoRTE') {
        $rteEcowatt->checkAndUpdateCmd('remainingTime', abs($diff));
      }
    }
  }

  public static function cronDaily() {
    $chgeDay = 1; // Changement de jour sans interrogation de RTE
    self::getTempoPricesJson(1); // 1 pour notification dans les logs des prix périmés ou absents
    $decData = null;
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
          // Chgt de jour today in yesterday, tomorrow in today
      if($datasource == 'tempoRTE') {
        if($chgeDay == 1) {
          $decData = self::getTempoData(1,0);
        }
        $chgeDay = 0;
        $rteEcowatt->updateInfoTempoRTE(0,$decData);
        $rteEcowatt->refreshWidget();
      }
    }
  }

  public static function cronHourly() {
    $h = date('G');
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
      if($datasource == 'tempoRTE') {
        if($h == 6 || $h == 22) { // MAJ de la commande Maintenant HP/HC
          $cmd = $rteEcowatt->getCmd(null,'today');
          if(is_object($cmd)) $today = $cmd->execCmd();
          else $today = 'UNDEF';
          $rteEcowatt->updateTempoNowValue(date('G'),$today);
        }
        $rteEcowatt->refreshWidget();
      }
      else if($datasource == 'ecowattRTE') { // MAJ tuile ecowatt
        $rteEcowatt->updateInfo(0); // without fetching data
      }
    }
  }

  public static function cron30() {
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
      if($datasource == 'consumptionRTE') {
        $rteEcowatt->updateInfo(1);
      }
    }
  }

  public static function pullDataEcowatt() {
    /* EDF HS
     */
      // deplacé de cronHourly pour étaler les requetes chez EDF
    $hour = array('tempoEDF' => array(0, 11, 12, 14, 16),
                  'ejpEDF' => array(1, 6, 12, 16, 17, 19, 22));
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      $datasource = $rteEcowatt->getConfiguration('datasource');
      if(isset($hour[$datasource]) && in_array(date('G'), $hour[$datasource])) {
        // log::add(__CLASS__, 'debug', __FUNCTION__ .' ' .$datasource .' ' .date('H:i:s'));
        $rteEcowatt->updateInfo(0);
      }
    }

    $recup = 1;
        // MAJ tous les équipements ecowattRTE. Fetch RTE 1 seule fois
    foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
      if($rteEcowatt->getConfiguration('datasource') == 'ecowattRTE') {
        $demo = $rteEcowatt->getConfiguration('demoMode',0);
        if($demo) $rteEcowatt->updateInfo(0);
        else {
          $rteEcowatt->updateInfo($recup);
          $recup = 0;
        }
      }
    }
        // MAJ tous les équipements tempoRTE. Fetch RTE 1 seule fois
    $h = date('Gi');
    if($h > 1030) { // de 10h31 à 23h
      if(date('G') == 11) $recup = 1; else $recup = 0; // forcage a 11h
      foreach (self::byType(__CLASS__,true) as $rteEcowatt) {
        $datasource = $rteEcowatt->getConfiguration('datasource');
        if($datasource == 'tempoRTE') {
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
      self::getNewTokenRTE($params);
      log::add(__CLASS__, 'debug', "  " .__FUNCTION__ ." $datasource NEW token. Expires: " .date('H:i:s',$params['tokenExpires']));
    }
    else log::add(__CLASS__, 'debug', "  " .__FUNCTION__ ." $datasource ReUSE token till: " .date('H:i:s',$params['tokenExpires']));
    $params['lastcall'] = config::byKey('lastcall-'.$datasource, __CLASS__, 0);
    return($params);
  }
  public static function getNewTokenRTE(&$params) {
    $token_url ="https://digital.iservices.rte-france.com/token/oauth/";
    $header = array("Content-Type: application/x-www-form-urlencoded",
      "Authorization: Basic " .$params['IDclientSecretB64']);
    $curloptions = array(
      CURLOPT_URL => $token_url, CURLOPT_HTTPHEADER => $header, CURLOPT_SSL_VERIFYPEER => false,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true);
    $curl = curl_init();
    curl_setopt_array($curl, $curloptions);
    $response = curl_exec($curl);
    $curlHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($curl);
    curl_close($curl);
    unset($curl);
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

  public static function getResourceRTE($params, $apiUrl) {
    log::add(__CLASS__,'debug',"----- CURL ".__FUNCTION__ ." URL: $apiUrl");
    $header = array("Authorization: Bearer {$params['tokenRTE']}",
      /* "Content-Type: application/json", */
      "Host: digital.iservices.rte-france.com");
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $apiUrl, CURLOPT_HTTPHEADER => $header,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true));
    $response = curl_exec($curl);
    if ($response === false)
      log::add(__CLASS__,'error', "Failed curl_error: " .curl_error($curl));
    $curlHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
// message::add(__CLASS__,"HeaderOut: ".json_encode($curlHeaderOut));
    if($curlHttpCode != 200) {
      log::add(__CLASS__,'error',__FUNCTION__ ." ----- CURL return code: $curlHttpCode URL: $apiUrl" 
        .(($response != '') ? " response: [$response]" : ""));
    }
    // log::add(__CLASS__,'debug',$response);
    curl_close($curl);
    unset($curl);
    return ($response);
  }

  public function fetchDataConsumptionRTE() {
    $demo = $this->getConfiguration('demoModeConso',0);
// message::add(__CLASS__,"Appel ".__FUNCTION__ ." consumptionRTE ".date('H:i:s'));
    $params = self::initParamRTE('consumptionRTE');
    if($demo) { // mode demo. Données du bac à sable RTE
      $api = "https://digital.iservices.rte-france.com/open_api/consumption/v1/sandbox/short_term";
      $fileConsumption = __DIR__ ."/../../data/consumptionRTEsandbox.json";
    }
    else {
      $api = "https://digital.iservices.rte-france.com/open_api/consumption/v1/short_term"; // ?type=<valeur(s)>&start_date=<valeur>&end_date=<valeur>";
      $fileConsumption = __DIR__ ."/../../data/consumptionRTE.json";
    }
    log::add(__CLASS__, 'debug', '  Lastcall: '.$params['lastcall'] .'s');
    // limitation des requetes 15 minutes pour l'API consumption
    if($demo || (!$demo && time() - $params['lastcall'] > 900)) { // plus d'un quart d'heure depuis derniere requete
      $response = self::getResourceRTE($params, $api);
      if($response != '') {
        json_decode($response,true);
        if(json_last_error() == JSON_ERROR_NONE) {
          if(!$demo) config::save('lastcall-consumptionRTE', time(), __CLASS__);
          $hdle = fopen($fileConsumption, "wb");
          if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
        }
        else log::add(__CLASS__, 'warning', "  Erreur json_decode: " .json_last_error_msg());
      }
    }
    else {
      log::add(__CLASS__, 'warning', '15 minutes minimum entre 2 demandes de mise à jour. Réessayez aprés: ' .date('H:i:s',$params['lastcall']+900));
      if(file_exists($fileConsumption)) {
        $response = file_get_contents($fileConsumption);
        if($response != '') log::add(__CLASS__, 'debug', '  Mise à jour de l\'interface avec les données de la requête précédente.');
        else return false;
      }
      else return false;
    }
    return $response;
  }

  public function fetchDataEcowattRTE() {
    // $demo = config::byKey('demoMode', __CLASS__, 0);
    $demo = $this->getConfiguration('demoMode',0);
// message::add(__CLASS__,"Appel ".__FUNCTION__ ." ecowattRTE ".date('H:i:s'));
    $params = self::initParamRTE('ecowattRTE');
    if($demo) { // mode demo. Données du bac à sable RTE
      $api = "https://digital.iservices.rte-france.com/open_api/ecowatt/v5/sandbox/signals";
      $fileEcowatt = __DIR__ ."/../../data/ecowattRTEsandbox.json";
    }
    else {
      $api = "https://digital.iservices.rte-france.com/open_api/ecowatt/v5/signals";
      $fileEcowatt = __DIR__ ."/../../data/ecowattRTE.json";
    }
    log::add(__CLASS__, 'debug', '  Lastcall: '.$params['lastcall'] .'s');
    // limitation des requetes 15 minutes pour l'API ecowatt
    if($demo || (!$demo && time() - $params['lastcall'] > 900)) { // plus d'un quart d'heure depuis derniere requete
      $response = self::getResourceRTE($params, $api);
      if($response != '')  {
        json_decode($response,true);
        if(json_last_error() == JSON_ERROR_NONE) {
          if(!$demo) config::save('lastcall-ecowattRTE', time(), __CLASS__);
          $hdle = fopen($fileEcowatt, "wb");
          if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
        }
        else log::add(__CLASS__,'warning',"Erreur json_decode: " .json_last_error_msg());
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

  public static function valueFromUrl($datasource,$_url) {
/* Ne fonctionne pas erreur ajax coté Jeedom ?????????????????
    if($datasource == 'EDFtempoDays') {
      $ch = curl_init();
      // configuration des options
      curl_setopt($ch, CURLOPT_URL, $_url);
      // curl_setopt($ch, CURLOPT_HEADER, 0);
      // curl_setopt_array($ch, $curloptions);
      $response = curl_exec($ch);
      $curlHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curl_error = curl_error($ch);
      curl_close($ch);
      unset($ch);
      if ($response === false)
        log::add(__CLASS__,'error', "Failed curl_error: " .$curl_error);

      return null;
    }
    else {
    }
*/
      $request_http = new com_http($_url);
      $request_http->setLogError(true);
      $request_http->setUserAgent('Wget/1.20.3 (linux-gnu)'); // User-Agent idem HA
      $dataUrl = $request_http->exec(20);
      $file = __DIR__ ."/../../data/$datasource.json";
      $hdle = fopen($file, "wb");
      if($hdle !== FALSE) { fwrite($hdle, $dataUrl); fclose($hdle); }
      if(is_json($dataUrl) === false) {
        return null;
      }
      return json_decode($dataUrl, true);
  }

  public function preInsert() {
    $this->setCategory('energy', 1);
    $this->setIsVisible(1);
    $this->setIsEnable(1);
  }

  public function postSave() {
    $datasource = $this->getConfiguration('datasource');
    $msg = "Start postsave Liste des commandes de l'équipement: ";
    foreach ($this->getCmd() as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      $msg .= "ID: ".$cmd->getId() ." $cmdLogicalId,";
    }
    // log::add(__CLASS__,'debug', $msg);
    $msg = "DataSource: $datasource";
    $msg .= " ID: ". $this->getId();
    $msg .= " Name: ". $this->getName();

    $cmd_list = array();
    if ($datasource == 'ejpEDF') {
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
        'ejpRemainingDays' => array(
          'name' => __('EJP non placés', __FILE__),
          'subtype' => 'numeric',
          'order' => 3,
        )
      );
    }
    else if ($datasource == 'consumptionRTE') {
      $cmd_list = array(
        'REALISED' => array(
          'name' => __('Consommation réalisée', __FILE__),
          'subtype' => 'numeric',
          'unit' => 'MW',
          'isHistorized' => 1,
          'order' => 1,
        ),
        'ID' => array(
          'name' => __('Prévision du jour', __FILE__),
          'subtype' => 'numeric',
          'unit' => 'MW',
          'isHistorized' => 1,
          'order' => 4,
        ),
        'D-1' => array(
          'name' => __('Prévision veille', __FILE__),
          'subtype' => 'numeric',
          'unit' => 'MW',
          'isHistorized' => 1,
          'order' => 5,
        ),
        'D-2' => array(
          'name' => __('Prévisions à 2 jours', __FILE__),
          'subtype' => 'numeric',
          'unit' => 'MW',
          'isHistorized' => 1,
          'order' => 6,
        ),
      );
    }
    else if ($datasource == 'ecowattRTE') {
      $cmd_list = array(
        'datenowTS' => array(
          'name' => __('Maintenant timestamp', __FILE__),
          'subtype' => 'numeric',
          'order' => 2,
        ),
        'valueNow' => array(
          'name' => __('Valeur maintenant', __FILE__),
          'subtype' => 'numeric',
          'isHistorized' => 1,
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
          'isHistorized' => 0,
          'order' => 6,
        )
      );
      $order = 10;
      for($i=0;$i<4;$i++) {
        $cmd_list["messageD$i"] = array('name' => "Message J$i", 'subtype' => 'string','order'=> $order++);
        $cmd_list["dayTimestampD$i"] = array('name' => "Jour J$i", 'subtype' => 'numeric','order'=> $order++);
        $cmd_list["dayValueD$i"] = array('name' => "Valeur J$i", 'subtype' => 'numeric','order'=> $order++);
        $cmd_list["dataHourD$i"] = array('name' => "Données horaires J$i", 'subtype' => 'string','order'=> $order++);
      }
    }
    else if ($datasource == 'tempoEDF') {
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
          'name' => __('Jours Bleus non placés', __FILE__),
          'subtype' => 'numeric',
          'order' => 3,
        ),
        'blue-totalDays' => array(
          'name' => __('Total jours Bleus', __FILE__),
          'subtype' => 'numeric',
          'order' => 4,
        ),
        'white-remainingDays' => array(
          'name' => __('Jours Blancs non placés', __FILE__),
          'subtype' => 'numeric',
          'order' => 5,
        ),
        'white-totalDays' => array(
          'name' => __('Total jours Blancs', __FILE__),
          'subtype' => 'numeric',
          'order' => 6,
        ),
        'red-remainingDays' => array(
          'name' => __('Jours Rouges non placés', __FILE__),
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
    else if ($datasource == 'tempoRTE') {
      $cmd_list = array(
        'now' => array(
          'name' => __('Maintenant', __FILE__),
          'subtype' => 'string',
          'order' => 1,
          'isVisible' => 0,
        ),
        'today' => array(
          'name' => __('Aujourd\'hui', __FILE__),
          'subtype' => 'string',
          'order' => 2,
          'isVisible' => 0,
        ),
        'tomorrow' => array(
          'name' => __('Demain', __FILE__),
          'subtype' => 'string',
          'order' => 3,
          'isVisible' => 0,
        ),
        'todayTS' => array(
          'name' => __('Aujourd\'hui timestamp', __FILE__),
          'subtype' => 'numeric',
          'order' => 4,
          'isVisible' => 0,
        ),
        'tomorrowTS' => array(
          'name' => __('Demain timestamp', __FILE__),
          'subtype' => 'numeric',
          'order' => 5,
          'isVisible' => 0,
        ),
        'blue-remainingDays' => array(
          'name' => __('Jours Bleus non placés', __FILE__),
          'subtype' => 'numeric',
          'order' => 6,
          'isVisible' => 0,
        ),
        'blue-totalDays' => array(
          'name' => __('Total jours Bleus', __FILE__),
          'subtype' => 'numeric',
          'order' => 7,
          'isVisible' => 0,
        ),
        'white-remainingDays' => array(
          'name' => __('Jours Blancs non placés', __FILE__),
          'subtype' => 'numeric',
          'order' => 8,
          'isVisible' => 0,
        ),
        'white-totalDays' => array(
          'name' => __('Total jours Blancs', __FILE__),
          'subtype' => 'numeric',
          'order' => 9,
          'isVisible' => 0,
        ),
        'red-remainingDays' => array(
          'name' => __('Jours Rouges non placés', __FILE__),
          'subtype' => 'numeric',
          'order' => 10,
          'isVisible' => 0,
        ),
        'red-totalDays' => array(
          'name' => __('Total jours Rouges', __FILE__),
          'subtype' => 'numeric',
          'order' => 11,
          'isVisible' => 0,
        ),
        'jsonCmdForWidget' => array(
          'name' => __('Json Cmd pour widget', __FILE__),
          'subtype' => 'string',
          'order' => 12,
          'template' => __CLASS__ ."::widget4JsonCmdByPhpvarious",
        ),
        'yesterday' => array(
          'name' => __('Hier', __FILE__),
          'subtype' => 'string',
          'order' => 13,
          'isVisible' => 0,
        ),
        'yesterdayDatetime' => array(
          'name' => __('Hier datetime', __FILE__),
          'subtype' => 'string',
          'order' => 14,
          'isVisible' => 0,
        ),
        'remainingTime' => array(
          'name' => __('Temps restant', __FILE__),
          'subtype' => 'numeric',
          'order' => 15,
        ),
      );
    }
    /* TODO crash si suppression des anciennes commandes quand changement type
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
        $cmd->setName($cmd_info['name']); 
        if(isset($cmd_info['isVisible'])) $cmd->setIsVisible($cmd_info['isVisible']);
        else $cmd->setIsVisible(1);
        if(isset($cmd_info['unit'])) $cmd->setUnite($cmd_info['unit']);
        if(isset($cmd_info['template'])) {
          $cmd->setTemplate('dashboard', $cmd_info['template']);
          $cmd->setTemplate('mobile', $cmd_info['template']);
        }
        $cmd->setOrder($cmd_info['order']);
        $msg .= " ++$key,";
      }
      else
        $msg .= " ==$key,";
      $cmd->setType('info');
      $cmd->setSubType($cmd_info['subtype']);
      $cmd->setEqLogic_id($this->getId());
      if(isset($cmd_info['isHistorized'])) $cmd->setIsHistorized($cmd_info['isHistorized']);
      $cmd->save();
    }

    $refresh = $this->getCmd(null, 'refresh');
    if (!is_object($refresh)) {
      $refresh = new rteEcowattCmd();
      $refresh->setName(__('Rafraichir', __FILE__));
        $msg .= " ++refresh";
    }
    $refresh->setEqLogic_id($this->getId());
    $refresh->setLogicalId('refresh');
    $refresh->setType('action');
    $refresh->setSubType('other');
    $refresh->setOrder(99);
    $refresh->save();

    /*
    $msg .= " 1 Liste des commandes de l'équipement: ";
    foreach ($this->getCmd() as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      $msg .= "ID: ".$cmd->getId() ." $cmdLogicalId,";
    }
log::add(__CLASS__ ,'debug',__FUNCTION__ ." $msg");
     */
    $this->updateInfo(0);
  }

  public function updateInfo($fetch) {
    $datasource = $this->getConfiguration('datasource');
    $eqName = $this->getName();
    log::add(__CLASS__, 'info', "---------------------- updateInfo $datasource Equipment [$eqName] Fetch: $fetch");
    switch ($datasource) {
      case 'tempoRTE': $this->updateInfoTempoRTE($fetch,null); break;
      case 'consumptionRTE': $this->updateInfoConsumption($fetch); break;
      case 'ecowattRTE': $this->updateInfoEcowatt($fetch); break;
      case 'tempoEDF': $this->updateInfoEdfTempo($fetch); break;
      case 'ejpEDF': $this->updateInfoEdfEjp($fetch); break;
      default: 
        if($datasource != '')
          log::add(__CLASS__, 'warning', "UpdateInfo unknown datasource [$datasource]");
    }
    $this->refreshWidget();
  }

  public function updateInfoEdfEjp($fetch) {
    $dat = date('nd');
     // log::add(__CLASS__,'warning', "Date $dat");
    if( 1 /* $dat > 331 && $dat <= 1031*/) {
      $this->checkAndUpdateCmd('today', 'OUT_OF_PERIOD');
      $this->checkAndUpdateCmd('tomorrow', 'OUT_OF_PERIOD');
      $this->checkAndUpdateCmd('ejpRemainingDays', 0);
      return;
    }
    $ejpdays = self::valueFromUrl('EDFejp','https://particulier.edf.fr/services/rest/referentiel/historicEJPStore');
    if($ejpdays === null) {
      log::add(__CLASS__,'warning', "Unable to retrieve EJP information from EDF");
      $this->checkAndUpdateCmd('today', 'ERROR');
      $this->checkAndUpdateCmd('tomorrow', 'ERROR');
      $this->checkAndUpdateCmd('ejpRemainingDays', 0);
    }
    else {
      config::save('lastcall-ejpEdf', time(), __CLASS__);
      $startEjpPeriod = strtotime($ejpdays['dateDebutPeriode']);
      $endEjpPeriod = strtotime($ejpdays['dateFinPeriode']);
      $todayTS = mktime(0,0,0);
      $tomorrowTS = mktime(0,0,0,date('m'),date('d')+1);
      $todayEjp = 0; $tomorrowEjp = 0;
      if($todayTS > $startEjpPeriod && $todayTS <= $endEjpPeriod) {
        foreach($ejpdays['listeEjp'] as $ejp) {
          $ejpTS = ($ejp['dateApplication']/1000);
          if($ejpTS == $todayTS) $todayEjp = 1;
          if($ejpTS == $tomorrowTS) $tomorrowEjp = 1;
        }
        if($todayEjp == 0) $this->checkAndUpdateCmd('today', "NOT_EJP");
        else if($todayEjp == 1) $this->checkAndUpdateCmd('today', "EJP");
        if($tomorrowEjp == 0) {
          if($todayTS == $endEjpPeriod) {
            $this->checkAndUpdateCmd('tomorrow', 'OUT_OF_PERIOD');
          }
          else if($tomorrowTS == $startEjpPeriod) {
            $this->checkAndUpdateCmd('tomorrow', "NOT_EJP");
          }
          else {
            if(date('G') < 16) $this->checkAndUpdateCmd('tomorrow', "UNDEFINED");
            else $this->checkAndUpdateCmd('tomorrow', "NOT_EJP");
          }
        }
        else if($tomorrowEjp == 1) $this->checkAndUpdateCmd('tomorrow', "EJP");
        $this->checkAndUpdateCmd('ejpRemainingDays', $ejpdays['nbEjpRestants']);
      }
      else {
        $this->checkAndUpdateCmd('today', 'OUT_OF_PERIOD');
        $this->checkAndUpdateCmd('tomorrow', 'OUT_OF_PERIOD');
        $this->checkAndUpdateCmd('ejpRemainingDays', 0);
      }
    }
  }

  public function updateInfoEdfTempo($fetch) {
    $this->checkAndUpdateCmd('blue-remainingDays', -1);
    $this->checkAndUpdateCmd('white-remainingDays', -1);
    $this->checkAndUpdateCmd('red-remainingDays', -1);
    $this->checkAndUpdateCmd('today', 'ERROR');
    $this->checkAndUpdateCmd('tomorrow', 'ERROR');
    return; // EDF HS
    $t = time();
    $cmd = $this->getCmd(null,'today');
    if(is_object($cmd)) $today = $cmd->execCmd();
    else $today = 'UNDEFINED';
    $cmd = $this->getCmd(null,'tomorrow');
    if(is_object($cmd)) $tomorrow = $cmd->execCmd();
    else $tomorrow = 'UNDEFINED';
    $tempoDays = self::valueFromUrl('EDFtempoColors','https://particulier.edf.fr/services/rest/referentiel/searchTempoStore?dateRelevant=' .date('Y-m-d'));
    if($tempoDays === null) {
      log::add(__CLASS__,'warning', "Unable to retrieve Tempo colors from EDF");
      $this->checkAndUpdateCmd('today', 'ERROR');
      $this->checkAndUpdateCmd('tomorrow', 'ERROR');
    }
    else {
      $file = __DIR__ ."/../../data/ecowattTempoEDF.json";
      $hdle = fopen($file, "wb");
      if($hdle !== FALSE) { fwrite($hdle, json_encode($tempoDays)); fclose($hdle); }
        // Aujourd'hui
      $color = $tempoDays['couleurJourJ'];
      if($color == 'TEMPO_ROUGE') $color='RED';
      else if($color == 'TEMPO_BLANC')  $color = 'WHITE';
      else if($color == 'TEMPO_BLEU') $color = 'BLUE';
      else if($color == 'NON_DEFINI') $color = 'UNDEFINED';
      else $color = 'ERROR';
      $this->checkAndUpdateCmd('today', $color);
        // Demain
      $color = $tempoDays['couleurJourJ1'];
      if($color == 'TEMPO_ROUGE') $color='RED';
      else if($color == 'TEMPO_BLANC')  $color = 'WHITE';
      else if($color == 'TEMPO_BLEU') $color = 'BLUE';
      else if($color == 'NON_DEFINI') $color = 'UNDEFINED';
      else $color = 'ERROR';
      $this->checkAndUpdateCmd('tomorrow', $color);
      config::save('lastcall-tempoEDF', $t, __CLASS__);
    }
      // Calcul nombre total de jours de chaque couleur
    if(date('m',$t)<9) { // Avant 1er septembre
      $leapYear = date('L',$t); // L'année en cours est-elle bissextile?
    }
    else { // Après 1er septembre
      $t2 = mktime(12,0,0,1,1,date('Y',$t)+1); // l'année prochaine est-elle bissextile?
      $leapYear = date('L',$t2);
    }
    $nbTotWhite = config::byKey('totalTempoWhite', __CLASS__, 43);
    $nbTotRed = config::byKey('totalTempoRed', __CLASS__, 22);
    $nbTotBlue = 365 + $leapYear - $nbTotWhite - $nbTotRed;
      // Interrogation sur nombre de jours
    // $nbTempoDays = self::valueFromUrl('EDFtempoDays','https://particulier.edf.fr/services/rest/referentiel/getNbTempoDays?TypeAlerte=TEMPO');
    $nbTempoDays = self::valueFromUrl('EDFtempoDays','https://api-commerce.edf.fr/commerce/activet/v1/saisons/search?option=TEMPO&dateReference=' .date('Y-m-d'));
    if($nbTempoDays === null) {
      log::add(__CLASS__,'warning', "Unable to retrieve Tempo information from EDF");
      $this->checkAndUpdateCmd('blue-remainingDays', -1);
      $this->checkAndUpdateCmd('white-remainingDays', -1);
      $this->checkAndUpdateCmd('red-remainingDays', -1);
    }
    else {
      /*
      $file = __DIR__ ."/../../data/ecowattTempoEDFnbDays.json";
      $hdle = fopen($file, "wb");
      if($hdle !== FALSE) { fwrite($hdle, json_encode($nbTempoDays)); fclose($hdle); }
      $nbBlue = $nbTempoDays['PARAM_NB_J_BLEU'];
      $nbWhite = $nbTempoDays['PARAM_NB_J_BLANC'];
      $nbRed = $nbTempoDays['PARAM_NB_J_ROUGE'];
      $this->checkAndUpdateCmd('blue-remainingDays', $nbBlue);
      $this->checkAndUpdateCmd('white-remainingDays', $nbWhite);
      $this->checkAndUpdateCmd('red-remainingDays', $nbRed);
       */
    }
      // Nb jours total
    $this->checkAndUpdateCmd('blue-totalDays', $nbTotBlue); // Total bleu
    $this->checkAndUpdateCmd('white-totalDays', $nbTotWhite); // Total blanc
    $this->checkAndUpdateCmd('red-totalDays', $nbTotRed);   // Total rouge
  }
   
  public static function getTempoData($chgeDay = 0,$fetch=0) {
    $t = time();
    if(date('m',$t) < 9) {
      $seasonStart = mktime(0,0,0,9,1,(date('Y',$t)-1));
      $seasonEnd = mktime(0,0,0,9,1,date('Y',$t));
      $leapYear = date('L',$t); // L'année en cours est-elle bissextile?
    }
    else {
      $seasonStart = mktime(0,0,0,9,1,date('Y',$t));
      $seasonEnd = mktime(0,0,0,9,1,(date('Y',$t)+1));
      $t2 = mktime(12,0,0,1,1,date('Y',$t)+1); // l'année prochaine est-elle bissextile?
      $leapYear = date('L',$t2);
    }
      // lecture données précédentes
    $dataTempo= __DIR__ ."/../../data/dataTempo.json";
    $data = @file_get_contents($dataTempo);
    $decData = null;
    if ($data !== false) {
      $decData = json_decode($data,true);
      if(isset($decData['TempoSeason']['start']) && isset($decData['TempoSeason']['end'])) {
        $sStart = strtotime($decData['TempoSeason']['start']);
        $sEnd = strtotime($decData['TempoSeason']['end']);
        if($t < $sStart || $t >= $sEnd) { // changement saison Tempo le 1er septembre
          log::add(__CLASS__, 'debug', "Starting new Tempo Season");
          @unlink($dataTempo);
          unset($decData); $decData = null;
        }
      }
      else {
        unset($decData); $decData = null;
      }
    }
    // message::add(__CLASS__,(($decData==null)?"Decdata NULL":print_r($decData,true)));
    if($decData == null) { // Pas de données construction
      $start_date = date('c',$seasonStart);
      $decData = array();
      $decData["TempoSeason"] = array("start"=>date('c',$seasonStart), "end"=>date('c',$seasonEnd), "leapYear"=>$leapYear);
      $tsLatestOK = $seasonStart-1;
    }
    else {
      if(isset($decData['latestOKdatetime'])) {
        $tsLatestOK = strtotime($decData['latestOKdatetime']);
        $start_date = date('c',$tsLatestOK);
      }
      else $start_date = date('c',$seasonStart);
    }
    $tsYesterday = strtotime('yesterday midnight');
    $tsToday = strtotime('today midnight');
    // echo "Today : $tsToday LatestOK $tsLatestOK\n";
    if($tsToday < $tsLatestOK) $start_date = date('c',$tsToday);
    $tsTomorrow = strtotime('tomorrow midnight');
    $tsEnd = mktime(0,0,0,date('m',$t),date('d',$t)+2,date('Y',$t));
    $end_date = date('c',$tsEnd); // "20xx-09-03T00:00:00+02:00";
    // log::add(__CLASS__, 'error', "RTE REQUESTS DATES: $start_date $end_date, LatestOK: " .date('c',$tsLatestOK));
    if($chgeDay == 1) { // changement de jour transfert today dans yesterday, tomorrow dans today
      $decData["yesterday"] = $decData["today"];
      $decData["today"] = $decData["tomorrow"];
      $decData["tomorrow"] = array("value"=> "UNDEFINED", "datetime"=>date('c',$tsTomorrow));
      $hdle = fopen($dataTempo, "wb");
      if($hdle !== FALSE) { fwrite($hdle, json_encode($decData)); fclose($hdle); }
      return $decData;
    }

    // Check and update yesterday
    if(isset($decData['yesterday']['datetime'])) {
      $yesterdayDataTS = strtotime($decData['yesterday']['datetime']);
      if($yesterdayDataTS != $tsYesterday) $yesterdayDataTS = 0;
    }
    else $yesterdayDataTS = 0;
// message::add(__CLASS__, "YesterdayDataTS: ".date('c',$yesterdayDataTS) ." LatestOK: " .date('c',$tsLatestOK) ." Today:" .$decData["today"]["value"]);

        // request to RTE
    if($decData && $tsLatestOK == $tsTomorrow && $yesterdayDataTS) {
      if($fetch) {
        $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/tempo_like_calendars"; // request for tomorrow only
        log::add(__CLASS__, 'debug', "Fetching data but Tomorrow is already OK since: ".date('c',$tsTomorrow) ." LatestOK: " .date('c',$tsLatestOK) ." Today:" .$decData["today"]["value"]);
      }
      else { 
        log::add(__CLASS__, 'debug', "  Tomorrow already OK: ".date('c',$tsTomorrow) ." LatestOK: " .date('c',$tsLatestOK) ." Today:" .$decData["today"]["value"]);
        return($decData);
      }
    }
    else if($decData && $tsLatestOK == $tsToday && $yesterdayDataTS) {
      log::add(__CLASS__, 'debug', "Updating tomorrow. LatestOK: " .date('c',$tsLatestOK));
      $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/tempo_like_calendars"; // request for tomorrow only
    }
    else {
      if($yesterdayDataTS == 0 && strtotime($start_date) > $tsYesterday)
        $start_date = date('c',$tsYesterday);
      log::add(__CLASS__, 'debug', "RTE REQUESTS DATES: $start_date $end_date, LatestOK: " .date('c',$tsLatestOK));
      $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/tempo_like_calendars?start_date=$start_date&end_date=$end_date&fallback_status=false";
      // $api = "https://digital.iservices.rte-france.com/open_api/tempo_like_supply_contract/v1/tempo_like_calendars?start_date=2023-09-01T00:00:00+02:00&end_date=2024-09-01T00:00:00+02:00&fallback_status=false";
    }
    $params = self::initParamRTE('tempoRTE');
    $response = self::getResourceRTE($params, $api);
    if($response == '') return null;

    $dec = json_decode($response,true);
    $latest = 0;
    if($dec === null) { // RTE aurait répondu en XML ???
      // TODO comptage des jours bleu,blanc rouge Uniquement les couleurs du jour et de demain
      $todayOK = $tomorrowOK = 0;
      $today = time();
      $tomorrow = $today + 86400;
      // message::add(__CLASS__, "XML content: ".json_encode($response));
      $xml = new SimpleXMLElement($response);
      $json = json_encode($xml);
      $dec = json_decode($json, TRUE);
      $file = __DIR__ ."/../../data/ecowattTempoXML.json";
      log::add(__CLASS__, 'warning', "getTempoData XML received and converted to JSON saved in $file");
      $hdle = fopen($file, "wb");
      if($hdle !== FALSE) { fwrite($hdle, $json); fclose($hdle); }
      if(isset($dec['Tempo'])) { // 2 dates ==> aujourd'hui et demain
        foreach($dec['Tempo'] as $tempo) {
          $color = $tempo['Couleur'];
          if($color == 'ROUGE') { $color='RED'; }
          else if($color == 'BLANC') { $color = 'WHITE'; }
          else if($color == 'BLEU') { $color = 'BLUE'; }
          else $color = 'UNDEFINED';
// message::add(__CLASS__, date('c') ." DateApplication: " .$tempo['DateApplication'] ." Color: ".$tempo['Couleur']);
          if($todayOK == 0 || $tomorrowOK == 0) {
            $deb= strtotime($tempo['DateApplication'] .' 00:00:00');
            $fin= $deb + 86400;
            if($todayOK == 0) {
              if($today >= $deb && $today < $fin) {
                $decData["today"] = array("value"=>"$color", "datetime"=>date('c',$tsToday));
// message::add(__CLASS__, date('c') ." TsToday = " .date('c',$tsToday) ." Color: $color");
                $todayOK = 1;
              }
            }
            if($tomorrowOK == 0) {
              if($tomorrow >= $deb && $tomorrow < $fin) {
                $decData["tomorrow"] = array("value"=> "$color", "datetime"=>date('c',$tsTomorrow));
// message::add(__CLASS__, date('c') ." TsTomorrow = " .date('c',$tsTomorrow) ." Color: $color");
                $tomorrowOK = 1;
              }
            }
          }
        }
        if($todayOK == 0) {
message::add(__CLASS__, "TODAY unknown " .date('c') ." TsToday = " .date('c',$tsToday) ." Color: UNDEFINED");
        }
        if($tomorrowOK == 0) {
          $decData["tomorrow"] = array("value"=> "UNDEFINED", "datetime"=>date('c',$tsTomorrow));
message::add(__CLASS__, "TOMORROW unknown " .date('c') ." TsTomorrow = " .date('c',$tsTomorrow) ." Color: UNDEFINED");
        }
        return $decData; // XML received not saved
      }
      else if(isset($dec['DateApplication'])) { // 1 seule date ==> demain
        $color = $dec['Couleur'];
        if($color == 'ROUGE') { $color='RED'; }
        else if($color == 'BLANC') { $color = 'WHITE'; }
        else if($color == 'BLEU') { $color = 'BLUE'; }
        else $color = 'UNDEFINED';
        $decData["tomorrow"] = array("value"=> "$color", "datetime"=>date('c',$tsTomorrow));
        return $decData; // Ajout
      }
      else {
        log::add(__CLASS__, 'debug', "getTempoData XML without Tempo data.");
        return null;
      }
    }
    else if(isset($dec['tempo_like_calendars']['values'])) {
      $fileTempo= __DIR__ ."/../../data/ecowattTempo.json";
      $hdle = fopen($fileTempo, "wb");
      if($hdle !== FALSE) { fwrite($hdle, $response); fclose($hdle); }
      log::add(__CLASS__, 'debug', "getTempoData JSON saved in $fileTempo");
      config::save('lastcall-tempoRTE', time(), __CLASS__);
      $listeTempo = (isset($decData["TempoDays"]))?$decData["TempoDays"]:array();
      $nbUsedBlue = isset($decData["nbUsedBlue"])?$decData["nbUsedBlue"]:0;
      $nbUsedWhite = isset($decData["nbUsedWhite"])?$decData["nbUsedWhite"]:0;
      $nbUsedRed = isset($decData["nbUsedRed"])?$decData["nbUsedRed"]:0;
      $todayOK = $tomorrowOK = $yesterdayOK = 0;
      foreach($dec['tempo_like_calendars']['values'] as $value) {
        $ts = strtotime($value['start_date']);
        if($ts > $latest) $latest = $ts;
        if(!isset($value['value'])) {
          log::add(__CLASS__, 'error', "Color not set for day: " .substr($value['start_date'],0,10) .". Changing to BLUE");
          $color = 'BLUE';
          if($ts > $tsLatestOK) $nbUsedBlue++;
        }
        else {
          $color = $value['value'];
          if($ts > $tsLatestOK) {
            if($color == 'RED') $nbUsedRed++;
            else if($color == 'WHITE') $nbUsedWhite++;
            else if($color == 'BLUE') $nbUsedBlue++;
            else {
              log::add(__CLASS__, 'error', "Unknown color [$color] changing to BLUE for " .date('d-m-Y',$ts));
              $color = 'BLUE';
              $nbUsedBlue++;
            }
          }
        }
        if($todayOK == 0 || $tomorrowOK == 0 || $yesterdayOK == 0) {
          $deb= strtotime($value['start_date']);
          $fin= strtotime($value['end_date']);
          if($yesterdayOK == 0) {
            if($tsYesterday >= $deb && $tsYesterday < $fin) {
              $decData["yesterday"] = array("value"=> "$color", "datetime" => date('c',$tsYesterday));
              $yesterdayOK = 1;
            }
          }
          if($todayOK == 0) {
            if($tsToday >= $deb && $tsToday < $fin) {
              $decData["today"] = array("value"=> "$color", "datetime" => date('c',$tsToday));
              $todayOK = 1;
            }
          }
          if($tomorrowOK == 0) {
            if($tsTomorrow >= $deb && $tsTomorrow < $fin) {
              $decData["tomorrow"] = array("value"=> "$color", "datetime" => date('c',$tsTomorrow));
              $tomorrowOK = 1;
            }
          }
        }
        if($color == 'RED' || $color == 'WHITE') {
          // verif si existe avant ajout
          if(!in_array($value['start_date'],array_column($listeTempo,"start_date")))
            $listeTempo[] = array( "start_date" => $value['start_date'], "value" => $color );
        }
      }
      if($tomorrowOK == 0) {
        $decData["tomorrow"] = array("value"=> "UNDEFINED", "datetime" => date('c',$tsTomorrow));
      }
      $decData["latestOKdatetime"] = date('c',$latest);
      $decData["nbUsedBlue"] = $nbUsedBlue;
      $decData["nbUsedWhite"] = $nbUsedWhite;
      $decData["nbUsedRed"] = $nbUsedRed;
      sort($listeTempo); // tri des jours blancs et rouge ordre chronologique
      $decData["TempoDays"] = $listeTempo;
      $hdle = fopen($dataTempo, "wb");
      if($hdle !== FALSE) { fwrite($hdle, json_encode($decData)); fclose($hdle); }
      return $decData;
    }
    else return null;
  }

  public function updateTempoNowValue($h,$today) {
    switch($today) {
      case 'BLUE': $jour = 'JB'; break;  // Jour Blue
      case 'WHITE': $jour = 'JW'; break; // Jour White
      case 'RED': $jour = 'JR'; break;   // Jour Red
      default: $jour = $today;
    }
    if($h >= 22) $txtTempo = "HC$jour";
    else if($h >= 6) $txtTempo = "HP$jour";
    else $txtTempo = ''; // TODO il faudrait la couleur de la veille. En attendant la valeur actuelle n'est pas modifiée.
    if($txtTempo != '') $this->checkAndUpdateCmd('now', $txtTempo);
  }

  public function updateInfoTempoRTE($fetch,$_decData=null) {
    if($_decData == null) $decData = self::getTempoData(0,$fetch);
    else $decData = $_decData;
    if($decData === null) {
      log::add(__CLASS__, 'warning', "decData vide getTempoData(0,$fetch)");
      // message::add(__CLASS__, "decData vide getTempoData(0,$fetch)");
    }
    else {
      // log::add(__CLASS__, 'debug', json_encode($decData));
      $leapYear = $decData['TempoSeason']['leapYear'];
        // Recup du nombre de jours blanc ou rouge dans les params du plugin
        // afin de pouvoir les modifier si variation coté RTE/EDF
      $nbTotWhite = config::byKey('totalTempoWhite', __CLASS__, 43);
      $nbTotRed = config::byKey('totalTempoRed', __CLASS__, 22);
      $nbTotBlue = 365 + $leapYear - $nbTotWhite - $nbTotRed;
      $nbUsedBlue = $decData['nbUsedBlue'];
      $nbUsedWhite = $decData['nbUsedWhite'];
      $nbUsedRed = $decData['nbUsedRed'];
      $today = $decData['today']['value'];
      $this->checkAndUpdateCmd('yesterday', $decData['yesterday']['value']);
      $this->checkAndUpdateCmd('yesterdayDatetime', $decData['yesterday']['datetime']);
      $this->checkAndUpdateCmd('today', $decData['today']['value']);
      $this->checkAndUpdateCmd('todayTS', strtotime($decData['today']['datetime']));
      $this->checkAndUpdateCmd('tomorrow', $decData['tomorrow']['value']);
      $this->checkAndUpdateCmd('tomorrowTS', strtotime($decData['tomorrow']['datetime']));
        // Nb jours non placés
      $nbRemainingBlue = $nbTotBlue - $nbUsedBlue;
      $nbRemainingWhite = $nbTotWhite - $nbUsedWhite;
      $nbRemainingRed = $nbTotRed - $nbUsedRed;
      if($nbRemainingBlue == -1 &&$nbRemainingWhite == 0 &&$nbRemainingRed == 0) $nbRemainingBlue=0;
      $this->checkAndUpdateCmd('blue-remainingDays', $nbRemainingBlue);
      $this->checkAndUpdateCmd('white-remainingDays', $nbRemainingWhite);
      $this->checkAndUpdateCmd('red-remainingDays', $nbRemainingRed);
        // Nb jours total
      $this->checkAndUpdateCmd('blue-totalDays', $nbTotBlue); // Total bleu
      $this->checkAndUpdateCmd('white-totalDays', $nbTotWhite); // Total blanc
      $this->checkAndUpdateCmd('red-totalDays', $nbTotRed);   // Total rouge
      $this->updateTempoNowValue(date('G'),$today);
      $jsonCmdValue = '{';
      if(isset($decData['yesterday'])) {
        $jsonCmdValue .= '"yesterday":{"value":"'.$decData['yesterday']['value'] .'","datetime":"' .$decData['yesterday']['datetime'] .'"},';
      }
      else {
        $arr = self::getTempoColor('yesterday midnight');
        $jsonCmdValue .= '"yesterday":{"value":"'.$arr['value'] .'","datetime":"' .$arr['start_date'] .'"},';
      }
      $jsonCmdValue .= '"today":{"value":"'.$decData['today']['value'] .'","datetime":"' .$decData['today']['datetime'] .'"},';
      $jsonCmdValue .= '"tomorrow":{"value":"'.$decData['tomorrow']['value'] .'","datetime":"' .$decData['tomorrow']['datetime'] .'"},';
      $jsonCmdValue .= '"remainingDays":{"BLUE":' .$nbRemainingBlue .',"WHITE":' .$nbRemainingWhite .',"RED":' .$nbRemainingRed .'},';
      $jsonCmdValue .= '"totalDays":{"BLUE":' .$nbTotBlue .',"WHITE":' .$nbTotWhite .',"RED":' .$nbTotRed .'},';
      $jsonCmdValue .= '"prices":' .self::getTempoPricesJson(0) .',';
      $jsonCmdValue .= '"colors":' .json_encode(self::$_colTempo) .'}';
// message::add(__FUNCTION__, $jsonCmdValue);
      $this->checkAndUpdateCmd('jsonCmdForWidget', str_replace('"','&quot;',$jsonCmdValue));
    }
  }

  public function updateInfoConsumption($fetch) {
    $fileConsumption = __DIR__ ."/../../data/consumptionRTE.json";
    $nowTS = time();
    if(file_exists($fileConsumption) && !$fetch) {
      $response = file_get_contents($fileConsumption);
      log::add(__CLASS__, 'debug', "  Using existing file $fileConsumption " .date('H:i:s',filemtime($fileConsumption)));
    }
    else {
      log::add(__CLASS__, 'debug', "  Fetching new Consumption data");
      $response = $this->fetchDataConsumptionRTE();
    }
    if($response === false) {
      log::add(__CLASS__, 'debug', "  Pas de données consommation de RTE");
      /*
      foreach ($this->getCmd('info') as $cmd) {
        $cmdLogicalId = $cmd->getLogicalId();
        // $this->checkAndUpdateCmd($cmdLogicalId, // TODO);
      }
       */
    }
    else {
      $dec = json_decode($response,true);
      $t = time();
      $lastcall = config::byKey('lastcall-consumptionRTE', __CLASS__, 0);
      $this->checkAndUpdateCmd('lastcallTS', $lastcall);
      if(isset($dec['short_term'])) {
        foreach($dec['short_term'] as $sTerm) {
          if(count($sTerm['values'])) {
            if($sTerm['type'] == 'REALISED') {
              $cmd = $this->getCmd(null,'REALISED');
              if(is_object($cmd)) {
                foreach($sTerm['values'] as $sT) {
                  $ts = strtotime($sT['start_date']);
                  $val = $sT['value'];
                  $cmd->addHistoryValue($val, gmdate('Y-m-d H:i:s',$ts));
                }
                $this->checkAndUpdateCmd('REALISED', $val,date('Y-m-d H:i:s',$ts)); // use last value
              }
              else message::add(__CLASS__,"Cmd REALISED not found. Equipment must be saved");
            }
            else if($sTerm['type'] == 'ID') {
              $cmd = $this->getCmd(null,'ID');
              if(is_object($cmd)) {
                foreach($sTerm['values'] as $sT) {
                  $ts = $st0 = strtotime($sT['start_date']);
                  $end0 = strtotime($sT['end_date']);
                  $val = $sT['value'];
                  $cmd->addHistoryValue($val, gmdate('Y-m-d H:i:s',$ts));
                  if($t >= $st0 && $t < $end0) {
                    $this->checkAndUpdateCmd('ID', $val,date('Y-m-d H:i:s',$ts));
                  }
                }
              }
              else message::add(__CLASS__,"Cmd ID not found. Equipment must be resaved");
            }
            else if($sTerm['type'] == 'D-1') {
              $cmd = $this->getCmd(null,'D-1');
              if(is_object($cmd)) {
                foreach($sTerm['values'] as $sT) {
                  $ts =  strtotime($sT['start_date']);
                  $st0 = $ts-86400;
                  $end0 = strtotime($sT['end_date'])-86400;
                  $val = $sT['value'];
                  $cmd->addHistoryValue($val, gmdate('Y-m-d H:i:s',$ts));
                  if($t >= $st0 && $t < $end0) {
                    $this->checkAndUpdateCmd('D-1', $val,date('Y-m-d H:i:s',$ts));
                  }
                }
              }
              else message::add(__CLASS__,"Cmd D-1 not found. Equipment must be resaved.");
            }
            else if($sTerm['type'] == 'D-2') {
              $cmd = $this->getCmd(null,'D-2');
              if(is_object($cmd)) {
                foreach($sTerm['values'] as $sT) {
                  $ts =  strtotime($sT['start_date']);
                  $st0 = $ts-172800; // -2 jours
                  $end0 = strtotime($sT['end_date'])-172800;
                  $val = $sT['value'];
                  $cmd->addHistoryValue($val, gmdate('Y-m-d H:i:s',$ts));
                  if($t >= $st0 && $t < $end0) {
                    $this->checkAndUpdateCmd('D-2', $val,date('Y-m-d H:i:s',$ts));
                  }
                }
              }
              else message::add(__CLASS__,"Cmd D-2 not found. Equipment must be resaved.");
            }
            else if($sTerm['type'] == 'CORRECTED') {
              $st0 = strtotime($sTerm['start_date']);
              $end0 = strtotime($sTerm['end_date']); 
              log::add(__CLASS__, 'debug', "  short_term[CORRECTED] from " .date('Y-m-d H:i:s',$st0) ." to " .date('Y-m-d H:i:s',$end0));
            }
            else {
              $cmd = $this->getCmd(null,$sTerm['type']);
              message::add(__CLASS__,"Processing [" .$sTerm['type'] ."] type");
              if(is_object($cmd)) {
                foreach($sTerm['values'] as $sT) {
                  $ts = strtotime($sT['end_date']);
                  $val = $sT['value'];
                  $cmd->addHistoryValue($sT['value'], date('Y-m-d H:i:s',$ts));
                }
                $this->checkAndUpdateCmd($sTerm['type'], $val, date('Y-m-d H:i:s',$ts));
              }
              else message::add(__CLASS__,"Cmd " .$sTerm['type'] ." not found");
            }
          }
        }
      }
    }
  }

  public function updateInfoEcowatt($fetch) {
    $demo = $this->getConfiguration('demoMode',0);
    if($demo) {
      $fileEcowatt = __DIR__ ."/../../data/ecowattRTEsandbox.json";
      $mod = str_pad((date('j') % 3)+3,2,'0',STR_PAD_LEFT);
        // date dans plage du bac à sable. Jour entre 3 et 5
      $nowTS = strtotime("2022-06-$mod " .date('H:i:s'));
      // $nowTS = strtotime("2022-06-05 05:00:00"); // .date('H:i:s'));
    }
    else {
      $fileEcowatt = __DIR__ ."/../../data/ecowattRTE.json";
      $nowTS = time();
      // $nowTS = strtotime("2022-11-01 00:00:12"); // .date('H:i:s'));
    }
    if(file_exists($fileEcowatt) && (!$fetch || $demo)) {
      $response = file_get_contents($fileEcowatt);
      log::add(__CLASS__, 'debug', "  Using existing file $fileEcowatt " .date('H:i:s',filemtime($fileEcowatt)));
    }
    else {
      log::add(__CLASS__, 'debug', "  Fetching new Ecowatt data ".date('j/m H:i:s'));
      $response = $this->fetchDataEcowattRTE();
    }
    $foundNowTS = 0; $nextAlertValue = 0; $valueAlertNow = 0;
    if($response === false) {
      log::add(__CLASS__, 'debug', "  Pas de données Ecowatt de RTE");
      for($i=0;$i<4;$i++) {
        $this->checkAndUpdateCmd("dayTimestampD$i", $nowTS+$i*86400);
        $this->checkAndUpdateCmd("dayValueD$i", -1);
        $this->checkAndUpdateCmd("messageD$i", "Données RTE non disponibles.");
        $this->checkAndUpdateCmd("dataHourD$i", substr(str_repeat('-1,',24),0,-1));
      }
      $this->checkAndUpdateCmd("dataHoursJson", substr(str_repeat('-1,',73),0,-1));
    }
    else {
      $dec = json_decode($response,true);
      if(isset($dec['signals'])) {
        $data = array();
        /* Simulation
          $dec['signals'][0]['values'][16]['hvalue'] = 2;
          $dec['signals'][0]['values'][17]['hvalue'] = 3;
          $dec['signals'][0]['dvalue'] = 3;
          $dec['signals'][0]['message'] = "Coupures d'électricité programmées";
         */
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
            $this->checkAndUpdateCmd("dayValueD$day", -1);
            $this->checkAndUpdateCmd("messageD$day", "Données RTE non disponibles.");
            $this->checkAndUpdateCmd("dataHourD$day", substr(str_repeat('-1,',24),0,-1));
            if($demo == 0 && $day != 3) log::add(__CLASS__, 'debug', "  Data for day $day not set");
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

    // remplacement de strftime pour des formats simples $format est le meme que strftime
  public static function myStrftime($format,$timestamp=null) {
    if($timestamp === null || trim($timestamp) == '') $timestamp = time();
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

  public static function getTempoPricesJson($log=0) {
    $expDate = trim(config::byKey('tempoExpirationDate', __CLASS__, ''));
    $HCJB = trim(config::byKey('HCJB', __CLASS__, 0));
    if(!is_numeric($HCJB)) $HCJB = '"'.$HCJB .'"';
    $HPJB = trim(config::byKey('HPJB', __CLASS__, 0));
    if(!is_numeric($HPJB)) $HPJB = '"'.$HPJB .'"';
    $HCJW = trim(config::byKey('HCJW', __CLASS__, 0));
    if(!is_numeric($HCJW)) $HCJW = '"'.$HCJW .'"';
    $HPJW = trim(config::byKey('HPJW', __CLASS__, 0));
    if(!is_numeric($HPJW)) $HPJW = '"'.$HPJW .'"';
    $HCJR = trim(config::byKey('HCJR', __CLASS__, 0));
    if(!is_numeric($HCJR)) $HCJR = '"'.$HCJR .'"';
    $HPJR = trim(config::byKey('HPJR', __CLASS__, 0));
    if(!is_numeric($HPJR)) $HPJR = '"'.$HPJR .'"';
    if($expDate == '') {
      if($log) log::add(__CLASS__,'warning','Expiration date of Tempo prices not defined');
      return('{"tempoExpirationDate":"UNDEFINED","HCJB":' .$HCJB .',"HPJB":' .$HPJB .',"HCJW":' .$HCJW .',"HPJW":' .$HPJW .',"HCJR":' .$HCJR .',"HPJR":' .$HPJR .'}');
    }
    $expDateTS = strtotime($expDate ."00:00:00");
    if($expDateTS < time()) {
      if($log) log::add(__CLASS__,'warning','Tempo prices are out of date');
      return('{"tempoExpirationDate":"OUTOFDATE","HCJB":' .$HCJB .',"HPJB":' .$HPJB .',"HCJW":' .$HCJW .',"HPJW":' .$HPJW .',"HCJR":' .$HCJR .',"HPJR":' .$HPJR .'}');
    }
    return('{"tempoExpirationDate":"' .$expDate .'","HCJB":' .$HCJB .',"HPJB":' .$HPJB .',"HCJW":' .$HCJW .',"HPJW":' .$HPJW .',"HCJR":' .$HCJR .',"HPJR":' .$HPJR .'}');
  }

  public function toHtml_ejpEDF(&$replace,$loglevel) {
    $t0 = -microtime(true);
    $color['NOT_EJP'] = '#509E2F';
    $color['OUT_OF_PERIOD'] = '#005BBB';
    $color['EJP'] = '#F34B32';
    $color['UNDEFINED'] = self::$_colTempo['UNDEFINED'];
    $color['ERROR'] =  self::$_colTempo['ERROR'];
    while ($col = current($color)) {
      $key = key($color);
      $replace["#color-$key#"] = $col;
      next($color);
    }
      // Recup de quelques valeurs de commande
    $cmd = $this->getCmd(null,'today');
    $today = (is_object($cmd))? $cmd->execCmd() : 'OUT_OF_PERIOD';
    $cmd = $this->getCmd(null,'tomorrow');
    $tomorrow = (is_object($cmd))? $cmd->execCmd() : 'OUT_OF_PERIOD';
    if($today == 'OUT_OF_PERIOD' && $tomorrow == 'OUT_OF_PERIOD') {
      $replace['#inEjpPeriod#'] = 'none'; $replace['#outOfEjpPeriod#'] = 'block';
    }
    else {
      $replace['#inEjpPeriod#'] = 'block'; $replace['#outOfEjpPeriod#'] = 'none';
    }
    $replace['#datenow#'] = self::myStrftime('%A %e %B');
    $replace['#legendEjp#'] = '<span><i class="fa fa-circle fa-lg" style="color:' .$color['EJP'] .'"></i>EJP </span>';
    $valLeg = array();
    $valLeg['EJP'] = $valLeg['NOT_EJP'] = $valLeg['OUT_OF_PERIOD'] = $valLeg['UNDEFINED'] = $valLeg['ERROR'] = 0;
    foreach ($this->getCmd('info') as $cmd) {
      $val = $cmd->execCmd(null);
      $cmdLogicalId = $cmd->getLogicalId();
      if($cmdLogicalId == 'today') {
        $replace['#colorEjpToday#'] = $color[$val];
        $valLeg[$val] += 1;
      }
      else if($cmdLogicalId == 'tomorrow') {
        $replace['#colorEjpTomorrow#'] = $color[$val];
        $valLeg[$val] += 1;
      }
      $replace['#' . $cmd->getLogicalId() . '#'] = $val;
    }
    $lastcallEjpTS = config::byKey('lastcall-ejpEdf', __CLASS__, 0);
    $replace['#dataActuEjp#'] = 'Données EDF du : '.date('d/m/Y H:i:s',$lastcallEjpTS);
    if($lastcallEjpTS == 0) $replace['#dataActuEjp#'] = 'Données EDF. Date inconnue';
    else $replace['#dataActuEjp#'] = 'Données EDF du : '.date('d/m/Y H:i:s',$lastcallEjpTS);
    if($loglevel == 'debug') {
      $replace['#dataActuEjp#'] .= '. Affichage: '.date('H:i:s');
      $replace['#dataActuEjp#'] .= ' en '.round($t0+microtime(true),3).'s';
    }
    if($valLeg['NOT_EJP']) $replace['#legendEjp#'] .= '<span><i class="fa fa-circle fa-lg" style="color:' .$color['NOT_EJP'] .'"></i>Non EJP </span>';
    if($valLeg['OUT_OF_PERIOD']) $replace['#legendEjp#'] .= '<span><i class="fa fa-circle fa-lg" style="color:' .$color['OUT_OF_PERIOD'] .'"></i>Période EJP terminée </span>';
    if($valLeg['UNDEFINED']) $replace['#legendEjp#'] .= '<span><i class="fa fa-circle fa-lg" style="color:' .$color['UNDEFINED'] .'"></i>Non défini </span>';
    if($valLeg['ERROR']) $replace['#legendEjp#'] .= '<span><i class="fa fa-circle fa-lg" style="color:' .$color['ERROR'] .'"></i>Erreur récupération données </span>';
  }

  public function toHtml_ecowattRTE(&$replace,$loglevel) {
    $t0 = -microtime(true);
    $color[-1] = '#95a5a6'; $titleEco[-1] = "Inconnu"; // gris
    $color[0] = '#00654A'; $titleEco[0] = "<br/>Production décarbonée"; // vert decarbon
    $color[1] = '#02F0C6'; $titleEco[1] = ""; // vert
    $color[2] = '#f2790F'; $titleEco[2] = ""; // orange
    $color[3] = '#e63946'; $titleEco[3] = ""; // rouge
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
      if($cmdLogicalId == 'valueNow') {
        $valueNow = $cmd->execCmd();
        $replace['#curHourLevel#'] = $valueNow;
        /* la punaise de couleur
          $replace['#valueNow#'] =
            '<i class="fa fa-circle fa-lg" style="color: '.$color[$valueNow] .'"></i>';
         */
        // La carte de France
        $svg = file_get_contents(__DIR__ ."/../template/images/franceRegions.svg");
        $svg = str_replace('#fbfaf9',$color[$valueNow],$svg);
        if($titleEco[$valueNow] == "") $replace['#valueNow#'] = $svg;
        else $replace['#valueNow#'] = "<span title=\"" .$titleEco[$valueNow] ."\">$svg </span>";
        if(!$valueNow) $replace['#curAlertColor#'] = $color[0];
        else $replace['#curAlertColor#'] = $color[$valueNow];
      }
      else if(substr($cmdLogicalId,0,13) == 'dayTimestampD') {
        $idx = substr($cmdLogicalId,13);
        $replace["#date$idx#"] = self::myStrftime('%A %e %B',$cmd->execCmd());
        $replace["#date${idx}dm#"] = self::myStrftime('%e %B',$cmd->execCmd());
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
          $title = $i ."h-" .($i+1) ."h" .$titleEco[$data];
          $tab .= '<td title="' .$title .'" width=4% style="font-size:8px!important;background-color:' .$color[$data] .';';
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
          if($numCmdsHour == 0) $numCmdsHour = 1;
          $replace['#numCmdsHour#'] = $numCmdsHour;
          $i = 0;
          $w = round(100/$numCmdsHour,2);
          foreach($datas as $data) {
            if($i >= $numCmdsHour) break;
            $tab .= '<td width='.$w.'% title="' .self::myStrftime('%A %e %B %kh-',$data['TS']) .date('G',$data['TS']+3600) .'h' .$titleEco[$data['hValue']] .'" style="background-color:' .$color[$data['hValue']] .'; font-size:8px!important;';
            if(date('G',$data['TS']) % 2 && $i != $numCmdsHour-1) $tab .= 'border-right: 1px solid #000;';
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
    }
    if(!$datenowTS) {
      $replace['#nextAlert#'] = '';
    }
    else if(!$nextAlertTS) {
      $replace['#nextAlert#'] = 'Pas d\'alerte Ecowatt prévue.';
    }
    else {
      if($valueNow == 0 || $valueNow == 1) { // Pas d'alerte en cours
        $replace['#nextAlert#'] = 'Prochaine alerte:  <i class="fa fa-circle fa-lg" style="color: '.$color[$nextAlertValue] .'"></i> ' .lcfirst(self::myStrftime('%a. %e %b %kh',$nextAlertTS)) .'<a href="https://coupures-temporaires.enedis.fr/verification_coupure_adresse.html" target="blank" title="+ Infos Enedis"> <i class="fas fa-info-circle fa-lg" style="color: '.$color[$nextAlertValue] .'"></i></a>';
      }
      else {
        $replace['#nextAlert#'] = 'Fin de l\'alerte en cours ' .lcfirst(self::myStrftime('%a. %e %b à %kh',$nextAlertTS)) .' <a href="https://coupures-temporaires.enedis.fr/verification_coupure_adresse.html" target="blank" title="+ Infos Enedis"><i class="fas fa-info-circle fa-lg" style="color: '.$color[$valueNow] .'"></i></a>';
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
      if($loglevel == 'debug') {
        $replace['#dataActuEcowatt#'] .= '. Affichage: '.date('H:i:s');
        $replace['#dataActuEcowatt#'] .= ' en '.round($t0+microtime(true),3).'s';
      }
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
  }

  public function toHtml_tempoEDF(&$replace,$loglevel,$templateFile) {
    $t0 = -microtime(true);
    $color['BLUE'] = self::$_colTempo['HPJB']; $title['BLUE'] = 'Jour bleu';
    $color['WHITE'] = self::$_colTempo['HPJW']; $title['WHITE'] = 'Jour blanc';
    $color['RED'] = self::$_colTempo['HPJR']; $title['RED'] = 'Jour rouge';
    $color['UNDEFINED'] = self::$_colTempo['UNDEFINED']; $title['UNDEFINED'] = 'Couleur non définie';
    $color['ERROR'] = self::$_colTempo['ERROR']; $title['ERROR'] = 'Erreur';
    while ($col = current($color)) {
      $key = key($color);
      $replace["#color$key#"] = $col;
      next($color);
    }
    foreach ($this->getCmd('info') as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      $val = $cmd->execCmd();
      if($cmdLogicalId == 'today') {
        $replace['#colorToday#'] = $color[$val];
        $replace['#titleToday#'] = $title[$val];
      }
      else if($cmdLogicalId == 'tomorrow') {
        $replace['#colorTomorrow#'] = $color[$val];
        $replace['#titleTomorrow#'] = $title[$val];
      }
      else if($cmdLogicalId == 'todayTS') {
        $ts = $cmd->execCmd();
        $replace['#todayDate#'] = self::myStrftime('%A %e %B',$val);
        if(date('m',$val)<9) { // Avant 1er septembre
          $replace['#endSeason#'] = date('Y');
          $replace['#startSeason#'] = $replace['#endSeason#']-1;
        }
        else {
          $replace['#startSeason#'] = date('Y');
          $replace['#endSeason#'] = $replace['#startSeason#']+1;
        }
      }
      else if($cmdLogicalId == 'tomorrowTS') {
        $replace['#tomorrowDate#'] = self::myStrftime('%A %e %B',$val);
      }
      else $replace['#' .$cmdLogicalId .'#'] = $cmd->execCmd();
    }
    $lastcallTempoTS = config::byKey('lastcall-tempoEDF', __CLASS__, 0);
    $replace['#dataActuTempo#'] = 'Dernière requête EDF le '.date('j/m/Y H:i:s',$lastcallTempoTS);
    if($loglevel == 'debug') {
      $replace['#dataActuTempo#'] .= '. Affichage: '.date('H:i:s');
      $replace['#dataActuTempo#'] .= ' en '.round($t0+microtime(true),3).'s';
      $replace['#dataActuTempo#'] .= " Template : " .$templateFile;
    }
  }

  public function toHtml_tempoRTE(&$replace,$loglevel,$templateFile) {
    $t0 = -microtime(true);
    $json = self::getTempoPricesJson(0);
    $price=json_decode($json,true);
    if($this->getConfiguration('displayPrices',1) == 0) {
      $priceHC['BLUE'] = ''; $priceHP['BLUE'] = '';
      $priceHC['WHITE'] = ''; $priceHP['WHITE'] = '';
      $priceHC['RED'] = ''; $priceHP['RED'] = '';
    }
    else {
      $priceHC['BLUE'] = $price['HCJB'] .'€'; $priceHP['BLUE'] = $price['HPJB'] .'€';
      $priceHC['WHITE'] = $price['HCJW'] .'€'; $priceHP['WHITE'] = $price['HPJW'] .'€';
      $priceHC['RED'] = $price['HCJR'] .'€'; $priceHP['RED'] = $price['HPJR'] .'€';
    }
    $color['BLUE'] = self::$_colTempo['HPJB']; $title['BLUE'] = 'Jour bleu'; $txtColor['BLUE'] = 'white';
    $borderColor['BLUE'] = $color['BLUE']; $colorHC['BLUE'] = self::$_colTempo['HCJB'];
    $txtHC['BLUE'] = 'Jour bleu HC'; $txtHP['BLUE'] = 'Jour bleu HP de 6h à 22h';
    $backgroundUndef['BLUE'] = '';

    $color['WHITE'] = self::$_colTempo['HPJW']; $title['WHITE'] = 'Jour blanc'; $txtColor['WHITE'] = 'black';
    $borderColor['WHITE'] = 'black'; $colorHC['WHITE'] = self::$_colTempo['HCJW'];
    $txtHC['WHITE'] = 'Jour blanc HC'; $txtHP['WHITE'] = 'Jour blanc HP de 6h à 22h';
    $backgroundUndef['WHITE'] = '';
    
    $color['RED'] = self::$_colTempo['HPJR']; $title['RED'] = 'Jour rouge'; $txtColor['RED'] = 'white';
    $borderColor['RED'] = $color['RED']; $colorHC['RED'] = self::$_colTempo['HCJR'];
    $txtHC['RED'] = 'Jour rouge HC'; $txtHP['RED'] = 'Jour rouge HP de 6h à 22h';
    $backgroundUndef['RED'] = '';
    
    $color['UNDEFINED'] = self::$_colTempo['UNDEFINED']; $title['UNDEFINED'] = 'Couleur non définie'; $txtColor['UNDEFINED'] = 'white';
    $borderColor['UNDEFINED'] = $color['UNDEFINED']; $colorHC['UNDEFINED'] = self::$_colTempo['UNDEFINED'];
    $txtHC['UNDEFINED'] = 'Tempo non défini HC'; $txtHP['UNDEFINED'] = 'Tempo non défini HP';
    $priceHC['UNDEFINED'] = ''; $priceHP['UNDEFINED'] = '';
    $nbred = 1;
    $cmd = $this->getCmd(null,'red-remainingDays');
    if(is_object($cmd)) $nbred = $cmd->execCmd();
    $nbwhite = 1;
    $cmd = $this->getCmd(null,'white-remainingDays');
    if(is_object($cmd)) $nbwhite = $cmd->execCmd();
// $nbred=0; $nbwhite=0;
    if(date('l',strtotime('tomorrow midnight')) == "Sunday") // always blue
      $backgroundUndef['UNDEFINED'] = 'background-image:radial-gradient('.self::$_colTempo['HPJB'] .',' .self::$_colTempo['UNDEFINED'] .')';
    else if($nbred > 0)
      $backgroundUndef['UNDEFINED'] = 'background-image:radial-gradient('.self::$_colTempo['HPJB'] .',' .self::$_colTempo['HPJW'] .',' .self::$_colTempo['HPJR'] .')';
    else if($nbwhite > 0)
      $backgroundUndef['UNDEFINED'] = 'background-image:radial-gradient('.self::$_colTempo['HPJB'] .',' .self::$_colTempo['HPJW'] .')';
    else
      $backgroundUndef['UNDEFINED'] = 'background-image:radial-gradient('.self::$_colTempo['HPJB'] .',' .self::$_colTempo['UNDEFINED'] .')';
    
    $color['ERROR'] = self::$_colTempo['ERROR']; $title['ERROR'] = 'Erreur'; $txtColor['ERROR'] = 'white';
    $borderColor['ERROR'] = $color['ERROR']; $colorHC['ERROR'] = self::$_colTempo['ERROR'];
    $txtHC['ERROR'] = 'Tempo ERREUR HC'; $txtHP['ERROR'] = 'Tempo ERREUR HP';
    $priceHC['ERROR'] = ''; $priceHP['ERROR'] = '';
    $backgroundUndef['ERROR'] = '';

    $val = '';
    $cmd = $this->getCmd(null,'yesterday');
    if(is_object($cmd)) $val = $cmd->execCmd();
    if($val == '') {
      $arr = self::getTempoColor('yesterday midnight');
      $val = $arr['value'];
    }
    $replace['#colorYesterdayHC#'] = 'background-color:' .$colorHC[$val];
    $replace['#txtColorYesterdayHC#'] = 'color:' .$txtColor[$val];
    $replace['#borderColorYesterday#'] = $borderColor[$val];
    $replace['#txtYesterdayHC#'] = $txtHC[$val];
    $replace['#priceYesterdayHC#'] = $priceHC[$val];

    while ($col = current($color)) {
      $key = key($color);
      $replace["#color$key#"] = $col;
      next($color);
    }
    foreach ($this->getCmd('info') as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      $val = $cmd->execCmd();
      if($cmdLogicalId == 'today') {
        $replace['#colorToday#'] = $color[$val];
        $replace['#txtColorToday#'] = $txtColor[$val];
        $replace['#colorTodayHP#'] = 'background-color:' .$color[$val];
        $replace['#txtColorTodayHP#'] = 'color:' .$txtColor[$val];
        $replace['#colorTodayHC#'] = $colorHC[$val];
        $replace['#txtColorTodayHC#'] = $txtColor[$val];
        $replace['#titleToday#'] = $title[$val];
        $replace['#borderColorToday#'] = $borderColor[$val];
        $replace['#txtTodayHC#'] = $txtHC[$val];
        $replace['#txtTodayHP#'] = $txtHP[$val];
        $replace['#priceTodayHC#'] = $priceHC[$val];
        $replace['#priceTodayHP#'] = $priceHP[$val];
      }
      else if($cmdLogicalId == 'tomorrow') {
        $replace['#colorTomorrow#'] = $color[$val];
        $replace['#colorTomorrowHC#'] = $colorHC[$val];
        $replace['#titleTomorrow#'] = $title[$val];
        $replace['#txtColorTomorrow#'] = $txtColor[$val];
        $replace['#borderColorTomorrow#'] = $borderColor[$val];
        $replace['#txtTomorrowHC#'] = $txtHC[$val];
        $replace['#txtTomorrowHP#'] = $txtHP[$val];
        $replace['#priceTomorrowHC#'] = $priceHC[$val];
        $replace['#priceTomorrowHP#'] = $priceHP[$val];
        $replace['#backgroundUndef#'] = $backgroundUndef[$val];
      }
      else if($cmdLogicalId == 'todayTS') {
        $val = time();
        $replace['#todayDate#'] = self::myStrftime('%A %e %B',$val);
        if(date('m',$val)<9) { // Avant 1er septembre
          $replace['#endSeason#'] = date('Y');
          $replace['#startSeason#'] = $replace['#endSeason#']-1;
        }
        else {
          $replace['#startSeason#'] = date('Y');
          $replace['#endSeason#'] = $replace['#startSeason#']+1;
        }
      }
      else if($cmdLogicalId == 'tomorrowTS') {
        $replace['#tomorrowDate#'] = self::myStrftime('%A %e %B',$val);
      }
      else if($cmdLogicalId == 'now') {
        $hphc = substr($val,0,2);
        $jour = substr($val,2);
        if($this->getConfiguration('displayPrices',1) == 0) $replace['#nowPrice#'] = "";
        else if($price['tempoExpirationDate'] == 'UNDEFINED') $replace['#nowPrice#'] = "Date de fin de validité des prix Tempo non définie";
        else if($price['tempoExpirationDate'] == 'OUTOFDATE') $replace['#nowPrice#'] = "Date de fin de validité des prix Tempo dépassée.";
        else $replace['#nowPrice#'] = $price[$val] ."€/kWh";
        if($hphc == 'HP') 
          $replace['#nowHelp#'] = "HP de 6h à 22h";
        else if($hphc == 'HC') 
          $replace['#nowHelp#'] = "HC de 22h à 6h le lendemain";
        else
          $replace['#nowHelp#'] = "HP 6h/22h HC 22h/6h le lendemain";
        if($jour == 'JW') {
          $replace['#now#'] = "TEMPO BLANC $hphc";
          $replace['#nowColor#'] = 'rgb(40,40,40)';
          $replace['#nowBackgroundColor#'] = $color['WHITE'];
          $replace['#nowForegroundColor#'] = 'var(--txt-color)';
        }
        else if($jour == 'JR') {
          $replace['#now#'] = "TEMPO ROUGE $hphc";
          $replace['#nowBackgroundColor#'] = $color['RED'];
          $replace['#nowForegroundColor#'] = $color['RED'];
          $replace['#nowColor#'] = 'white';
        }
        else if($jour == 'JB') {
          $replace['#now#'] = "TEMPO BLEU $hphc";
          $replace['#nowBackgroundColor#'] = $color['BLUE'];
          $replace['#nowForegroundColor#'] = $color['BLUE'];
          $replace['#nowColor#'] = 'white';
        }
        else {
          $replace['#now#'] = "TEMPO NON DEFINI ($jour)";
          $replace['#nowBackgroundColor#'] = $color['UNDEFINED'];
          $replace['#nowForegroundColor#'] = $color['UNDEFINED'];
          $replace['#nowColor#'] = 'white';
        }
      }
      else $replace['#' .$cmdLogicalId .'#'] = $val;
    }
    $hr = date('G');
    for($i=0;$i<24;$i++) {
      if($i==$hr) $replace['#hr'.$i .'#'] ='<i class="fas fa-arrow-up"></i>';
      // else if($i==0) $replace['#hr'.$i .'#'] ='0h';
      else if($i==6) $replace['#hr'.$i .'#'] ='6h';
      else if($i==22) $replace['#hr'.$i .'#'] ='22h';
      else if($i == ($hr + 1)) $replace['#hr'.$i .'#'] ="${i}h";
      else $replace['#hr'.$i .'#'] ='&nbsp;';
    }
    $lastcallTempoTS = config::byKey("lastcall-tempoRTE", __CLASS__, 0);
    $replace['#dataActuTempo#'] = 'Dernière requête RTE le '.date('j/m/Y H:i:s',$lastcallTempoTS);
    if($loglevel == 'debug') {
      $replace['#dataActuTempo#'] .= '.<br/>Affichage: '.date('H:i:s');
      $replace['#dataActuTempo#'] .= ' en '.round($t0+microtime(true),3).'s';
      $replace['#dataActuTempo#'] .= " Template : " .$templateFile;
    }
  }

  public function toHtml_consumptionRTE(&$replace,$loglevel) {
    $t0 = -microtime(true);
    // $cmds = array('ID','REALISED','D-1','D-2','CORRECTED');
    $minVal = 1e6; $maxVal = 0;
    $minValReal=1e6; $maxValReal=0; $dateMinReal=0; $dateMaxReal=0;
    $minValFcast0=1e6; $maxValFcast0=0; $dateMinFcast0=0; $dateMaxFcast0=0;
    $minValFcast1=1e6; $maxValFcast1=0; $dateMinFcast1=0; $dateMaxFcast1=0;
    $minValFcast2=1e6; $maxValFcast2=0; $dateMinFcast2=0; $dateMaxFcast2=0;
    $startTS = strtotime('-' .abs($this->getConfiguration('numConsumptionDays',6)) .' days midnight'); $startTime1 = date('Y-m-d H:i:s',$startTS);
    // $startTS = strtotime('-55 minutes'); $startTime2 = date('Y-m-d H:i:s',$startTS);
    $startTime2 = $startTime1;
    $endTS = strtotime('+3 days midnight'); $endTime = date('Y-m-d H:i:s',$endTS);
    $replace['#dataREALISED#'] = '';
    $replace['#dataID#'] = '';
    $replace['#dataD-1#'] = '';
    $replace['#dataD-2#'] = '';
    $replace['#dataTempo#'] = '';
    $ts0 = strtotime('today midnight'); $ts1 = strtotime('tomorrow midnight');
    foreach ($this->getCmd('info') as $cmd) {
      $cmdLogicalId = $cmd->getLogicalId();
      // if($cmdLogicalId == 'D-2') continue;
      if($cmdLogicalId == 'REALISED') {
        $replace['#consumption#'] = $cmd->execCmd() .' ' .$cmd->getUnite();
        $startHist = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' -' . config::byKey('historyCalculTendance') . ' hour'));
        $tendance = $cmd->getTendance($startHist, date('Y-m-d H:i:s'));
        if ($tendance > config::byKey('historyCalculTendanceThresholddMax')) {
          $replace['#tendance#'] = '+';
        } else if ($tendance < config::byKey('historyCalculTendanceThresholddMin')) {
          $replace['#tendance#'] = '-';
        } else {
          $replace['#tendance#'] = '=';
        }
        $collectDateTS = strtotime($cmd->getCollectDate());
        $replace['#dateNow#'] = date('d-m à H\hi',$collectDateTS);
        $startTime = $startTime1;
      }
      else $startTime = $startTime2;
      $histories = $cmd->getHistory($startTime,$endTime);
      uasort($histories,function($a,$b) { return strcmp($a->getDatetime(), $b->getDatetime()); });
      $nb = count($histories);
      // message::add(__CLASS__,"NB: $nb Deb: $startTime End: $endTime");
      if($nb) {
        foreach($histories as $histo) {
          $t = strtotime($histo->getDatetime());
          $val = $histo->getValue();
          if($cmdLogicalId == 'REALISED') {
            if($t >= $ts0 && $t < $ts1) {
              if($val < $minValReal) {
                $minValReal = $val;
                $dateMinReal = $t;
              }
              if($val > $maxValReal) {
                $maxValReal = $val;
                $dateMaxReal = $t;
              }
            }
          }
          else if($cmdLogicalId == 'ID') {
            if($t >= $ts0 && $t < $ts1) {
              if($val < $minValFcast0) {
                $minValFcast0 = $val;
                $dateMinFcast0 = $t;
              }
              if($val > $maxValFcast0) {
                $maxValFcast0 = $val;
                $dateMaxFcast0 = $t;
              }
            }
          }
          else if($cmdLogicalId == 'D-1') {
            if($t >= $ts0 && $t < $ts1) {
              if($val < $minValFcast1) {
                $minValFcast1 = $val;
                $dateMinFcast1 = $t;
              }
              if($val > $maxValFcast1) {
                $maxValFcast1 = $val;
                $dateMaxFcast1 = $t;
              }
            }
          }
          else if($cmdLogicalId == 'D-2') {
            if($t >= $ts0 && $t < $ts1) {
              if($val < $minValFcast2) {
                $minValFcast2 = $val;
                $dateMinFcast2 = $t;
              }
              if($val > $maxValFcast2) {
                $maxValFcast2 = $val;
                $dateMaxFcast2 = $t;
              }
            }
          }
          $minVal = min($val,$minVal);
          $maxVal = max($val,$maxVal);
          $replace["#data$cmdLogicalId#"] .= '['.($t*1000) .',' .$val .'],';
        }
        // message::add(__CLASS__, "$cmdLogicalId Min: $minVal Max: $maxVal");
      }
    }

    if($minValReal > $maxValReal) {
      $replace['#TxtReal#'] = "";
    }
    else if($minValReal == $maxValReal) {
      $replace['#TxtReal#'] = "Réalisé ce jour: Mini = maxi à ".date('H\hi',$dateMinReal) .": $minValReal MW.";
    }
    else {
      $replace['#TxtReal#'] = "Réalisé ce jour: Mini à ".date('H\hi',$dateMinReal) .": $minValReal MW. Maxi à ".date('H\hi',$dateMaxReal) .": $maxValReal MW<br>";
    }
    if($minValFcast0 > $maxValFcast0) {
      $replace['#TxtFcast0#'] = "";
    }
    else {
    $replace['#dateMaxFcast1#'] = date('H\hi',$dateMaxFcast1);
      $replace['#TxtFcast0#'] = "Prévision du jour: Mini à ".date('H\hi',$dateMinFcast0) .": " .round($minValFcast0) ." MW. Maxi à ".date('H\hi',$dateMaxFcast0) .": " .round($maxValFcast0) ." MW<br>";
    }
    if($minValFcast1 > $maxValFcast1) {
      $replace['#TxtFcast1#'] = "";
    }
    else {
    $replace['#dateMaxFcast1#'] = date('H\hi',$dateMaxFcast1);
      $replace['#TxtFcast1#'] = "Prévision veille: Mini à ".date('H\hi',$dateMinFcast1) .": ".round($minValFcast1) ." MW. Maxi à ".date('H\hi',$dateMaxFcast1) .": " .round($maxValFcast1) ." MW<br>";
    }

    if($minValFcast2 > $maxValFcast2) {
      $replace['#TxtFcast2#'] = "";
    }
    else {
      $replace['#TxtFcast2#'] = "Prévision à 2 jours: Mini à ".date('H\hi',$dateMinFcast2) .": ".round($minValFcast2) ." MW. Maxi à ".date('H\hi',$dateMaxFcast2) .": " .round($maxValFcast2) ." MW<br>";
    }
    $replace['#minVal#'] = $minVal;
    $replace['#maxVal#'] = $maxVal;
    $replace['#softMinVal#'] = $minVal-700;
    $replace['#softMaxVal#'] = $maxVal;
    // message::add(__CLASS__, "Min:$minVal Max:$maxVal");
    $replace['#dataActuConsumption#'] = ''; // 'Voir <a href="https://www.rte-france.com/eco2mix" target="blank">Rte Eco2Mix</a>';

    $color['BLUE'] = self::$_colTempo['HPJB']; $title['BLUE'] = 'Jour bleu';
    $color['WHITE'] = self::$_colTempo['HPJW']; $title['WHITE'] = 'Jour blanc';
    $color['RED'] = self::$_colTempo['HPJR']; $title['RED'] = 'Jour rouge';
    $color['UNDEFINED'] = self::$_colTempo['UNDEFINED']; $title['UNDEFINED'] = 'Couleur non définie';
    $color['ERROR'] = self::$_colTempo['ERROR']; $title['ERROR'] = 'Erreur';
    $dayStart = abs($this->getConfiguration('numConsumptionDays',6)) * -1;
    for($i = $dayStart; $i<3; $i++) {
      $arr = self::getTempoColor($i .' days midnight');
      $t =strtotime($arr['start_date']) +43200;
      $val = $arr['value'];
      $replace['#dataTempo#'] .= '{ x:'.($t*1000) .', y:' .$minVal .', name: "'.$title[$val].'", color: "'.$color[$val] .'" },';
    }
  }
    
  public function toHtml($_version = 'dashboard') {
    $loglevel = log::convertLogLevel(log::getLogLevel(__CLASS__));
    $templateFile = '';
    $replace = $this->preToHtml($_version, array('#background-color#' => '#bdc3c7'));
    if (!is_array($replace)) {
      return $replace;
    }
    $version = jeedom::versionAlias($_version);
    $datasource = $this->getConfiguration('datasource');
    $replace['#dataActuEcowatt#'] = '';
    if ($datasource == 'ejpEDF') {
      if($this->getConfiguration('usePluginTemplateEjpEdf','1') == '0')
        return parent::toHtml($_version);
      $this->toHtml_ejpEDF($replace,$loglevel);
      $template = 'edf_ejp';
    }
    else if ($datasource == 'ecowattRTE') {
      $templateF = $this->getConfiguration('templateEcowatt','plugin');
      if($templateF == 'none') return parent::toHtml($_version);
      else if($templateF == 'plugin') $templateFile = 'rte_ecowatt';
      else if($templateF == 'custom') $templateFile = 'custom.rte_ecowatt';
      else $templateFile = substr($templateF,0,-5);
      $this->toHtml_ecowattRTE($replace,$loglevel);
      $template = 'rte_ecowatt';
    }
    else if ($datasource == 'tempoEDF') {
      $templateF = $this->getConfiguration('templateTempoEdf','plugin');
      if($templateF == 'none') return parent::toHtml($_version);
      else if($templateF == 'plugin') $templateFile = 'edf_tempo';
      else if($templateF == 'custom') $templateFile = 'custom.edf_tempo';
      else $templateFile = substr($templateF,0,-5);
      $this->toHtml_tempoEDF($replace,$loglevel,$templateFile);
      $template = 'edf_tempo';
    }
    else if ($datasource == 'tempoRTE') {
      $templateF = $this->getConfiguration('templateTempo','plugin');
      if($templateF == 'none') return parent::toHtml($_version);
      else if($templateF == 'plugin') $templateFile = 'rte_tempo';
      else if($templateF == 'custom') $templateFile = 'custom.rte_tempo';
      else $templateFile = substr($templateF,0,-5);
      $this->toHtml_tempoRTE($replace,$loglevel,$templateFile);
      $template = 'rte_tempo';
    }
    else if ($datasource == 'consumptionRTE') {
      if($this->getConfiguration('usePluginTemplateConsumption','1') == '0')
        return parent::toHtml($_version);
      $this->toHtml_consumptionRTE($replace,$loglevel);
      $template = 'rte_consumption';
    }
    else {
      log::add(__CLASS__, 'warning', __FUNCTION__ ." Unknown type: $datasource");
      return parent::toHtml($_version);
    }

    if($templateFile == '') {
      if (file_exists( __DIR__ ."/../template/$_version/custom.${template}.html")) {
        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, "custom." .$template, __CLASS__)));
      }
      else {
        return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, $template, __CLASS__)));
      }
    }
    else {
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

  function getTempoColor($startDate) {
      // normalize startDate ( minuit)
    $stTs = strtotime($startDate);
    $startTs = mktime(0,0,0,date('m',$stTs),date('d',$stTs),date('Y',$stTs));
    $start = date('c',$startTs);
    $dataTempo= __DIR__ ."/../../data/dataTempo.json";
    $data = @file_get_contents($dataTempo);
    if ($data !== false) {
      $decData = json_decode($data,true);
      if($startTs > strtotime($decData["latestOKdatetime"]))
        return(array('start_date'=>$start,'value'=>'UNDEFINED'));
      foreach( $decData["TempoDays"] as $day) {
        if(strtotime($day['start_date']) == $startTs) return($day);
      }
      return(array('start_date'=>$start,'value'=>'BLUE'));
    }
    else
      return(array('start_date'=>$start,'value'=>'ERROR'));
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
