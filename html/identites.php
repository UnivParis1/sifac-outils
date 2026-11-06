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
    <title>Gestion des identités</title>
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

    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY, true))
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

    $fullSifacRoleArray = ['NOM' => [], 'DESCRIPTION' => []];
    // Si les rôles sont gérés dans le CIAM, il faut mettre en cache les rôles SIFAC
    // Sinon les rôles sont gérés directement dans SIFAC, donc on récupère le libellé 
    // des rôles directement en chargeant les rôles depuis SIFAC
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

    $searchField = '';
    $listeLdapAgent = [];
    $ldapSearchAgentObj = new LdapUserClass();

    if (count($_POST) > 0)
    {

        $ciamAction = filter_input(INPUT_POST, 'ciamaction', FILTER_UNSAFE_RAW);
        // Si on ne demande pas à charger un agent, on doit prendre le searfield input (s'il existe)
        if ($ciamAction!=LOAD_USER_RESOURCE)
        {
            $searchField = filter_input(INPUT_POST, 'searchfield', FILTER_UNSAFE_RAW);
        }
        // Si le searchfield n'existe pas ou est vide, on récupère le champ agent
        if (is_null($searchField) or ($searchField===false) or (trim($searchField) == ''))
        {
            $searchField = filter_input(INPUT_POST, 'agent', FILTER_UNSAFE_RAW);
        }
        $ldapName = filter_input(INPUT_POST, 'ldapname', FILTER_UNSAFE_RAW);
        $ldapFirstname = filter_input(INPUT_POST, 'ldapfirstname', FILTER_UNSAFE_RAW);
        $ldapUid = filter_input(INPUT_POST, 'ldapuid', FILTER_UNSAFE_RAW);
        $ldapDisplayName = filter_input(INPUT_POST, 'ldapdisplayname', FILTER_UNSAFE_RAW);

        $ciamGroupList = filter_input(INPUT_POST, 'ciamgroup', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
        if (!is_array($ciamGroupList))
        {
            $ciamGroupList = null;
        }
        $sifacRoleNameList = filter_input(INPUT_POST, 'sifacrolename', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
        if (!is_array($sifacRoleNameList))
        {
            $sifacRoleNameList = null;
        }
        $checkSifacRoleList = filter_input(INPUT_POST, 'checksifacrole', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
        if (!is_array($checkSifacRoleList))
        {
            $checkSifacRoleList = null;
        }
        $sifacDescriptionRoleList = filter_input(INPUT_POST, 'sifacdescriptionrole', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
        if (!is_array($sifacDescriptionRoleList))
        {
            $sifacDescriptionRoleList = null;
        }
        $sifacCiamRoleList = filter_input(INPUT_POST, 'ciamroles', FILTER_UNSAFE_RAW, FILTER_REQUIRE_ARRAY);
        // error_log(basename(__FILE__) . stripAccents(" Récupération de la liste des ciamrolelist => " . print_r($sifacCiamRoleList,true)));
        if (!is_array($sifacCiamRoleList))
        {
            error_log(basename(__FILE__) . stripAccents(" ciamrolelist n'est pas un tableau => null"));
            $sifacCiamRoleList = null;
        }

        $ldapMail = filter_input(INPUT_POST, 'ldapmail', FILTER_UNSAFE_RAW);
        if (!filter_var($ldapMail, FILTER_VALIDATE_EMAIL)) 
        {
            $ldapMail = null;
        }
        $eppn = filter_input(INPUT_POST, 'ldapeppn', FILTER_UNSAFE_RAW);
        if (!filter_var($eppn, FILTER_VALIDATE_EMAIL)) 
        {
            $eppn = null;
        }
        $ciamId = filter_input(INPUT_POST, 'ciamid', FILTER_UNSAFE_RAW);
        // /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/
        // /^[0-9a-fA-F-]+$/
        if (!filter_var($ciamId, FILTER_VALIDATE_REGEXP,["options" => ["regexp"=>"/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/"]])) 
        {
            $ciamId = null;
        }
        $ciamActive = filter_input(INPUT_POST, 'ciamactive', FILTER_UNSAFE_RAW);
        if (!filter_var($ciamActive, FILTER_VALIDATE_BOOL)) 
        {
            $ciamActive = false;
        }

        if (!is_null($ciamAction))
        {
            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_KO;
            switch ($ciamAction)
            {
                case MODIFY_USER_RESOURCE:
                    $ciamUser = new CiamUserClass();
                    // On fixe l'eppn du CIAM à la valeur de l'EPPN passée par le formulaire
                    if (is_null($eppn) or (is_null($ciamId)))
                    {
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = "Le format de l'EPPN ou du CIAMID n'est pas correct.";
                        break;
                    }

                    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_ADMIN))
                    {
                        $ldapFunctions = new LdapFunctions();
                        $ldapResponse = $ldapFunctions->getLDAPInfos($eppn,
                                                                    [LDAP_AGENT_EPPN_ATTR],
                                                                    [LDAP_AGENT_UID_ATTR],
                                                                    LdapSearchClass::LDAP_OPTION_ONE_RESPONSE
                                                                    );
                        if ($ldapResponse->status == ResponseStatus::STATUT_KO or count($ldapResponse->data)==0)
                        {
                            $response = $ldapResponse;
                            break;
                        }
                        else
                        {
                            if (strtolower($ldapResponse->data[0][LDAP_AGENT_UID_ATTR]) == strtolower($casUid))
                            {
                                $response->status = ResponseStatus::STATUT_KO;
                                $response->description = "Vous ne pouvez pas modifier votre propre identité.";
                                break;
                            }
                        }
                    }
                    $ciamData['eppn'] = $eppn;
                    $ciamData['id'] = $ciamId;

                    // On vide le tableau des groupes CIAM de l'utilisateur
                    $ciamData['groups'] = [];

                    // On recharge les groupes CIAM qui ont été marqués
                    if (!is_null($ciamGroupList))
                    {
                        $ciamData['groups'] = $ciamGroupList;
                    }

                    // Attention : On ne charge ici que les informations minimale pour modifier les groupes CIAM
                    // On a l'Id CIAM de l'utilisateur, son eppn et ses groupes CIAM
                    $ciamUser->load($ciamData,$fullSifacRoleArray);

                    // On va supprimer/ajouter l'utilisateur CIAM dans les groupes CIAM
                    $responseCiamGroupList = $ciamfunctions->getListCiamGroupInfos();
                    if ($responseCiamGroupList->status == ResponseStatus::STATUT_KO)
                    {
                        $response = $responseCiamGroupList;
                        break;
                    }
                    else
                    {
                        foreach($responseCiamGroupList->data as $groupData)
                        {
                            $ciamGroup = new CiamGroupClass();
                            $ciamGroup->load($groupData);
                            // On regarde si l'id du groupe CIAM est dans la liste de groupes de l'utilisateur
                            if (in_array($ciamGroup->id,$ciamUser->ciamgroups))
                            {
                                // Alors on l'ajoute
                                $ciamGroup->addMember($ciamUser->id);
                            }
                            else
                            {
                                // Sinon on a supprimé le groupe pour l'utilisateur
                                $ciamGroup->removeMember($ciamUser->id);
                            }
                            // Si le groupe a été modifié (ajout/suppression de membre)
                            if ($ciamGroup->isModified())
                            {
                                // var_dump(__FUNCTION__ . " On va enregistrer la modification du groupe " . $ciamGroup->displayname);
                                // On modifie les informations du groupe CIAM
                                $ciamfunctions->modifyCiamGroupInfos($ciamGroup);
                            }
                        }
                    }
                    // On recharge l'utilisateur après les modifications
                    // Cette fois, on recharge complètement l'utilisateur avec les informations complètes du CIAM
                    $response = $ciamfunctions->getCiamIdentitiesInfosFromCiamId($ciamId);
                    if ($response->status == ResponseStatus::STATUT_OK and count($response->data)==1)
                    {
                        $ciamUser = new CiamUserClass();
                        $ciamUser->load($response->data[0],$fullSifacRoleArray);
                        // On met à jour l'activation/désactivation de l'utilisateur dans le CIAM
                        $ciamUser->active = convertvaluetobool($ciamActive);

                        // Si la collection de rôles CIAM est définie => On peut modifier les rôles CIAM
                        // Sinon, on n'y touche pas -> Aucune modification
                        if (defined('CIAMROLES') and ENABLE_ROLE_CIAM)
                        {
                            error_log(basename(__FILE__) . stripAccents(" La constante CIAMROLES est bien définie."));

                            // On supprime les roles CIAM connus dans le tableau des rôles CIAM de l'utilisateur
                            // error_log(basename(__FILE__) . stripAccents(" La liste des rôles CIAM de l'utilisateur est : "). print_r($ciamUser->ciamroles,true));
                            foreach(CIAMROLES as $roleId => $roleDescription)
                            {
                                if (($index = array_search($roleId,$ciamUser->ciamroles)) !== false)
                                {
                                    unset($ciamUser->ciamroles[$index]);
                                }
                            }
                            // error_log(basename(__FILE__) . stripAccents(" Après nettoyage, la liste des rôles CIAM de l'utilisateur est : "). print_r($ciamUser->ciamroles,true));

                            // Si on a défini les CIAMroles => On les sauvegarde dans le CIAM
                            // On recharge les rôles CIAM qui ont été marqués
                            if (!is_null($sifacCiamRoleList))
                            {

                                // error_log(basename(__FILE__) . stripAccents(" Rappel Liste des roles CIAM sifacciamrolelist : "). print_r($sifacCiamRoleList,true));
                                // error_log(basename(__FILE__) . stripAccents(" On va fusionner ciamuser->ciamroles et sifacciamrolelist."));
                                $ciamUser->ciamroles = array_merge($ciamUser->ciamroles,$sifacCiamRoleList);
                            }
                            else
                            {
                                error_log(basename(__FILE__) . stripAccents(" sifacciamrolelist est null => Pas de réaffectation"));
                            }
                            // error_log(basename(__FILE__) . stripAccents(" Au final, la liste des roles CIAM de l'utilisateur est : "). print_r($ciamUser->ciamroles,true));
                        }
                        else
                        {
                            error_log(basename(__FILE__) . stripAccents(" La constante CIAMROLES n'est pas définie => On ne fait rien"));
                        }

                        // On met à jour les rôles SIFAC de l'utilisateur dans le CIAM si le mode SIFACROLES_INTO_CIAM est actif
                        // Pour chaque rôle SIFAC déjà enregistrés dans le CIAM
                        foreach($ciamUser->sifacroles as $key => $sifacRole)
                        {
                            // Si c'est un rôle YS, YP ou YC on le supprime car ils sont gérés par l'application
                            if (str_starts_with($sifacRole->name,'YC_') or str_starts_with($sifacRole->name,'YP_') or str_starts_with($sifacRole->name,'YS_'))
                            {
                                // On supprime le rôle SIFAC
                                unset($ciamUser->sifacroles[$key]);
                            }
                        }
                        // Pour chaque rôle SIFAC postés depuis le formulaire
                        foreach((array)$sifacRoleNameList as $index => $sifacRoleName)
                        {
                            // Si c'est un rôle YS, YC ou YP
                            if (str_starts_with($sifacRoleName,'YC_') or str_starts_with($sifacRoleName,'YP_') or str_starts_with($sifacRoleName,'YS_'))
                            {
                                // Si le rôle n'est pas marqué comme à supprimé (=> Donc dans le tableau $checkSifacRoleList ou que sa valeur n'est pas 'on')
                                if (!isset($checkSifacRoleList[$index]))
                                {
                                    // On charge le rôle SIFAC
                                    $sifacRole = new SifacRoleClass();
                                    $sifacRole->load(['name' => $sifacRoleName, 'description' => $sifacDescriptionRoleList[$index]]);
                                    // On l'ajoute à l'utilisateur CIAM
                                    $ciamUser->sifacroles[] = $sifacRole;
                                }
                            }
                        }
                    }
                    else
                    {
                        showMessage(MSGERROR, "Impossible de charger l'utilisateur à partir de son identifiant => " . $response->description);
                        $response->status = ResponseStatus::STATUT_KO;
                        break;
                    }

                    // var_dump($ciamUser);
                    // Si on n'a pas eu de problème => On va pouvoir sauvegarder les modifications de l'utilisateur CIAM.
                    if ($response->status == ResponseStatus::STATUT_OK)
                    {
                        $ldapAgentObj = new LdapUserClass();
                        $ldapData = [   'name' => $ldapName,
                                        'firstname' => $ldapFirstname,
                                        // 'eppn' => $ldapeppn,
                                        'eppn' => $eppn,
                                        'uid' => $ldapUid,
                                        'displayname' => $ldapDisplayName,
                                        'mail' => $ldapMail
                                    ];
                        $ldapAgentObj->load($ldapData);
                        // var_dump($ldapAgentObj);
                        $ciamfunctions = new CiamFunctions();
                        $response = $ciamfunctions->modifyCiamUserResource($ldapAgentObj, $ciamUser);
                    }
                    break;
                case DELETE_USER_RESOURCE:
                    $ciamUser = new CiamUserClass();
                    // On charge les informations de l'utilisateur depuis le CIAM
                    $response = $ciamfunctions->getCiamIdentitiesInfosFromCiamId($ciamId);
                    if ($response->status == ResponseStatus::STATUT_KO or count($response->data)!=1)
                    {
                        $response->description = "Impossible de récupérer l'utilisateur (" . $ciamId . ") pour la suppression : " . $response->description;
                        showMessage(MSGERROR, $response->description);
                        break;
                    }
                    $ciamUser->load($response->data[0],$fullSifacRoleArray);
                    $ciamfunctions = new CiamFunctions();
                    // On supprime l'utilisateur du CIAM
                    $response = $ciamfunctions->deleteCiamUserResource($ciamUser);
                    break;
                case CREATE_USER_RESOURCE:
                    $ciamUser = new CiamUserClass();

                    // On crée les données qui seront insérées dans le CIAM (=> on crée un tableau ciamdata)
                    if (is_null($eppn))
                    {
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = "Le format de l'EPPN n'est pas correct ou non renseigné.";
                        break;
                    }

                    // On fixe l'eppn du CIAM à la valeur de l'EPPN passée par le formulaire
                    $ciamData['eppn'] = $eppn;
                    // Gestion du flag 'Active'
                    $ciamData['active'] = $ciamActive;

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
                                $sifacRole = new SifacRoleClass();
                                $sifacRole->load(['name' => $sifacRoleName, 'description' => $sifacDescriptionRoleList[$index]]);
                                $ciamData['sifacroles'][] = $sifacRole;
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
                            // Si l'eppn de l'utilisateur (=> $ldapeppn) est un des utilisateur déjà dans le CIAM ($tempCiamUser->eppn)
                            // if (strcasecmp($tempCiamUser->eppn,$ldapeppn) == 0)
                            if (strcasecmp($tempCiamUser->eppn,$eppn) == 0)
                            {
                                $response->status = ResponseStatus::STATUT_KO;
                                $response->description = "L'eppn existe déjà dans le CIAM. Impossible de le créer => " . $tempCiamUser->displayname . " (" . $tempCiamUser->externalid . ").";
                                $response->data = [];
                                break;
                            }
                        }
                    }

                    // L'utilisateur n'existe pas (=> eppn inexistant)
                    if ($response->status == ResponseStatus::STATUT_OK)
                    {
                        // Si on a défini les CIAMroles => On les sauvegarde dans le CIAM
                        if (!is_null($sifacCiamRoleList) and ENABLE_ROLE_CIAM)
                        {
                            $ciamUser->ciamroles = $sifacCiamRoleList;
                        }

                        $ldapAgentObj = new LdapUserClass();
                        $ldapData = [   'name' => $ldapName,
                                        'firstname' => $ldapFirstname,
                                        'uid' => $ldapUid,
                                        // 'eppn' => $ldapeppn,
                                        'eppn' => $eppn,
                                        'displayname' => $ldapDisplayName,
                                        'mail' => $ldapMail
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

                    break;
                case LOAD_USER_RESOURCE:
                    // Si on a soumis l'id de l'utilisateur CIAM (par exemple depuis listegroupes.php)
                    if (!is_null($ciamId))
                    {
                        // On cherche dans le CIAM les informations de cet utilisateur
                        $ciamfunctions = new CiamFunctions();
                        $response = $ciamfunctions->getCiamIdentitiesInfosFromCiamId($ciamId);
                        if ($response->status == ResponseStatus::STATUT_KO or count($response->data)!=1)
                        {
                            $response->description = "Impossible de récupérer l'utilisateur (" . $ciamId . ") pour l'affichage : " . $response->description;
                            showMessage(MSGERROR, $response->description);
                            break;
                        }
                        $ciamUser = new CiamUserClass();
                        $ciamUser->load($response->data[0],$fullSifacRoleArray);
                        // On fixe le 'agent' avec l'eppn => On va donc charger l'agent à partir de son EPPN
                        // On aurait pu aussi utiliser le mail mais moins fiable
                        $searchField = $ciamUser->eppn;
                    }
                    // Nothing to do => All ok
                    $response->status = ResponseStatus::STATUT_OK;
                    break;
                default:
                    $response->description = "L'action " . $ciamAction . " n'est pas définie.";
                    showMessage(MSGERROR, $response->description);
                    break;
            }
            if ($response->status == ResponseStatus::STATUT_OK)
            {
                $ldapFunctions = new LdapFunctions();

                $response = $ldapFunctions->getLDAPInfos(
                                                $searchField,
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
                                                // LdapSearchClass::LDAP_OPTION_ONE_RESPONSE
                                                LdapSearchClass::LDAP_OPTION_ALL_RESPONSES
                                                        );
                if ($response->status == ResponseStatus::STATUT_OK)
                {
                    if (count($response->data)==1)
                    {
                        $ldapSearchAgentObj = new LdapUserClass();
                        $ldapSearchAgentObj->load($response->data[0]);
                    }
                    else if (count($response->data)>1)
                    {
                        showMessage(MSGWARNING, "Il y a plusieurs agents correspondant à votre sélection.<br>Veuillez choisir l’agent souhaité.");
                        foreach($response->data as $ldapData)
                        {
                            $ldapTempAgentObj = new LdapUserClass();
                            $ldapTempAgentObj->load($ldapData);
                            $listeLdapAgent[$ldapTempAgentObj->eppn] = $ldapTempAgentObj;
                        }
                    }
                    else
                    {
                        showMessage(MSGERROR, $response->description . "<br>Critère de recherche : $searchField");
                    }
                }
            }

            if ($response->status == ResponseStatus::STATUT_KO)
            {
                showMessage(MSGERROR, "Une erreur s'est produite : " . $response->description);
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
            }
            else
            {
                // var_dump("Tout s'est bien passé !");
            }
        }
    }

    if (!cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY, true))
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

?>
    <form method='post'>
        Personne à rechercher : <br>
        <input type='hidden' id='searchfield' name='searchfield' value="<?php echo htmlspecialchars($searchField); ?>"/>
        <input id='agent' name='agent' placeholder='Nom et/ou prenom' value='<?php echo htmlspecialchars($searchField); ?>' size=40 />

<?php
    if (LDAP_MODE == LdapSearchClass::LDAP_MODE_WSGROUPS)
    {
?>
        <br>
        <br>
        <script>
            if ($("#agent"))
            {
                $("#agent").autocompleteUser('<?php echo WSGROUPURL;?>/searchUserCAS', { select: selectItem, disableEnterKey: true, wantedAttr: 'eduPersonPrincipalName,sn,givenName', wsParams: { filter_eduPersonAffiliation: 'staff|teacher' }} );
            }
        </script>
<?php
    }
    else
    {
        if (cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY))
        {
?>
            <button id='submitselect' name='submitselect' class='sifacbouton sifacdocumentbouton' type='button' <?php echo ATTR_ACTION . '="' . LOAD_USER_RESOURCE . '"'; ?> onclick="clearPageData(); reloadPage(this);">Valider</button>
            <br>
            <br>
<?php
            if (count($listeLdapAgent)>0)
            {
?>
                <table class="tableausimple" id='tableldapselect' name='tableldapselect'>
                    <thead>
                        <tr class="entete" >
                            <th class='titresimple cursorpointer'>Nom <span class='sortindicator'> </span></th>
                            <th class='titresimple cursorpointer'>Prénom <span class='sortindicator'> </span></th>
                            <th class='titresimple cursorpointer'>EPPN <span class='sortindicator'> </span></th>
                            <th class='titresimple cursorpointer'>Mail <span class='sortindicator'> </span></th>
                            <th class='titresimple '>Sélection</th>
                        </tr>
                    </thead>
                    <tbody>

<?php
                foreach($listeLdapAgent as $ldapAgentObj)
                {
?>
                        <tr class=''>
                            <td class="cellulesimple <?php echo LDAPNAMECELLULE; ?>"><?php echo $ldapAgentObj->name; ?></td>
                            <td class="cellulesimple <?php echo LDAPFIRSTNAMECELLULE; ?>"><?php echo $ldapAgentObj->firstname; ?></td>
                            <td class="cellulesimple <?php echo LDAPEPPNCELLULE; ?>"><?php echo $ldapAgentObj->eppn; ?></td>
                            <td class="cellulesimple <?php echo LDAPMAILCELLULE; ?>"><?php echo $ldapAgentObj->mail; ?></td>
                            <td class="cellulesimple"><button type='button' class='sifacbouton sifacdocumentbouton'  <?php echo ATTR_ACTION . '="' . LOAD_USER_RESOURCE . '"'; ?> onclick="document.getElementById('agent').value=getCellValue(this.closest('tr'), '<?php echo LDAPEPPNCELLULE; ?>'); reloadPage(this);">Sélection</button></td>
                        </tr>
<?php
                }
?>
                    </tbody>
                </table>
                <script>
                    let table = document.querySelector('#tableldapselect');
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
                <br>
                <br>
<?php
            }
        }
    }
