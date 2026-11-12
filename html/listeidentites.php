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

?>
<!doctype html>
<html>
<head>
    <title>Liste des identités</title>
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
    <link rel="stylesheet" href="./css/application.css?dummy=<?php echo rand(1,9999999999); //filemtime('./css/application.css') ?>" type="text/css" media="all" />
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
    $userId = null;
    if (isset($_POST['userid']))
    {
        $userId = $_POST['userid'];
    }
    const ALLUSERS = 'all';

    $ciamFunctions = new CiamFunctions();
    $response = $ciamFunctions->getCiamIdentitiesInfos(true);

    if ($response->status == ResponseStatus::STATUT_KO)
    {
        showMessage(MSGERROR,$response->description);
        exit;
    }

    // On trie sur le nom de famille de l'identité en conservant la clé du tableau
    uasort($response->data, function($a, $b) {
        return strcasecmp($a['name']['familyName'] , $b['name']['familyName']);
    });

?>
    <form method='post'>
        <select class='listuserciamclass' size='1' name='userid' id='userid' onchange='this.closest("form").submit();'> 
            <option value=''>----- Veuillez sélectionner un utilisateur -----</option>
<?php
            $selected = '';
            if ($userId == ALLUSERS)
            {
                $selected = ' selected ';
            }
?>
            <!-- <option value='<?php echo ALLUSERS; ?>' <?php echo $selected; ?>>Voir tous les utilisateurs du CIAM</option> -->
