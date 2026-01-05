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
      <label class="col-md-4 control-label">{{Lien vers Prix Tempo à récupérer : }}</label>
      <div class="col-md-4">
        <input class="configKey form-control" data-l1key="tempoPriceUrl" placeholder="https://particulier.edf.fr/content/dam/2-Actifs/Documents/Offres/Grille_prix_Tarif_Bleu.pdf"/>
      </div>
      <div class="col-sm-1">
        <select class="configKey form-control" data-l1key="tempoAbo">
          <option value="6" selected>6 KVA</option>
          <option value="9">9 KVA</option>
          <option value="12">12 KVA</option>
          <option value="15">15 KVA</option>
          <option value="18">18 KVA</option>
          <option value="30">30 KVA</option>
          <option value="36">36 KVA</option>
        </select>
      </div>
      <div class="col-sm-2">
        <a class="btn btn-success" id="bt_fetchTempoPrices"><i class="fas fa-download"></i> {{Récupérer les prix Tempo}}</a>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Bleu HC}}</label>
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
                <a class="btn btn-danger" id="bt_removeDataTempoJson" style="width:100%;"><i class="fas fa-trash"></i> {{Supprimer le fichier d'historique Tempo}}</a>
            </div>
            <div class="col-sm-6"></div>
        </div>
    </div>
  </fieldset>
</form>
<script>
// Réparations existant
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

// Nouveau bouton : Récupérer les prix Tempo depuis le PDF
$('#bt_fetchTempoPrices').on('click', function () {
    var urlInput = $('.configKey[data-l1key="tempoPriceUrl"]');
    var url = urlInput.val().trim();

    // Si le champ est vide, on utilise la valeur du placeholder
    if (url == '') {
        url = urlInput.attr('placeholder');
        if (url == '' || url === undefined) {
            $.fn.showAlert({message: '{{Aucune URL définie (ni saisie ni placeholder)}}', level: 'warning'});
            return;
        }
        // Optionnel : on peut même remplir automatiquement le champ pour l'utilisateur
        urlInput.val(url);
    }

    var puissance = $('.configKey[data-l1key="tempoAbo"]').val();

    $('#bt_fetchTempoPrices').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> {{Chargement...}}');

    $.ajax({
        type: "POST",
        url: "plugins/rteEcowatt/core/ajax/rteEcowatt.ajax.php",
        data: {
            action: "fetchTempoPrices",
            url: url,
            puissance: puissance
        },
        dataType: 'json',
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
            $('#bt_fetchTempoPrices').prop('disabled', false).html('<i class="fas fa-download"></i> {{Récupérer les prix Tempo}}');
        },
        success: function (data) {
            $('#bt_fetchTempoPrices').prop('disabled', false).html('<i class="fas fa-download"></i> {{Récupérer les prix Tempo}}');
            if (data.state != 'ok') {
                $.fn.showAlert({message: data.result, level: 'danger'});
                return;
            }
            // Mise à jour des champs (valeurs en centimes)
            $('.configKey[data-l1key="HCJB"]').val(data.result.bleuHC);
            $('.configKey[data-l1key="HPJB"]').val(data.result.bleuHP);
            $('.configKey[data-l1key="HCJW"]').val(data.result.blancHC);
            $('.configKey[data-l1key="HPJW"]').val(data.result.blancHP);
            $('.configKey[data-l1key="HCJR"]').val(data.result.rougeHC);
            $('.configKey[data-l1key="HPJR"]').val(data.result.rougeHP);

            $.fn.showAlert({message: '{{Prix Tempo mis à jour pour ' + puissance + ' kVA !}}', level: 'success'});
        }
    });
});
</script>
