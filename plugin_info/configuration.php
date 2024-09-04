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
    <div class="form-group">
      <label class="col-md-4 control-label">{{Tempo: Fin de validité des prix.}}
<sup><i class="fas fa-question-circle tooltips" title="{{Format: AAAA-MM-JJ}}"></i></sup>
      </label>
      <div class="col-md-2">
        <input class="configKey form-control" data-l1key="tempoExpirationDate" placeholder="{{Format: AAAA-MM-JJ}}"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Prix Tempo à récupérer}} <a target="blank" href="https://particulier.edf.fr/content/dam/2-Actifs/Documents/Offres/Grille_prix_Tarif_Bleu.pdf">ICI</a>{{ €/kWh Bleu HC}}</label>
      <div class="col-md-1">
        <input class="configKey form-control" data-l1key="HCJB"/>
      </div>
      <label class="col-md-1 control-label">{{Bleu HP}}</label>
      <div class="col-md-1">
        <input class="configKey form-control" data-l1key="HPJB"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Blanc HC}}</label>
      <div class="col-md-1">
        <input class="configKey form-control" data-l1key="HCJW"/>
      </div>
      <label class="col-md-1 control-label">{{Blanc HP}}</label>
      <div class="col-md-1">
        <input class="configKey form-control" data-l1key="HPJW"/>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Rouge HC}}</label>
      <div class="col-md-1">
        <input class="configKey form-control" data-l1key="HCJR"/>
      </div>
      <label class="col-md-1 control-label">{{Rouge HP}}</label>
      <div class="col-md-1">
        <input class="configKey form-control" data-l1key="HPJR"/>
      </div>
    </div>
    <div class="col-lg-6 col-sm-12">
        <legend><i class="fas fa-wrench"></i>{{Réparations}}</legend>
        <div class="form-group">
            <label class="col-sm-1 control-label">&nbsp;</label>
            <div class="col-sm-5">
                <a class="btn btn-danger" id="bt_removeDataTempoJson" style="width:100%;"><i class="fas fa-trash" style="display:none;"></i> <i class="fas fa-trash"></i> {{Supprimer le fichier d'historique Tempo}}</a>
            </div>
            <div class="col-sm-6"></div>
        </div>
    </div>
  </fieldset>
</form>
<script>
// Réparations
// Remove data/dataTempo.json
$('#bt_removeDataTempoJson').on('click', function () {
    bootbox.confirm('{{Êtes-vous sûr de vouloir supprimer le fichier des historiques Tempo:}}<br/><b>plugins/rteEcowatt/data/dataTempo.json</b>{{ ?}}', function(result) {
        if (!result) return;
        $.post({
          url: "plugins/rteEcowatt/core/ajax/rteEcowatt.ajax.php",
          data: {
            action: "removeDataTempoJson"
          },
          error: function (request, status, error) {
            handleAjaxError(request, status, error);
          },
          success: function(data) {
            if (data.state != 'ok') {
              $.fn.showAlert({message: 'Unable to remove dataTempo.json', level: 'danger'});
              return;
            }
            $.fn.showAlert({message: '{{Fichier supprimé.}}', level: 'success'});
          }
        });
    });
});
</script>
