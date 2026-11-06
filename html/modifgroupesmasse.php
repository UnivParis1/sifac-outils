<?php

    require_once ("../vendor/autoload.php");
    require_once ("../config/config.php");
    require_once ("../include/fonctions.php");
    // Initialise la variable $casUid à partir du ticket CAS
    require_once ("../include/casconnexion.php");

    global $casUid;

    // Pour mémoire : querySelector(.XXX) => class XXX / querySelector(#XXX) => id XXX 

?>
<!doctype html>
<html>
<head>
    <title>Modification en masse des groupes</title>
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

    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY,true))
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

    $response = new ResponseClass();
    $response->status = ResponseStatus::STATUT_OK;
    $CasUserEppn = '';
    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_ADMIN))
    {
        $ldapFunctions = new LdapFunctions();
        $ldapResponse = $ldapFunctions->getLDAPInfos($casUid,
                                                    [LDAP_AGENT_UID_ATTR],
                                                    [LDAP_AGENT_EPPN_ATTR],
                                                    LdapSearchClass::LDAP_OPTION_ONE_RESPONSE
                                                    );
        if ($ldapResponse->status == ResponseStatus::STATUT_KO or count($ldapResponse->data)==0)
        {
            $response = $ldapResponse;
        }
        else
        {
            $CasUserEppn = $ldapResponse->data[0][LDAP_AGENT_EPPN_ATTR];
        }
    }

    if ($response->status == ResponseStatus::STATUT_KO)
    {
        showMessage(MSGERROR,$response->description);
    }


    $ciamAction = '';
    $userIdArray = [];
    $groupid = '';
    if (count($_POST) > 0)
    {
        $ciamAction = filter_input(INPUT_POST, 'ciamaction', FILTER_UNSAFE_RAW);
        $groupId = filter_input(INPUT_POST, 'groupid', FILTER_UNSAFE_RAW);
        $userIdArray = filter_input(INPUT_POST, 'userIdArray', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
        if (!is_array($userIdArray))
        {
            $userIdArray = [];
        }
    }

    if (($ciamAction == MODIFY_GROUP_RESOURCE) and ($groupId .'') != '')
    {
        $ciamFunctions = new CiamFunctions();
        $response = $ciamFunctions->getCiamGroupInfos($groupId);
        if ($response->status == ResponseStatus::STATUT_OK)
        {
            $ciamGroup = new CiamGroupClass();
            $ciamGroup->load($response->data[0]);
            foreach($ciamGroup->ciammembers as $member)
            {
                $memberId = $member['value'];
                if (!in_array($memberId,$userIdArray))
                {
                    $ciamGroup->removeMember($memberId);
                }
            }
            foreach($userIdArray as $userId)
            {
                $ciamGroup->addMember($userId);
            }
            if ($ciamGroup->isModified())
            {
                $response = $ciamFunctions->modifyCiamGroupInfos($ciamGroup);
                if ($response->status == ResponseStatus::STATUT_OK)
                {
                    showMessage(MSGINFO,"Le groupe " . $ciamGroup->displayname . " a été modifié.");
                }
                else
                {
                    showMessage(MSGERROR,"Une erreur s'est produite.<br>Le groupe " . $ciamGroup->displayname . " n'a pas été modifié.<br>" . $response->description);
                }
            }
            else
            {
                showMessage(MSGWARNING,"Pas de sauvegarde : Aucune modification sur le groupe " . $ciamGroup->displayname . " n'a été réalisée.");
            }
        }
        else
        {
            showMessage(MSGERROR,"Une erreur s'est produite lors du chargement du groupe " . $ciamGroup->displayname . ".<br>" . $response->description);
        }
    }

    echo '<br><br>';

    $htmlText = '';
    $groupId = '';
    if (isset($_POST['groupid']))
    {
        $groupId = $_POST['groupid'];
    }

    $ciamFunctions = new CiamFunctions();

    $responseCiamGroup = $ciamFunctions->getListCiamGroupInfos();
    if ($responseCiamGroup->status == ResponseStatus::STATUT_KO)
    {
        showMessage(MSGERROR,$responseCiamGroup->description);
        exit;
    }

?>
    <form method='post'>
        <select class='listuserciamclass' size='1' name='groupid' id='groupid' onchange='this.closest("form").submit();'> 
            <option value=''>----- Veuillez sélectionner un groupe CIAM -----</option>
<?php
    $selectedGroup = new CiamGroupClass();
    if ($responseCiamGroup->status == ResponseStatus::STATUT_OK)
    {
        // On trie les groupes/rôles par ordre alphabétique
        usort($responseCiamGroup->data, function($a, $b) {
            return strcmp($a['displayName'] , $b['displayName']);
        });

        $selected = '';
        if (count($responseCiamGroup->data)>0)
        {
            foreach($responseCiamGroup->data as $ciamGroupData)
            {
                $ciamGroup = new CiamGroupClass();
                $ciamGroup->load($ciamGroupData);
                // var_dump($ciamGroup->ciammembers);
                $selected = '';
                if ($ciamGroup->id == $groupId)
                {
                    $selected = ' selected '; 
                    $selectedGroup = $ciamGroup;
                }
                echo "<option value='" . $ciamGroup->id . "' $selected >Groupe : " . $ciamGroup->displayname . "</option>" . PHP_EOL;
            }
        }
    }
    echo "</select>";
    echo '<br>';
    echo '<br>';
    if ($selectedGroup->id == $groupId and $groupId != '')
    {
        $tableName = "tableGroupIdent";
?>
        <br>
        <button class="sifacbouton sifacmodifierbouton" name="modifyidbutton" id="modifyidbutton" type="button" onclick="askConfirmBeforeSave(this,'reloadPage');" <?php echo ATTR_MODALHEADER . '="Confirmation"'; ?> <?php echo ATTR_ACTION . '="' . MODIFY_GROUP_RESOURCE . '"'; ?> <?php echo ATTR_LIBELLE . '="Confirmez-vous la modification du groupe ' . $selectedGroup->displayname . ' ?"'; ?> >Modifier</button>
        <br>
        <table class='tableausimple' id='<?php echo $tableName; ?>' name='<?php echo $tableName; ?>'>
            <thead>
                <tr class='entete' >
                    <th class='titresimple cursorpointer'>Nom<span class='sortindicator'> </span></th>
                    <th class='titresimple cursorpointer'>Prénom<span class='sortindicator'> </span></th>
                    <th class='titresimple cursorpointer'>Mail<span class='sortindicator'> </span></th>
                    <th class='titresimple cursorpointer'>Eppn<span class='sortindicator'> </span></th>
                    <th class='titresimple'>Supprimer</th>
                </tr>
            </thead>
            <tbody>
<?php
        if (count($selectedGroup->ciammembers)==0)
        {
?>
                <tr>
                    <td class='cellulesimple' colSpan=5 >Aucun membre dans ce groupe.</td>
                </tr>
<?php
        }
        else
        {
            foreach($selectedGroup->ciammembers as $member)
            {
                $response =  $ciamFunctions->getCiamIdentitiesInfosFromCiamId($member['value']);
                if ($response->status == ResponseStatus::STATUT_KO or count($response->data) != 1)
                {
                    $ciamUser = new CiamUserClass();
                    $ciamUser->load([]);
                }
                else
                {
                    $ciamUser = new CiamUserClass();
                    $ciamUser->load($response->data[0]);
                }
?>
                <tr>
                    <td class="cellulesimple cursorpointer"><?php echo htmlentities($ciamUser->name); ?></td>
                    <td class="cellulesimple"><?php echo htmlentities($ciamUser->firstname); ?></td>
                    <td class="cellulesimple"><?php echo htmlentities($ciamUser->mail); ?></td>
                    <td class="cellulesimple <?php echo LDAPEPPNCELLULE; ?>"><?php echo htmlentities($ciamUser->eppn); ?></td>
                    <td class="cellulesimple"><button class="sifacbouton sifacsupprbouton" name="suppridbutton[]" id="suppridbutton[]" type="button" onclick="removeIdentity(this);">Supprimer</button>
                    <input type='hidden' name='userIdArray[]' id='userIdArray[]' class='useridinput' value='<?php echo $ciamUser->id; ?>'/>
                </tr>
<?php
            }
        }
?>
            </tbody>
        </table>
        <script>
            let groupTable = document.getElementById('<?php echo $tableName; ?>');
            sortTableInit('<?php echo $tableName; ?>' ,0);
        </script>
        <br>
        <br>
<?php
    }
?>
    </form>
    <br>
<?php
    if ($selectedGroup->id == $groupId and $groupId != '')
    {
?>
        Ajouter un utilisateur à un groupe :<br>
<?php
        // On cherche toutes les identités du CIAM
        $ciamFunctions = new CiamFunctions();
        $response = $ciamFunctions->getCiamIdentitiesInfos();

        if ($response->status == ResponseStatus::STATUT_KO)
        {
            showMessage(MSGERROR,$response->description);
            exit;
        }

        // On trie sur le nom de famille de l'identité
        usort($response->data, function($a, $b) {
        return strcmp($a['name']['familyName'] , $b['name']['familyName']);
        });

?>
        <select class='listuserciamclass' size='1' name='userid' id='userid' onchange='addIdentity(groupTable,this.options[this.selectedIndex])'> 
            <option value=''>----- Veuillez sélectionner un utilisateur -----</option>
<?php
        $excludeDomains = [];
        if (defined('EXCLUDE_USERDOMAIN'))
        {
            $excludeDomains = array_map('strtolower', EXCLUDE_USERDOMAIN);
        }
        foreach($response->data as $resource)
        {
            $ciamUser = new CiamUserClass();
            $ciamUser->load($resource);

            $userDomain = strtolower(explode('@',$ciamUser->eppn)[1]);
            if (in_array($userDomain,$excludeDomains))
            {
                // Si le domaine de l'EPPN est un domaine exclu, alors on l'ignore => Continue
                continue;
            }
?>
            <option value='<?php echo $ciamUser->id; ?>' 
                    <?php echo ATTR_EPPN . "='" . $ciamUser->eppn . "'" ?> 
                    <?php echo ATTR_CIAMUSERID . "='" . htmlspecialchars($ciamUser->id) . "'" ?>
                    <?php echo ATTR_CIAMUSERFIRSTNAME . "='" . htmlspecialchars($ciamUser->firstname) . "'" ?>
                    <?php echo ATTR_CIAMUSERNAME . "='" . htmlspecialchars($ciamUser->name) . "'" ?>
                    <?php echo ATTR_CIAMUSERMAIL . "='" . htmlspecialchars($ciamUser->mail) . "'" ?>
            ><?php echo $ciamUser->name . " " . $ciamUser->firstname . ' &lt;' . $ciamUser->mail . '&gt;'; ?></option>
<?php
        }
?>
    </select>
<?php
    }
?>
    <br>
    <br>
    <br>
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

        function addIdentity(table, option)
        {
            if (!table || !option)
            {
                return;
            }
            if (option.value == '')
            {
                return;
            }

            let eppnValue = option.getAttribute('<?php echo ATTR_EPPN; ?>');
            let idValue = option.getAttribute('<?php echo ATTR_CIAMUSERID; ?>');
            let nameValue = option.getAttribute('<?php echo ATTR_CIAMUSERNAME; ?>');
            let firstNameValue = option.getAttribute('<?php echo ATTR_CIAMUSERFIRSTNAME; ?>');
            let mailValue = option.getAttribute('<?php echo ATTR_CIAMUSERMAIL; ?>');

            if (!idValue || !eppnValue || !nameValue || !firstNameValue || !mailValue)
            {
                return;
            }

            if (eppnValue == '<?php echo $CasUserEppn; ?>')
            {
                showModalSingleButton('error',"Ajout d'un utilisateur",'Ok',"Vous ne pouvez pas ajouter votre propre identité dans un groupe.");                
                return;
            }

            let identityArray;
            let newRow;
            let newCell;
            let hiddenInput;
            let delButton;

            for (rowIndex = 0 ; rowIndex < table.rows.length ; rowIndex++)
            { 
                let currentRow = table.rows[rowIndex];
                if (currentRow.cells.length>0 && currentRow.cells[0].colSpan > 1)
                {
                    table.deleteRow(currentRow.rowIndex);
                }
            };

            inputIdArray = table.getElementsByClassName("useridinput");
            if (inputIdArray.length>0)
            {
                for (index = 0 ; index < inputIdArray.length ; index++)
                {
                    let inputId = inputIdArray[index];
                    if (inputId.value == idValue)
                    {
                        showModalSingleButton('error',"Ajout d'un utilisateur",'Ok',"L'utilisateur " + firstNameValue + ' ' + nameValue + " a déjà les accès sur ce groupe.");                
                        return;
                    }
                }
            }

            newRow = table.insertRow();
            newRow.classList.add('nouvelleligne');
            newCell = newRow.insertCell(0);
            newCell.classList.add('cellulesimple', 'cursorpointer');
            newCell.textContent = nameValue;
            newCell = newRow.insertCell(1);
            newCell.classList.add('cellulesimple', 'cursorpointer');
            newCell.textContent = firstNameValue;
            newCell = newRow.insertCell(2);
            newCell.classList.add('cellulesimple', 'cursorpointer');
            newCell.textContent = mailValue;
            newCell = newRow.insertCell(3);
            newCell.classList.add('cellulesimple', 'cursorpointer', '<?php echo LDAPEPPNCELLULE; ?>');
            newCell.textContent = eppnValue;
            newCell = newRow.insertCell(4);
            newCell.classList.add('cellulesimple');
            delButton = table.ownerDocument.createElement('button');
            delButton.classList.add('sifacbouton', 'sifacsupprbouton');
            delButton.name = 'suppridbutton[]';
            delButton.id = delButton.name;
            delButton.textContent = 'Supprimer';
            delButton.addEventListener('click', function() { removeIdentity(delButton); }); 
            newCell.appendChild(delButton);
            hiddenInput = table.ownerDocument.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'userIdArray[]';
            hiddenInput.classList.add('useridinput')
            hiddenInput.id = hiddenInput.name;
            hiddenInput.value = idValue;
            newRow.appendChild(hiddenInput);

            // On va retrier le tableau
            resortTable(table.id);

        }

        function removeIdentity(button)
        {
            event.stopPropagation();

            let currentRow;
            let currentTable;
            if (!button)
            {
                return false;
            }
            currentRow = button.closest('tr');
            if (currentRow)
            {
                let eppnCell = currentRow.getElementsByClassName('<?php echo LDAPEPPNCELLULE; ?>')[0];
                let eppnValue = '';
                if (eppnCell)
                {
                    eppnValue = eppnCell.textContent;
                }
                if (eppnValue == '<?php echo $CasUserEppn; ?>')
                {
                    showModalSingleButton('error',"Suppression d'un utilisateur",'Ok',"Vous ne pouvez pas supprimer votre propre identité d'un groupe.");                
                    return false;
                }

                currentTable = currentRow.closest('table');
                currentTable.deleteRow(currentRow.rowIndex);
            }
            return false;
        }
    </script>
</body>
</html>
