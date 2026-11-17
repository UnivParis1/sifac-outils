<?php
    require_once (dirname(__FILE__) . "/../include/constants.php");

    ///////////////////////////////////////////////////
    // Configuration générale
    ///////////////////////////////////////////////////

    // Bandeau d'information sur le type d'environnement
    //  * texte => Texte libre ('PRODUCTION', 'PRE-PRODUCTION', 'TEST', 'INTEGRATION', ....). Si texte est vide, aucun affichage n'est fait
    //  * couleurtexte => code HTML de la couleur du texte (facultatif : couleur par défaut : #57dc0f)
    //  * couleurfond => code HTML de la couleur du fond (facultatif : couleur par défaut : #0f47dc)
    const TYPE_ENVIRONNEMENT = ['texte' => 'ENVIRONNEMENT DE TEST',
                                'couleurtexte' => '#f52727',
                                'couleurfond' => '#95999e'
                                ];

    // URL du serveur web hébergeant l'application
    const WEB_SERVER_URL = 'https://sifac-outils.etab.fr/';
    // Chemin d'appel des WS de l'application
    const WS_SERVER_URL = "https://sifac-outils.etab.fr/ws/";
    
    // Authorization Bearer pour l'accès aux Web Services de l'application
    const PRIVATE_AUTHORIZATION_BEARER = 'Bearer_To_Define'; 

    // Intégration des rôles SIFAC dans le CIAM
    // Actuellement : false
    // Si true, les rôles SIFAC sont égalements déversés dans le CIAM pour être intégré dans SIFAC lors de la connexion de l'utilisateur
    // Attention : Fonction non mis à disposition par l'AMUE => Il est recommandé de ne pas l'activer pour le moment
    const SIFACROLES_INTO_CIAM = false;

    // Durée (en minute) de validité du cache des rôles SIFAC
    const DUREE_VALIDITE_CACHE_ROLE_SIFAC = 10;

    // Chemin de stockage des fichiers temporaires (sur le serveur)
    // Jetons CIAM, jetons SIFAC, cache des rôles SIFAC+
    const TEMP_DIR = __DIR__ . '/../tmp/';

    // Liste des utilisateurs autorisés à utiliser l'application
    // Syntaxe : ['uid1' => '', 'uid2' => '', ...] ou [ 'uid1', 'uid2', ...]
    const USERID_ALLOWED = [
        'uid_user1',
        'uid_user2',
        'uid_user3'
    ];

    // Permet de filtrer les utilisateurs et d'exclure les personnes hors établissement
    // dans l'affichage listeidentites.php
    const EXCLUDE_USERDOMAIN = [
        'amue.fr',
        'soprasteria.com',
        'ext.soprasteria.com'
    ];

    ///////////////////////////////////////////////////
    // LDAP et WSGROUPS
    ///////////////////////////////////////////////////

    // Mode d'accès à LDAP (LdapSearchClass::LDAP_MODE_DIRECT ou LdapSearchClass::LDAP_MODE_WSGROUPS)
    // Infos sur les WSGroups : https://github.com/UnivParis1/wsgroups
    const LDAP_MODE =  LdapSearchClass::LDAP_MODE_DIRECT;

    // A définir si le mode d'accès à LDAP (LDAP_MODE) est LDAP_MODE_WSGROUPS
    const WSGROUPURL = "https://wsgroups.etab.fr/";
    const WSGROUPS_SECRET_TOKEN = 'secret_token_for_wsgroups';

    const LDAPSERVER = 'ldap://ldap.etab.fr ldap://ldap2.etab.fr';
    const LDAP_ETAB_DOMAIN = 'dc=etab,dc=fr';
    const LDAPLOGIN = 'cn=my_cn,ou=my_ou,' . LDAP_ETAB_DOMAIN;
    const LDAPPASSWD = 'xxxxxxxxxxxxxxxxx';
    const LDAPSEARCHBASE =  'ou=people,' . LDAP_ETAB_DOMAIN;
    // Critère de sélection LDAP complémentaire
    // Exemple : On filtre les personnes avec une affiliation 'member' ( = en activité) et qui ne sont pas 'student' (= étudiant)
    const LDAP_EXTRACRITERE = "(eduPersonAffiliation=member)(!(eduPersonAffiliation=student))";
    // Définition des attributs LDAP
    const LDAP_AGENT_SUPANNEMPID_ATTR = 'supannempid';
    const LDAP_AGENT_NOM_ATTR = 'sn';
    const LDAP_AGENT_PRENOM_ATTR = 'givenname';
    const LDAP_AGENT_MAIL_ATTR = 'mail';
    const LDAP_AGENT_UID_ATTR = 'uid';
    const LDAP_AGENT_EPPN_ATTR = 'edupersonprincipalname';
    const LDAP_AGENT_DISPLAYNAME_ATTR = 'displayname';
    const LDAP_AGENT_BIRTHDAY_ATTR = 'supannoidcdatedenaissance';
    const LDAP_AGENT_CN_ATTR = 'cn';
    // Attention : l'attribut LDAP_AGENT_NUMAGENTSIHAM_ATTR n'est pas normalisé dans eSupLDAP
    // Il est donc à personnaliser en fonction de l'établissement
    const LDAP_AGENT_NUMAGENTSIHAM_ATTR = 'x-ns-siham';

    ///////////////////////////////////////////////////
    // CAS
    ///////////////////////////////////////////////////

    // Configuration du serveur CAS
    const CAS_SERVER = 'cas.etab.fr';
    const CAS_PORT = 443;
    // Attention : Le cas général est /cas.
    // Dans le cas de la mise en place de la double authentification, se renseigner (=> /otp ?)
    const CAS_PATH = '/cas';

    ///////////////////////////////////////////////////
    // Connexion Gravitee AMUE
    ///////////////////////////////////////////////////

    // Proxy établissement (pour les appels curl)
    // Laisser vide si pas de proxy
    const ETAB_PROXY_URL = 'http://proxy.etab.fr:3128';

    // Code établissement SIFAC+ (voir fiche de raccordement)
    const CODE_ETABLISSEMENT_SIFAC = 'etab';

    // URL et données de connexion aux CIAM AMUE
    // Ici sont données les URL de PRE-PROD (sandbox)
    // Si passage en production, il convient de modifier la base de l'URL
    const CIAM_TOKEN_URL = "https://sandbox.auth.amue.fr/auth/" . CODE_ETABLISSEMENT_SIFAC . "/oauth/token";
    const CIAM_LOGINID = 'xxx-xxxxx-xxxxx-xxxxx';
    const CIAM_LOGINSECRET = 'zzzzzzzzzzzzzzzzzzzzzzzzzz';
    const CIAM_USER_URL = "https://sandbox.api.amue.fr/acces/identite/v2/" . CODE_ETABLISSEMENT_SIFAC . "/Users";
    const CIAM_GROUP_URL = "https://sandbox.api.amue.fr/acces/identite/v2/" . CODE_ETABLISSEMENT_SIFAC . "/Groups";

    ///////////////////////////////////////////////////
    // Connexion SIFAC+ (CDSView et entrepot de données)
    ///////////////////////////////////////////////////

    // Mode de requêtage de SIFAC : 
    // SifacSqlMode::QUERY_DIRECT : Interroge les tables SIFAC+ en direct (CDSView)
    // SifacSqlMode::QUERY_ECC : Interroge la base SIFAC ECC (mode compatibilité qui nécessite des pré-requis)
    // SifacSqlMode::QUERY_POSTGRE : Interroge les table SIFAC+ via l'entrepot de données (En cours de développement !)
    const SQLMODE = SifacSqlMode::QUERY_DIRECT;

    const SIFAC_TOKEN_URL = "https://sandbox.auth.amue.fr/auth/fer/oauth/token";
    const SIFAC_CLIENTID='yyyyyyyyyyyyyyyyyyyyyyyyyyyyy';
    const SIFAC_CLIENTSECRET='vvvvv-vvvv-vvvv-vvvv-vvvvvvv';
    const SIFAC_ENTREPOT_URL = "https://sandbox.api.amue.fr/finances/entrepotdedonnees/v1/preprod/" . CODE_ETABLISSEMENT_SIFAC . "/";
    const SIFAC_DIRECT_URL = "https://sandbox.api.amue.fr/finances/cdv/v1/preprod/" . CODE_ETABLISSEMENT_SIFAC . "/direct";

    ///////////////////////////////////////////////////
    // Gestion des rôles/groupes Gravitee
    ///////////////////////////////////////////////////

    // Prise en compte ou pas du mode 'Rôles CIAM' => En théorie désactivé fin 2026
    // ENABLE_ROLE_CIAM = false => Les rôles CIAM ne sont plus affichés, modifiables dans les différents écrans de l'application
    // ENABLE_ROLE_CIAM = true => Les rôles CIAM sont affichés et modifiables dans les différents écrans de l'application
    const ENABLE_ROLE_CIAM = true;

    // Liste des rôles d'accès à gérer dans l'interface de l'application
    const CIAMROLES = [
        "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa" => "GRAVITEEAPIM_USER",
        "bbbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbb" => "SCIM_ADMIN",
        "ccccccccc-cccc-cccc-cccc-ccccccccccc" => "SCIM_PRINCIPAL",
        "ddddddddd-dddd-dddd-dddd-ddddddddddd" => "SIFAC_INTEGRATION_ACCESS",
        "eeeeeeeee-eeee-eeee-eeee-eeeeeeeeeee" => "SIFAC_PREPROD_ACCESS",
        "fffffffff-ffff-ffff-ffff-fffffffffff" => "SIFAC_PREPROD_FORMATION_ACCESS",
        "ggggggggg-gggg-gggg-gggg-ggggggggggg" => "SIFAC_PROD_ACCESS",
        "hhhhhhhhh-hhhh-hhhh-hhhh-hhhhhhhhhhh" => "TEST_ACCESS",
    ];

    ///////////////////////////////////////////////////
    // Missions
    ///////////////////////////////////////////////////

    // Nombre d'années d'affichage des missions
    const INTERVAL_MISSIONS = 3;

?>
