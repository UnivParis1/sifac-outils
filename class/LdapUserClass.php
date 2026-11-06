<?php
    
    require_once('LdapSearchClass.php');

    /**
     * Représente un utilisateur provenant de l'annuaire LDAP.
     *
     * Cette classe permet de :
     * - stocker les informations principales d'un utilisateur LDAP
     * - préparer les paramètres de recherche LDAP
     * - convertir les données en JSON
     * 
     * Dépend de {@see LdapSearchClass}
     *
     * @author Pascal COMTE / Université Paris 1 Panthéon-Sorbonne
     */
    class LdapUserClass
    {
        /**
         * UID de l'utilisateur.
         *
         * @var string
         */
        public string $uid = '';

        /**
         * Nom de famille de l'utilisateur.
         *
         * @var string
         */
        public string $name = '';

        /**
         * Prénom de l'utilisateur.
         *
         * @var string
         */
        public string $firstname = '';

        /**
         * EduPersonPrincipalName de l'utilisateur.
         *
         * @var string
         */
        public string $eppn = '';

        /**
         * Nom d'affichage complet.
         *
         * @var string
         */
        public string $displayname = '';

        /**
         * Adresse email de l'utilisateur.
         *
         * @var string
         */
        public string $mail = '';

        /**
         * Paramètres de recherche LDAP associés à l'utilisateur.
         *
         * @var LdapSearchClass
         */
        public LdapSearchClass $search;

        /**
         * Initialise les paramètres de recherche LDAP.
         */
        public function __construct()
        {
            $this->search = new LdapSearchClass();
        }

        /**
         * Retourne le nom court de la classe.
         *
         * @return string Nom de la classe sans namespace.
         */
        public static function className() :string        {
            $arrayClassName = explode('\\',__CLASS__);
            return end($arrayClassName);
        }

        /**
         * Charge les informations LDAP d'un utilisateur à partir d'un tableau.
         *
         * Instancie également les paramètres de recherche imbriqués (this->search)
         * à partir de $ldapData['search'] (searchinfo, matchattrs, returnedattrs,
         * options), si présents.
         *
         * @param array<string, mixed> $ldapData Données LDAP.
         * @return bool Toujours true (aucune condition d'échec).
         */
        public function load(array $ldapData) :bool
        {
            $ldapData = array_change_key_case($ldapData, CASE_LOWER);
            if (isset($ldapData['name'])) $this->name = trim($ldapData['name'] . '');
            if (isset($ldapData['sn'])) $this->name = trim($ldapData['sn'] . '');
            if (isset($ldapData['firstname'])) $this->firstname = trim($ldapData['firstname'] . '');
            if (isset($ldapData['givenname'])) $this->firstname = trim($ldapData['givenname'] . '');
            if (isset($ldapData['eppn'])) $this->eppn = trim($ldapData['eppn'] . '');
            if (isset($ldapData['edupersonprincipalname'])) $this->eppn = trim($ldapData['edupersonprincipalname'] . '');
            if (isset($ldapData['displayname'])) $this->displayname = trim($ldapData['displayname'] . '');
            if (isset($ldapData['mail'])) $this->mail = trim($ldapData['mail'] . '');
            if (isset($ldapData['uid'])) $this->uid = trim($ldapData['uid'] . '');
            if (isset($ldapData['search']['searchinfo'])) $this->search->searchinfo = trim($ldapData['search']['searchinfo'] . '');
            if (isset($ldapData['search']['matchattrs']) and is_array($ldapData['search']['matchattrs'])) $this->search->matchattrs = $ldapData['search']['matchattrs'];
            if (isset($ldapData['search']['returnedattrs']) and is_array($ldapData['search']['returnedattrs'])) $this->search->returnedattrs = $ldapData['search']['returnedattrs'];
            if (isset($ldapData['search']['options'])) $this->search->options = $ldapData['search']['options'];
            return true;
        }

        /**
         * Retourne la représentation JSON de l'utilisateur LDAP.
         *
         * @param bool $prettyPrint Si true, retourne la chaine JSON embellie (pour l'affichage). false par défaut
         * @return string Chaîne JSON sérialisée.
         */
        public function jsonString(bool $prettyPrint = false) :string
        {
            $jsonData = [
                        'className' => $this->className(), 
                        'uid' => $this->uid,
                        'name' => $this->name,
                        'firstname' => $this->firstname,
                        'eppn' => $this->eppn,
                        'displayname' => $this->displayname,
                        'mail' => $this->mail,
                        'search' => ['searchinfo' => $this->search->searchinfo, 
                                     'matchattrs' => $this->search->matchattrs, 
                                     'returnedattrs' => $this->search->returnedattrs,
                                     'options' => $this->search->options
                                    ]
                        ];

            if ($prettyPrint)
            {
                return json_encode($jsonData,JSON_UNESCAPED_SLASHES + JSON_PRETTY_PRINT);
            }
            else
            {
                return json_encode($jsonData, JSON_UNESCAPED_SLASHES);
            }
        }
        
    }

?>