<?php
if (!isConnect('admin')) {
  throw new Exception('{{401 - Accès non autorisé}}');
}
// Déclaration des variables obligatoires
$plugin = plugin::byId('rteEcowatt');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
  <!-- Page d'accueil du plugin -->
  <div class="col-xs-12 eqLogicThumbnailDisplay">
    <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
    <!-- Boutons de gestion du plugin -->
    <div class="eqLogicThumbnailContainer">
      <div class="cursor eqLogicAction logoPrimary" data-action="add">
        <i class="fas fa-plus-circle"></i>
        <br>
        <span>{{Ajouter}}</span>
      </div>
      <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
        <i class="fas fa-wrench"></i>
        <br>
        <span>{{Configuration}}</span>
      </div>
    </div>
    <legend><i class="fas fa-table"></i> {{Mes équipements RteEcowatt}}</legend>
    <?php
    if (count($eqLogics) == 0) {
      echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement RteEcowatt trouvé, cliquer sur "Ajouter" pour commencer}}</div>';
    } else {
      // Champ de recherche
      echo '<div class="input-group" style="margin:5px;">';
      echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
      echo '<div class="input-group-btn">';
      echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
      echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
      echo '</div>';
      echo '</div>';
      // Liste des équipements du plugin
      echo '<div class="eqLogicThumbnailContainer">';
      foreach ($eqLogics as $eqLogic) {
        $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
        echo '<div class="eqLogicDisplayCard cursor ' .$opacity .'" data-eqLogic_id="' . $eqLogic->getId() . '">';
        echo '<img src="' . $plugin->getPathImgIcon() . '">';
        echo '<br>';
        echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
        echo '<span class="hiddenAsCard displayTableRight hidden">';
        echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
        echo '</span>';
        echo '</div>';
      }
      echo '</div>';
    }
    ?>
  </div> <!-- /.eqLogicThumbnailDisplay -->
  
  <!-- Page de présentation de l'équipement -->
  <div class="col-xs-12 eqLogic" style="display: none;">
    <!-- barre de gestion de l'équipement -->
    <div class="input-group pull-right" style="display:inline-flex;">
      <span class="input-group-btn">
        <!-- Les balises <a></a> sont volontairement fermées à la ligne suivante pour éviter les espaces entre les boutons. Ne pas modifier -->
        <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
        </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
        </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
        </a>
      </span>
    </div>
    <!-- Onglets -->
    <ul class="nav nav-tabs" role="tablist">
      <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
      <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
      <li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
    </ul>
    <div class="tab-content">
      <!-- Onglet de configuration de l'équipement -->
      <div role="tabpanel" class="tab-pane active" id="eqlogictab">
        <!-- Partie gauche de l'onglet "Equipements" -->
        <!-- Paramètres généraux et spécifiques de l'équipement -->
        <form class="form-horizontal">
          <fieldset>
            <div class="col-lg-6">
              <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
                <div class="col-sm-6">
                  <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                  <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement Ecowatt}}">
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                <div class="col-sm-6">
                  <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                    <option value="">{{Aucun}}</option>
                    <?php
                      $options = '';
                      foreach ((jeeObject::buildTree(null, false)) as $object) {
                        $options .= '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                      }
                      echo $options;
                    ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Catégorie}}</label>
                <div class="col-sm-6">
                    <?php
                    foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                      echo '<label class="checkbox-inline">';
                      echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" >' . $value['name'];
                      echo '</label>';
                    }
                    ?>
                </div>
              </div>
              <div class="form-group">
                  <label class="col-sm-4 control-label">{{Options}}</label>
                  <div class="col-sm-6">
                  <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
                  <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
                </div>
              </div>

              <legend><i class="fas fa-cogs"></i> {{Paramètres spécifiques}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Type de source de données}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Sélectionnez le type de source de données}}"></i></sup>
                </label>
                <div class="col-sm-6">
                  <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="datasource">
                    <option value="ecowattRTE">{{Ecowatt (RTE)}}</option>
                    <option value="tempoRTE">{{Tempo (RTE)}}</option>
                    <option value="consumptionRTE">{{Consommation (RTE)}}</option>