?>
        <div id='divldapinfouser' name='divldapinfouser'>
            <table class="tableausimple" id='tableldapinfouser' name='tableldapinfouser'>
                <thead>
                    <tr class="entete" >
                        <th class="titresimple" colspan="4">Informations LDAP sur l'agent</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class=''>
                        <!-- Important : tabindex="-1" => Pas de sélection par tabulation -->
                        <td class="cellulesimple">Nom :</td><td class="cellulesimple width300 <?php echo LDAPNAMECELLULE; ?>"><input type='text' readonly tabindex="-1" class='noborder width300' id='ldapname' name='ldapname' value='<?php echo $ldapSearchAgentObj->name; ?>'/></td>
                        <td class="cellulesimple">Prénom :</td><td class="cellulesimple width300 <?php echo LDAPFIRSTNAMECELLULE; ?>"><input type='text' readonly tabindex="-1" class='noborder width300' id='ldapfirstname' name='ldapfirstname' value='<?php echo $ldapSearchAgentObj->firstname; ?>'/></td>
                    </tr>
                    <tr class=''>
                        <td class="cellulesimple">Mail :</td><td class="cellulesimple width300 <?php echo LDAPMAILCELLULE; ?>"><input type='text' readonly tabindex="-1" class='noborder width300' id='ldapmail' name='ldapmail' value='<?php echo $ldapSearchAgentObj->mail; ?>'/></td>
                        <td class="cellulesimple">Display Name :</td><td class="cellulesimple width300 <?php echo LDAPDISPLAYNAMECELLULE; ?>"><input type='text' readonly class='noborder width300' id='ldapdisplayname' name='ldapdisplayname' value='<?php echo $ldapSearchAgentObj->displayname; ?>'/></td>
                    </tr>
                    <tr class=''>
                        <td class="cellulesimple">EPPN :</td><td class="cellulesimple width300 <?php echo LDAPEPPNCELLULE; ?>"><input type='text' readonly tabindex="-1" class='noborder width300' id='ldapeppn' name='ldapeppn' value='<?php echo $ldapSearchAgentObj->eppn; ?>'/></td>
                        <td class="cellulesimple">UID :</td><td class="cellulesimple width300 <?php echo LDAPUIDCELLULE; ?>"> <input type='text' readonly tabindex="-1" class='noborder width300' id='ldapuid' name='ldapuid' value='<?php echo $ldapSearchAgentObj->uid; ?>'/></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <br>
    <br>
