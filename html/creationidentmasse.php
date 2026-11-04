<?php
    // ini_set('max_execution_time', '800');
    set_time_limit(800);

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
    <title>Création en masse des identités</title>
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

    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_CREATE,true))
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

    $casversion = phpCAS::getVersion();
    $errlog = "Version de CAS.php utilisée  : " . $casversion;
    error_log(basename(__FILE__) . " " . stripAccents($errlog));

    // var_dump($_POST);
    $agentlist = '';

    $ciamAction = filter_input(INPUT_POST, 'ciamaction', FILTER_UNSAFE_RAW);
    if ($ciamAction==CREATE_USER_RESOURCE)
    {
        $agentlist = filter_input(INPUT_POST, 'agentlist', FILTER_UNSAFE_RAW);
        $searchInfos=explode( "\n", $agentlist);

        $fullSifacRoleArray = ['NOM' => [], 'DESCRIPTION' => []];
        // Si les rôles SIFAC sont gérés dans le CIAM, il faut mettre en cache les rôles SIFAC
        if (SIFACROLES_INTO_CIAM)
        {
            // On récupère tous les rôles SIFAC
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
                showMessage(MSGERROR,"Erreur lors de la récupération des rôles SIFAC : " . $resultJson->description);  
            }
        }

        // On passe en majuscule toutes les clés du tableau des noms => Tous les noms des rôles sont en majuscules
        $fullSifacRoleArray['NOM'] = array_change_key_case($fullSifacRoleArray['NOM'], CASE_UPPER);

        $responseCiamGroupList = $ciamfunctions->getListCiamGroupInfos();
        $fullCiamGroupList = [];
        if ($responseCiamGroupList->status == ResponseStatus::STATUT_KO)
        {
            // Impossible de récupérer les groupes
        }
        else
        {
            foreach($responseCiamGroupList->data as $groupData)
            {
                $ciamGroup = new CiamGroupClass();
                $ciamGroup->load($groupData);
                $fullCiamGroupList[$ciamGroup->displayname] = $ciamGroup;
            }
        }

        $uppercaseCiamRoles = [];
        if (defined('CIAMROLES'))
        {
            // On passe tous les noms des rôles CIAM en majuscule
            $uppercaseCiamRoles = array_map('strtoupper',CIAMROLES);
        }

        $excludeDomains = [];
        if (defined('EXCLUDE_USERDOMAIN'))
        {
            $excludeDomains = array_map('strtolower', EXCLUDE_USERDOMAIN);
        }

        foreach($searchInfos as $lineInfos)
        {
            $infos = explode(';',$lineInfos);
            $agent=trim($infos[0]);
            if ($agent=='')
            {
                continue;
            }

            // On supprime le critère de recherche de l'agent
            // Il ne reste donc plus que les CIAMRole et CIAMGroup (s'ils sont saisis)
            unset($infos[0]);

            $sifacCiamRoleList = [];
            $ciamGroupList = [];
            $sifacRoleNameList = [];
            $itemNotFoundText = "";
            // Pour tous les autres infos (soit CIAMRole, soit CIAMGroup) on va alimenter les tableaux correspondants
            foreach($infos as $ciamElement)
            {
                $itemFound = false;
                $ciamElement = trim($ciamElement);
                // Si le ciamElement n'est pas vide
                if ($ciamElement != '')
                {
                    // On passe en majuscule l'élément recherché
                    $ciamElement = strtoupper($ciamElement);

                    // On va chercher dans les noms des rôles CIAM (=> Donc dans le tableau CIAMROLES s'il est déclaré)
                    if (in_array($ciamElement,$uppercaseCiamRoles) and ENABLE_ROLE_CIAM)
                    {
                        $ciamElementId = array_search($ciamElement,$uppercaseCiamRoles);
                        $sifacCiamRoleList[$ciamElementId] = $ciamElementId;
                        $itemFound = true;
                    }
                    // Si l'élément n'est pas dans le CIAMROLES, on regarde s'il est dans les groupes CIAM
                    if (!$itemFound)
                    {
                        // Pour chaque rôle de la liste des groupes du CIAM (=> liste complète)
                        foreach($fullCiamGroupList as $ciamGroup)
                        {
                            // Si on trouve le nom du groupe CIAM dans la liste complète (attention : Recherche case-insensitive)
                            if (strcasecmp($ciamGroup->displayname, $ciamElement)==0)
                            {
                                // On met l'id CIAM du groupe dans la liste des groupes CIAM de l'utilisateur
                                $ciamGroupList[$ciamGroup->id] = $ciamGroup->id;
                                $itemFound = true;
                                break;
                            }
                        }
                    }
                    if (!$itemFound)
                    {
                        if (isset($fullSifacRoleArray['NOM'][$ciamElement]))
                        {
                            $sifacRoleNameList[$ciamElement] = $ciamElement;
                            $itemFound = true;
                        }
                    }

                    // Si on n'a pas trouvé l'élément dans les rôles CIAM ou les groupes CIAM ou les rôles SIFAC
                    if (!$itemFound)
                    {
                        // On construit un message afin de tracer les ciamElements qui n'ont pas de correspondances
                        if ($itemNotFoundText == '')
                        {
                            $itemNotFoundText = "Identité : $agent <br>";
                        }
                        $itemNotFoundText .= "L'élément d'autorisation $ciamElement n'a pas pu être identfié.<br>";
                    }
                }
            }

            $searchField = '';
            $displayName = '';
            $eppn = '';
            $uid = '';
            $mail = '';
            $nom = '';
            $prenom = '';
            if ($itemNotFoundText != '')
            {
                // Si on a des éléments sans correspondance => On lève une erreur et l'identité n'est pas créee
                $response = new ResponseClass();
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $itemNotFoundText . "<br>Echec de la création de l'identité.";
                $response->data = [];
            }
            else
            {
                $ldapFunctions = new LdapFunctions();
                $response = $ldapFunctions->getLDAPInfos(
                                                $agent,
                                                [   LDAP_AGENT_MAIL_ATTR,
                                                    LDAP_AGENT_NOM_ATTR,
                                                    LDAP_AGENT_PRENOM_ATTR,
                                                    LDAP_AGENT_EPPN_ATTR,
                                                    LDAP_AGENT_DISPLAYNAME_ATTR,
                                                    LDAP_AGENT_UID_ATTR
                                                ],
                                                [   LDAP_AGENT_EPPN_ATTR,
                                                    LDAP_AGENT_NOM_ATTR,
                                                    LDAP_AGENT_PRENOM_ATTR,
                                                    LDAP_AGENT_MAIL_ATTR,
                                                    LDAP_AGENT_DISPLAYNAME_ATTR,
                                                    LDAP_AGENT_UID_ATTR
                                                ],
                                                LdapSearchClass::LDAP_OPTION_ONE_RESPONSE
                                                        );
            }
            if ($response->status == ResponseStatus::STATUT_OK)
            {
                if (count($response->data)==1)
                {
                    $searchField = $agent;
                    $displayName = htmlspecialchars_decode($response->data[0][LDAP_AGENT_DISPLAYNAME_ATTR]);
                    $eppn = htmlspecialchars_decode($response->data[0][LDAP_AGENT_EPPN_ATTR]);
                    $mail = htmlspecialchars_decode($response->data[0][LDAP_AGENT_MAIL_ATTR]);
                    $nom = htmlspecialchars_decode($response->data[0][LDAP_AGENT_NOM_ATTR]);
                    $prenom = htmlspecialchars_decode($response->data[0][LDAP_AGENT_PRENOM_ATTR]);
                    $uid = htmlspecialchars_decode($response->data[0][LDAP_AGENT_UID_ATTR]);
                }
                else
                {
                    $response->description .= "<br>Critère de recherche : $agent";
                    $response->status = ResponseStatus::STATUT_KO;
                }
            }
            
            if (trim($eppn . '') != '')
            {
                $ciamActive = true;

                $ciamUser = new CiamUserClass();
                // On fixe l'eppn du CIAM à la valeur de l'EPPN passée par le formulaire
                $ciamData['eppn'] = $eppn;
                // Gestion du flag 'Active'
                $ciamData['active'] = $ciamActive;
                error_log(basename(__FILE__) . " " . stripAccents(" On va créer l'eppn : " . $eppn . "."));  

                // Si on doit associer des groupes CIAM à l'utilisateur CIAM
                if (!is_null($ciamGroupList))
                {
                    // On alimente les groupes
                    $ciamData['groups'] = $ciamGroupList;
                }
                // Si des rôles SIFAC sont associés à l'utilisateur qu'on va créer
                if (!is_null($sifacRoleNameList))
                {
                    foreach($sifacRoleNameList as $index => $sifacRoleName)
                    {
                        if (str_starts_with($sifacRoleName,'YC_') or str_starts_with($sifacRoleName,'YP_') or str_starts_with($sifacRoleName,'YS_'))
                        {
                            // $sifacRole = new SifacRoleClass();
                            // $sifacRole->load(['name' => $sifacRoleName, 'description' => $fullSifacRoleArray['NOM'][$sifacRoleName]->description]);
                            $ciamData['sifacroles'][] = $fullSifacRoleArray['NOM'][$sifacRoleName];
                        }
                    }
                }


                // On transforme ces données dans un objet ciamuserclass
                $ciamUser->load($ciamData,$fullSifacRoleArray);

                // On vérifie si l'EPPN existe déjà dans le CIAM => Vérification case-insensitive
                $ciamfunctions = new CiamFunctions();
                $response = $ciamfunctions->getCiamIdentitiesInfos();
                if ($response->status == ResponseStatus::STATUT_OK)
                {
                    $data = $response->data;
                    foreach ($data as $userInfo)
                    {
                        $tempCiamUser = new CiamUserClass();
                        $tempCiamUser->load($userInfo,$fullSifacRoleArray);

                        $userDomain = strtolower(explode('@',$tempCiamUser->eppn)[1]);
                        if (in_array($userDomain,$excludeDomains))
                        {
                            // Si le domaine de l'EPPN est un domaine exclu, alors on l'ignore => Continue
                            error_log(basename(__FILE__) . " " . stripAccents(" L'EPPN est dans un domaine exclu " . $tempCiamUser->eppn . "."));  
                            continue;
                        }

                        // Si l'eppn de l'utilisateur (=> $ldapeppn) est un des utilisateur déjà dans le CIAM ($tempCiamUser->eppn)
                        // if (strcasecmp($tempCiamUser->eppn,$ldapeppn) == 0)
                        if (strcasecmp($tempCiamUser->eppn,$eppn) == 0)
                        {
                            $response->status = ResponseStatus::STATUT_KO;
                            $response->description = "L'eppn existe déjà dans le CIAM. Impossible de le créer => " . $agent . " (" . $tempCiamUser->eppn . ").";
                            $response->data = [];
                            break;
                        }
                    }
                }

                // L'utilisateur n'existe pas (=> eppn inexistant)
                if ($response->status == ResponseStatus::STATUT_OK)
                {
                    // Si on a défini les CIAMroles => On les sauvegarde dans le CIAM
                    if (!is_null($sifacCiamRoleList))
                    {
                        $ciamUser->ciamroles = $sifacCiamRoleList;
                    }

                    $ldapAgentObj = new LdapUserClass();
                    $ldapData = [   'name' => $nom,
                                    'firstname' => $prenom,
                                    'uid' => $uid,
                                    // 'eppn' => $ldapeppn,
                                    'eppn' => $eppn,
                                    'displayname' => $displayName,
                                    'mail' => $mail
                                ];
                    $ldapAgentObj->load($ldapData);

                    $response = $ciamfunctions->createCiamUserResource($ldapAgentObj, $ciamUser);

                    if ($response->status == ResponseStatus::STATUT_OK)
                    {
                        // On initialise l'id de l'utilisateur CIAM qu'on vient de créer
                        $ciamUser->id = json_decode(reset($response->data),true)['id'];

                        // On va ajouter l'utilisateur CIAM dans les groupes CIAM
                        $responseCiamGroupList = $ciamfunctions->getListCiamGroupInfos();
                        if ($responseCiamGroupList->status == ResponseStatus::STATUT_KO)
                        {
                            $response = $responseCiamGroupList;
                        }
                        else
                        {
                            foreach($responseCiamGroupList->data as $groupData)
                            {
                                $ciamGroup = new CiamGroupClass();
                                $ciamGroup->load($groupData);
                                if (in_array($ciamGroup->id,$ciamUser->ciamgroups))
                                {
                                    $ciamGroup->addMember($ciamUser->id);
                                    // var_dump(__FUNCTION__ . " On va enregistrer la modification du groupe " . $ciamGroup->displayname);
                                    $ciamfunctions->modifyCiamGroupInfos($ciamGroup);
                                }
                            }
                        }
                    }
                }

            }
            if ($response->status == ResponseStatus::STATUT_OK)
            {
                showMessage(MSGINFO, "L'agent $agent a été créé avec l'identifiant : " . $ciamUser->id);
            }
            else
            {
                showMessage(MSGERROR, $response->description);
            }
        }
    }


    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_CREATE,true))
    {
        error_log(basename(__FILE__) . " " . stripAccents(" L'utilisateur CAS $casUid n'est plus autorisé."));  
        echo '<script type="text/javascript">';
        echo 'window.location.href="index.php";';
        echo '</script>';
        echo '<noscript>';
        echo '<meta http-equiv="refresh" content="0;url=index.php" />';
        echo '</noscript>';        
        // echo "Vous n'êtes pas autorisé à accéder à ce service.";
        exit();
    }

