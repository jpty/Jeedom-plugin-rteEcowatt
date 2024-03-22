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

require_once __DIR__ . '/../../../core/php/core.inc.php';

// Fonction exécutée automatiquement après l'installation du plugin
function rteEcowatt_install() {
  rteEcowatt::setCronDataEcowatt(1);
}

// Fonction exécutée automatiquement après la mise à jour du plugin
function rteEcowatt_update() {
  rteEcowatt::setCronDataEcowatt(1);
  $nbEcow = 0;
  foreach (eqLogic::byType('rteEcowatt') as $eqLogic) {
    if($eqLogic->getConfiguration('datasource') == 'ecowattRTE') $nbEcow++;
    $eqLogic->save();
    log::add('rteEcowatt', 'debug', 'Mise à jour des commandes de l\'équipement '. $eqLogic->getHumanName() ." effectuée.");
  }
  if($nbEcow)
    message::add('rteEcowatt', "Vous utilisez $nbEcow équipement(s) Ecowatt (RTE). N'oubliez pas de vous abonner à l'API Ecowatt v5 sur le site de web de RTE.");
}

// Fonction exécutée automatiquement après la suppression du plugin
function rteEcowatt_remove() {
  rteEcowatt::setCronDataEcowatt(0);
}