<?php
    // if ($eppn <> '')
    if (trim($ldapSearchAgentObj->eppn) <> '')
    {
        // $ldapuser = new LdapUserClass();
        // $ldapuser->eppn = $eppn;

        $ciamfunctions = new CiamFunctions();
        $resultJson = $ciamfunctions->getCiamIdentitiesInfosFromEppn($ldapSearchAgentObj->eppn);
        $ciamUser = new CiamUserClass();
        if (count($resultJson->data)==1)
        {
            $ciamUser->load($resultJson->data[0],$fullSifacRoleArray);
        }

        if ($resultJson->status == ResponseStatus::STATUT_OK)
        {
            echo "<div id='divciaminfouser' name='divciaminfouser' class='divciaminfouser'>" . PHP_EOL;
            echo $ciamUser->identityHtml();
            echo "</div>" . PHP_EOL;
            echo "<br>";
        }
        else
        {
            showMessage(MSGERROR, "Erreur lors de la récupération des informations du CIAM : <br>" . $resultJson->description);
        }

        $formextraclass = '';
        if (trim($ciamUser->id) != '')
        {
            if (cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY))
            {
?>
                <button class="sifacbouton sifacmodifierbouton" name="modifyidbutton" id="modifyidbutton" type="button" onclick="askConfirmBeforeSave(this,'reloadPage');" <?php echo ATTR_MODALHEADER . '="Confirmation"'; ?> <?php echo ATTR_ACTION . '="' . MODIFY_USER_RESOURCE . '"'; ?> <?php echo ATTR_LIBELLE . '="Confirmez-vous la modification de ' . $ldapSearchAgentObj->displayname . ' ?"'; ?> >Modifier</button>
<?php
            }
            if (cas_user_check_access($casUid, CIAMGROUPACCESS_ADMIN))
            {
?>
                <button class="sifacbouton sifacsupprbouton" name="deleteidbutton" id="deleteidbutton" type="button" onclick="askConfirmBeforeSave(this,'reloadPage');" <?php echo ATTR_MODALHEADER . '="Confirmation"'; ?> <?php echo ATTR_ACTION . '="' . DELETE_USER_RESOURCE . '"'; ?> <?php echo ATTR_LIBELLE . '="Confirmez-vous la suppression de ' . $ldapSearchAgentObj->displayname . ' ?"'; ?> >Supprimer</button>
<?php
            }
        }
        else
        {
            if (cas_user_check_access($casUid, CIAMGROUPACCESS_CREATE))
            {
?>
                <button class="sifacbouton sifacvalidebouton" name="createidbutton" id="createidbutton" type="button" onclick="askConfirmBeforeSave(this,'reloadPage');" <?php echo ATTR_MODALHEADER . '="Confirmation"'; ?> <?php echo ATTR_ACTION . '="' . CREATE_USER_RESOURCE . '"'; ?> <?php echo ATTR_LIBELLE . '="Confirmez-vous la création de ' . $ldapSearchAgentObj->displayname . ' ?"'; ?> >Créer</button>
<?php
            }
        }
