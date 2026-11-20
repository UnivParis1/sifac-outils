# SIFAC-OUTILS

Cette bibliothèque de classes permet d'interroger les CDSView SIFAC+ (mode direct) ainsi que les API de la plateforme de service (PFS) mises à disposition par l'AMUE.

A noter : Le requêtage via l’entrepôt de données (Postgre SQL) est en cours de développement.

## Fonctionnalités

Des applications sont intégrées dans ce développement, utilisant les classes de base :
- La gestion des identités dans Gravitee
- L'affectation des accès à la plateforme SIFAC+ (en mode "rôles" ou en mode "groupes")
- La consultation des rôles SIFAC+ associés à l'utilisateur
- La consultation des missions enregistrées dans SIFAC+ (PR05).

D'autres fonctionnalités peuvent être créées via ces classes, à la discression des établissements.

## Auteur

- Pascal Comte (Université Paris 1 Panthéon - Sorbonne) [pascal.comte@univ-paris1.fr]

## Licence

Ce projet est distribué selon la licence "Apache-2.0 license"

## Exigences

### PHP

* Version PHP
> - \>= 8.2.x, 
> - \>= 8.3.x, 
> - \>= 8.4.x 
> - \>= 8.5.x
 
**Point d'attention :**
- La racine web du VHost applicatif doit pointer uniquement sur le dossier `html/`.
- La racine web du VHost webservice doit pointer uniquement sur le dossier `ws/`.


### Modules complémentaires
* PHP curl
* PHP ldap
* PHP mbstring
* Apereo/PHPCas
> - version 1.6.1 - compatibilité jusqu'à PHP 8.3.x
> - version 1.6.2 - compatibilité jusqu'à PHP 8.5.x

## Installation
Bientôt disponible sur github
```bash
    cd <appli_home>
    composer install 
```

## Configuration

L'application est entièrement paramétrable via un fichier de configuration config.php.  
Pour cela :  
- Copiez le fichier config-exemple.php disponible dans le dossier config de l'application en config.php
- Editez le fichier config.php et instanciez les constantes avec les valeurs spécifiques à votre établissement

### Aide à la configuration

#### *Configuration générale*
- `TYPE_ENVIRONNEMENT` : Permet d'afficher un bandeau en haut de la page afin de préciser à l'utilisateur sur quel environnement il est connecté. Utile dans le cas de multi-instance de l'application (Production, Pré-production, Recette, ...). Si le nom de l'environnement est vide, aucun bandeau ne s'affiche.  
- `WEB_SERVER_URL` : URL du serveur hébergeant l'application  
- `WS_SERVER_URL`: URL permettant de contacter les WS de l'application 
- `PRIVATE_AUTHORIZATION_BEARER` : Authorization Bearer pour l'accès aux Web Services de l'application
- `SIFACROLES_INTO_CIAM` : Boolean permettant de définir si les rôles SIFAC+ sont gérés dans Gravitee (=> true) ou dans SIFAC+ (=> false).  
Par défaut : false car fonctionnalité non encore implémentée par l'AMUE.
- `DUREE_VALIDITE_CACHE_ROLE_SIFAC` : Durée (en minute) de validité du cache des rôles SIFAC
- `TEMP_DIR` : Chemin de stockage des fichiers temporaires (Jeton CIAM, jeton SIFAC+, cache des rôles SIFAC+)
- `USERID_ALLOWED` : Liste des UID LDAP autorisés à accéder à l'applicaton de gestion des identités  
Syntaxe : `['uid1' => '', 'uid2' => '', ...]` ou `[ 'uid1', 'uid2', ...]`
- `EXCLUDE_USERDOMAIN` : Liste des domaines des utilisateurs à ne pas afficher car non gérés par l'établissement (Exemple : domaine AMUE, Sopra/Stéria)