?>
<form method='post'>
    Indiquez les identifiants des utilisateurs à créer.<br>
    Un identifiant par ligne (adresse mail, eppn, uid).<br>
    Chaque identifiant peut être suivi des rôles ou des groupes CIAM (séparateur : point virgule).<br>
    <label class='bold '>L'utilisation du 'displayName', du nom et/ou du prénom est possible.<br>
    Cependant, elle est déconseillée car pas forcément assez précise (risque de doublons).<br>
    </label>
    <br>
    <label class='bold underline'>Exemples : </label><br>
    ident1;SIFAC_PREPROD_USER;SIFAC_INTEGRATION_ACCESS;SIFAC_PAIE_ACCESS;SIFAC_STAGIAIRES_USER;SIFAC_PAIE_USER<br>
    ident2;SIFAC_FORMATEUR_2_USER;SIFAC_FORMATION_USER;SCIM_PRINCIPAL<br>
    <br>
<!--
    <button class='sifacbouton sifacvalidebouton' name='createidbutton' id='createidbutton' type='button' onclick='askConfirmBeforeSave(this,"reloadPage");' <?php echo ATTR_MODALHEADER . '="Confirmation"'; ?> <?php echo ATTR_ACTION . '="' . CREATE_USER_RESOURCE . '"'; ?> <?php echo ATTR_LIBELLE . '="Confirmez-vous la création en masse de ces utilisateurs ?"'; ?> >Créer</button>