?>
        <br>
        <!-- Liste des groupes d'accès CIAM : -->
        <br>
<?php
        $ciamReturn = $ciamUser->ciamRolesHtml();
        if (trim($ciamReturn) == "")
        {
            showMessage(MSGERROR, "Impossible de récupérer les groupes CIAM.");
        }
        else
        {
            echo $ciamReturn;
        }

        $sifacUser = new SifacUserClass();
        $sifacFunctions = new SifacFunctions();
        $resultJson = $sifacFunctions->getSifacLoginFromEppn($ldapSearchAgentObj,SQLMODE);
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
            if ($resultJson->status == ResponseStatus::STATUT_OK and count($resultJson->data)==1)
            {
                $sifacUser = $resultJson->data[$sifacLogin];
                echo $sifacUser->sifacInfosHtml();
            }
            else
            {
                showMessage(MSGERROR,"Impossible de récupérer les informations de l'utilisateur SIFAC : " . $resultJson->description);
            }
        }
        else
        {
            showMessage(MSGWARNING,"Impossible de récupérer le login SIFAC : " . $resultJson->description);  
        }

        $sifacRoleArray = [];
        // Si les rôles sont gérés dans le CIAM
        if (SIFACROLES_INTO_CIAM)
        {
            if (count($ciamUser->sifacroles) > 0)
            {
                $sifacRoleArray = $ciamUser->sifacroles;
            }
        }
        else
        {
            if (isset($sifacUser) and trim($sifacUser->login . '') != '')
            {
                $resultJson = $sifacFunctions->getSifacRoleByUser($sifacUser,SQLMODE);
                // var_dump($resultJson);
                if ($resultJson->status == ResponseStatus::STATUT_OK)
                {
                    $sifacRoleArray = $resultJson->data;
                }
                else
                {
                    showMessage(MSGERROR,"Erreur lors de la récupération des rôles SIFAC pour l'utilisateur : " . $resultJson->description);  
                }
            }
            else
            {
                showMessage(MSGWARNING,"Pas de récupération de rôles dans SIFAC : Utilisateur SIFAC inconnu.");  
            }
        }