#### *LDAP et WSGROUPS*
- `LDAP_MODE` : Spécifie si l'application, pour la recherche d'identité, doit interroger LDAP directement ou si elle doit utiliser les API [WSGroups](https://github.com/UnivParis1/wsgroups)  
- `WSGROUPURL`/`WSGROUPS_SECRET_TOKEN` : Si les WSGroups sont utilisés, URL d'hébergement des services et token d'identification  
- `LDAPSERVER` : URL du serveur LDAP
- `LDAP_ETAB_DOMAIN` : Domaine LDAP de l'établissement
- `LDAPLOGIN`/`LDAPPASSWD` : Bind et mot de passe de connexion LDAP 
- `LDAPSEARCHBASE` : Base de recherche des personnes dans LDAP
- `LDAP_EXTRACRITERE` : Critère de sélection LDAP complémentaire.  
Par exemple : "personnes avec une affiliation 'member' ( = en activité) et qui ne sont pas 'student' (= étudiant)"  
- `LDAP_AGENT_SUPANNEMPID_ATTR` : Attribut LDAP contenant l'identifiant de l'employé
- `LDAP_AGENT_NOM_ATTR` : Attribut LDAP contenant le nom de l'employé 
- `LDAP_AGENT_PRENOM_ATTR` : Attribut LDAP contenant le prénom de l'employé
- `LDAP_AGENT_MAIL_ATTR` : Attribut LDAP contenant le mail de l'employé
- `LDAP_AGENT_UID_ATTR` : Attribut LDAP contenant l'identifiant de connexion de l'employé
- `LDAP_AGENT_EPPN_ATTR` : Attribut LDAP contenant l'EPPN de l'employé
- `LDAP_AGENT_DISPLAYNAME_ATTR` : Attribut LDAP contenant le nom complet de l'employé
- `LDAP_AGENT_CN_ATTR` : Attribut LDAP contenant le nom complet de l'employé
- `LDAP_AGENT_BIRTHDAY_ATTR` : Attribut LDAP contenant la date de naissance de l'employé (utilisé dans la recherche de missions)
- `LDAP_AGENT_NUMAGENTSIHAM_ATTR` : Attribut LDAP contenant l'identifiant SIHAM/RH de l'employé (utilisé dans la recherche de missions)

#### *CAS*
- `CAS_SERVER` : URL du serveur CAS
- `CAS_PORT` : Port du serveur CAS
- `CAS_PATH` : Service d'authentification CAS (généralement `/cas` ou `/otp`)

#### *Connexion Gravitee AMUE*
- `ETAB_PROXY_URL` : Proxy afin de forcer le passage via une IP de l'établissement (filtrage Gravitee)  
Laisser vide si pas de proxy utilisé.
- `CODE_ETABLISSEMENT_SIFAC` : Code de l'établissement défini dans la fiche de raccordement
- `CIAM_TOKEN_URL` : URL de récupération du token d'identification pour Gravitee
- `CIAM_LOGINID`/`CIAM_LOGINSECRET` : Login et secret permettant de se connecter à Gravitee (fourni dans la fiche de raccordement)
- `CIAM_USER_URL` : URL de requétage des identités dans Gravitee
- `CIAM_GROUP_URL` : URL de requétage des groupes dans Gravitee

#### *Connexion SIFAC+ (CDSView et entrepot de données)*
- `SQLMODE` : Mode de requêtage de SIFAC  
> - SifacQueryClass::QUERY_DIRECT : Interroge les tables SIFAC+ en direct (CDSView)
> - SifacQueryClass::QUERY_ECC : Interroge la base SIFAC ECC (mode compatibilité qui nécessite des pré-requis)
> - SifacQueryClass::QUERY_POSTGRE : Interroge les table SIFAC+ via l'entrepot de données (En cours de développement !)  

Par défaut : SifacQueryClass::QUERY_DIRECT;
- `SIFAC_TOKEN_URL` : URL de récupération du token d'identification pour les données SIFAC+
- `SIFAC_CLIENTID`/`SIFAC_CLIENTSECRET` : Client id et secret permettant de se connecter aux données SIFAC+
- `SIFAC_ENTREPOT_URL` : URL de récupération des données SIFAC+ via l'entrepot de données
- `SIFAC_DIRECT_URL` : URL de récupération des données SIFAC+ via les CDSView (accès direct)

#### *Gestion des rôles/groupes Gravitee*

- `ENABLE_ROLE_CIAM` : Prise en compte ou pas du mode 'Rôles CIAM' => En théorie désactivé fin 2026
> - ENABLE_ROLE_CIAM = false => Les rôles CIAM ne sont plus affichés, modifiables dans les différents écrans de l'application
> - ENABLE_ROLE_CIAM = true => Les rôles CIAM sont affichés et modifiables dans les différents écrans de l'application

- `CIAMROLES` : Tableau reprenant les rôles Gravitee fournis dans la fiche de raccordement.

#### *Missions*
- `INTERVAL_MISSIONS` : Nombre d'années d'affichage des missions

## Utilisation

- identites.php : Gère les identités dans Gravitee
- listegroupes.php : Liste les groupes et les rôles Gravitee avec les personnes membres.
- listeidentites.php : Liste les identités de Gravitee et affiche le détail pour l'identité sélectionnée.
- missions.php : Liste les informations relatives aux missions de l'agent (si la mission est saisie dans SIFAC+).