-->
    <button class='sifacbouton sifacvalidebouton' name='createidbutton' id='createidbutton' type='button' onclick='askConfirmBeforeSave(this,"massIdentityCreation");' <?php echo ATTR_MODALHEADER . '="Confirmation"'; ?> <?php echo ATTR_ACTION . '="' . CREATE_USER_RESOURCE . '"'; ?> <?php echo ATTR_LIBELLE . '="Confirmez-vous la création en masse de ces utilisateurs ?"'; ?> >Créer</button>
    <br>
    <textarea name='agentlist' id='agentlist' cols='200' rows='30' style='resize: none;' wrap='off'><?php echo htmlspecialchars($agentlist);?></textarea>
    <br>
</form>
<br>
<div id='responsezone' name='responsezone'></div>
<br>


<script>
    function massIdentityCreation()
    {
        let inputHTMLTextArea = document.getElementById('agentlist');
        if (inputHTMLTextArea)
        {
<?php
            // Si la constante n'est pas définie, on l'initialise à chaine vide.
            if (!defined('WS_SERVER_URL'))
            {
                define('WS_SERVER_URL', '');
            }
?>
            let fullWSURL = "<?php echo WS_SERVER_URL ?>/internalFunctionsWS.php";
            let sqlMode = "<?php echo SQLMODE->value ?>";

            // On vide la zone de réponse des WS
            let outputDiv = document.getElementById("responsezone");
            outputDiv.textContent = '';

            let inputDataArray = inputHTMLTextArea.value.split(/\r?\n/);

            inputDataArray.forEach(inputData => {
                if (inputData.trim() != '')
                {
                    createIdentity(fullWSURL, sqlMode, inputData, outputDiv);
                }
            });
        }
    }

</script>

</body>
</html>