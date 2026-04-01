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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if(!isConnect('admin')) {
      throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();
 
      // remove data/dataTempo.json
    if(init('action') == 'removeDataTempoJson') {
      $file = realpath(__DIR__ . '/../../data/dataTempo.json');
      if(file_exists($file)) {
        if(@unlink($file)) ajax::success();
        else ajax::error();
      }
      else ajax::success();
    }

    if(init('action') == 'fetchTempoPrices') {
        $url = init('url');
        $puissance = init('puissance');

        if ($url == '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            ajax::error('URL invalide');
        }
        if (!in_array($puissance, ['6','9','12','15','18','30','36'])) {
            ajax::error('Puissance invalide');
        }

        $result = rteEcowatt::fetchEdf4TempoPrices($url, $puissance);
        if (isset($result["error"]))
          ajax::error($result["error"]);
        else if (isset($result["subscription"]))
                ajax::success([
                    'dateOfRates' => $result["dateOfRates"],
                    'subscription' => $result["subscription"],
                    'HCJB' => $result["HCJB"],
                    'HPJB' => $result["HPJB"],
                    'HCJW' => $result["HCJW"],
                    'HPJW' => $result["HPJW"],
                    'HCJR' => $result["HCJR"],
                    'HPJR' => $result["HPJR"],
                    'tempoExpirationDate' => $result["tempoExpirationDate"]
                ]);
        else
          ajax::error('Prix non trouvés pour cette puissance dans le PDF');
    }

    throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
    /*     * *********Catch exception*************** */
}
catch (Exception $e) {
  if(version_compare(jeedom::version(), '4.4', '>=')) {
      ajax::error(displayException($e), $e->getCode());
    } else {
      ajax::error(displayExeption($e), $e->getCode());
    }
}
