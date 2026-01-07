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

        $pdfContent = @file_get_contents($url);
        if ($pdfContent === false) {
            ajax::error('Impossible de télécharger le PDF');
        }

        $tempPdf = sys_get_temp_dir() . '/edf_tempo.pdf';
        file_put_contents($tempPdf, $pdfContent);

        $text = shell_exec("pdftotext -layout '$tempPdf' - 2>/dev/null");
        unlink($tempPdf);

        if (empty($text)) {
            ajax::error('Échec extraction texte (pdftotext indisponible ?)');
        }

        $lines = explode("\n", $text);
        $inTempo = false;
        $dateOfRates = '';
        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line));
            if (stripos($line, 'Applicable au') !== false) {
              $dateOfRates = $line;
            }

            if (stripos($line, 'Option Tempo') !== false) {
                $inTempo = true;
                continue;
            }

            if ($inTempo && preg_match('/^'.$puissance.'\s+([0-9]{1,3},[0-9]{2})\s+([0-9]{1,3},[0-9]{2})\s+([0-9]{1,3},[0-9]{2})\s+([0-9]{1,3},[0-9]{2})\s+([0-9]{1,3},[0-9]{2})\s+([0-9]{1,3},[0-9]{2})\s+([0-9]{1,3},[0-9]{2})/', $line, $m)) {
                $subscription = round((float)(str_replace(',', '.', $m[1])),2);
                $bleuHC = round((float)(str_replace(',', '.', $m[2])/100),4);
                $bleuHP = round((float)(str_replace(',', '.', $m[3])/100),4);
                $blancHC = round((float)(str_replace(',', '.', $m[4])/100),4);
                $blancHP = round((float)(str_replace(',', '.', $m[5])/100),4);
                $rougeHC = round((float)(str_replace(',', '.', $m[6])/100),4);
                $rougeHP = round((float)(str_replace(',', '.', $m[7])/100),4);

                ajax::success([
                    'dateOfRates' => $dateOfRates,
                    'subscription' => $subscription,
                    'bleuHC' => $bleuHC,
                    'bleuHP' => $bleuHP,
                    'blancHC' => $blancHC,
                    'blancHP' => $blancHP,
                    'rougeHC' => $rougeHC,
                    'rougeHP' => $rougeHP
                ]);
            }
        }
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