?>
        <div>
<?php
        if (SIFACROLES_INTO_CIAM)
        {
            // Si la constante n'est pas définie, on l'initialise à chaine vide.
            if (!defined('WS_SERVER_URL'))
            {
                define('WS_SERVER_URL', '');
            }
?>
            <!-- On va chercher via le WS l'ensemble des rôles METIER et des rôles PERIMETRE pour les charger dans le tableau listeCompleteSifacRole -->
            <script>
                const ROLEMETIER = 'ROLEMETIER';
                const ROLEPERIMETRE = 'ROLEPERIMETRE';
                var listeCompleteSifacRole = {ROLEMETIER : {}, ROLEPERIMETRE : {}}
                getSifacRoles('<?php echo WS_SERVER_URL . '/internalFunctionsWS.php'; ?>','<?php echo SQLMODE->value; ?>', listeCompleteSifacRole);
            </script>

            Liste des rôles SIFAC dans le CIAM pour l'utilisateur <?php echo $ldapSearchAgentObj->displayname; ?> <br>
            Les lignes <label class=nouvelleligne>en bleue</label> n'ont pas été sauvegardées dans le CIAM.<br>
            <table class="tableausimple" id="userroletable" name="userroletable">
                <thead>
                    <tr class='entete'>
                        <th class='titresimple cursorpointer'>Nom du rôle <span class='sortindicator'> </span></th>
                        <th class='titresimple cursorpointer'>Description <span class='sortindicator'> </span></th>
                        <th class='titresimple cursorpointer hidden'>Début validité <span class='sortindicator'> </span></th>
                        <th class='titresimple cursorpointer hidden'>Fin validité <span class='sortindicator'> </span></th>
                        <th class='titresimple cursordefault'>Suppression</th>
                    </tr>
                </thead>
                <tbody>
<?php
                foreach($sifacRoleArray as $index => $sifacRole)
                {
                    // Si l'affectation n'est pas directe, on n'affiche pas le rôle car il est hérité et on ne peut pas modifier une affectation indirecte
                    $extraClass = '';
                    if ($sifacRole->affectation != SifacRoleClass::SIFAC_ROLE_DIRECT)
                    {
                        // On passe au rôle suivant
                        continue;
                    }
                    if ($sifacRole->status == SifacRoleClass ::NOT_SAVE_TO_SIFAC)
                    {
                        $extraClass = $extraClass . " nouvelleligne ";
                    }
                    echo "<tr class='lignerole $extraClass ' " . ATTR_INDEX . "='$index' >" . PHP_EOL;
                    $roleId = $sifacRole->name . '_' . $sifacRole->startdate . '_' . $sifacRole->enddate;
                    echo "  <td class='cellulesimple " . SIFACNOMROLECELLULE . "' id='$roleId'>";
                    $nameRoleId = "sifacrolename[$index]";
                    echo "<input type='text' readonly tabindex='-1' class='noborder width300' id='$nameRoleId' name='$nameRoleId' value='$sifacRole->name'/>";
                    echo "  </td>" . PHP_EOL;
                    echo "  <td class='cellulesimple " . SIFACDESCRIPTIONROLECELLULE  . "'>";
                    if (trim($sifacRole->description) == '')
                    {
                        if (isset($fullSifacRoleArray['NOM'][$sifacRole->name]))
                        {
                            $sifacRole->description = $fullSifacRoleArray['NOM'][$sifacRole->name]->description;
                        }
                    }
                    $descrroleid = "sifacdescriptionrole[$index]";
                    echo "<input type='text' readonly tabindex='-1' class='noborder width300' id='$descrroleid' name='$descrroleid' value='" . htmlspecialchars($sifacRole->description) . "'/>";
                    echo "  </td>" . PHP_EOL;
                    echo "  <td class='cellulesimple hidden " . SIFACDEBUTVALIDITECELLULE ."'>" . PHP_EOL;
                    $calendrierId = "calendrierdebut[$index]";
                    echo "      <input type='text' id='$calendrierId' name='$calendrierId' class='calendrier' size='10' onchange='updateTimeElement(this);' " . ATTR_INDEX . "='$index' value='" . formatdate($sifacRole->startdate) . "'>" . PHP_EOL;
                    echo "          <time datetime='" . $sifacRole->startdate . "'></time>" . PHP_EOL;
                    echo "      </input>" . PHP_EOL;
                    echo "      <script>$('.calendrier').datepicker({});</script>" . PHP_EOL;
                    echo "  </td>" . PHP_EOL;
                    echo "  <td class='cellulesimple hidden " . SIFACFINVALIDITECELLULE ."'>" . PHP_EOL;
                    $calendrierId = "calendrierfin[$index]";
                    echo "      <input type='text' id='$calendrierId' name='$calendrierId' class='calendrier' size='10' onchange='updateTimeElement(this);' " . ATTR_INDEX . "='$index' value='" . formatdate($sifacRole->enddate) . "'>" . PHP_EOL;
                    echo "          <time datetime='" . $sifacRole->enddate . "'></time>" . PHP_EOL;
                    echo "      </input>" . PHP_EOL;
                    echo "      <script>$('.calendrier').datepicker({});</script>" . PHP_EOL;
                    echo "  </td>" . PHP_EOL;

                    echo "  <td class='cellulesimple centeraligntext nopadding " . SIFACSUPPRROLECELLULE . "'>" . PHP_EOL;
                    $checkboxId = "checksifacrole[$index]";
                    if (str_starts_with($sifacRole->name,'YC_') or str_starts_with($sifacRole->name,'YP_'))
                    {
                        $disabled = "";
                    }
                    else
                    {
                        $disabled = " disabled ";
                    }
                    echo "      <input type='checkbox' id='$checkboxId' name='$checkboxId' class='checksifacrole nomargin ' $disabled />" . PHP_EOL;
                    echo "  </td>" . PHP_EOL;
                    echo "</tr>" . PHP_EOL;
                }
?>
                </tbody>
            </table>
            <script>
                {
                    let checkboxList=$(document.querySelectorAll('.checksifacrole'));
                    $.each(checkboxList, function(index, checkbox) {
                        let cellule=$(checkbox).parent();
                        $(checkbox).width(Math.trunc($(cellule).height()) - 2);
                        $(checkbox).height($(checkbox).width());
                    });
                }
            </script>

            <br>
<?php
            if (cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY))
            {

?>
                <button class='sifacbouton sifacajoutbouton sifacboutonwidthauto' type='button' name='ajoutroleperimetre' id='ajoutroleperimetre' onclick='addEmptyRoleLine(this,listeCompleteSifacRole.ROLEPERIMETRE);'>Rôle périmètre</button>
                <button class='sifacbouton sifacajoutbouton sifacboutonwidthauto' type='button' name='ajoutrolemetier' id='ajoutrolemetier' onclick='addEmptyRoleLine(this,listeCompleteSifacRole.ROLEMETIER);'>Rôle métier</button>
<?php
            }
        }
        else
        {
            echo $sifacUser->sifacRolesHtml($sifacRoleArray);
        }
?>
            <script>
                table = document.querySelector('#userroletable');
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
            <br>
        </div>
    </form>
<?php
    }
?>
    <script>
        let errorMsg = document.querySelectorAll('table.tabmessage td.celerror');
        if (errorMsg && errorMsg.length>0)
        {
            let buttonList = document.querySelectorAll('#createidbutton, #modifyidbutton, #deleteidbutton');
            buttonList.forEach((element) => element.remove());
        }
    </script>
</body>
</html>
