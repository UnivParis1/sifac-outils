<?php

    require_once ("../vendor/autoload.php");
    require_once ("../config/config.php");
    require_once ("../include/fonctions.php");
    // Initialise la variable $casUid à partir du ticket CAS
    require_once ("../include/casconnexion.php");

    global $casUid;

    // Pour mémoire : querySelector(.XXX) => class XXX / querySelector(#XXX) => id XXX 

    if (!defined('SQLMODE'))
    {
        define('SQLMODE',SifacSqlMode::QUERY_DIRECT);
    }

    $response = new ResponseClass();
    $sifacFunctions = new SifacFunctions();
    $sifacquery = new SifacQueryClass();
    $ciamfunctions = new CiamFunctions();

?>
<!doctype html>
<html>
<head>
<?php
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Cache-Control: no-store, no-cache, must-revalidate"); // HTTP/1.1
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache"); // HTTP/1.0
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
?>
    <title>Gestion des groupes internes</title>
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
    <link rel="stylesheet" href="./css/application.css?dummy=<?php echo rand(1,9999999999); ?>" type="text/css" media="all" />
</head>
<body class="bodyhtml">
    <script src="javascript/functions.js?dummy=<?php echo rand(1,9999999999); ?>"></script>

<?php
    if (file_exists('../include/menu.php')) include('../include/menu.php');

    $casversion = phpCAS::getVersion();
    $errlog = "Version de CAS.php utilisée  : " . $casversion;
    error_log(basename(__FILE__) . " " . stripAccents($errlog));

    // var_dump($_POST);
    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_ADMIN,true))
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

    $ciamAction = filter_input(INPUT_POST, 'ciamaction', FILTER_UNSAFE_RAW);
    $delCiamGroupList = filter_input(INPUT_POST, 'delcustomgroup', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
    if (!is_array($delCiamGroupList))
    {
        $delCiamGroupList = null;
    }
    $newCiamGroupList = filter_input(INPUT_POST, 'newcustomgroup', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
    if (!is_array($newCiamGroupList))
    {
        $newCiamGroupList = null;
    }

    if (($ciamAction == DELETE_GROUP_RESOURCE) and !is_null($delCiamGroupList))
    {
        foreach($delCiamGroupList as $ciamGroupId => $value)
        {
            // On recharge le groupe CIAM pour récupérer son displayName
            $response = $ciamfunctions->getCiamGroupInfos($ciamGroupId);
            if ($response->status != ResponseStatus::STATUT_OK)
            {
                showMessage(MSGERROR,$response->description);
            }
            else
            {
                $ciamGroup = new CiamGroupClass();
                $ciamGroup->load($response->data[0]);
                $ciamGroupName = $ciamGroup->displayname;

                // On peut maintenant l'effacer du CIAM après avoir vérifier que c'est rôle applicatif
                // En théorie c'est toujours le cas, mais il vaut mieux assurer
                if (str_starts_with(mb_strtoupper($ciamGroupName),mb_strtoupper(INTERNAL_ROLE_PREFIX)))
                {
                    $ciamGroup = new CiamGroupClass();
                    $ciamGroup->id = $ciamGroupId;
                    $response = $ciamfunctions->deleteCiamGroup($ciamGroup);
                    if ($response->status != ResponseStatus::STATUT_OK)
                    {
                        showMessage(MSGERROR,$response->description);
                    }
                    else
                    {
                        showMessage(MSGINFO,"Le groupe d'autorisation $ciamGroupName vient d'être supprimé.");  
                    }
                }
            }
        }
    }

    if (($ciamAction == CREATE_GROUP_RESOURCE) and !is_null($newCiamGroupList))
    {
        foreach($newCiamGroupList as $ciamGroupName => $value)
        {
            if (str_starts_with(mb_strtoupper($ciamGroupName),mb_strtoupper(INTERNAL_ROLE_PREFIX)))
            {
                $ciamGroup = new CiamGroupClass();
                $ciamGroup->displayname = mb_strtoupper($ciamGroupName);
                $response = $ciamfunctions->createCiamGroupInfos($ciamGroup);
                if ($response->status != ResponseStatus::STATUT_OK)
                {
                    if (str_contains($response->description, ' 409 ')) // 409 = HTTP Code pour 'Conflict' : Le groupe existe déjà
                    {
                        showMessage(MSGWARNING,"Le groupe $ciamGroupName est déjà présent dans le CIAM");   
                    }
                    else
                    {
                        showMessage(MSGERROR,$response->description);
                    }
                }
                else
                {
                    showMessage(MSGINFO,"Le groupe d'autorisation $ciamGroupName vient d'être crée.");  
                }
            }
        }
    }

    $fullCiamGroupList = [];
    $response = $ciamfunctions->getListCiamGroupInfos();
    if ($response->status != ResponseStatus::STATUT_OK)
    {
        showMessage(MSGERROR,$response->description);
    }
    else
    {
        foreach($response->data as $groupData)
        {
            $ciamGroup = new CiamGroupClass();
            $ciamGroup->load($groupData);
            if (str_starts_with(mb_strtoupper($ciamGroup->displayname),mb_strtoupper(INTERNAL_ROLE_PREFIX)))
            {
                $fullCiamGroupList[$ciamGroup->displayname] = $ciamGroup;
            }
        }

        if (count($fullCiamGroupList)>0)
        {
?>
            <form method='post'>
            <table class="tableausimple" id="delgrouptable" name="delgrouptable">
                <thead>
                    <tr><th class='cellulesimple bold centeraligntext' colSpan=2>Liste des rôles applicatifs existants</th></tr>
                    <tr class='entete'>
                        <th class='titresimple cursorpointer'>Nom du groupe <span class='sortindicator'> </span></th>
                        <th class='titresimple cursordefault'>Suppression</th>
                    </tr>
                </thead>
                <tbody>
<?php
            foreach($fullCiamGroupList as $ciamGroup)
            {
?>
                    <tr class=''>
                        <td class="cellulesimple"><?php echo $ciamGroup->displayname ?></td>
                        <td class="cellulesimple"><input type='checkbox' id='delcustomgroup[<?php echo $ciamGroup->id; ?>]' name='delcustomgroup[<?php echo $ciamGroup->id; ?>]'></td>
                    </tr>
<?php
            }
?>
                </tbody>
            </table>
            <br>
            <button class="sifacbouton sifacsupprbouton" name="deletegroupbutton" id="deletegroupbutton" type="button" onclick="askConfirmBeforeSave(this,'reloadPage');" <?php echo ATTR_MODALHEADER . '="Confirmation"'; ?> <?php echo ATTR_ACTION . '="' . DELETE_GROUP_RESOURCE . '"'; ?> <?php echo ATTR_LIBELLE . '="Confirmez-vous la suppression des groupes sélectionnés ?<br>Cela supprimera également les droits des utilisateurs sur ces groupes."'; ?> >Supprimer</button>
            <br>
            </form>
<?php
        }

?>
        <br>
        <br>
<?php
        $firstrow = true;
        // foreach((CIAMGROUPACCESS_ADMIN + CIAMGROUPACCESS_CREATE + CIAMGROUPACCESS_MODIFY + CIAMGROUPACCESS_CONSULT) as $ciamgroupname)
        foreach(array_unique(array_merge(CIAMGROUPACCESS_ADMIN, CIAMGROUPACCESS_CREATE, CIAMGROUPACCESS_MODIFY, CIAMGROUPACCESS_CONSULT)) as $ciamgroupname)
        {
            if (str_starts_with(mb_strtoupper($ciamgroupname),mb_strtoupper(INTERNAL_ROLE_PREFIX)) and !(isset($fullCiamGroupList[mb_strtoupper($ciamgroupname)])))
            {
                if ($firstrow)
                {
?>
                    <form method='post'>
                    <table class="tableausimple" id="newgrouptable" name="newgrouptable">
                        <thead>
                            <tr><th class='cellulesimple bold centeraligntext' colSpan=2>Liste des rôles applicatifs à créer</th></tr>
                            <tr class='entete'>
                                <th class='titresimple cursorpointer'>Nom du groupe <span class='sortindicator'> </span></th>
                                <th class='titresimple cursordefault'>Création</th>
                            </tr>
                        </thead>
                        <tbody>
<?php                    
                    $firstrow = false;
                }
?>
                <tr class=''>
                    <td class="cellulesimple"><?php echo $ciamgroupname ?></td>
                    <td class="cellulesimple"><input type='checkbox' id='newcustomgroup[<?php echo mb_strtoupper($ciamgroupname); ?>]' name='newcustomgroup[<?php echo mb_strtoupper($ciamgroupname); ?>]'></td>
                </tr>
<?php
            }
        }

        if (!$firstrow)
        {
?>
            </tbody>
        </table>
        <br>
        <button class="sifacbouton sifacajoutbouton" name="newgroupbutton" id="newgroupbutton" type="button" onclick="askConfirmBeforeSave(this,'reloadPage');" <?php echo ATTR_MODALHEADER . '="Confirmation"'; ?> <?php echo ATTR_ACTION . '="' . CREATE_GROUP_RESOURCE . '"'; ?> <?php echo ATTR_LIBELLE . '="Confirmez-vous la création des groupes sélectionnés ?"'; ?> >Création</button>
        <br>
        </form>
<?php
        }
?>
        <script>
            let table;
            table = document.querySelector('#newgrouptable');
            if (table)
            {
                let thList = table.querySelectorAll('th');
                let numColonneTri = 0;
                let sort = false;
                thList.forEach(th => { 
                            if (th.querySelector('.sortindicator')!==null) 
                            {
                                // Si le contenu HTML du sortindicator n'est pas vide => On a trié sur cette colonne
                                if (th.querySelector('.sortindicator').innerHTML.trim()!="")
                                {
                                    numColonneTri = th.cellIndex;
                                    sort = !th.asc;
                                }
                            }
                        });

                // // On initialise les evènements pour le tri
                sortTableInit(table.id,numColonneTri);
                // On trie le tableau sur la colonne précédemment triée et dans le même ordre si cela était défini
                table.querySelectorAll('th')[numColonneTri].asc = sort;
                table.querySelectorAll('th')[numColonneTri].click();
            }
            table = document.querySelector('#delgrouptable');
            if (table)
            {
                let thList = table.querySelectorAll('th');
                let numColonneTri = 0;
                let sort = false;
                thList.forEach(th => { 
                            if (th.querySelector('.sortindicator')!==null) 
                            {
                                // Si le contenu HTML du sortindicator n'est pas vide => On a trié sur cette colonne
                                if (th.querySelector('.sortindicator').innerHTML.trim()!="")
                                {
                                    numColonneTri = th.cellIndex;
                                    sort = !th.asc;
                                }
                            }
                        });

                // // On initialise les evènements pour le tri
                sortTableInit(table.id,numColonneTri);
                // On trie le tableau sur la colonne précédemment triée et dans le même ordre si cela était défini
                table.querySelectorAll('th')[numColonneTri].asc = sort;
                table.querySelectorAll('th')[numColonneTri].click();
            }
        </script>


<?php
    }

?>
</body>
</html>
