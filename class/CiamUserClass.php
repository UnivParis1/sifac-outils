<?php

    /**
     * Représente un utilisateur CIAM (Customer Identity and Access Management).
     *
     * Cette classe encapsule :
     * - les données utilisateur issues du CIAM (SCIM)
     * - les rôles CIAM et SIFAC
     * - la transformation vers/depuis le format SCIM
     * - la génération de rendus HTML pour affichage
     *
     * @author Pascal COMTE / Université Paris 1 Panthéon-Sorbonne
     */
    class CiamUserClass    
    {

        private const GROUPMODE = 0;
        private const ROLEMODE = 1;

        /**
         * Indique si l'utilisateur est actif dans le CIAM.
         * 
         * @var bool
         */
        public bool $active = false;

        /**
         * Identifiant unique de la ressource utilisateur dans le CIAM.
         * 
         * @var string
         */
        public string $id = '';

        /**
         * Date de création de l'identité CIAM.
         * 
         * @var string
         */
        public string $creationdate = '';

        /**
         * Date de dernière modification de l'identité CIAM.
         * 
         * @var string
         */
        public string $modifdate = '';

        /**
         * Liste des rôles attribués dans le CIAM.
         * 
         * @var array
         */
        public array $ciamroles = [];

        /**
         * Liste des groupes attribués dans le CIAM.
         * 
         * @var array
         */
        public array $ciamgroups = [];

        /**
         * Liste des rôles SIFAC.
         * 
         * @var array
         */
        public array $sifacroles = [];

        /**
         * Identifiant externe de l'utilisateur dans le CIAM (externalId SCIM).
         *
         * Correspond en général à l'EPPN de l'agent.
         *
         * @var string
         */
        public readonly string $externalid;

        /**
         * Nom d'utilisateur SCIM (userName).
         *
         * Correspond en général à l'EPPN de l'agent.
         *
         * @var string
         */
        public readonly string $username;

        /**
         * Nom de famille de l'utilisateur (SCIM name.familyName).
         *
         * @var string
         */
        public readonly string $name;

        /**
         * Prénom de l'utilisateur (SCIM name.givenName).
         *
         * @var string
         */
        public readonly string $firstname;

        /**
         * Adresse mail principale de l'utilisateur (SCIM emails[0].value).
         *
         * @var string
         */
        public readonly string $mail;

        /**
         * Nom d'affichage complet de l'utilisateur (SCIM displayName).
         *
         * @var string
         */
        public readonly string $displayname;

        /**
         * EPPN (eduPersonPrincipalName) de l'utilisateur.
         *
         * Résolu depuis l'extension SCIM custom, à défaut depuis externalid ou
         * username, à défaut via une recherche LDAP par mail (voir load()).
         *
         * @var string
         */
        public readonly string $eppn;

        /**
         * Retourne le nom de la classe sans le namespace.
         *
         * @return string Nom court de la classe.
         */
        static function className() :string        
        {
            $arrayClassName = explode('\\',__CLASS__);
            return end($arrayClassName);
        }


        /**
         * Charge les informations d'un utilisateur à partir d'un tableau
         * provenant généralement d'une réponse JSON CIAM.
         * Gère également :
         * - les rôles CIAM
         * - les rôles SIFAC (avec enrichissement via $fullSifacRoleArray)
         *
         * L'EPPN est résolu en cascade : extension SCIM custom
         * (eduPersonPrincipalName), puis externalid ou username s'ils contiennent
         * un '@', puis $ciamData['eppn'], et enfin une recherche LDAP par mail.
         * Si aucune de ces sources n'aboutit, l'EPPN vaut '@'. De même, les autres
         * propriétés readonly (externalid, username, name, firstname, mail,
         * displayname) reçoivent une valeur de repli explicite (ex : "Nom
         * indéfini") si elles sont absentes des données fournies.
         *
         * @param array $ciamData Données utilisateur SCIM
         * @param array{
         *     NOM?: array<string, object>,
         *     DESCRIPTION?: array<string, object>
         * } $fullSifacRoleArray Référentiel des rôles SIFAC (voir
         *                       SifacFunctions::getSifacRolesList()), utilisé
         *                       pour enrichir les rôles/fonctions métier
         *                       trouvés dans les attributs SCIM personnalisés
         *                       (sifacPermetresPreprod/sifacFonctionsMetierPreprod).
         *                       Si absent ou incomplet, les rôles/fonctions non
         *                       trouvés dans ce référentiel sont simplement
         *                       ignorés. C'est à l'appelant de fournir un
         *                       référentiel déjà peuplé.
         *
         * @return bool Toujours true (aucune condition d'échec ; les données
         *              manquantes sont remplacées par des valeurs de repli).
         */
        public function load(array $ciamData, array $fullSifacRoleArray = ['NOM' => [], 'DESCRIPTION' => []]) :bool        
        {
            // $trace = debug_backtrace();
            // // $trace[0] contient les infos de l'appel de debug_backtrace() lui-même
            // // $trace[1] contient les infos de l'endroit qui a appelé maFonction()
            // $appelant = $trace[0]; // ou $trace[1] selon le niveau recherché
            // // echo "Fichier : " . $appelant['file'] . "\n";
            // // echo "Ligne : " . $appelant['line'] . "\n";            var_dump("La ligne appelante est : " . )
            // var_dump("Infos appelant : " . basename($appelant['file']) . '  ligne ' . $appelant['line']);
            // var_dump($ciamData);

            $ciamData = array_change_key_case($ciamData, CASE_LOWER);

            if (isset($ciamData['id'])) $this->id = trim($ciamData['id'] . '');
            if (isset($ciamData['active'])) $this->active = convertvaluetobool(trim($ciamData['active']));
            if (isset($ciamData['creationdate'])) $this->creationdate = trim($ciamData['creationdate'] . '');
            if (isset($ciamData['meta']['created'])) $this->creationdate = trim($ciamData['meta']['created'] . '');
            if (isset($ciamData['modifdate'])) $this->modifdate = trim($ciamData['modifdate'] . '');
            if (isset($ciamData['meta']['lastModified'])) $this->modifdate = trim($ciamData['meta']['lastModified'] . '');
            if (isset($ciamData['roles']) and is_array($ciamData['roles'])) $this->ciamroles = $ciamData['roles'];
            // if (isset($ciamData['entitlements']) and is_array($ciamData['entitlements'])) $this->sifacroles = $ciamData['entitlements'];
            if (isset($ciamData['groups']) and is_array($ciamData['groups']))
            {
                foreach($ciamData['groups'] as $groupData)
                {
                    if (is_array($groupData))
                    {
                        $this->ciamgroups[$groupData['value']] = $groupData;
                    }
                    else // On suppose qu'on ne récupère que l'id du groupe (c'est le cas lors d'une initialisation après un POST dans identites.php)
                    {
                        $this->ciamgroups[$groupData] = $groupData;
                    }
                }
            }

            if (isset($ciamData['urn:ietf:params:scim:schemas:extension:custom:2.0:user']["sifacPermetresPreprod"]) and SIFACROLES_INTO_CIAM)
            {
                foreach($ciamData['urn:ietf:params:scim:schemas:extension:custom:2.0:user']["sifacPermetresPreprod"] as $rolePerimetreName)
                {
                    if (isset($fullSifacRoleArray['NOM'][$rolePerimetreName]))
                    {
                        $this->sifacroles[$rolePerimetreName] = $fullSifacRoleArray['NOM'][$rolePerimetreName];
                        $this->sifacroles[$rolePerimetreName]->startdate = date("d/m/Y");
                        $this->sifacroles[$rolePerimetreName]->enddate = '31/12/2999';
                    }
                }
            }
            if (isset($ciamData['urn:ietf:params:scim:schemas:extension:custom:2.0:user']["sifacFonctionsMetierPreprod"]) and SIFACROLES_INTO_CIAM)
            {
                foreach($ciamData['urn:ietf:params:scim:schemas:extension:custom:2.0:user']["sifacFonctionsMetierPreprod"] as $roleMetierDescription)
                {
                    if (isset($fullSifacRoleArray['DESCRIPTION'][$roleMetierDescription]))
                    {
                        $this->sifacroles[$fullSifacRoleArray['DESCRIPTION'][$roleMetierDescription]->name] = $fullSifacRoleArray['DESCRIPTION'][$roleMetierDescription];
                        $this->sifacroles[$fullSifacRoleArray['DESCRIPTION'][$roleMetierDescription]->name]->startdate = date("d/m/Y");
                        $this->sifacroles[$fullSifacRoleArray['DESCRIPTION'][$roleMetierDescription]->name]->enddate = '31/12/2999';
                    }
                }
            }

            if (isset($ciamData['ciamroles']) and is_array($ciamData['ciamroles'])) $this->ciamroles = $ciamData['ciamroles'];
            if (isset($ciamData['sifacroles']) and is_array($ciamData['sifacroles'])) $this->sifacroles = $ciamData['sifacroles'];

            if (isset($ciamData['externalid'])) $this->externalid = trim($ciamData['externalid'] . '');
            if (isset($ciamData['username'])) $this->username = trim($ciamData['username'] . '');
            if (isset($ciamData['name']['familyName'])) $this->name = trim($ciamData['name']['familyName'] . '');
            if (isset($ciamData['name']['givenName'])) $this->firstname = trim($ciamData['name']['givenName'] . '');
            if (isset($ciamData['emails'][0]['value'])) $this->mail = trim($ciamData['emails'][0]['value'] . '');
            if (isset($ciamData['displayname'])) $this->displayname = trim($ciamData['displayname'] . '');


            if (isset($ciamData['urn:ietf:params:scim:schemas:extension:custom:2.0:user']['eduPersonPrincipalName'])) 
            {
                $this->eppn = trim($ciamData['urn:ietf:params:scim:schemas:extension:custom:2.0:user']['eduPersonPrincipalName']);
            }
            elseif (isset($this->externalid) and str_contains($this->externalid, '@'))
            {
                // error_log(basename(__FILE__) . " " . stripAccents(__FUNCTION__ . " => l'eppn est absent ! On considère qu'il est dans externalid : " . $this->externalid));  
                $this->eppn = $this->externalid;
            }
            elseif (isset($this->username) and str_contains($this->username, '@'))
            {
                // error_log(basename(__FILE__) . " " . stripAccents(__FUNCTION__ . " => l'eppn est absent ! On considère qu'il est dans username : " . $this->username));  
                $this->eppn = $this->username;
            }
            elseif (isset($ciamData['eppn']))
            {
                $this->eppn = $ciamData['eppn'];
            }
            else
            {
                error_log(basename(__FILE__) . " " . stripAccents(__FUNCTION__ . " => l'eppn est absent ! Impossible de trouver une correspondance de secours : On cherche dans LDAP si possible."));
                if (isset($this->mail))
                {
                    $ldapfunctions = new LdapFunctions();
                    $responseLdap = $ldapfunctions->getLDAPInfos($this->mail, [LDAP_AGENT_MAIL_ATTR], [LDAP_AGENT_EPPN_ATTR],ldapsearchclass::LDAP_OPTION_ONE_RESPONSE);
                    if ($responseLdap->status == ResponseStatus::STATUT_KO)
                    {
                        error_log(basename(__FILE__) . " " . stripAccents(__FUNCTION__ . " => Erreur dans la recherche de correspondance dans ldap avec le mail " . $this->mail . "."));  
                        $this->eppn = '@';
                    }
                    if (count($responseLdap->data) == 1)
                    {
                        error_log(basename(__FILE__) . " " . stripAccents(__FUNCTION__ . " => Correspondance ldap trouvée avec le mail " . $this->mail . " => " . reset($responseLdap->data)[LDAP_AGENT_EPPN_ATTR]));  
                        $this->eppn = reset($responseLdap->data)[LDAP_AGENT_EPPN_ATTR];
                    }
                    else
                    {
                        error_log(basename(__FILE__) . " " . stripAccents(__FUNCTION__ . " => Trop ou pas de correspondance dans ldap avec le mail " . $this->mail . "."));  
                        $this->eppn = '@';
                    }
                }
                else
                {
                        error_log(basename(__FILE__) . " " . stripAccents(__FUNCTION__ . " => Pas de mail pour la recherche LDAP."));  
                        $this->eppn = '@';
                }
            }

            if (!isset($this->externalid)) $this->externalid = 'Externalid indéfini';
            if (!isset($this->username)) $this->username = 'UserName indéfini';
            if (!isset($this->name)) $this->name = 'Nom indéfini';
            if (!isset($this->firstname)) $this->firstname = 'Prénom indéfini';
            if (!isset($this->mail)) $this->mail = 'mail@undefined.fr';
            if (!isset($this->displayname)) $this->displayname = 'DisplayName indéfini';
            if (!isset($this->eppn)) $this->eppn = '@';

            return true;
        }

        /**
         * Génère la représentation JSON SCIM d'un utilisateur CIAM
         * à partir des informations LDAP et de l'instance de CIAMUserClass courante.
         *
         * Ce JSON est utilisé pour :
         * - la création d'identité
         * - la modification d'identité
         * dans l'API CIAM.
         *
         * @param LdapUserClass $ldapInfos Informations LDAP de l'utilisateur.
         * @param bool $prettyPrint Si true, retourne la chaine JSON embellie (pour l'affichage). false par défaut
         * @return string Chaîne JSON représentant la ressource SCIM.
         */
        public function jsonString(LdapUserClass $ldapInfos, bool $prettyPrint = false) :string
        {
            $ciamIdentity = array();
            $ciamIdentity["schemas"][] = "urn:ietf:params:scim:schemas:core:2.0:User";
            $ciamIdentity["schemas"][] = "urn:ietf:params:scim:schemas:extension:custom:2.0:User";
            $ciamIdentity["id"] = $this->id; 
            $ciamIdentity["externalId"] = $ldapInfos->eppn; 
            $ciamIdentity["userName"] = $ldapInfos->eppn; // explode('@',$ldapInfos->eppn)[0]; // $ldapInfos->eppn;
            $ciamIdentity["name"]["familyName"] = $ldapInfos->name; 
            $ciamIdentity["name"]["givenName"] = $ldapInfos->firstname;
            $ciamIdentity["displayName"] = $ldapInfos->displayname; 
            $ciamIdentity["active"] = $this->active; 
            $emailArray = array("value" => $ldapInfos->mail, "primary" => true); 
            $ciamIdentity["emails"][] = $emailArray;
            // if (count($this->ciamroles)>0)
            // {
                $ciamIdentity["roles"] = array_values($this->ciamroles);
            // }


            // $ciamIdentity["entitlements"] = $this->sifacroles; 
            // if (count($ciamIdentity["entitlements"]) == 0)
            // {
            //     unset($ciamIdentity["entitlements"]);
            // }
            $roleMetier = [];
            $rolePerimetre = [];
            foreach($this->sifacroles as $key => $sifacRole)
            {
                // error_log(basename(__FILE__) . stripAccents(" " .__FUNCTION__ . " : Le nom du role est " . $sifacRole->name . " et description : " . $sifacRole->description));
                if (str_starts_with($sifacRole->name,'YC_'))
                {
                    $roleMetier[] = $sifacRole->description;
                }
                elseif (str_starts_with($sifacRole->name,'YP_'))
                {
                    $rolePerimetre[] = $sifacRole->name;
                }
                else
                {
                    error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Error : " . $sifacRole->name . " => Role non YC et non YP"));
                }
            }
            $ciamIdentity["urn:ietf:params:scim:schemas:extension:custom:2.0:User"] = 
                    ["eduPersonPrincipalName" => $ldapInfos->eppn,
                    //  "sifacFonctionsMetierPreprod" => $roleMetier,
                    //  "sifacPermetresPreprod" => $rolePerimetre
                    ]; 

            // $ciamIdentity["urn:ietf:params:scim:schemas:extension:custom:2.0:User"] = [];
            // $ciamIdentity["urn:ietf:params:scim:schemas:extension:custom:2.0:User"]["eduPersonPrincipalName"] = $ldapInfos->eppn;
            // if (count($roleMetier)>0)
            // {
            //     $ciamIdentity["urn:ietf:params:scim:schemas:extension:custom:2.0:User"]["sifacFonctionsMetierPreprod"] = $roleMetier;
            // }
            // if (count($rolePerimetre)>0)
            // {
            //     $ciamIdentity["urn:ietf:params:scim:schemas:extension:custom:2.0:User"]["sifacPermetresPreprod"] = $rolePerimetre;
            // }

            if ($prettyPrint)
            {
                return json_encode($ciamIdentity,JSON_UNESCAPED_SLASHES+JSON_PRETTY_PRINT);
            }
            else
            {
                // var_export(json_encode($ciamIdentity,JSON_UNESCAPED_SLASHES));
                return json_encode($ciamIdentity,JSON_UNESCAPED_SLASHES);
            }


        }

        /**
         * Génère le code HTML affichant les informations de l'utilisateur
         * provenant du serveur d'identité CIAM.
         *
         * @param bool $readOnly Rend les champs non modifiables si true (valeur par défaut : false)
         * @return string Code HTML du bloc d'information utilisateur CIAM
         */
        public function identityHtml(bool $readOnly = false) :string
        {

            $readOnlyInfos = '';
            if ($readOnly)
            {
                $readOnlyInfos = ' readonly="" tabindex="-1" ';
            }

            $htmlText = "";
            $htmlText .= "
                <table class='tableausimple' id='tableciaminfouser' name='tableciaminfouser'>
                    <thead>
                        <tr class='entete' >
                            <th class='titresimple' colspan='4'>Informations sur le serveur d'identité de l'AMUE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class='cellulesimple'>Identifiant CIAM :</td>
                            <td class='cellulesimple width300 " . CIAMIDENTITYIDCELLULE ."'>
                                <input type='text' readonly tabindex='-1' class='noborder width300' id='ciamid' name='ciamid' value='" . $this->id . "'/>
                            </td>
                            <td class='cellulesimple'>Active :</td>
                            <td class='cellulesimple width300 centeraligntext " . CIAMACTIVECELLULE . "'>
                                <input type='checkbox' name='ciamactive' id='ciamactive' $readOnlyInfos
                ";
            if ($this->active) $htmlText .= ' checked ';
            $htmlText .= "
                                />
                            </td>
                        </tr>
                        <tr>
                            <td class='cellulesimple'>Nom de l'utilisateur :</td>
                            <td class='cellulesimple width300 " . CIAMNAMECELLULE ."'>
                ";
            $htmlText .= isset($this->name) ? $this->name : '';
            $htmlText .= "
                            </td>
                            <td class='cellulesimple'>Prénom de l'utilisateur :</td>
                            <td class='cellulesimple width300 " . CIAMFIRSTNAMECELLULE ."'>
                ";
            $htmlText .= isset($this->firstname) ? $this->firstname : '';
            $htmlText .= "
                            </td>
                        </tr>
                        <tr>
                            <td class='cellulesimple'>Display name :</td>
                            <td class='cellulesimple width300 " . CIAMDISPLAYNAMECELLULE ."'>
                ";
            $htmlText .= isset($this->displayname) ? $this->displayname : '';
            $htmlText .= "
                            </td>
                            <td class='cellulesimple'>Mail de l'utilisateur :</td>
                            <td class='cellulesimple width300 " . CIAMMAILCELLULE ."'>
                ";
            $htmlText .= isset($this->mail) ? $this->mail : '';
            $htmlText .= "
                            </td>
                        </tr>
                        <tr>
                            <td class='cellulesimple'>Identifiant externe :</td>
                            <td class='cellulesimple width300 " . CIAMEXTERNALIDCELLULE ."'>
                ";
            $htmlText .= isset($this->externalid) ? $this->externalid : '';
            $htmlText .= "
                            </td>
                            <td class='cellulesimple'>Nom complet de l'utilisateur :</td>
                            <td class='cellulesimple width300 " . CIAMUSERNAMECELLULE ."'>
                ";
            $htmlText .= isset($this->username) ? $this->username : '';
            $htmlText .= "
                            </td>
                        </tr>
                        <tr>
                            <td class='cellulesimple'>Date de création :</td>
                            <td class='cellulesimple width300 " . CIAMCREATIONDATECELLULE ."'>
                ";
            if ($this->creationdate != '') $htmlText .= formatdate($this->creationdate);
            $htmlText .= "
                            </td>
                            <td class='cellulesimple'>Date de modification :</td>
                            <td class='cellulesimple width300 " . CIAMMODIFICATIONDATECELLULE . "'>
                ";
            if ($this->modifdate != '') $htmlText .= formatdate($this->modifdate);
            $htmlText .= "
                            </td>
                        </tr>
                    </tbody>
                </table>
                ";
        
            return $htmlText;
        }

        /**
         * Génère le tableau HTML des groupes et des rôles CIAM correspondant à l'utilisateur sous forme de cases à cocher.
         *
         * @param bool $readOnly Rend les champs non modifiables si true (valeur par défaut : false)
         * @return string HTML des tableaux de groupes et de rôles CIAM. Le bloc
         *                correspondant est omis si sa source de données n'a pas pu
         *                être récupérée ; la chaîne retournée est vide si les deux
         *                sources échouent.
         *
         */
        public function ciamRolesHtml(bool $readOnly = false) :string
        {
            $htmlText = "";
            $htmlCustomGroupText = "";
            $customCiamIndex = 0;
            for ($index=0 ; $index < 2 ; $index++)
            {
                $response = new ResponseClass();
                $response->status = ResponseStatus::STATUT_KO;
                if ($index == self::GROUPMODE)
                {
                    $ciamfunctions = new CiamFunctions;
                    $response = $ciamfunctions->getListCiamGroupInfos();
                    // var_dump($response);
                }
                elseif ($index == self::ROLEMODE and ENABLE_ROLE_CIAM)
                {
                    if (defined('CIAMROLES'))
                    {
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->data = [];
                        foreach(CIAMROLES as $key => $displayname)
                        {
                            $response->data[] = ['id' => $key, 'displayName' => $displayname];
                        }
                    }
                }
                else
                {
                    $response->status = ResponseStatus::STATUT_KO;
                }
                if ($response->status == ResponseStatus::STATUT_KO)
                {
                    // $htmlText = "Impossible de récupérer les groupes CIAM.<br>";
                    // return $htmlText;
                    // return "";
                    continue;
                }

                $ciamIndex = 0;
                $colsPerRows = 5; 

                $readOnlyInfos = '';
                if ($readOnly)
                {
                    $readOnlyInfos = ' readonly="" tabindex="-1" ';
                }

                $htmlText .= "
                    <table class='tableausimple' id='tableciamuserrole' name='tableciamuserrole'>
                        <thead>
                            <tr class='entete' >";
                if ($index == self::GROUPMODE)
                {
                    $htmlText .= "  <th class='titresimple' colspan='$colsPerRows'>Liste des groupes d'accès CIAM</th>";
                }
                else
                {
                    $htmlText .= "  <th class='titresimple' colspan='$colsPerRows'>Liste des rôles d'accès CIAM</th>";  
                }
                $htmlText .= "</tr>
                        </thead>
                        <tbody>
                    ";


                // On trie les groupes par ordre alphabétique
                usort($response->data, function($a, $b) {
                    return strcmp($a['displayName'] , $b['displayName']);
                });

                // var_dump($response->data['Resources']);

                foreach($response->data as $ciamGroupData)
                {
                    $ciamGroup = new CiamGroupClass();
                    $ciamGroup->load($ciamGroupData);

                    $roleKey = $ciamGroup->id;
                    $roleDescription = $ciamGroup->displayname;

                    if (str_starts_with(strtoupper($ciamGroup->displayname),strtoupper(INTERNAL_ROLE_PREFIX)))
                    {
                        if ($htmlCustomGroupText == '')
                        {
                            $htmlCustomGroupText = "
                                <table class='tableausimple' id='tablecustomciamgroup' name='tablecustomciamgroup'>
                                    <thead>
                                        <tr class='entete' >
                                            <th class='titresimple' >Liste des groupes applicatifs d'accès</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                ";
                        }
                        if (($customCiamIndex % $colsPerRows) == 0)
                        {
                            if ($customCiamIndex >= $colsPerRows)
                            {
                                $htmlCustomGroupText .= "</tr>" . PHP_EOL;
                            }
                            $htmlCustomGroupText .= "<tr>" . PHP_EOL;
                        }
                        $htmlCustomGroupText .= "<td class='cellulesimple'>" . PHP_EOL;
                        $ciamRoleId = "ciamgroup[" . $customCiamIndex+10000 . "]";
                        $htmlCustomGroupText .= "<input type='checkbox' class='checkciamrole customgroup' id='$ciamRoleId' name='$ciamRoleId' value='$roleKey' $readOnlyInfos ";
                        if (isset($this->ciamgroups["$roleKey"]))
                        {
                            $htmlCustomGroupText .= " checked ";
                        }
                        $htmlCustomGroupText .= " onclick='modifyCustomCheckbox_". str_replace('-','_',$this->id) ."(this);' /><label for='$ciamRoleId' $readOnlyInfos>$roleDescription</label><br>" . PHP_EOL;
                        $htmlCustomGroupText .= "</td>" . PHP_EOL;
                        $customCiamIndex++;
                    }
                    else
                    {
                        if (($ciamIndex % $colsPerRows) == 0)
                        {
                            if ($ciamIndex >= $colsPerRows)
                            {
                                $htmlText .= "</tr>" . PHP_EOL;
                            }
                            $htmlText .= "<tr>" . PHP_EOL;
                        }
                        $htmlText .= "<td class='cellulesimple'>" . PHP_EOL;
                        if ($index == self::GROUPMODE)
                        {
                            $ciamRoleId = "ciamgroup[$ciamIndex]";
                        }
                        elseif ($index == self::ROLEMODE)
                        {
                            $ciamRoleId = "ciamroles[$ciamIndex]";
                        }
                        else
                        {
                            $ciamRoleId = "Inconnu";
                        }
                        $htmlText .= "<input type='checkbox' class='checkciamrole' id='$ciamRoleId' name='$ciamRoleId' value='$roleKey' $readOnlyInfos ";
                        if ($index == self::GROUPMODE and isset($this->ciamgroups["$roleKey"]))
                        {
                            $htmlText .= " checked ";
                        }
                        elseif ($index == self::ROLEMODE and in_array($roleKey, $this->ciamroles))
                        {
                            $htmlText .= " checked "; 
                        }
                        $htmlText .= "/><label for='$ciamRoleId' $readOnlyInfos>$roleDescription</label><br>" . PHP_EOL;
                        $htmlText .= "</td>" . PHP_EOL;
                        $ciamIndex++;
                    }
                }

                for(($ciamIndex % $colsPerRows) ; ($ciamIndex % $colsPerRows)>0 ; $ciamIndex++)
                {
                    $htmlText .= "<td class='cellulesimple'>" . PHP_EOL;
                    $htmlText .= "</td>" . PHP_EOL;
                
                }
                $htmlText .= "
                            </tr>
                        </tbody>
                    </table>
                    ";

                $htmlText .= "<br>";

                if ($htmlCustomGroupText != '')
                {
                    if ($customCiamIndex >=$colsPerRows)
                    {
                        for(($customCiamIndex % $colsPerRows) ; ($customCiamIndex % $colsPerRows)>0 ; $customCiamIndex++)
                        {
                            $htmlCustomGroupText .= "<td class='cellulesimple'>" . PHP_EOL;
                            $htmlCustomGroupText .= "</td>" . PHP_EOL;
                        }
                    }
                    $htmlCustomGroupText .= "
                                </tr>
                            </tbody>
                        </table>
                        <br>
                        <br>
                        <script>
                            let tablecustomciamgroup = document.querySelector('#tablecustomciamgroup');
                            if (tablecustomciamgroup)
                            {
                                let tableheader = tablecustomciamgroup.querySelector('thead tr th');
                                if (tableheader)
                                {
                                    tableheader.colSpan = Math.min(" . $customCiamIndex . ", " . $colsPerRows . ");
                                }
                            }

                            function modifyCustomCheckbox_". str_replace('-','_',$this->id) ."(currentcheckbox)
                            {
                                if (currentcheckbox)
                                {
                                    let currentTable = currentcheckbox.closest('table');
                                    if (currentTable)
                                    {
                                        let customCheckboxList = currentTable.querySelectorAll('.customgroup');
                                        customCheckboxList.forEach((checkbox) => {
                                            if (checkbox.id != currentcheckbox.id)
                                            {
                                                checkbox.checked = false;
                                            }
                                        }
                                        );
                                    }
                                }
                            }
                        </script>";

                }
                if ($index == self::GROUPMODE)
                {
                    $htmlText .= $htmlCustomGroupText;
                    $htmlCustomGroupText = '';
                }
            }
            return $htmlText;
        }

        /**
         * Génère un tableau HTML des rôles SIFAC de l'utilisateur.
         *
         * Seuls les rôles à affectation directe sont affichés.
         * Si la description d'un rôle est vide, elle est complétée à partir de
         * $fullSifacRoleArray['NOM'][nom_du_role] si une correspondance existe.
         *
         * @param array{
         *     NOM?: array<string, object>,
         *     DESCRIPTION?: array<string, object>
         * } $fullSifacRoleArray Référentiel des rôles SIFAC (voir
         *                       SifacFunctions::getSifacRolesList()), à fournir
         *                       par l'appelant.
         *
         * @return string HTML du tableau des rôles SIFAC
         */
        public function sifacRolesHtml(array $fullSifacRoleArray)
        {
            $htmlText = "";
            $htmlText .= "
                <table class='tableausimple' id='userroletable' name='userroletable'>
                    <thead>
                        <tr class='entete'>
                            <th class='titresimple '>Nom du rôle <span class='sortindicator'> </span></th>
                            <th class='titresimple '>Description <span class='sortindicator'> </span></th>
                            <th class='titresimple hidden'>Début validité <span class='sortindicator'> </span></th>
                            <th class='titresimple hidden'>Fin validité <span class='sortindicator'> </span></th>
                        </tr>
                    </thead>
                    <tbody>
                ";
            foreach($this->sifacroles as $index => $sifacRole)
            {
                if ($sifacRole->affectation != sifacroleclass::SIFAC_ROLE_DIRECT)
                {
                    // On n'affiche pas le rôle car il est hérité
                    continue;
                }
                $extraclass = '';
                if ($sifacRole->status == SifacRoleClass::NOT_SAVE_TO_SIFAC)
                {
                    $extraclass = $extraclass . " nouvelleligne ";
                }
                $htmlText .= "<tr class='lignerole $extraclass ' " . ATTR_INDEX . "='$index' >" . PHP_EOL;
                $roleId = $sifacRole->name . '_' . $sifacRole->startdate . '_' . $sifacRole->enddate;
                $htmlText .= "  <td class='cellulesimple " . SIFACNOMROLECELLULE . "' id='$roleId'>";
                $nameRoleId = "sifacrolename[$index]";
                $htmlText .= "<input type='text' readonly tabindex='-1' class='noborder width300' id='$nameRoleId' name='$nameRoleId' value='$sifacRole->name'/>";
                $htmlText .= "  </td>" . PHP_EOL;
                $htmlText .= "  <td class='cellulesimple " . SIFACDESCRIPTIONROLECELLULE  . "'>";
                if (trim($sifacRole->description) == '')
                {
                    if (isset($fullSifacRoleArray['NOM'][$sifacRole->name]))
                    {
                        $sifacRole->description = $fullSifacRoleArray['NOM'][$sifacRole->name]->description;
                    }
                }
                $descrRoleId = "sifacdescriptionrole[$index]";
                $htmlText .= "<input type='text' readonly tabindex='-1' class='noborder width300' id='$descrRoleId' name='$descrRoleId' value='$sifacRole->description'/>";
                $htmlText .= "  </td>" . PHP_EOL;
                $htmlText .= "  <td class='cellulesimple hidden " . SIFACDEBUTVALIDITECELLULE ."'>" . PHP_EOL;
                $htmlText .= $sifacRole->startdate;
                $htmlText .= "  </td>" . PHP_EOL;
                $htmlText .= "  <td class='cellulesimple hidden " . SIFACFINVALIDITECELLULE ."'>" . PHP_EOL;
                $htmlText .= $sifacRole->enddate;
                $htmlText .= "  </td>" . PHP_EOL;
                $htmlText .= "</tr>" . PHP_EOL;
            }
            $htmlText .= "
              </tbody>
            </table>
            ";

            return $htmlText;

        }

    }


?>