<?php

    require_once ("../vendor/autoload.php");
    require_once ("../config/config.php");
    require_once ("../include/fonctions.php");
    // Initialise la variable $casUid à partir du ticket CAS
    require_once ("../include/casconnexion.php");

    global $casUid;

    // Pour mémoire : querySelector(.XXX) => class XXX / querySelector(#XXX) => id XXX 

    $displayLibelle = 'groupes et rôles CIAM';


?>
<!doctype html>
<html>
<head>
    <title>Liste des <?php echo $displayLibelle ?></title>
<?php
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Cache-Control: no-store, no-cache, must-revalidate"); // HTTP/1.1
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache"); // HTTP/1.0
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
?>
    <link rel="stylesheet" href="jquery-ui/jquery-ui.css?<?php echo filemtime('jquery-ui/jquery-ui.css') ?>" type="text/css" media="all" />
    <script src="jquery-ui/jquery.js"></script>
    <script src="jquery-ui/jquery-ui.js"></script>
<?php
    if (LDAP_MODE == LdapSearchClass::LDAP_MODE_WSGROUPS)
    {
?>
    <script src="<?php echo WSGROUPURL;?>/web-widget/kraaden.github.io-autocomplete.js"></script>
    <link rel="stylesheet" href="<?php echo WSGROUPURL;?>/web-widget/autocompleteUser.css" type="text/css" media="all" />
    <script src="<?php echo WSGROUPURL;?>/web-widget/autocompleteUser.js"></script>
<?php
    }
?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/i18n/fr.js"></script>
    <link rel="stylesheet" href="./css/application.css?dummy=<?php echo rand(1,9999999999); ?>" type="text/css" media="all" />
</head>
<body class="bodyhtml">
    <script src="javascript/functions.js?dummy=<?php echo rand(1,9999999999); ?>"></script>
<?php
    if (file_exists('../include/menu.php')) include('../include/menu.php');

    // print_r($_POST);
    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_CONSULT,true))
    {
        echo '<script type="text/javascript">';
        echo 'window.location.href="index.php";';
        echo '</script>';
        echo '<noscript>';
        echo '<meta http-equiv="refresh" content="0;url=index.php" />';
        echo '</noscript>';        
        // echo "Vous n'êtes pas autorisé à accéder à ce service.";
        exit();
    }
    echo '<br><br>';

    $htmlText = '';
    $groupId = null;
    if (isset($_POST['groupid']))
    {
        $groupId = $_POST['groupid'];
    }
    const ALLGROUPS = 'allgroups';
    const ALLROLES = 'allroles';
    const ALL = 'all';

    $ciamFunctions = new CiamFunctions();

    // On construit le tableau avec l'ensemble des roles CIAM 
    $ciamRolesUsersArray = [];
    $responseCiamRole = new ResponseClass();
    $responseCiamRole->data = [];
    $responseIdentities = $ciamFunctions->getCiamIdentitiesInfos(true);
    if ($responseIdentities->status == ResponseStatus::STATUT_KO)
    {
        $responseCiamRole->status = ResponseStatus::STATUT_KO;
        showMessage(MSGERROR,"Impossible de récupérer les identités depuis le CIAM.");
    }
    elseif (defined('CIAMROLES'))
    {
        foreach($responseIdentities->data as $ciamUserData)
        {
            $ciamUser = new CiamUserClass();
            $ciamUser->load($ciamUserData);

            // On initialise les informations comme si elle venait de groupes/rôles CIAM
            foreach($ciamUser->ciamroles as $ciamRoleKey => $ciamRoleId)
            {
                $ciamRolesUsersArray[$ciamRoleId][$ciamUser->id]['value'] = $ciamUser->id;
                $ciamRolesUsersArray[$ciamRoleId][$ciamUser->id]['display'] = $ciamUser->displayname;
            }
        }

        $responseCiamRole->status = ResponseStatus::STATUT_OK;
        foreach(CIAMROLES as $key => $ciamRoleName)
        {
            // Pour chaque rôle CIAM on initialise les membres (s'il y en a), son id et son displayname
            $membersArray = [];
            if (isset($ciamRolesUsersArray[$key]))
            {
                $membersArray = $ciamRolesUsersArray[$key];
            }
            $responseCiamRole->data[] = ['id' => $key, 'displayName' => $ciamRoleName, 'members' => $membersArray];
        }
    }

    $responseCiamGroup = $ciamFunctions->getListCiamGroupInfos();
    if ($responseCiamGroup->status == ResponseStatus::STATUT_KO)
    {
        showMessage(MSGERROR,$responseCiamGroup->description);
        exit;
    }

    $fullList = "----- Veuillez sélectionner un groupe ";
    if (ENABLE_ROLE_CIAM)
    {
        $fullList .= "ou un rôle";
    }
    $fullList .= " CIAM -----";

