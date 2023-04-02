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
include_file('core', 'authentification', 'php');
if(!isConnect('admin')) {
  throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
  <fieldset>
    <div class="form-group">
      <label class="col-md-4 control-label">{{ID client RTE et ID secret en base 64 à récupérer}}
        <a target="blank" href="https://data.rte-france.com">ICI</a>
        <sup><i class="fas fa-question-circle tooltips" title="{{A copier sur le site RTE et à coller dans la configuration du plugin}}"></i></sup>
      </label>
      <div class="col-md-7">
        <input class="configKey form-control" data-l1key="IDclientSecretB64" />
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Tempo: Nombre de jours blancs par saison}}
      </label>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="totalTempoWhite" placeholder="43 par défaut"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Tempo: Nombre de jours rouges par saison}}
      </label>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="totalTempoRed" placeholder="22 par défaut"/>
      </div>
    </div>
  </fieldset>
</form>
