<?php

    ///////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////////
    //          Constantes techniques - NE PAS MODIFIER
    ///////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////////////////////////////////////////////////////////////////

    // Vesion de la bibliothèque d'outils
    const SIFAC_OUTILS_VERSION = '0.17';

    // Liste des actions possibles (identite.php)
    const CREATE_USER_RESOURCE = 'CREATEUSERRESOURCE';
    const DELETE_USER_RESOURCE = 'DELETEUSERRESOURCE';
    const MODIFY_USER_RESOURCE = 'MODIFYUSERRESOURCE';
    const LOAD_USER_RESOURCE = 'LOADUSERRESOURCE';
    const DELETE_GROUP_RESOURCE = 'DELETEGROUPRESOURCE';
    const CREATE_GROUP_RESOURCE = 'CREATEGROUPRESOURCE';

    // Liste des attributs disponibles pour les boutons de l'interface ou les cellules du tableau (identite.php)
    const ATTR_ACTIVE_ELEMENT = 'sifac-active-element';
    const ATTR_ACTION = 'sifac-action';
    const ATTR_CALLBACK_FCT = 'sifac-callback-fct';
    const ATTR_LIBELLE = 'sifac-libelle';
    const ATTR_MODALHEADER = 'sifac-modalheader';
    const ATTR_MATRICULE_AGENT = 'sifac-matricule';
    const ATTR_TYPEMISSION_AGENT = 'sifac-typemission';
    const ATTR_INDEX = 'sifac-index';
    const ATTR_EPPN = 'sifac-eppn';
    const ATTR_CIAMUSERID = 'ciam-userid';

    // Pseudo classes pour identifier les cellules des tableaux des informations CIAM
    const CIAMIDENTITYIDCELLULE = 'ciamidentityidcellule';
    const CIAMCREATIONDATECELLULE = 'ciamcreationcellule';
    const CIAMMODIFICATIONDATECELLULE = 'ciammodificationcellule';
    const CIAMACTIVECELLULE = 'ciamactivecellule';
    const CIAMEXTERNALIDCELLULE = 'ciamexternalidcellule';
    const CIAMUSERNAMECELLULE = 'ciamusernamecellule';
    const CIAMNAMECELLULE = 'ciamnamecellule';
    const CIAMFIRSTNAMECELLULE = 'ciamfirstnamecellule';
    const CIAMMAILCELLULE = 'ciammailcellule';
    const CIAMDISPLAYNAMECELLULE = 'ciamdisplaynamecellule';

    // Pseudo classes pour identifier les cellules des tableaux des informations LDAP
    const LDAPEPPNCELLULE = "ldapeppncellule";
    const LDAPDISPLAYNAMECELLULE = "ldapdisplaynamecellule";
    const LDAPMAILCELLULE = "ldapmail";
    const LDAPFIRSTNAMECELLULE = "ldapfirstnamecellule";
    const LDAPNAMECELLULE = "ldapnamecellule";
    const LDAPBIRTHDAYCELLULE = 'ldapbirthdaycellule';
    const LDAPUIDCELLULE = 'ldapuidcellule';
    const LDAPSUPANNEMPIDCELLULE = 'ldapsupannempid';
    const LDAPPRIMARYAFFILIATIONCELLULE = 'primaryaffiliationcellule';
    const LDAPNUMEROSIHAMCELLULE = 'numerosihamcellule';

    // Pseudo classes pour identifier les cellules des tableaux des informations SIFAC
    const SIFACLOGINCELLULE = "sifaclogincellule";
    const SIFACDEBUTVALIDITECELLULE = "sifacdebutvalidcellule";
    const SIFACFINVALIDITECELLULE = "sifacfinvalidcellule";
    const SIFACNOMROLECELLULE = "sifacnomrolecellule";
    const SIFACDESCRIPTIONROLECELLULE = "sifacdescriptionrolecellule";
    const SIFACSUPPRROLECELLULE = "sifacsupprrolecellule";

    // Les différents types de message à afficher dans la fonction showMessage
    const MSGERROR = 'error';
    const MSGWARNING = 'warning';
    const MSGINFO = 'info';

    // Liste des rôles internes à l'application pour gérer les habilitations
    const INTERNAL_ROLE_PREFIX = 'SIFAC_OUTILS_';
    const INTERNAL_ROLE_ADMIN = 'ADMIN';
    const INTERNAL_ROLE_CREATE = 'CREATION';
    const INTERNAL_ROLE_MODIFY = 'MODIF';
    const INTERNAL_ROLE_CONSULT = 'CONSULT';

    ////////////////////////////////////////////////////
    // Configuration des droits d'accès par CIAMGroups
    ////////////////////////////////////////////////////
    define('CIAMGROUPACCESS_ADMIN', ['ADMIN_PRINCIPAL', strtoupper(INTERNAL_ROLE_PREFIX . INTERNAL_ROLE_ADMIN)]);
    define('CIAMGROUPACCESS_CREATE', array_merge(CIAMGROUPACCESS_ADMIN,[strtoupper(INTERNAL_ROLE_PREFIX . INTERNAL_ROLE_CREATE)]));
    define('CIAMGROUPACCESS_MODIFY', array_merge(CIAMGROUPACCESS_CREATE,[strtoupper(INTERNAL_ROLE_PREFIX . INTERNAL_ROLE_MODIFY)]));
    define('CIAMGROUPACCESS_CONSULT', array_merge(CIAMGROUPACCESS_MODIFY,[strtoupper(INTERNAL_ROLE_PREFIX . INTERNAL_ROLE_CONSULT)]));

    // Méthodes internes pour les WS
    const INTERNALWS_DELCIAMTOKEN = 'delciamtoken';
    const INTERNALWS_DELSIFACTOKEN = 'delsifactoken';
    const INTERNALWS_DELROLECACHE = 'delrolecache';
    const INTERNALWS_SIFACROLELISTE = 'sifacroleliste';
    const INTERNALWS_CREATECIAMIDENT = 'createciamident';

    // Liste des messages d'erreur HTTP possibles dans un CURL
    const HTTP_ERROR_MSG = [100 => "Continue", 
                            101 => "Switching Protocols", 
                            102 => "Processing", 
                            200 => "OK", 
                            201 => "Created", 
                            202 => "Accepted", 
                            203 => "Non-Authoritative Information", 
                            204 => "No Content", 
                            205 => "Reset Content", 
                            206 => "Partial Content", 
                            207 => "Multi-Status", 
                            300 => "Multiple Choices", 
                            301 => "Moved Permanently", 
                            302 => "Found", 
                            303 => "See Other", 
                            304 => "Not Modified", 
                            305 => "Use Proxy", 
                            306 => "(Unused)", 
                            307 => "Temporary Redirect", 
                            308 => "Permanent Redirect", 
                            400 => "Bad Request", 
                            401 => "Unauthorized", 
                            402 => "Payment Required", 
                            403 => "Forbidden", 
                            404 => "Not Found", 
                            405 => "Method Not Allowed", 
                            406 => "Not Acceptable", 
                            407 => "Proxy Authentication Required", 
                            408 => "Request Timeout", 
                            409 => "Conflict", 
                            410 => "Gone", 
                            411 => "Length Required", 
                            412 => "Precondition Failed", 
                            413 => "Request Entity Too Large", 
                            414 => "Request-URI Too Long", 
                            415 => "Unsupported Media Type", 
                            416 => "Requested Range Not Satisfiable", 
                            417 => "Expectation Failed", 
                            418 => "I'm a teapot", 
                            419 => "Authentication Timeout", 
                            420 => "Enhance Your Calm", 
                            422 => "Unprocessable Entity", 
                            423 => "Locked", 
                            424 => "Failed Dependency", 
                            425 => "Unordered Collection", 
                            426 => "Upgrade Required", 
                            428 => "Precondition Required", 
                            429 => "Too Many Requests", 
                            431 => "Request Header Fields Too Large", 
                            444 => "No Response", 
                            449 => "Retry With", 
                            450 => "Blocked by Windows Parental Controls", 
                            451 => "Unavailable For Legal Reasons", 
                            494 => "Request Header Too Large", 
                            495 => "Cert Error", 
                            496 => "No Cert", 
                            497 => "HTTP to HTTPS", 
                            499 => "Client Closed Request", 
                            500 => "Internal Server Error", 
                            501 => "Not Implemented", 
                            502 => "Bad Gateway", 
                            503 => "Service Unavailable", 
                            504 => "Gateway Timeout", 
                            505 => "HTTP Version Not Supported", 
                            506 => "Variant Also Negotiates", 
                            507 => "Insufficient Storage", 
                            508 => "Loop Detected", 
                            509 => "Bandwidth Limit Exceeded", 
                            510 => "Not Extended", 
                            511 => "Network Authentication Required", 
                            598 => "Network read timeout error", 
                            599 => "Network connect timeout error"
                            ];

    $repertoire = '../class';
    // Vérifie si le répertoire existe
    if (is_dir($repertoire)) 
    {
        // Récupère tous les fichiers et dossiers
        $fichiers = scandir($repertoire);
        
        // Filtre pour ne garder que les fichiers .php
        $fichiersPhp = array_filter($fichiers, function ($file) use ($repertoire) {
            $cheminComplet = $repertoire . '/' . $file;
            return is_file($cheminComplet) && pathinfo($file, PATHINFO_EXTENSION) === 'php';
        });

        // Inclusion des fichiers .php
        foreach ($fichiersPhp as $fichier) 
        {
            require_once ($repertoire . '/' . $fichier);
        }
    }

?>