?>
    <form method='post'>
        <select class='listuserciamclass' size='1' name='groupid' id='groupid' onchange='this.closest("form").submit();'> 
            <option value=''><?php echo $fullList; ?></option>
<?php
    $knownCiamUsers = [];
    if ($responseCiamGroup->status == ResponseStatus::STATUT_OK)
    {
        // On trie les groupes/rôles par ordre alphabétique
        usort($responseCiamGroup->data, function($a, $b) {
            return strcasecmp($a['displayName'] , $b['displayName']);
        });

        $selected = '';
        if (count($responseCiamGroup->data)>0)
        {
            if (ENABLE_ROLE_CIAM)
            {
                echo "<optgroup label='Groupes CIAM'>" . PHP_EOL;
            }
            if ($groupId == ALLGROUPS)
            {
                $selected = ' selected ';
            }
            echo "<option value='" . ALLGROUPS . "' $selected >Voir tous les groupes du CIAM</option>" . PHP_EOL;

            foreach($responseCiamGroup->data as $ciamGroupData)
            {
                $ciamGroup = new CiamGroupClass();
                $ciamGroup->load($ciamGroupData);
                // var_dump($ciamGroup->ciammembers);
                $selected = '';
                if ($ciamGroup->id == $groupId)
                {
                    $selected = ' selected ';   
                }
                echo "<option value='" . $ciamGroup->id . "' $selected >Groupe : " . $ciamGroup->displayname . "</option>" . PHP_EOL;

                if (($ciamGroup->id == $groupId) or ($groupId == ALLGROUPS))
                {
                    $tableName = "table_" . $ciamGroup->displayname;
                    $htmlText .= '<button class="collapsible">Groupe : ' . $ciamGroup->displayname .  ' </button>' . PHP_EOL;
                    $htmlText .= '<div class="collapsecontent">' . PHP_EOL;
                    $htmlText .= '<br>' . PHP_EOL;
                    $htmlText .= "<table class='tableausimple' id='$tableName' name='$tableName'>" . PHP_EOL;
                    $htmlText .= "    <thead>" . PHP_EOL;
                    $htmlText .= "        <tr class='entete' >" . PHP_EOL;
                    // $htmlText .= "            <th class='titresimple'>Liste des membres</th>" . PHP_EOL;
                    $htmlText .= "            <th class='titresimple cursorpointer'>Nom<span class='sortindicator'> </span></th>" . PHP_EOL;
                    $htmlText .= "            <th class='titresimple cursorpointer'>Prénom<span class='sortindicator'> </span></th>" . PHP_EOL;
                    $htmlText .= "            <th class='titresimple cursorpointer'>Mail<span class='sortindicator'> </span></th>" . PHP_EOL;
                    $htmlText .= "            <th class='titresimple cursorpointer'>Eppn<span class='sortindicator'> </span></th>" . PHP_EOL;
                    $htmlText .= "        </tr>" . PHP_EOL;
                    $htmlText .= "    </thead>" . PHP_EOL;
                    $htmlText .= "    <tbody>" . PHP_EOL;
                    if (count($ciamGroup->ciammembers)==0)
                    {
                        $htmlText .= "        <tr>" . PHP_EOL;
                        $htmlText .= "            <td class='cellulesimple' colSpan=4 >Aucun membre dans ce groupe.</td>" . PHP_EOL;
                        $htmlText .= "        </tr>" . PHP_EOL;
                    }
                    else
                    {
                        foreach($ciamGroup->ciammembers as $member)
                        {
                            if (isset($knownCiamUsers[$member['value']]))
                            {
                                $eppn = $knownCiamUsers[$member['value']]->eppn;
                                $mail = $knownCiamUsers[$member['value']]->mail;
                                $nom = $knownCiamUsers[$member['value']]->name;
                                $prenom = $knownCiamUsers[$member['value']]->firstname;
                            }
                            else
                            {
                                $response =  $ciamFunctions->getCiamIdentitiesInfosFromCiamId($member['value']);
                                if ($response->status == ResponseStatus::STATUT_KO or count($response->data) != 1)
                                {
                                    $eppn = '(EPPN impossible à identifier)';
                                    $mail = '<mail inconnu>';
                                    $nom = 'Inconnu';
                                    $prenom = 'Inconnu';
                                }
                                else
                                {
                                    $ciamUser = new CiamUserClass();
                                    $ciamUser->load($response->data[0]);
                                    $eppn = $ciamUser->eppn;
                                    $mail = $ciamUser->mail;
                                    $nom = $ciamUser->name;
                                    $prenom = $ciamUser->firstname;
                                    $knownCiamUsers[$member['value']] = $ciamUser;
                                }
                            }

                            $htmlText .= "        <tr>" . PHP_EOL;
                            // $htmlText .= '           <td class="cellulesimple cursorpointer"><a ' . ATTR_CIAMUSERID . '="' . $member['value'] . '" data-tip="Cliquez sur le nom de l\'agent pour modifier sa fiche." onclick="event.stopPropagation(); modifyuser(this);">' . $member['display'] . " " . htmlentities($mail) . " $eppn</a>" . PHP_EOL;

                            $openModifyLink= '';
                            $closeModifyLink = '';
                            if (cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY))
                            {
                                $openModifyLink = '<a ' . ATTR_CIAMUSERID . '="' . $member['value'] . '" data-tip="Cliquez sur le nom de l\'agent pour modifier sa fiche." onclick="event.stopPropagation(); modifyuser(this);">';
                                $closeModifyLink = '</a>';
                            }

                            $htmlText .= '           <td class="cellulesimple cursorpointer">' . $openModifyLink . htmlentities($nom) . "$closeModifyLink</td>" . PHP_EOL;
                            $htmlText .= '           <td class="cellulesimple">' . htmlentities($prenom) . '</td>' . PHP_EOL;
                            $htmlText .= '           <td class="cellulesimple">' . htmlentities($mail) . "</td>" . PHP_EOL;
                            $htmlText .= '           <td class="cellulesimple">' . htmlentities($eppn) ."</td>" . PHP_EOL;
                            $htmlText .= "        </tr>" . PHP_EOL;
                        }
                    }
                    $htmlText .= "    </tbody>" . PHP_EOL;
                    $htmlText .= "</table>" . PHP_EOL;
                    $htmlText .= "<script>" . PHP_EOL;
                    $htmlText .= "sortTableInit('$tableName' ,0);" . PHP_EOL;
                    $htmlText .= "</script>" . PHP_EOL;
                    $htmlText .= "<br>" . PHP_EOL;
                    $htmlText .= '</div>' . PHP_EOL;
                    $htmlText .= "<br>" . PHP_EOL;
                }
            }
            if (ENABLE_ROLE_CIAM)
            {
                echo "</optgroup>" . PHP_EOL;
            }
        }
    }

    if ($responseCiamRole->status == ResponseStatus::STATUT_OK and ENABLE_ROLE_CIAM)
    {
        // On trie les groupes/rôles par ordre alphabétique
        usort($responseCiamRole->data, function($a, $b) {
            return strcasecmp($a['displayName'] , $b['displayName']);
        });

        $selected = '';
        if (count($responseCiamRole->data)>0)
        {
            echo "<optgroup label='Rôles CIAM'>" . PHP_EOL;
            if ($groupId == ALLROLES)
            {
                $selected = ' selected ';
            }
            echo "<option value='" . ALLROLES . "' $selected >Voir tous les rôles du CIAM</option>" . PHP_EOL;
            foreach($responseCiamRole->data as $ciamRoleData)
            {
                $ciamRole = new CiamGroupClass();
                $ciamRole->load($ciamRoleData);
                // var_dump($ciamRole->ciammembers);
                $selected = '';
                if ($ciamRole->id == $groupId)
                {
                    $selected = ' selected ';   
                }
                echo "<option value='" . $ciamRole->id . "' $selected >Rôle : " . $ciamRole->displayname . "</option>" . PHP_EOL;

                if (($ciamRole->id == $groupId) or ($groupId == ALLROLES))
                {
                    $tableName = "table_" . $ciamRole->displayname;
                    $htmlText .= '<button class="collapsible">Rôle : ' . $ciamRole->displayname .  ' </button>' . PHP_EOL;
                    $htmlText .= '<div class="collapsecontent">' . PHP_EOL;
                    $htmlText .= '<br>' . PHP_EOL;
                    $htmlText .= "<table class='tableausimple' id='$tableName' name='$tableName'>" . PHP_EOL;
                    $htmlText .= "    <thead>" . PHP_EOL;
                    $htmlText .= "        <tr class='entete' >" . PHP_EOL;
                    // $htmlText .= "            <th class='titresimple'>Liste des membres</th>" . PHP_EOL;
                    $htmlText .= "            <th class='titresimple cursorpointer'>Nom<span class='sortindicator'> </span></th>" . PHP_EOL;
                    $htmlText .= "            <th class='titresimple cursorpointer'>Prénom<span class='sortindicator'> </span></th>" . PHP_EOL;
                    $htmlText .= "            <th class='titresimple cursorpointer'>Mail<span class='sortindicator'> </span></th>" . PHP_EOL;
                    $htmlText .= "            <th class='titresimple cursorpointer'>Eppn<span class='sortindicator'> </span></th>" . PHP_EOL;
                    $htmlText .= "        </tr>" . PHP_EOL;
                    $htmlText .= "    </thead>" . PHP_EOL;
                    $htmlText .= "    <tbody>" . PHP_EOL;
                    if (count($ciamRole->ciammembers)==0)
                    {
                        $htmlText .= "        <tr>" . PHP_EOL;
                        $htmlText .= "            <td class='cellulesimple' colSpan=4 >Aucun membre dans ce groupe.</td>" . PHP_EOL;
                        $htmlText .= "        </tr>" . PHP_EOL;
                    }
                    else
                    {
                        foreach($ciamRole->ciammembers as $member)
                        {
                            if (isset($knownCiamUsers[$member['value']]))
                            {
                                $eppn = $knownCiamUsers[$member['value']]->eppn;
                                $mail = $knownCiamUsers[$member['value']]->mail;
                                $nom = $knownCiamUsers[$member['value']]->name;
                                $prenom = $knownCiamUsers[$member['value']]->firstname;
                            }
                            else
                            {
                                $response =  $ciamFunctions->getCiamIdentitiesInfosFromCiamId($member['value']);
                                if ($response->status == ResponseStatus::STATUT_KO or count($response->data) != 1)
                                {
                                    $eppn = '(EPPN impossible à identifier)';
                                    $mail = '<mail inconnu>';
                                    $nom = 'Inconnu';
                                    $prenom = 'Inconnu';
                                }
                                else
                                {
                                    $ciamUser = new CiamUserClass();
                                    $ciamUser->load($response->data[0]);
                                    $eppn = $ciamUser->eppn;
                                    $mail = $ciamUser->mail;
                                    $nom = $ciamUser->name;
                                    $prenom = $ciamUser->firstname;
                                    $knownCiamUsers[$member['value']] = $ciamUser;
                                }
                            }

                            $htmlText .= "        <tr>" . PHP_EOL;
                            // $htmlText .= '           <td class="cellulesimple cursorpointer"><a ' . ATTR_CIAMUSERID . '=' . $member['value'] . ' data-tip="Cliquez sur le nom de l\'agent pour modifier sa fiche." onclick="event.stopPropagation(); modifyuser(this);">' . $member['display'] . " " . htmlentities($mail) . " $eppn</a>" . PHP_EOL;

                            $openModifyLink= '';
                            $closeModifyLink = '';
                            if (cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY))
                            {
                                $openModifyLink = '<a ' . ATTR_CIAMUSERID . '="' . $member['value'] . '" data-tip="Cliquez sur le nom de l\'agent pour modifier sa fiche." onclick="event.stopPropagation(); modifyuser(this);">';
                                $closeModifyLink = '</a>';
                            }

                            $htmlText .= '           <td class="cellulesimple cursorpointer">' . $openModifyLink . htmlentities($nom) . "$closeModifyLink</td>" . PHP_EOL;
                            $htmlText .= '           <td class="cellulesimple">' . htmlentities($prenom) . '</td>' . PHP_EOL;
                            $htmlText .= '           <td class="cellulesimple">' . htmlentities($mail) . "</td>" . PHP_EOL;
                            $htmlText .= '           <td class="cellulesimple">' . htmlentities($eppn) ."</td>" . PHP_EOL;
                            $htmlText .= "        </tr>" . PHP_EOL;
                        }
                    }
                    $htmlText .= "    </tbody>" . PHP_EOL;
                    $htmlText .= "</table>" . PHP_EOL;
                    $htmlText .= "<script>" . PHP_EOL;
                    $htmlText .= "sortTableInit('$tableName' ,0);" . PHP_EOL;
                    $htmlText .= "</script>" . PHP_EOL;
                    $htmlText .= "<br>" . PHP_EOL;
                    $htmlText .= '</div>' . PHP_EOL;
                    $htmlText .= "<br>" . PHP_EOL;
                }
            }
        }
    }