<?php
    if ($response->status == ResponseStatus::STATUT_OK)
    {
        foreach($response->data as $resource)
        {
            $ciamUser = new CiamUserClass();
            $ciamUser->load($resource);

            $selected = '';
            if (!is_null($userId) and ($ciamUser->id == $userId or $userId == ALLUSERS))
            {
                $openModifyLink= '';
                $closeModifyLink = '';
                if (cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY))
                {
                    $openModifyLink = '<a data-tip="Cliquez sur le nom de l\'agent pour modifier sa fiche." onclick="event.stopPropagation(); modifyuser(this);">';
                    $closeModifyLink = '</a>';
                }

                $htmlText .= '<button class="collapsible" ' . ATTR_EPPN . '="' . $ciamUser->eppn .'">' . $openModifyLink . $ciamUser->displayname . ' &lt;' . $ciamUser->mail . '&gt;' . $closeModifyLink .'</button>' . PHP_EOL;
                $htmlText .= '<div class="collapsecontent">' . PHP_EOL;
                $htmlText .= '<br>' . PHP_EOL;
                $htmlText .= $ciamUser->identityHtml(true);
                $htmlText .= "<br>" . PHP_EOL;
                $ciamReturn = $ciamUser->ciamRolesHtml(true);
                if (trim($ciamReturn) == "")
                {
                    $htmlText .= "Impossible de récupérer les groupes CIAM.";
                }
                else
                {
                    $htmlText .= $ciamReturn;
                }

                $htmlText .= "<br>" . PHP_EOL;
                if (SIFACROLES_INTO_CIAM)
                {
                    $fullSifacRoleArray = ['NOM' => [], 'DESCRIPTION' => []];
                    // Si les rôles sont gérés dans le CIAM, il faut mettre en cache les rôles SIFAC
                    // Sinon les rôles sont gérés directement dans SIFAC, donc on récupère le libellé 
                    // des rôles directement en chargeant les rôles depuis SIFAC
                    $sifacFunctions = new SifacFunctions();
                    $resultJson = $sifacFunctions->getSifacRolesList(SQLMODE);
                    if ($resultJson->status == ResponseStatus::STATUT_OK)
                    {
                        $fullSifacRoleArray = $resultJson->data;
                        if (trim($resultJson->description . '') != '')
                        {
                            showMessage(MSGWARNING,$resultJson->description);
                        }
                    }
                    else
                    {
                        error_log(basename(__FILE__) . " " . stripAccents(__FUNCTION__ . " => Erreur lors de la récupération des rôles SIFAC : " . $resultJson->description));  
                    }
                    $htmlText .= $ciamUser->sifacRolesHtml($fullSifacRoleArray);
                    $htmlText .= "<br>" . PHP_EOL;
                }
                else
                {
                    $sifacFunctions = new SifacFunctions();
                    $resultJson = $sifacFunctions->getSifacLoginFromEppn($ciamUser,SQLMODE);
                    if ($resultJson->status == ResponseStatus::STATUT_KO)
                    {
                        if (isset($ciamUser->mail) and trim($ciamUser->mail . '') != '')
                        {
                            error_log(basename(__FILE__) . stripAccents(" On va chercher le login SIFAC+ à partir du mail " . $ciamUser->mail . "."));
                            $resultJson = $sifacFunctions->getSifacLoginFromMail($ciamUser,SQLMODE);
                        }
                        else
                        {
                            error_log(basename(__FILE__) . stripAccents(" Le mail de l'utilisateur ciam n'est pas défini. Impossible de faire le lien avec le login SIFAC+."));
                        }
                    }
                    if ($resultJson->status == ResponseStatus::STATUT_OK and count($resultJson->data)==1)
                    {
                        // Si c'est ok et que le $resultJson->data ne contient qu'un seul enregistrement.
                        // On peut donc récupérer la valeur du login SIFAC comme étant le premier élément du tableau
                        $sifacLogin = reset($resultJson->data);
                        $resultJson = $sifacFunctions->getSifacUserInfo($sifacLogin,SQLMODE); 
                        $sifacUser = new SifacUserClass();
                        if ($resultJson->status == ResponseStatus::STATUT_OK)
                        {
                            if (count($resultJson->data) != 1)
                            {
                                $htmlText .= "Impossible de charger l'utilisateur à partir de son login SIFAC";
                                $resultJson->status = ResponseStatus::STATUT_KO;
                            }
                            else
                            {
                                $sifacUser = $resultJson->data[$sifacLogin];
                                $htmlText .= $sifacUser->sifacInfosHtml();
                                $resultJson = $sifacFunctions->getSifacRoleByUser($sifacUser,SQLMODE);
                                if ($resultJson->status == ResponseStatus::STATUT_OK)
                                {
                                    $sifacRoleArray = $resultJson->data;
                                    $htmlText .= $sifacUser->sifacRolesHtml($sifacRoleArray);
                                }
                                else
                                {
                                    $htmlText .= "Une erreur s'est produite lors du chargement des rôles SIFAC de l'utilisateur.";
                                }
                            }
                        }
                        else
                        {
                            $htmlText .= "Une erreur s'est produite lors de la récupération des informations SIFAC de l'utilisateur : " . $resultJson->description ;    
                        }
                    }
                    else
                    {
                        $htmlText .= "Impossible de récupérer le login SIFAC : " . $resultJson->description;
                    }
                    $htmlText .= "<br>" . PHP_EOL;
                }
                $htmlText .= '</div>' . PHP_EOL;
                $htmlText .= "<br>" . PHP_EOL;
                if ($userId != ALLUSERS)
                {
                    $selected = ' selected ';
                }
            }
?>
            <option value='<?php echo $ciamUser->id; ?>' <?php echo $selected; ?>><?php echo $ciamUser->name . " " . $ciamUser->firstname . ' &lt;' . $ciamUser->mail . '&gt;'; ?></option>
<?php
        }
    }

?>
        </select>
    </form>
    <br>
<?php
    echo $htmlText;
?>
    <script>
        let table = document.querySelector('#userroletable');
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
         * Redirige vers identites.php pour modifier l'identité dont le bouton
         * (ancêtre le plus proche) porte l'EPPN dans son attribut ATTR_EPPN.
         *
         * Construit et soumet dynamiquement un formulaire POST temporaire
         * (champs 'agent' = EPPN, 'ciamaction' = LOAD_USER_RESOURCE), retiré
         * du DOM juste après la soumission.
         *
         * @param {HTMLElement} currentelement Élément cliqué à l'intérieur du
         *                       bouton portant l'attribut ATTR_EPPN.
         * @returns {void}
         */
        function modifyuser(currentelement)
        {
            let eppn = currentelement.closest("button").getAttribute('<?php echo ATTR_EPPN; ?>');
            let form = document.createElement('form');
            form.method = 'post';
            form.action = 'identites.php';
            // form.target="_blank";
            let eppnInput = document.createElement('input');
            eppnInput.type = 'hidden';
            eppnInput.name = 'agent';
            eppnInput.id = eppnInput.name;
            eppnInput.value = eppn;
            form.appendChild(eppnInput);
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
