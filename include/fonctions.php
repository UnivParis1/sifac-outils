<?php

    /**
     * Permet de convertir des dates en format "base de données"
     *
     * @param string $date La date à convertir 
     * @return string|null La date convertie au format YYYYMMDD si elle est valide ou null sinon
     */
    function formatdatedb(string $date) :string|null
    {
        $tempdate = null;
        $jour = null;
        $mois = null;
        $annee = null;
        if (is_null($date)) 
        {
            $errlog = "formatdatedb : La date est NULL !!!";
            echo $errlog . "<br/>";
            error_log(basename(__FILE__) . " " . stripAccents($errlog));
        } 
        else 
        {
            // Si la date est au format JJ + séparateur non numérique + MM + séparateur non numérique + AAAA
            // => 15-03-2024 ou 15.03.2024 ou 15/03/2024 : Ok
            // => 1510382024 => KO
            if (preg_match("`^([0-9]{2})([^0-9]{1})([0-9]{2})([^0-9]{1})([0-9]{4})$`", $date)==1)
            {
                $jour = substr($date, 0, 2);
                $mois = substr($date, 3, 2);
                $annee = substr($date, 6, 4);
            } 
            // Si la date est au format AAAA + séparateur non numérique + MM + séparateur non numérique + JJ
            // => 2024-03-15 ou 2024.03.15 ou 2024/03/15 : Ok
            // => 2024103815 => KO
            elseif (preg_match("`^([0-9]{4})([^0-9]{1})([0-9]{2})([^0-9]{1})([0-9]{2})$`", $date)==1)
            {
                $annee = substr($date, 0, 4);
                $mois = substr($date, 5, 2);
                $jour = substr($date, 8, 2);
            }
            // Si la date est une série de 8 chiffres (sans doute au format AAAAMMJJ)
            elseif (preg_match("`^([0-9]{4})([0-9]{2})([0-9]{2})$`", $date)==1)
            {
                $annee = substr($date, 0, 4);
                $mois = substr($date, 4, 2);
                $jour = substr($date, 6, 2);
            }
            // Le format de la date n'est pas reconnu
            else 
            {
                $errlog = "formatdatedb : Le format de la date est inconnu [Date=$date] !!";
                echo $errlog . "<br/>";
                error_log(basename(__FILE__) . " " . stripAccents($errlog));
            }
            // Si la date convertie a un format connu (=> $jour != null), on vérifie que c'est une date valide
            if (!is_null($jour))
            {
                if (checkdate($mois,$jour,$annee))
                {
                    $tempdate = $annee . $mois . $jour;
                }
                else
                {
                    $errlog = "formatdatedb : La date convertie n'est pas valide [Date=$date / date convertie=" . $annee . $mois . $jour . "] !!";
                    echo $errlog . "<br/>";
                    error_log(basename(__FILE__) . " " . stripAccents($errlog));
                }
            }
        }
        return $tempdate;
    }

    /**
     * Permet de convertir des dates (souvent au format dase de données) en format français
     *
     * @param string $date La date à convertir
     * @return string|null La date convertie au format DD/MM/YYYY si elle est valide ou null sinon
     */
    function formatdate(string $date) :string|null
    {
        $tempdate = null;
        $jour = null;
        $mois = null;
        $annee = null;
        if (is_null($date)) 
        {
            $errlog = "formatdate : La date est NULL !!!";
            echo $errlog . "<br/>";
            error_log(basename(__FILE__) . " " . stripAccents($errlog));
        } 
        else
        {
            // Si la date contient des HH:MM:SS ou des HH:MM => On supprime la partie horaire de la date
            if (preg_match("` ([0-9]{2})([^0-9]{1})([0-9]{2})([^0-9]{1})([0-9]{2})$`", $date)==1
             or preg_match("` ([0-9]{2})([^0-9]{1})([0-9]{2})$`", $date)==1)
            {
                $date = explode(' ', $date)[0];
            }

            // Si la date est au format ISO 8601
            if (preg_match('/^(-?(?:[1-9][0-9]*)?[0-9]{4}-(?:1[0-2]|0[1-9])-(?:3[01]|[12][0-9]|0[1-9])($|T(?:2[0-3]|[01][0-9]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]+)?(Z|[+-][01][0-9]:?[0-5][0-9])?))$/', $date))
            {
                $jour = substr($date, 8, 2);
                $mois = substr($date, 5, 2);
                $annee = substr($date, 0, 4);
            }
            // Si la date est une série de 8 chiffres (sans doute au format AAAAMMJJ)
            elseif (preg_match("`^([0-9]{4})([0-9]{2})([0-9]{2})$`", $date)==1)
            {
                $jour = substr($date, 6, 2) ;
                $mois = substr($date, 4, 2);
                $annee = substr($date, 0, 4);
            } 
            // Si la date est au format AAAA + un séparateur non numérique + MM + un séparateur non numérique + JJ
            // => 2024-03-15 ou 2024.03.15 ou 2024/03/15 : Ok
            // => 2024103815 => KO
            elseif (preg_match("`^([0-9]{4})([^0-9]{1})([0-9]{2})([^0-9]{1})([0-9]{2})$`", $date)==1)
            {
                $jour = substr($date, 8, 2);
                $mois = substr($date, 5, 2);
                $annee = substr($date, 0, 4);
            }
            // Si la date est au format JJ + séparateur non numérique + MM + séparateur non numérique + AAAA
            elseif (preg_match("`^([0-9]{2})([^0-9]{1})([0-9]{2})([^0-9]{1})([0-9]{4})$`", $date)==1)
            {
                $jour = substr($date, 0, 2);
                $mois = substr($date, 3, 2);
                $annee = substr($date, 6, 4);
            } 
            // Le format de la date n'est pas reconnu
            else 
            {
                $errlog = "formatdate : Le format de la date est inconnu [Date=$date] !!";
                echo $errlog . "<br/>";
                error_log(basename(__FILE__) . " " . stripAccents($errlog));
            }
            // Si la date convertie a un format connu (=> $jour != null), on vérifie que c'est une date valide
            if (!is_null($jour))
            {
                if (checkdate($mois,$jour,$annee))
                {
                    $tempdate = $jour . "/" . $mois . "/" . $annee;
                }
                else
                {
                    $errlog = "formatdate : La date convertie n'est pas valide [Date=$date / date convertie=" . $jour . "/" . $mois . "/" . $annee . "] !!";
                    echo $errlog . "<br/>";
                    error_log(basename(__FILE__) . " " . stripAccents($errlog));
                }
            }
        }
        return $tempdate;
    }

    /**
     * Permet de remplacer les caractères accentués par des caractères correspondant sans accent
     * Attention : La chaine de caractère résultat est convertie en minuscule
     *
     * @param string $texte La chaine de caractères
     * @return string la chaine de caractères sans les accents
     */
    function stripAccents(string $texte) :string
    {
        $texte = mb_strtolower($texte, 'UTF-8');
        $texte = str_replace(array(
            'à','â','ä','á','ã','å','î','ï','ì','í','ô','ö','ò','ó','õ','ø','ù','û','ü','ú','é','è','ê','ë','ç','ÿ','ñ'
        ), array(
            'a','a','a','a','a','a','i','i','i','i','o','o','o','o','o','o','u','u','u','u','e','e','e','e','c','y','n'
        ), $texte);
        return $texte;
    }

    /**
     * Permet de vérifier si un utilisateur de l'application peut accéder à celle-ci
     *
     * @param string $uid L'uid de l'utilisateur
     * @return boolean Retourne true si l'utilisateur est autorisé à utiliser l'application. False sinon
     */
    function cas_user_check_access(string $uid, array $ciamrolefilter, bool $force = false) :bool
    {
        static $userCiamGroups = [];

        if (trim($uid) == '')
        {
            return false;
        }

        if (isset($userCiamGroups[$uid]) and !$force)
        {
            $ciamGroupList = $userCiamGroups[$uid];
            // $errlog = "Les groupes sont déjà chargés pour l'utilisateur " . $uid;
            // error_log(basename(__FILE__) . " " . stripAccents($errlog));
        }
        else
        {
            // $errlog = "L'utilisateur " . $uid . " n'est pas connu du tableau";
            // error_log(basename(__FILE__) . " " . stripAccents($errlog));
            $ciamGroupList = [];
            // On demande à LDAP de convertir l'uid en eppn
            $ldapFunctions = new LdapFunctions();
            $response = $ldapFunctions->getLDAPInfos("$uid",
                    [LDAP_AGENT_UID_ATTR,LDAP_AGENT_EPPN_ATTR], 
                    [LDAP_AGENT_EPPN_ATTR],
                    ldapsearchclass::LDAP_OPTION_ONE_RESPONSE);

            // Si la réponse est ok et qu'on n'a qu'un résultat
            if ($response->status == ResponseStatus::STATUT_OK and count($response->data)>0)
            {
                // On récupère l'eppn de l'utilisateur
                $eppn = $response->data[0][LDAP_AGENT_EPPN_ATTR];

                // On récupère les informations du CIAM sur l'identité de l'utilisateur à partir de son eppn
                $ciamfunctions = new CiamFunctions();
                $response = $ciamfunctions->getCiamIdentitiesInfosFromEppn($eppn);

                // Si la réponse est ok
                if ($response->status == ResponseStatus::STATUT_OK)
                {
                    // On charge les données de l'identité dans un objet CiamUserClass
                    $ciamuser = new CiamUserClass();
                    $ciamuser->load($response->data[0]);
                    // On récupère la liste des groupes à laquelle l'identité appartient
                    $userCiamGroups[$uid] = $ciamuser->ciamgroups;
                    $ciamGroupList = $userCiamGroups[$uid];
                }
                else
                {
                    $errlog = "Impossible de charger depuis le CIAM l'utilisateur ($uid) avec l'eppn " . $eppn;
                    error_log(basename(__FILE__) . " " . stripAccents($errlog));
                }
            }
            else
            {
                $errlog = "Impossible de trouver dans LDAP l'utilisateur $uid";
                error_log(basename(__FILE__) . " " . stripAccents($errlog));
            }
        }

        // Pour chaque groupe, on regarde s'il est dans la liste des groupes autorisés.
        foreach($ciamGroupList as $ciamgroupdata)
        {
            if (in_array(strtoupper($ciamgroupdata['display']),$ciamrolefilter))
            {
                // On a trouvé un groupe qui correspond => On sort et on retourne true;
                $errlog = "Le groupe " . $ciamgroupdata['display'] . " est dans la liste des groupes de l'utilisateur " . $uid;
                error_log(basename(__FILE__) . " " . stripAccents($errlog));
                return true;
            }
        }

        // Si on est là, c'est soit :
        // * on n'a pas trouvé de correspondance dans LDAP
        // * on n'a pas trouvé l'identité dans le CIAM,
        // * l'identité n'est dans aucun groupe autorisé
        // on cherche alors si l'identité est dans le tableau des personnes autorisées USERID_ALLOWED
        $uid = strtolower($uid);
        $useridallowed = array_change_key_case(USERID_ALLOWED,CASE_LOWER);
        if (!isset($useridallowed[$uid]) and array_search(strtolower($uid), array_map('strtolower', $useridallowed))===false)
        {
            $errlog = "L'uid de l'utilisateur n'est pas dans la liste des personnes autorisées.";
            error_log(basename(__FILE__) . " " . stripAccents($errlog));
            return false;
        }
        $errlog = "L'uid de l'utilisateur est dans le tableau des utilisateurs autorisés.";
        error_log(basename(__FILE__) . " " . stripAccents($errlog));
        return true;
    }

    /**
     * Permet de déterminer l'URL du service lors de l'authentification CAS
     *
     * @return string Retourne l'URL du serveur actuel (soit à partir de la constante, soit calculée à partir des paramètres PHP du serveur)
     */
	function get_CASservicename() :string
    {
        if (defined('WEB_SERVER_URL'))
        {
            // En partant de la constante G2T_URL on va définir l'URL du service CAS
            $arrayUrl = parse_url(WEB_SERVER_URL);
            $clientServiceName = $arrayUrl["scheme"] . '://' . $arrayUrl["host"];
            if (isset($arrayUrl["port"]) and $arrayUrl["port"] != 0)
            {
                $clientServiceName = $clientServiceName . ":" . $arrayUrl["port"];
            }
            return $clientServiceName;
        }

        if (!isset($_SERVER['SERVER_NAME']))
        {
            return '';
        }

        //////////////////////////////////
        // On va construire l'URL du service pour CAS
        // On récupère le nom du serveur
        $servername = $_SERVER['SERVER_NAME'];
        // Si on passe par un proxy ==> HTTP_X_FORWARDED_PROTO est défini dans le header (protocole utilisé entre le client et le proxy)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']))
        {
            $serverprotocol = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        }
        // Si la requète vient directement sur le serveur, on regarde si $_SERVER['HTTPS'] est défini
        else if (isset($_SERVER['HTTPS']))
        {
            $serverprotocol = "https";
        }
        // Sinon c'est de l'HTTP
        else
        {
            $serverprotocol = "http";
        }

        //Si on passe par un proxy => HTTP_X_FORWARDED_PORT est défini dans le header (port utilisé entre le client et le proxy)
        if (isset($_SERVER['HTTP_X_FORWARDED_PORT']))
        {
            $serverport = $_SERVER['HTTP_X_FORWARDED_PORT'];
        }
        // Si la requête vient directement sur le serveur, on regarde si $_SERVER['SERVER_PORT'] est défini
        else if (isset($_SERVER['SERVER_PORT']))
        {
            // Le port pour parler au serveur est contenu dans la variable
            $serverport = $_SERVER['SERVER_PORT'];
        }
        // Si le protocole est en https => Le port par défaut est 443
        else if ($serverprotocol == "https")
        {
            $serverport = "443";
        }
        // Si c'est de l'HTTP ou si on n'a aucune information => Le port par défaut est 80
        else
        {
            $serverport = "80";
        }
        //echo "serverprotocol  = $serverprotocol   servername = $servername   serverport = $serverport <br>";
        return $serverprotocol . "://" . $servername . ":" . $serverport;
    }

    /**
     * Permet de créer des objets role à partir d'un tableau de rôles (convertion tableau JSON => objet role)
     *
     * @param array $arrayrole Un tableau contenant la table représentant les propriétés d'un rôle
     * @return array Retourne un tableau d'objet role initialisé
     */
    function convert_array_to_roles(array $arrayrole) :array
    {
        $rolelist = array();
        foreach($arrayrole as $roledata)
        {
            $role = new sifacroleclass();
            $role->load($roledata);
            $rolelist[] = $role;
        }
        return $rolelist;
    }

    /**
     * Permet de convertir des valeurs pseudo-booléennes en valeur booleénne
     *
     * @param string|bool|int $value Valeur à convertir en valeur booléenne
     * @return boolean Retourne la valeur booléenne correspondante
     */
    function convertvaluetobool(string|bool|int $value) :bool
    {
        if (is_bool($value))
        {
            return $value;
        }

        switch (trim(strtoupper($value . '')))
        {
            case '':
                return false;
            case 'Y':
            case 'YES':
            case 'O':
            case 'OUI':
            case '1':
            case 'ON':
                return true;
                break;
            case 'N':
            case 'NO':
            case 'NON':
            case '0':
            case 'OFF':
                return false;
                break;
            default:
                return false;
        }        
    }

    /**
     * Retourne si le tableau représente bien la classe JS
     *
     * @param array $arrayobject Tableau représentant une classe JS
     * @param string $className Nom de la classe attendue
     * @return bool true si le tableau représente bien la classe JS, false dans le cas contraire
     */
    function checkArrayIsCorrectClass(array $arrayobject, string $className)
    {
        // On supprime le domainname de la classe (s'il est présent)
        $arrayclassname = explode('\\',$className);
        $className = end($arrayclassname);
        if (!isset($arrayobject['className']) or $arrayobject['className'] != $className)
        {
            return false;
        }
        return true;
    }

    /**
     * Retourne le nom du jour en français correspondant à une date
     *
     * @param string $date date au format français ou format base de données. Si null, prend la date du jour.
     * @param int $length Longueur du nom du jour à retourner (=> tronque le nom du jour au lenght caractère). Si null, pas de troncature.
     * @return string Le nom du jour (en français) correspondant à la date
     */
    function nomjour(string|null $date = null, int|null $length = null) :string
    {
        if (is_null($date))
        {
            $date = date("d/m/Y");
        }

        $numday = date("w", strtotime(formatdatedb($date)));
        $dayname = nomjourparindex($numday, $length);
        return ucfirst($dayname);
    }

    /**
     * Retourne le nom du jour en français correspondant à un index
     *
     * @param int $index index du jour de la semaine (0=Dimanche, 6=Samedi, 7=Dimanche)
     * @param int $length Longueur du nom du jour à retourner (=> tronque le nom du jour au lenght caractère). Si null, pas de troncature.
     * @return string Le nom du jour (en français) correspondant à l'index
     */
    function nomjourparindex(int|null $index = null, int|null $length = null) :string
    {
        
        if (is_null($index)) 
        {
            $errlog = "Fonctions->nomjourparindex : L'index du jour est NULL";
            error_log(basename(__FILE__) . " " . stripAccents($errlog));
            return '';
        } 
        else 
        {
            $dayname = '';
            $index = $index % 7;
            switch ($index)
            {
                case 0:
                    $dayname = "Dimanche";
                    break;
                case 1:
                    $dayname = "lundi";
                    break;
                case 2:
                    $dayname = "mardi";
                    break;
                case 3:
                    $dayname = "mercredi";
                    break;
                case 4:
                    $dayname = "jeudi";
                    break;
                case 5:
                    $dayname = "vendredi";
                    break;
                case 6:
                    $dayname = "samedi";
                    break;
            }
            if (!is_null($length) and $length<strlen($dayname) and $length>0)
            {
                $dayname = substr($dayname,0,$length) . ".";
            }
            return ucfirst($dayname);
        }
    }

    /**
     * Retourne le nom du mois en français correspondant à une date
     *
     * @param string $date date au format français ou format base de données. Si null, prend la date du jour
     * @return string Le nom du mois (en français) correspondant à la date
     */
    function nommois($date = null)
    {
        if (is_null($date))
        {
            $date = date("d/m/Y");
        }
        $nummonth = date("n", strtotime(formatdatedb($date)));
        $monthname = nommoisparindex($nummonth);
        return ucfirst($monthname);
    }

    /**
     * Retourne le nom du mois en français correspondant à un index
     *
     * @param string $index index du mois (0=Décembre, 1=Janvier, 12=Décembre)
     * @return string Le nom du mois correspondant à l'index
     */
    function nommoisparindex($index = null)
    {
        if (is_null($index)) 
        {
            $errlog = "Fonctions->nommoisparindex : L'index du mois est NULL";
            error_log(basename(__FILE__) . " " . stripAccents($errlog));
            return "";
        } 
        else 
        {
            $monthname = '';
            $index = $index % 12;
            if ($index==0) 
            {
                $index = 12;
            }
            switch ($index)
            {
                case 1:
                    $monthname = "janvier";
                    break;
                case 2:
                    $monthname = "février";
                    break;
                case 3:
                    $monthname = "mars";
                    break;
                case 4:
                    $monthname = "avril";
                    break;
                case 5:
                    $monthname = "mai";
                    break;
                case 6:
                    $monthname = "juin";
                    break;
                case 7:
                    $monthname = "juillet";
                    break;
                case 8:
                    $monthname = "août";
                    break;
                case 9:
                    $monthname = "septembre";
                    break;
                case 10:
                    $monthname = "octobre";
                    break;
                case 11:
                    $monthname = "novembre";
                    break;
                case 12:
                    $monthname = "décembre";
                    break;
            }
            return ucfirst($monthname);
        }
    }

    /**
     * Retourne la date complète (nom du jour + jour + nom du mois + année) de la date
     *
     * @param string $date date au format français ou format base de données. Si null, prend la date du jour
     * @return string Le date complète
     */
    function datetextuelle(string|null $date=null)
    {
        if (is_null($date))
        {
            $date = date("d/m/Y");
        }
        $datedb = formatdatedb($date);
        $nomjour = nomjour($date);
        $annee = substr($datedb,0,4);
        $mois = substr($datedb,4,2);
        $jour = substr($datedb,6,2);
        return ($nomjour . " " . $jour . " " .nommoisparindex($mois) . " " . $annee);
    }

    /**
     * Retourne le chemin où sont stokées les images de fenêtre modale et de l'image de chargement
     *
     * @return string Le chemin du dossier
     */
    function imagepath() :string
    {
        return dirname(__FILE__,2) . "/images/";
    }

    /**
     * Affiche un message dans la page
     *
     * @param string $type Type de message (MSGERROR, MSGWARNING, MSGINFO)
     * @param string $message Le message à afficher
     */
    function showMessage(string $type, string $message)
    {
        //var_dump($message);
        $message = preg_replace('/\s\s+/', ' ', $message);
        //var_dump($message);
        $message = preg_replace('/<br>\s*/i','<br>', $message);
        //var_dump($message);
        $oldmessage = "";
        while ($oldmessage != $message and $message != '')
        {
            $oldmessage = $message;
            $message = preg_replace('/<br><br>*/i','<br>', $message);
        }
        //var_dump($message);
        $message = preg_replace('/^<br>/i','', $message);
        $message = preg_replace('/<br>$/i','', $message);
        //var_dump($message);
        if (trim(str_ireplace('<br>', '', $message)) == '')
        {
       	    $message = '';
        }
        //var_dump($message);

        $html = '';
        if  ($message == '')
        {
            return $html;
        }
        $html = $html . "<p>";
        $html = $html . "<table class='tabmessage'><tbody>";
        $html = $html . "<tr>";
        $html = $html . "<td class='cel" . $type  . " celllogo'>";
        $path = imagepath() . "/" . $type  . "_logo.png";
        list($width, $height, $imagetype) = getimagesize("$path");
        $typeimage = image_type_to_extension($imagetype,false);
        if ($typeimage===false) // Si on n'a pas pu déterminé le type d'image => On récupère l'extension du fichier
        {
            error_log(basename(__FILE__) . " " . stripAccents("imagetype = $imagetype => extension non définie"));
            $typeimage = pathinfo($path, PATHINFO_EXTENSION);
        }
        $data = file_get_contents($path);
        $base64 = 'data:image/' . $typeimage . ';base64,' . base64_encode($data);
        $html = $html . "<img class='img". $type ."' src='" . $base64 . "'>"; 
        $html = $html . "</td>";
        $html = $html . "<td class='cel" . $type  . " cellmsg'>";
        $html = $html . "$message";
        $html = $html . "</td>";
        $html = $html . "</tr>";
        $html = $html . "</tbody></table>";
        $html = $html . "</p>";
        echo $html;
    }

    /**
     * Change récursivement la casse des clés d'un tableau (majuscule ou minuscule)
     *
     * @param array $array Tableau à convertir
     * @param int $case La casse des clés à retourner
     * @return array Le tableau initial avec toutes les clés converties dans la casse choisie
     */
    function array_change_key_case_recursive(array $array, int $case = CASE_LOWER) :array
    {
        if (is_null($array))
        {
            return $array;
        }
        return array_map(function($item) use($case) {
            if(is_array($item))
                $item = array_change_key_case_recursive($item, $case);
            return $item;
        },array_change_key_case($array, $case));
    }
?>