?>
        </select>
    </form>
    <br>
    <br>
<?php
    echo $htmlText;
?>
    <script>
        var coll = document.getElementsByClassName("collapsible");
        var index;

        for (index = 0; index < coll.length; index++) 
        {
            coll[index].addEventListener("click", function() 
            {
                this.classList.toggle("collapseactive");
                let content = this.nextElementSibling;
                if (content.style.maxHeight)
                {
                    content.style.maxHeight = null;
                } 
                else 
                {
                    content.style.maxHeight = content.scrollHeight + "px";
                } 
            });
        }

        if (coll.length == 1)
        {
            coll[0].click();
        }

        /**
         * Redirige vers identites.php pour modifier l'identité dont
         * l'identifiant CIAM est porté par l'attribut ATTR_CIAMUSERID de
         * currentElement.
         *
         * Construit et soumet dynamiquement un formulaire POST temporaire
         * (champs 'ciamid' = identifiant CIAM, 'ciamaction' =
         * LOAD_USER_RESOURCE), retiré du DOM juste après la soumission.
         *
         * Contrairement à la fonction homonyme de listeidentites.php (qui lit
         * l'EPPN sur le bouton ancêtre le plus proche et poste un champ
         * 'agent'), ici l'attribut est lu directement sur currentElement et le
         * champ posté est 'ciamid'.
         *
         * @param {HTMLElement} currentElement Élément portant l'attribut
         *                       ATTR_CIAMUSERID (l'identifiant CIAM de la personne).
         * @returns {void}
         */
        function modifyuser(currentElement)
        {
            let ciamUserId = currentElement.getAttribute('<?php echo ATTR_CIAMUSERID; ?>');
            let form = document.createElement('form');
            form.method = 'post';
            form.action = 'identites.php';
            // form.target="_blank";
            let ciamUserIdInput = document.createElement('input');
            ciamUserIdInput.type = 'hidden';
            ciamUserIdInput.name = 'ciamid';
            ciamUserIdInput.id = ciamUserIdInput.name;
            ciamUserIdInput.value = ciamUserId;
            form.appendChild(ciamUserIdInput);
            let ciamActionInput = document.createElement('input');
            ciamActionInput.type = 'hidden';
            ciamActionInput.name = 'ciamaction';
            ciamActionInput.value = '<?php echo LOAD_USER_RESOURCE; ?>';
            form.appendChild(ciamActionInput);
            document.body.appendChild(form);
            form.submit();
            form.remove();
        }
    </script>
</body>
</html>
