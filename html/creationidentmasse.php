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