<!--
-->
                    <option value="tempoEDF">{{Tempo (EDF)}}</option>
                    <option value="ejpEDF">{{Ejp (EDF)}}</option>
                  </select>
                </div>
              </div>
              <div class="datasource tempoRTE">
                <div class="form-group">
                  <label class="col-sm-4 control-label" >{{Note sur ce type d'équipement}}</label>
                  <div class="col-sm-7">
L'API <a target="blank" href="https://data.rte-france.com/catalog/-/api/consumption/Tempo-Like-Supply-Contract/v1.1">Tempo Like Supply Contract</a> doit être associée à votre application créée sur le <a target="blank" href="https://data.rte-france.com/group/guest/apps">site RTE</a>.
                  </div>
                </div>
<!--
                <div class="form-group">
                  <label class="col-sm-4 control-label" >{{Utiliser le template du plugin}}</label>
                  <div class="col-sm-7">
                    <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="usePluginTemplate" checked>
                  </div>
                </div>
-->
                <div class="form-group">
                  <label class="col-sm-4 control-label">{{Template}}
                  </label>
                  <div class="col-sm-6">
                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="templateTempo">
                      <option value="plugin">{{Template du plugin}}</option>
                      <option value="none">{{Pas de template}}</option>
    <?php
                      if(file_exists(__DIR__ .'/../../core/template/dashboard/custom.rte_tempo.html'))
                        echo '<option value="custom">{{Template custom}}</option>';
                      $files = array();
                      if ($dh = opendir(__DIR__ .'/../../core/template/dashboard')) {
                        while (($file = readdir($dh)) !== false) {
                          if($file != 'custom.rte_tempo.html' && substr($file,0,17) == 'custom.rte_tempo.' && substr($file,-5) == '.html')
                            $files[] = array('name' => substr($file,17,-5).' (custom)', 'fileName' => $file);
                          if($file != 'rte_tempo.html' && substr($file,0,10) == 'rte_tempo.' && substr($file,-5) == '.html')
                            $files[] = array('name' => substr($file,10,-5), 'fileName' => $file);
                        }
                        closedir($dh);
                      }
                      if(count($files)) {
                        sort($files);
                        foreach($files as $file) {
                          echo '<option value="' .$file['fileName'] .'">' .$file['name'] .'</option>';
                        }
                      }
    ?>
                    </select>
                  </div>
                </div>
              </div>

              <div class="datasource tempoEDF">
                <div class="form-group">
                  <label class="col-sm-4 control-label" ></label>
                  <div class="col-sm-7">Pas d'API pour ce type d'équipement. Les données sont récupérées sur le site <a target="blank" href="https://particulier.edf.fr/fr/accueil/gestion-contrat/options/tempo.html#/">Web d'EDF</a> avec cette mention:<br> Seules les informations affichées sur votre compteur sont contractuelles. Les données communiquées sur le site internet le sont à titre indicatif. En effet, pour des raisons techniques, des différences peuvent parfois survenir entre la couleur indiquée et celle réellement pratiquée.
                  </div>
                </div>
                <div class="form-group">
                  <label class="col-sm-4 control-label" >{{Utiliser le template du plugin}}</label>
                  <div class="col-sm-7">
                    <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="usePluginTemplateTempoEdf" checked>
                  </div>
                </div>
              </div>

              <div class="datasource ejpEDF">
                <div class="form-group">
                  <label class="col-sm-4 control-label" ></label>
                  <div class="col-sm-7">Pas d'API pour ce type d'équipement. Les données sont récupérées sur le site <a target="blank" href="https://particulier.edf.fr/fr/accueil/gestion-contrat/options/ejp.html#/">Web d'EDF</a> avec cette mention:<br> Ces informations sont non contractuelles et sans valeur d'engagement.
La mise à jour des données est effectuée quotidiennement entre 13h et 15h, cependant des contraintes d'exploitation peuvent la retarder jusqu'à 5h du matin du jour EJP.
                  </div>
                </div>
                <div class="form-group">
                  <label class="col-sm-4 control-label" >{{Utiliser le template du plugin}}</label>
                  <div class="col-sm-7">
                    <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="usePluginTemplateEjpEdf" checked>
                  </div>
                </div>
              </div>

              <div class="datasource consumptionRTE">
                <div class="form-group">
                  <label class="col-sm-4 control-label" >{{Note sur ce type d'équipement}}</label>
                  <div class="col-sm-7">
L'API <a target="blank" href="https://data.rte-france.com/catalog/-/api/consumption/Consumption/v1.2">Consumption</a> doit être associée à votre application créée sur le <a target="blank" href="https://data.rte-france.com/group/guest/apps">site RTE</a>.
                  </div>
                </div>
                <div class="form-group">
                  <label class="col-sm-4 control-label" >{{Utiliser le template du plugin}}</label>
                  <div class="col-sm-7">
                    <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="usePluginTemplateConsumption" checked>
                  </div>
                </div>
                <div class="form-group">
                  <label class="col-sm-4 control-label">{{Nombre de jours passés}}
<sup><i class="fas fa-question-circle tooltips" title="{{Nombre de jours avant aujourd'hui affichés dans le graphique.}}"></i></sup>
</label>
                  <div class="col-sm-4">
                    <input size="25" type="input" class="eqLogicAttr" data-l1key="configuration" data-l2key="numConsumptionDays" placeholder="{{Défaut: 6}}">
                  </div>
                </div>
              </div>

              <div class="datasource ecowattRTE">
                <div class="form-group">
                  <label class="col-sm-4 control-label" >{{Note sur ce type d'équipement}}</label>
                  <div class="col-sm-7">
L'API <a target="blank" href="https://data.rte-france.com/catalog/-/api/consumption/Ecowatt/v5.0">Ecowatt v5</a> doit être associée à votre application créée sur le <a target="blank" href="https://data.rte-france.com/group/guest/apps">site RTE</a>.
                  </div>
                </div>

                <div class="form-group">
                  <label class="col-sm-4 control-label">{{Template}}
                  </label>
                  <div class="col-sm-6">
                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="templateEcowatt">
                      <option value="plugin">{{Template du plugin}}</option>
                      <option value="none">{{Pas de template}}</option>
    <?php
                      if(file_exists(__DIR__ .'/../../core/template/dashboard/custom.rte_ecowatt.html'))
                        echo '<option value="custom">{{Template custom}}</option>';
                      $files = array();
                      if ($dh = opendir(__DIR__ .'/../../core/template/dashboard')) {
                        while (($file = readdir($dh)) !== false) {
                          if($file != 'custom.rte_ecowatt.html' && substr($file,0,19) == 'custom.rte_ecowatt.' && substr($file,-5) == '.html')
                            $files[] = array('name' => substr($file,19,-5).' (custom)', 'fileName' => $file);
                          if($file != 'rte_ecowatt.html' && substr($file,0,12) == 'rte_ecowatt.' && substr($file,-5) == '.html')
                            $files[] = array('name' => substr($file,12,-5), 'fileName' => $file);
                        }
                        closedir($dh);
                      }
                      if(count($files)) {
                        sort($files);
                        foreach($files as $file) {
                          echo '<option value="' .$file['fileName'] .'">' .$file['name'] .'</option>';
                        }
                      }
    ?>
                    </select>
                  </div>
                </div>
                <div class="form-group">
                  <label class="col-sm-4 control-label">{{Nombre d'heures}}
<sup><i class="fas fa-question-circle tooltips" title="{{de niveaux d'alerte dans le graphique commencant à l'heure actuelle.}}"></i></sup>
</label>
                  <div class="col-sm-4">
                    <input size="25" type="input" class="eqLogicAttr" data-l1key="configuration" data-l2key="numCmdsHour" placeholder="{{Nombre d'heures. Défaut: 24}}">
                  </div>
                </div>
                <div class="form-group">
                  <label class="col-sm-4 control-label">{{Mode demo}}
<sup><i class="fas fa-question-circle tooltips" title="{{Pour cet équipement, les données du bac à sable RTE fournies avec le plugin seront utilisées sans requête à RTE.}}"></i></sup>
                  </label>
                  <div class="col-sm-6">
                    <input type="checkbox" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="demoMode">
                  </div>
                </div>
              </div>

            </div>
          </fieldset>
        </form>
      </div><!-- /.tabpanel #eqlogictab-->

      <!-- Onglet des commandes de l'équipement -->
      <div role="tabpanel" class="tab-pane" id="commandtab">
        <br><br>
        <div class="table-responsive">
          <table id="table_cmd" class="table table-bordered table-condensed">
            <thead>
              <tr>
                <th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
                <th style="min-width:50px;width:50px;">{{LogicalId}}</th>
                <th style="min-width:200px;width:350px;">{{Nom}}</th>
                <th>{{Paramètres}}</th>
                <th>{{Etat}}</th>
                <th style="min-width:80px;width:200px;">{{Actions}}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
            </tbody>
          </table>
        </div>
      </div><!-- /.tabpanel #commandtab-->
    
    </div><!-- /.tab-content -->
  </div><!-- /.eqLogic -->
</div><!-- /.row row-overflow -->

<!-- Inclusion du fichier javascript du plugin (dossier, nom_du_fichier, extension_du_fichier, id_du_plugin) -->
<?php include_file('desktop', 'rteEcowatt', 'js', 'rteEcowatt'); ?>
<!-- Inclusion du fichier javascript du core - NE PAS MODIFIER NI SUPPRIMER -->
<?php include_file('core', 'plugin.template', 'js'); ?>
