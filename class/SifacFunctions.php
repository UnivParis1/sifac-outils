<?php
    require_once('LdapFunctions.php');
    require_once('ResponseClass.php');
    require_once('SifacQueryClass.php');
    require_once('SifacUserClass.php');
    require_once('SifacRoleClass.php');
    require_once('SifacMissionClass.php');

    /**
     * Classe utilitaire permettant d'interagir avec le système SIFAC.
     *
     * Cette classe fournit différentes méthodes pour :
     * - récupérer les logins SIFAC à partir des informations LDAP
     * - interroger les tables SIFAC via le service SOAP RFC_READ_TABLE
     * - récupérer les rôles, utilisateurs et structures financières
     * - vérifier la validité des comptes utilisateurs
     * - manipuler les données fonctionnelles SIFAC (missions, CFI, rôles)
     *
     * Les résultats sont généralement retournés sous forme d'objet ResponseClass.
     *
     * @author Pascal COMTE / Université Paris 1 Panthéon-Sorbonne
     */
    class SifacFunctions
    {
        private const SIFACROLE = 'sifacroles';
        private const EXPIRATIONDATE = 'expirationdate';
        private const SQLMODE = 'sqlmode';
        private const DATA_SEPARATOR = '¨';   // chr(127)


        /**
         * Récupère un token d'accès à la base SIFAC valide.
         *
         * Le token est mis en cache dans un fichier local (TEMP_DIR/sifac_token.lck)
         * avec gestion de verrou exclusif (flock) afin d'éviter les accès concurrents.
         * L'acquisition du verrou est tentée pendant 5 secondes maximum avant abandon.
         *
         * Si le token est absent, invalide ou expiré, un nouveau token est généré
         * via une requête OAuth2 "client_credentials" (SIFAC_TOKEN_URL).
         *
         * @return string Token d'accès Bearer valide, ou chaîne vide si le verrou
         *                n'a pas pu être obtenu ou si la génération a échoué.
         */
        public function getSifacToken() :string
        {
            $token = '';
            $expirationDate = '';
            $expireDate = '1900-01-01 01:00:00';
            $tokenAccess = 0600; // rw- --- ---

            $paramsString = array();

            $newTokenNeeded = false;
            $sifacTokenFilename = TEMP_DIR . '/sifac_token.lck';
            $path = dirname("$sifacTokenFilename");
            $fileexist = false;
            // Si le dossier n'existe pas, on le crée et on positionne les droits dessus
            if (!file_exists($path))
            {
                mkdir("$path",0700,true);
                chmod("$path", 0700);
            }
            // Si le fichier contenant le token existe, on positionne les droits dessus 
            if (file_exists($sifacTokenFilename))
            {
                chmod("$sifacTokenFilename", $tokenAccess);
                $fileexist = true;
            }
            // En mode C+, si le fichier n'existe pas il est créé
            $fileDescriptor = fopen($sifacTokenFilename,'c+');
            $lockFlag = false;
            if ($fileDescriptor !== false)
            {
                // $lockFlag = flock($fileDescriptor,LOCK_EX);
                $start = microtime(true);
                $lockFlag = true;
                while (!flock($fileDescriptor, LOCK_EX | LOCK_NB)) 
                {
                    if ((microtime(true) - $start) > 5) 
                    {
                        $lockFlag = false;
                        break;
                    }
                    usleep(100000);
                }
            }
            // On vérifie si on a un accès exclusif au fichier
            if ($lockFlag)
            {
                $json = false;
                if ($fileexist)
                {
                    // $json = fread($fileDescriptor, filesize($sifacTokenFilename)+1); 
                    rewind($fileDescriptor);
                    $json = stream_get_contents($fileDescriptor);
                }
                if (!$json)
                {
                    // Si la lecture retourne false => On regénère un token
                    error_log(basename(__FILE__) . stripAccents(" Impossible de lire le contenu du fichier token " . basename($sifacTokenFilename) . " => On regénère le token"));
                    $newTokenNeeded = true;
                }
                else
                {
                    $arrayContent = json_decode($json,true);
                    // var_dump($arrayContent);
                    if (!isset($arrayContent['token']) or !isset($arrayContent['expirationdate']))
                    {
                        // Mauvaises données lues => On regénère un token
                        error_log(basename(__FILE__) . stripAccents(" Mauvaise données lues dans le fichier token " . basename($sifacTokenFilename) . " => On regénère le token"));
                        $newTokenNeeded = true;
                    }
                    else
                    {
                        $token = $arrayContent['token'];
                        $expireDate = $arrayContent['expirationdate'];
                        $currentDate = date('Y-m-d H:i:s', time());
                        if ($expireDate < $currentDate)
                        {
                            // Le token est expiré => On regénère le token
                            error_log(basename(__FILE__) . stripAccents(" Le token a expiré " . basename($sifacTokenFilename) . " => On regénère le token"));
                            $newTokenNeeded = true;
                        }
                        else
                        {
                            $newTokenNeeded = false;
                        }
                    }
                }

                if ($newTokenNeeded)
                {
                    $paramsString["client_id"] = SIFAC_CLIENTID;
                    $paramsString["client_secret"] = SIFAC_CLIENTSECRET;
                    $paramsString["grant_type"] = "client_credentials";
                    // $paramsString["scope"] = "scim";

                    $curl = curl_init();
                    $opts = [
                        CURLOPT_URL => SIFAC_TOKEN_URL, 
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query($paramsString, '', '&'), 
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_PROXY => ETAB_PROXY_URL,
                        // CURLOPT_VERBOSE => true
                    ];
                    curl_setopt_array($curl, $opts);
                    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                    curl_setopt($curl, CURLOPT_USERPWD, SIFAC_CLIENTID . ":" . SIFAC_CLIENTSECRET);  

                    $header = array();
                    $header[] = "Content-Type: application/x-www-form-urlencoded";
                    // $header[] = 'authorization: Basic '. base64_encode(CIAM_LOGINID . ":" . CIAM_LOGINSECRET);
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                    $json = curl_exec($curl);
                    $response = (array)json_decode($json, true);
                    $error = curl_error ($curl);

                    $httpcode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    if ($httpcode !== 200 and $error=="")
                    {
                        $error = __FUNCTION__ . " : Code retour HTTP => $httpcode : " . HTTP_ERROR_MSG[$httpcode];
                    }
                    if ($error != "")
                    {
                        $error = __FUNCTION__ . " : Erreur Curl => " . $error;
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                    }
                    elseif (isset($response['access_token']))
                    {
                        $token = $response['access_token'];
                        $validity = $response['expires_in'];
                        $timestamp = time() + $validity - 600; // On prend une marge de 600 sec => 10min
                        $expirationDate = date('Y-m-d H:i:s', $timestamp);
                    }
                    else
                    {
                        $error = __FUNCTION__ . " : Erreur => Pas de token !" . $json;
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                    }
                    if ($token != '' and $expirationDate != '')
                    {
                        // On remet le pointeur du fichier au début du fichier et on ecrit le nouveau token dans le fichier
                        if (ftruncate($fileDescriptor,0) === false
                         or rewind($fileDescriptor) === false
                         or fwrite($fileDescriptor, json_encode(array('expirationdate' => $expirationDate, 'token' => $token),JSON_PRETTY_PRINT)) === false
                         or fflush($fileDescriptor) === false
                        )
                        {
                            error_log(basename(__FILE__) . stripAccents(" Le token a été généré mais impossible de l'enregistrer dans le fichier " . basename($sifacTokenFilename) . "."));
                            flock($fileDescriptor, LOCK_UN);
                            fclose($fileDescriptor);
                            return $token;
                        }
                        error_log(basename(__FILE__) . stripAccents(" Le token a été généré et correctement enregistré dans le fichier " . basename($sifacTokenFilename) . "."));
                    }
                }
                flock($fileDescriptor, LOCK_UN);
                fclose($fileDescriptor);
                // On repositionne les droits sur le fichier au cas où il ne serait pas bon
                chmod("$sifacTokenFilename", $tokenAccess);
            }
            else // On n'a pas réussi à avoir le lock
            {
                error_log(basename(__FILE__) . stripAccents(" Impossible de récupérer le lock sur le fichier " . basename($sifacTokenFilename) . "."));
                fclose($fileDescriptor);       
            }
            //var_dump($token);
            return $token;
        }

        /**
         * Supprime (vide) le fichier de cache contenant le token SIFAC.
         *
         * Le fichier est vidé (ftruncate) sous verrou exclusif (flock), avec la même
         * logique de tentative de verrouillage que getSifacToken() (5 secondes
         * maximum). Si le fichier n'existe pas, l'opération échoue simplement (rien
         * à supprimer).
         *
         * @return ResponseClass STATUT_KO si le fichier n'existe pas, si le verrou n'a
         *                        pas pu être obtenu, ou si la troncature a échoué ;
         *                        STATUT_OK si le fichier a été correctement vidé.
         */
        public function deleteSifacTokenFile() :ResponseClass
        {
            $response = new ResponseClass();

            $sifacTokenFilename = TEMP_DIR . '/sifac_token.lck';
            // Si le fichier contenant le token existe 
            if (file_exists($sifacTokenFilename))
            {
                // En mode C+, si le fichier n'existe pas il est créé
                $fileDescriptor = fopen($sifacTokenFilename,'c+');
                $lockFlag = false;
                if ($fileDescriptor !== false)
                {
                    // $lockFlag = flock($fileDescriptor,LOCK_EX);
                    $start = microtime(true);
                    $lockFlag = true;
                    while (!flock($fileDescriptor, LOCK_EX | LOCK_NB)) 
                    {
                        if ((microtime(true) - $start) > 5) 
                        {
                            $lockFlag = false;
                            break;
                        }
                        usleep(100000);
                    }
                }
                // On vérifie si on a un accès exclusif au fichier
                if ($lockFlag)
                {
                    if (rewind($fileDescriptor) === false
                        or ftruncate($fileDescriptor,0) === false
                        or fflush($fileDescriptor) === false
                    )
                    {
                        $description = "Impossible d'effacer le jeton SIFAC.";
                        error_log(basename(__FILE__) . stripAccents(" $description"));
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->description = $description;
                        $response->data = [];
                    }
                    else
                    {
                        $description = "Le jeton SIFAC a été effacé.";
                        error_log(basename(__FILE__) . stripAccents(" $description"));
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->description = $description;
                        $response->data = [];
                    }
                }
                else
                {
                    $description = "Impossible de vérouiller le fichier contenant le jeton SIFAC.";
                    error_log(basename(__FILE__) . stripAccents(" $description"));
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->description = $description;
                    $response->data = [];
                }
                flock($fileDescriptor, LOCK_UN);
                fclose($fileDescriptor);
            }
            else
            {
                $description = "Le fichier contenant le jeton SIFAC n'existe pas.";
                error_log(basename(__FILE__) . stripAccents(" $description"));
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $description;
                $response->data = [];
            }

            return $response;
        }

        /**
         * Déduit le login SIFAC d'un utilisateur à partir de son EPPN LDAP.
         *
         * Extrait l'UID (partie avant le '@' de l'EPPN), puis recherche dans
         * la table SIFAC usr05 une correspondance sur PARID='ZUSER_CATALOG'
         * dont la valeur PARVA commence par "UID@".
         * Le '@' est ajouté après l'UID avant la recherche afin d'éviter 
         * qu'un UID préfixe d'un autre UID plus long ne produise un faux positif.
         *
         * @param LdapUserClass|CiamUserClass $userClass Doit exposer la propriété ->eppn.
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT
         *                         ou QUERY_ECC (QUERY_POSTGRE non géré par cette
         *                         méthode). Paramètre obligatoire (pas de valeur
         *                         par défaut réelle malgré le commentaire dans
         *                         la signature).
         * @return ResponseClass STATUT_KO si la lecture de la table échoue,
         *                        ou si le nombre de correspondances trouvées
         *                        n'est pas exactement 1 (aucune ou plusieurs) ;
         *                        dans tous ces cas, data reste vide. STATUT_OK
         *                        avec $response->data[login] = login (en
         *                        majuscules) si une correspondance unique est
         *                        trouvée.
         */
        public function getSifacLoginFromEppn(LdapUserClass|CiamUserClass $userClass, SifacSqlMode $sqlMode) :ResponseClass
        {
            // var_dump("On cherche le login à partir de l'EPPN => " . $userClass->eppn);
            $eppn = urldecode($userClass->eppn);
            $uid = explode('@', $eppn)[0];
            $uid .= '@';

            static $eppnLoginList = [];

            if (isset($eppnLoginList[$eppn]))
            {
                error_log(basename(__FILE__) . stripAccents(" L'EPPN $eppn a déjà été trouvé : login = " . $eppnLoginList[$eppn]));
                return $eppnLoginList[$eppn];
            }

            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_OK;

            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['bname','parva'];
                $sifacQuery->fromclause = 'usr05';
                $sifacQuery->whereclause = [
                                            "startswith(parva,'" . $uid  . "')",
                                            " and parid eq 'ZUSER_CATALOG'"
                                            ];
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                /////////////////////////////////////////////////////////////
                // MODE ECC SQL /////////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = array('BNAME', 'PARVA');
                $sifacQuery->fromclause = 'USR05';
                $sifacQuery->whereclause = ["PARVA LIKE '" . mb_strtoupper($uid)  . "%'",
                                            " AND PARID = 'ZUSER_CATALOG'"
                                        ];
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_POSTGRE;
                $sifacQuery->selectclause = array('BNAME', 'PARVA');
                $sifacQuery->fromclause = 'USR05';
                $sifacQuery->whereclause = ["parva=like." . mb_strtoupper($uid)  . "*",
                                            "parid=eq.ZUSER_CATALOG"
                                            ];
                // $response->status = ResponseStatus::STATUT_KO;
                // $response->data = [];
                // $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                // error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                // return $response;
            }
            ///////////////////////////////////////////////////////////
            
            $response_USR05 = $this->readSifacTable($sifacQuery);
            if ($response_USR05->status == ResponseStatus::STATUT_KO or count($response_USR05->data) != 1)
            {
                error_log(basename(__FILE__) . stripAccents(" Erreur ou pas/trop de correspondance lors de la récupération du USER_CATALOG pour l'UID $uid."));

                if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    $tempString = explode('@', $eppn);
                    $response->status = ResponseStatus::STATUT_OK;
                    $response->data[trim($tempString[0])] = trim(mb_strtoupper($tempString[0]));
                    error_log(basename(__FILE__) . stripAccents(" On passe au plan B => Récupération à partir de l'EPPN : " . trim(mb_strtoupper($tempString[0]))));
                    $eppnLoginList[$eppn] = $response->data[trim($tempString[0])];
                }
                else
                {
                    $eppnLoginList[$eppn] = '';
                    $response->data = [];
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->description = "Impossible de récupérer une correspondance unique avec l'EPPN $eppn.";
                }
                return $response;
            }
            $login = trim(reset($response_USR05->data)['BNAME']);
            error_log(basename(__FILE__) . stripAccents(" On a trouvé le login $login pour l'eppn $eppn.")); 
            $response->data[$login] = mb_strtoupper($login);
            $eppnLoginList[$eppn] = $response->data[$login];
            return $response;
        }

        /**
         * Déduit le login SIFAC d'un utilisateur à partir de son adresse mail LDAP ou du CIAM.
         *
         * Recherche en deux temps, avec des règles différentes à chaque étape :
         * 1. Table adr6 : recherche de l'adresse à partir du mail. Une adresse
         *    mail pouvant être saisie pour plusieurs types de tiers
         *    (utilisateur, missionnaire, fournisseur...), l'absence de
         *    correspondance renvoie STATUT_OK avec data vide (ce n'est pas une
         *    erreur) ; plusieurs correspondances sont en revanche acceptées
         *    sans erreur et toutes transmises à l'étape 2 (recherche en OU sur
         *    l'ensemble des couples addrnumber/persnumber trouvés).
         * 2. Table usr21 : recherche de l'utilisateur à partir du ou des
         *    couples addrnumber/persnumber obtenus à l'étape 1. Ici,
         *    contrairement à l'étape 1, l'absence de correspondance et la
         *    présence de plusieurs correspondances renvoient toutes les deux
         *    STATUT_KO (dans ce dernier cas, la liste des logins trouvés est
         *    journalisée dans la description et via error_log).
         *
         * @param LdapUserClass|CiamUserClass $agentinfos Doit exposer la propriété ->mail.
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT
         *                         ou QUERY_ECC (QUERY_POSTGRE non géré par cette
         *                         méthode). Paramètre obligatoire.
         * @return ResponseClass STATUT_KO en cas d'erreur technique (readSifacTable
         *                        en échec), d'absence de correspondance dans usr21,
         *                        ou de correspondances multiples dans usr21 ;
         *                        STATUT_OK avec $response->data[login] = login
         *                        (en majuscules) si une correspondance unique est
         *                        trouvée dans usr21, ou avec data vide si aucune
         *                        adresse n'a été trouvée dans adr6 (étape 1
         *                        uniquement).
         */
        public function getSifacLoginFromMail(LdapUserClass|CiamUserClass $agentinfos, SifacSqlMode $sqlMode) :ResponseClass
        {
            $mail = urldecode($agentinfos->mail);

            static $mailLoginList = [];

            if (isset($mailLoginList[$mail]))
            {
                error_log(basename(__FILE__) . stripAccents(" Le mail $mail a déjà été trouvé : login = " . $mailLoginList[$mail]));
                return $mailLoginList[$mail];
            }


            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_OK;

            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['addrnumber','persnumber'];
                $sifacQuery->fromclause = 'adr6';
                $sifacQuery->whereclause = [
                                            "smtp_addr eq '" . $mail . "'"
                                            ];
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                /////////////////////////////////////////////////////////////
                // MODE ECC SQL /////////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = ['ADDRNUMBER','PERSNUMBER'];
                $sifacQuery->fromclause = 'ADR6';
                $sifacQuery->whereclause = [
                                            "SMTP_ADDR = '" . $mail . "'"
                                            ];
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_POSTGRE;
                $sifacQuery->selectclause = ['ADDRNUMBER','PERSNUMBER'];
                $sifacQuery->fromclause = 'ADR6';
                $sifacQuery->whereclause = [
                                            "smtp_addr=eq." . $mail . ""
                                            ];

                // $response->status = ResponseStatus::STATUT_KO;
                // $response->data = [];
                // $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                // error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                // return $response;
            }
            ///////////////////////////////////////////////////////////
            
            $response_ADR6 = $this->readSifacTable($sifacQuery);
            if ($response_ADR6->status == ResponseStatus::STATUT_KO)
            {
                $logError = "Une erreur s'est produite lors de la recherche des informations d'adresse $mail - " . $response_ADR6->description;
                error_log(basename(__FILE__) . stripAccents(" $logError"));
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $logError;
                $response->data = [];
                $mailLoginList[$mail] = '';
                return $response;
            }
            if (count($response_ADR6->data) == 0)
            {
                $logError = "Aucune correspondance d'adresse mail trouvée pour $mail.";
                error_log(basename(__FILE__) . stripAccents(" $logError"));
                $response->status = ResponseStatus::STATUT_OK;
                $response->description = $logError;
                $response->data = [];
                $mailLoginList[$mail] = '';
                return $response;
            }
            ///// Attention : il peut y avoir plusieurs fois l'adresse mail dans USR06, car saisie pour l'utilisateur, le missionnaire, le fournisseur....
            // Donc s'il y a plusieurs réponses, ce n'est pas grave. On va chercher les correspondances dans la table USR21.
            // Là, si on a plusieurs correspondances qu'on lève une erreur => Trop de correspondance.
            // if (count($response_ADR6->data) > 1)
            // {
            //     error_log(basename(__FILE__) . stripAccents(" Nombre d'adresse mail trouvée pour $mail : " . count($response_ADR6->data)));
            //     $response->status = ResponseStatus::STATUT_KO;
            //     $response->description = "Trop de correspondance(s) trouvée(s) dans la table d'adresse.";
            //     $response->data = [];
            //     return $response;
            // }
            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['bname'];
                $sifacQuery->fromclause = 'usr21';
                $sifacQuery->whereclause = [];
                foreach ($response_ADR6->data as $data)
                {
                    $addrnumber = trim($data['ADDRNUMBER']);
                    $persnumber = trim($data['PERSNUMBER']);
                    $or = '';
                    if (count($sifacQuery->whereclause)>0)
                    {
                        $or = ' or ';
                    }
                    $sifacQuery->whereclause[] = $or . "(addrnumber eq '" . $addrnumber ."' and persnumber eq '" . $persnumber . "')";
                }
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                /////////////////////////////////////////////////////////////
                // MODE ECC SQL /////////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = ['BNAME'];
                $sifacQuery->fromclause = 'USR21';
                $sifacQuery->whereclause = [];
                foreach ($response_ADR6->data as $data)
                {
                    $addrnumber = trim($data['ADDRNUMBER']);
                    $persnumber = trim($data['PERSNUMBER']);
                    $or = '';
                    if (count($sifacQuery->whereclause)>0)
                    {
                        $or = ' OR ';
                    }
                    $sifacQuery->whereclause[] = $or . "(ADDRNUMBER = '" . $addrnumber ."' AND PERSNUMBER = '" . $persnumber . "')";
                }
            }            
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////

                // Exemple de requête 
                // or=(and(addrnumber.eq.1234_1,persnumber.eq.5678_1),and(addrnumber.eq.1234_2,persnumber.eq.5678_2),and(addrnumber.eq.1234_3,persnumber.eq.5678_3))

                $sifacQuery->querytype = SifacSqlMode::QUERY_POSTGRE;
                $sifacQuery->selectclause = ['BNAME'];
                $sifacQuery->fromclause = 'USR21';
                $sifacQuery->whereclause = [];
                $querystring = "";
                foreach ($response_ADR6->data as $data)
                {
                    $addrnumber = trim($data['ADDRNUMBER']);
                    $persnumber = trim($data['PERSNUMBER']);
                    if ($querystring != '')
                    {
                        $querystring .= ',';
                    }
                    $querystring .= "(and(addrnumber.eq.$addrnumber,persnumber.eq.$persnumber)";
                }
                if (count($response_ADR6->data) > 1)
                {
                    $querystring = "or(" . $querystring . ")";
                }
                $sifacQuery->whereclause[] = $querystring;

                // $response->status = ResponseStatus::STATUT_KO;
                // $response->data = [];
                // $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                // error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                // return $response;
            }
            ///////////////////////////////////////////////////////////
            
            $response_USR21 = $this->readSifacTable($sifacQuery);

            if ($response_USR21->status == ResponseStatus::STATUT_KO)
            {
                $logError = "Une erreur s'est produite lors de la recherche des informations de l'utilisateur $mail - " . $response_USR21->description;
                error_log(basename(__FILE__) . stripAccents(" $logError"));
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $logError;
                $response->data = [];
                $mailLoginList[$mail] = '';
                return $response;
            }
            if (count($response_USR21->data) == 0)
            {
                $logError = "Aucune correspondance trouvée dans la table des utilisateurs pour $mail.";
                error_log(basename(__FILE__) . stripAccents(" $logError"));
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $logError;
                $response->data = [];
                $mailLoginList[$mail] = '';
                return $response;
            }
            if (count($response_USR21->data) > 1)
            {
                $loginList = [];
                foreach($response_USR21->data as $logindata)
                {
                    $loginList[] = $logindata["BNAME"];
                }
                $logError = "Plusieurs logins trouvés dans la table des utilisateurs pour le mail $mail : " . implode(", ",$loginList) . ".";
                error_log(basename(__FILE__) . stripAccents(" $logError"));
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $logError;
                $response->data = [];
                $mailLoginList[$mail] = '';
                return $response;
            }

            $login = trim(reset($response_USR21->data)['BNAME']);
            error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " On a trouvé le login $login pour le mail $mail."));
            $response->description = '';
            $response->data[$login] = mb_strtoupper($login);
            $mailLoginList[$mail] = $response->data[$login];

            return $response;
        }

        /**
         * Reconstruit l'EPPN LDAP correspondant à un login SIFAC.
         *
         * Récupère l'UID associé au login dans la table SIFAC usr05
         * (PARID='ZUSER_CATALOG'), puis interroge LDAP (getLDAPInfos) sur cet UID
         * pour récupérer l'EPPN réel de l'agent.
         *
         * @param string $sifacLogin Login SIFAC (insensible à la casse).
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT
         *                         ou QUERY_ECC (QUERY_POSTGRE non géré par cette
         *                         méthode). Paramètre obligatoire.
         * @return string EPPN trouvé dans LDAP, ou chaîne vide si le login est inconnu
         *                de SIFAC, ambigu (plusieurs USER_CATALOG), ou sans correspondance
         *                unique dans LDAP.
         */
        public function getEppnFromSifacLogin(string $sifacLogin, SifacSqlMode $sqlMode) :string
        {
            $sifacLogin = trim(mb_strtoupper($sifacLogin));
            static $loginEppnList = [];

            if (isset($loginEppnList[$sifacLogin]))
            {
                error_log(basename(__FILE__) . stripAccents(" Le login $sifacLogin a déjà été trouvé : eppn = " . $loginEppnList[$sifacLogin]));
                return $loginEppnList[$sifacLogin];
            }

            $response = new ResponseClass();
            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['bname','parva'];
                $sifacQuery->fromclause = 'usr05';
                $sifacQuery->whereclause = [
                                            "bname eq '" . trim(mb_strtoupper($sifacLogin))  . "' and parid eq 'ZUSER_CATALOG'"
                                            ];
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                ///////////////////////////////////////////////////////////
                // Mode ECC SQL ///////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = array('BNAME', 'PARVA');
                $sifacQuery->fromclause = 'USR05';
                $sifacQuery->whereclause = ["BNAME = '" . mb_strtoupper($sifacLogin)  . "'",
                                            " AND PARID = 'ZUSER_CATALOG'"
                                           ];
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return "";
            }
            ///////////////////////////////////////////////////////////
            $response = $this->readSifacTable($sifacQuery);
            if ($response->status == ResponseStatus::STATUT_KO)
            {
                $loginEppnList[$sifacLogin] = "";
                error_log(basename(__FILE__) . stripAccents(" Erreur lors de la récupération du USER_CATALOG."));  
                return "";
            }

            // Si on est en mode ECC et qu'il n'y a pas de réponse on passe au plan B
            // On considère que le login SIFAC est une partie de l'UID => response->data = "login@"
            if ($sqlMode == SifacSqlMode::QUERY_ECC and count($response->data) == 0)
            {
                error_log(basename(__FILE__) . stripAccents(" Forçage de la réponse avec le login sifac $sifacLogin + '@'."));  
                $response->data[0]['PARVA'] = $sifacLogin . '@';
            }

            if (count($response->data) == 0)
            {
                $loginEppnList[$sifacLogin] = "";
                error_log(basename(__FILE__) . stripAccents(" Impossible de trouver le USER_CATALOG de l'utilisateur SIFAC $sifacLogin."));  
                return "";
            }
            if (count($response->data) > 1)
            {
                // En théorie impossible car ZUSER_CATALOG est une clé pour un utilisateur donné.
                $loginEppnList[$sifacLogin] = "";
                error_log(basename(__FILE__) . stripAccents(" Plusieurs correspondances USER_CATALOG pour l'utilisateur SIFAC $sifacLogin."));  
                return "";
            }

            $userCatalog = reset($response->data)['PARVA'];
            $uid = explode('@',$userCatalog)[0];

            $ldapFunctions = new LdapFunctions();
            // Note : On cherche l'uid dans tout le LDAP car peut importe si l'agent a une affectation ou pas => Ajout de l'option ldapsearchclass::LDAP_OPTION_FULL_SEARCH. 
            $responseLdap = $ldapFunctions->getLDAPInfos("$uid", [LDAP_AGENT_UID_ATTR], [LDAP_AGENT_EPPN_ATTR],ldapsearchclass::LDAP_OPTION_ONE_RESPONSE + ldapsearchclass::LDAP_OPTION_FULL_SEARCH);
            if ($responseLdap->status == ResponseStatus::STATUT_KO)
            {
                $loginEppnList[$sifacLogin] = "";
                error_log(basename(__FILE__) . stripAccents(" Erreur dans la recherche de correspondance dans ldap avec l'uid $uid de l'utilisateur $sifacLogin."));  
                return "";
            }
            if (count($responseLdap->data) == 1)
            {
                $loginEppnList[$sifacLogin] = reset($responseLdap->data)[LDAP_AGENT_EPPN_ATTR];
                error_log(basename(__FILE__) . stripAccents(" Correspondance trouvée avec l'uid $uid de l'utilisateur $sifacLogin => " . reset($responseLdap->data)[LDAP_AGENT_EPPN_ATTR]));  
                return reset($responseLdap->data)[LDAP_AGENT_EPPN_ATTR];
            }
            else
            {
                $loginEppnList[$sifacLogin] = "";
                error_log(basename(__FILE__) . stripAccents(" Trop ou pas de correspondance dans ldap avec l'uid $uid de l'utilisateur $sifacLogin."));  
                return "";
            }
        }

        /**
         * Complète, en place, la description (->description) de chaque rôle
         * d'une liste d'objets SifacRoleClass, à partir de la table ECC AGR_TEXTS
         * (LINE=0, SPRAS='F').
         *
         * Utile uniquement en mode QUERY_ECC, où les descriptions ne sont pas
         * disponibles directement dans la table des rôles (contrairement aux
         * modes DIRECT/POSTGRE).
         *
         * @param SifacRoleClass[] $roleList Tableau d'objets SifacRoleClass, modifié par référence.
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC ; seul
         *                         SifacSqlMode::QUERY_ECC est actuellement
         *                         implémenté par cette méthode (valeur par défaut).
         * @return string Toujours une chaîne vide (pas de valeur de retour exploitable ;
         *                en cas d'erreur de lecture, $roleList reste inchangé).
         */
        public function fillSifacRolesDescription(array &$roleList, SifacSqlMode $sqlMode = SifacSqlMode::QUERY_ECC) :string
        {
            if ($sqlMode != SifacSqlMode::QUERY_ECC)
            {
                error_log(basename(__FILE__) . stripAccents(__FUNCTION__ . " : Mode SQL ne nécessitant pas le chargement des descriptions."));  
                return "";
            }
            $internalArray = array();
            foreach($roleList as $role)
            {
                $internalArray[$role->name] = $role->description;
            }

            $sifacQuery = new SifacQueryClass();
            ///////////////////////////////////////////////////////////
            // Mode ECC SQL ///////////////////////////////////////////
            $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
            $sifacQuery->selectclause = array('AGR_NAME', 'TEXT');
            $sifacQuery->fromclause = 'AGR_TEXTS';
            $sifacQuery->whereclause = ["(AGR_NAME = '' "];
            foreach($internalArray as $rolename => $description)
            {
                $sifacQuery->whereclause[] = " OR AGR_NAME = '" . mb_strtoupper(trim($rolename)) . "'";
            }
            $sifacQuery->whereclause[] = ") AND LINE = 0 AND SPRAS = 'F'";
            ///////////////////////////////////////////////////////////  
            $response = $this->readSifacTable($sifacQuery);
            if ($response->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" Erreur lors de la lecture de la description des rôles."));  
                return "";
            }
            foreach ($response->data as $data)
            {
                $nomRole = trim($data['AGR_NAME']);
                $descriptionRole = trim($data['TEXT']);
                $internalArray[trim($nomRole)] = trim($descriptionRole);
            }
            foreach($roleList as $role)
            {
                $role->description = $internalArray[$role->name];
            }
            return "";
        }

        /**
         * Retourne la description (libellé français) d'un rôle SIFAC donné.
         *
         * Le résultat est mis en cache en mémoire (variable statique) pour la durée
         * de la requête courante, afin d'éviter de réinterroger SIFAC pour un même
         * rôle. Lit la table ECC AGR_TEXTS (LINE=0, SPRAS='F').
         *
         * @param string $roleName Nom du rôle SIFAC (insensible à la casse).
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC ; seul
         *                         SifacSqlMode::QUERY_ECC est actuellement
         *                         implémenté par cette méthode (valeur par défaut).
         * @return string Description du rôle, ou chaîne vide si le rôle n'existe pas
         *                dans SIFAC ou en cas d'erreur de lecture.
         */
        public function getSifacRoleDescription(string $roleName, SifacSqlMode $sqlMode = SifacSqlMode::QUERY_ECC) :string
        {
            if ($sqlMode != SifacSqlMode::QUERY_ECC)
            {
                error_log(basename(__FILE__) . stripAccents(__FUNCTION__ . " : Mode SQL ne nécessitant pas le chargement des descriptions."));  
                return "";
            }
            static $roledescriptionliste = array();

            $roleName = mb_strtoupper(trim($roleName));
            $roleDescription = '';

            if (isset($roledescriptionliste[$roleName]))
            {
                return $roledescriptionliste[$roleName];
            }

            $sifacQuery = new SifacQueryClass();
            ///////////////////////////////////////////////////////////
            // Mode ECC SQL ///////////////////////////////////////////
            $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
            $sifacQuery->selectclause = array('TEXT');
            $sifacQuery->fromclause = 'AGR_TEXTS';
            $sifacQuery->whereclause = ["AGR_NAME = '$roleName' ",
                                        " AND LINE = 0 AND SPRAS = 'F'"];
            ///////////////////////////////////////////////////////////           
            $response = $this->readSifacTable($sifacQuery);
            if ($response->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" Erreur lors de la lecture de la description du rôle : $roleName."));  
                return "";
            }
            if (!isset($response->data['TEXT']))
            {
                error_log(basename(__FILE__) . stripAccents(" La description du rôle $roleName n'existe pas dans SIFAC."));  
                return "";
            }
            $roleDescription = trim($response->data['TEXT']);
            return $roleDescription;
        }

        /**
         * Récupère les informations SIFAC (compte, identité) d'un ou plusieurs
         * utilisateurs, en croisant plusieurs tables : usr02 (compte), usr21
         * (identifiant d'adresse) et ADRP (nom/prénom).
         *
         * @param string|array $sifacLogin Soit un login SIFAC unique (string), soit un
         *                                 tableau d'objets exposant chacun une propriété
         *                                 ->login.
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT
         *                         ou QUERY_ECC (QUERY_POSTGRE non géré par cette
         *                         méthode). Paramètre obligatoire.
         * @return ResponseClass STATUT_KO si la lecture de usr02 échoue ou si aucun
         *                        utilisateur n'est trouvé (sera STATUT_OK avec data vide
         *                        dans ce dernier cas) ; sinon STATUT_OK avec
         *                        $response->data[login] = objet SifacUserClass.
         *                        Les erreurs sur usr21/ADRP sont journalisées mais
         *                        n'interrompent pas le traitement (champs nom/prénom
         *                        alors absents).
         */
        public function getSifacUserInfo(string|array $sifacLogin, SifacSqlMode $sqlMode) :ResponseClass
        {
            $response = new ResponseClass();
            $options = array();

            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['bname','gltgv','gltgb','locnt','uflag'];
                $sifacQuery->fromclause = 'usr02';
                $sifacQuery->whereclause = [];
                if (is_string($sifacLogin))
                {
                    $sifacQuery->whereclause = ["bname eq '" . trim(mb_strtoupper($sifacLogin)) . "' "];
                }
                else if (is_array($sifacLogin))
                {
                    foreach($sifacLogin as $userlogin)
                    {
                        // userlogin est un objet de type user
                        if (count($sifacQuery->whereclause)>0)
                        {
                            $or = ' or ';
                        }
                        else
                        {
                            $or = '';
                        }
                        $sifacQuery->whereclause[] = "$or bname eq '" . trim(mb_strtoupper($userlogin->login)) . "' ";
                    }
                }
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                ///////////////////////////////////////////////////////////
                // Mode ECC SQL ///////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = array('BNAME','GLTGV','GLTGB','LOCNT','UFLAG');
                $sifacQuery->fromclause = 'USR02';
                $sifacQuery->whereclause = [];
                if (is_string($sifacLogin))
                {
                    $login = mb_strtoupper($sifacLogin);
                    $sifacQuery->whereclause = ["BNAME = '" . $login . "' "];
                }
                else if (is_array($sifacLogin))
                {
                    foreach($sifacLogin as $userlogin)
                    {
                        // userlogin est un objet de type user
                        if (count($sifacQuery->whereclause)>0)
                        {
                            $or = ' OR ';
                        }
                        else
                        {
                            $or = '';
                        }
                        $sifacQuery->whereclause[] = "$or BNAME = '" . mb_strtoupper($userlogin->login) . "' ";
                    }
                }
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            ///////////////////////////////////////////////////////////
            $responseSifac = $this->readSifacTable($sifacQuery);
            if ($responseSifac->status == ResponseStatus::STATUT_KO)
            {
                $error = "Erreur lors de la lecture des infos de l'utilisateur/des utilisateurs SIFAC.";
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $response = new ResponseClass();
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $error;
                $response->data = array();
                return $response;
            }
            if (count($responseSifac->data)==0)
            {
                $error = "Aucune information disponible pour l'utilisateur/des utilisateurs SIFAC.";
                error_log(basename(__FILE__) . stripAccents(" " . $error));  
                $response = new ResponseClass();
                $response->status = ResponseStatus::STATUT_OK;
                $response->description = $error;
                $response->data = array();
                return $response;
            }

            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_OK;
            $response->description = '';
            $response->data = array();
            foreach ($responseSifac->data as $key => $userdata)
            {
                $responseSifac->data[$userdata['BNAME']] = $responseSifac->data[$key];
                unset($responseSifac->data[$key]);
            }

            // On va chercher l'identifiant d'adresse de/des utilisateur(s) (table USR21)
            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['persnumber','bname'];
                $sifacQuery->fromclause = 'usr21';
                $sifacQuery->whereclause = [];
                foreach ($responseSifac->data as $data)
                {
                    $login = trim($data['BNAME']);
                    $or = '';
                    if (count($sifacQuery->whereclause)>0)
                    {
                        $or = ' or ';
                    }
                    $sifacQuery->whereclause[] = $or . "(bname eq '" . $login ."')";
                }
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                /////////////////////////////////////////////////////////////
                // MODE ECC SQL /////////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = ['PERSNUMBER','BNAME'];
                $sifacQuery->fromclause = 'USR21';
                $sifacQuery->whereclause = [];
                foreach ($responseSifac->data as $data)
                {
                    $login = trim($data['BNAME']);
                    $or = '';
                    if (count($sifacQuery->whereclause)>0)
                    {
                        $or = ' OR ';
                    }
                    $sifacQuery->whereclause[] = $or . "(BNAME = '" . $login ."')";
                }
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            ///////////////////////////////////////////////////////////
            $response_USR21 = $this->readSifacTable($sifacQuery);
            if ($response_USR21->status == ResponseStatus::STATUT_OK)
            {
                foreach($response_USR21->data as $data)
                {
                    if (isset($responseSifac->data[$data['BNAME']]))
                    {
                        $responseSifac->data[$data['BNAME']]['PERSNUMBER'] = $data['PERSNUMBER'];
                    }
                }
            }
            else
            {
                error_log(basename(__FILE__) . stripAccents(" Erreur lors de la récupération des informations de/des utilisateur(s) (Table USR21)"));
            }

            // On va chercher le prénom et le nom de/des utilsateur(s) (table ADRP)
            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['name_first', 'name_last','persnumber'];
                $sifacQuery->fromclause = 'adrp';
                $sifacQuery->whereclause = [];
                foreach ($responseSifac->data as $data)
                {
                    if (isset($data['PERSNUMBER']))
                    {
                        $adressid = trim($data['PERSNUMBER']);
                        $or = '';
                        if (count($sifacQuery->whereclause)>0)
                        {
                            $or = ' or ';
                        }
                        $sifacQuery->whereclause[] = $or . "(persnumber eq '" . $adressid ."')";
                    }
                }
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                /////////////////////////////////////////////////////////////
                // MODE ECC SQL /////////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = ['NAME_FIRST', 'NAME_LAST','PERSNUMBER'];
                $sifacQuery->fromclause = 'ADRP';
                $sifacQuery->whereclause = [];
                foreach ($responseSifac->data as $data)
                {
                    if (isset($data['PERSNUMBER']))
                    {
                        $adressid = trim($data['PERSNUMBER']);
                        $or = '';
                        if (count($sifacQuery->whereclause)>0)
                        {
                            $or = ' OR ';
                        }
                        $sifacQuery->whereclause[] = $or . "(PERSNUMBER = '" . $adressid ."')";
                    }
                }
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            ///////////////////////////////////////////////////////////
            $response_ADRP = $this->readSifacTable($sifacQuery);

            if ($response_ADRP->status == ResponseStatus::STATUT_OK)
            {
                foreach ($response_ADRP->data as $key => $adrpdata)
                {
                    $response_ADRP->data[$adrpdata['PERSNUMBER']] = $response_ADRP->data[$key];
                    unset($response_ADRP->data[$key]);
                }
            }
            else
            {
                error_log(basename(__FILE__) . stripAccents(" Erreur lors de la récupération des informations de/des utilisateur(s) (table ADRP)"));
            }

            foreach($responseSifac->data as $key => $data)
            {
                if (isset($responseSifac->data[$key]['PERSNUMBER']) 
                    and isset($response_ADRP->data[$responseSifac->data[$key]['PERSNUMBER']])
                   )
                {
                    $responseSifac->data[$key]['NAME_FIRST'] = $response_ADRP->data[$responseSifac->data[$key]['PERSNUMBER']]['NAME_FIRST'];
                    $responseSifac->data[$key]['NAME_LAST'] = $response_ADRP->data[$responseSifac->data[$key]['PERSNUMBER']]['NAME_LAST'];
                }
            }
            // var_dump($responseSifac->data);

            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_OK;
            $response->description = '';
            $response->data = array();
            foreach ($responseSifac->data as $userdata)
            {
                $sifacuser = new SifacUserClass();
                $sifacuser->load($userdata);
                $response->data[$sifacuser->login] = $sifacuser;
            }
            return $response;
        }

        /**
         * Retourne la liste des rôles SIFAC (YP/YC/YS) avec mise en cache locale.
         *
         * Le cache est stocké dans TEMP_DIR/sifac_roles.cache et reste valide
         * DUREE_VALIDITE_CACHE_ROLE_SIFAC minutes. Au-delà, les rôles sont
         * réinterrogés via readSifacTable() sur la table agr_define.
         *
         * Les données retournées sont indexées de deux façons :
         * - $response->data['NOM'][nomDuRole]        => SifacRoleClass
         * - $response->data['DESCRIPTION'][libellé]  => SifacRoleClass
         *
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT,
         *                         QUERY_ECC ou QUERY_POSTGRE. Paramètre obligatoire
         *                         (n'intervient que lors d'un rafraîchissement du
         *                         cache ; sans effet si le cache est encore valide).
         * @return ResponseClass STATUT_KO uniquement si le verrou de fichier
         *                        n'a pas pu être obtenu ; sinon STATUT_OK avec
         *                        les rôles (issus du cache ou fraîchement lus).
         */
        public function getSifacRolesList(SifacSqlMode $sqlMode) :ResponseClass
        {
            $response = new ResponseClass();

            $roleFileAccess = 0600; // rw- --- ---
            $newRolesNeeded = false;

            $sifacRolesFilename = TEMP_DIR . '/sifac_roles.cache';
            $path = dirname("$sifacRolesFilename");
            // Si le dossier n'existe pas, on le crée et on positionne les droits dessus
            if (!file_exists($path))
            {
                mkdir("$path",0700,true);
                chmod("$path", 0700);
            }
            // Si le fichier contenant le token existe, on positionne les droits dessus 
            if (file_exists($sifacRolesFilename))
            {
                chmod("$sifacRolesFilename", $roleFileAccess);
            }

            // En mode C+, si le fichier n'existe pas il est créé
            $fileDescriptor = fopen($sifacRolesFilename,'c+');
            $lockFlag = false;
            if ($fileDescriptor !== false)
            {
                // $lockFlag = flock($fileDescriptor,LOCK_EX);
                $start = microtime(true);
                $lockFlag = true;
                while (!flock($fileDescriptor, LOCK_EX | LOCK_NB)) 
                {
                    if ((microtime(true) - $start) > 5) 
                    {
                        $lockFlag = false;
                        break;
                    }
                    usleep(100000);
                }
            }
            // On vérifie si on a un accès exclusif au fichier
            if ($lockFlag)
            {
                // $json = fread($fileDescriptor, filesize($sifacRolesFilename)+1); 
                rewind($fileDescriptor);
                $json = stream_get_contents($fileDescriptor);
                if (!$json)
                {
                    error_log(basename(__FILE__) . stripAccents(" Impossible de lire le contenu du fichier " . basename($sifacRolesFilename) . " => On regénère le fichier."));
                    $newRolesNeeded = true;
                }
                else
                {
                    $arrayContent = json_decode($json,true); // ,512,JSON_PARTIAL_OUTPUT_ON_ERROR);
                    // if (is_null($arrayContent))
                    // {
                    //     var_dump(json_last_error());
                    //     var_dump(json_last_error_msg());
                    // }
                    if (!isset($arrayContent[self::SIFACROLE]) or !isset($arrayContent[self::EXPIRATIONDATE]) or !isset($arrayContent[self::SQLMODE]))
                    {
                        // Mauvaises données lues => On regénère un token
                        error_log(basename(__FILE__) . stripAccents(" Mauvaise données lues dans le fichier " . basename($sifacRolesFilename) . " => On regénère le fichier."));
                        $newRolesNeeded = true;
                    }
                    else if ($arrayContent[self::SQLMODE] != $sqlMode->value)
                    {
                        error_log(basename(__FILE__) . stripAccents(" Le mode lecture des données sifac a changé (Mode dans le fichier : " . $arrayContent[self::SQLMODE] . "  Mode demandé : " . $sqlMode->value . ") => On regénère le fichier " . basename($sifacRolesFilename) . "."));
                        $newRolesNeeded = true;
                    }
                    else
                    {
                        $expireDate = $arrayContent[self::EXPIRATIONDATE];
                        $currentDate = date('Y-m-d H:i:s', time());
                        if ($expireDate < $currentDate)
                        {
                            // Le cache des roles est expiré => On regénère le token
                            error_log(basename(__FILE__) . stripAccents(" Le cache des rôles a expiré => On regénère le fichier " . basename($sifacRolesFilename) . "."));
                            $newRolesNeeded = true;
                        }
                        else
                        {
                            // error_log(basename(__FILE__) . stripAccents(" Le cache des rôles est valide => On retourne le contenu du fichier"));
                            $newRolesNeeded = false;
                        }
                    }
                }
                if ($newRolesNeeded)
                {

                    // On demande les rôles à SIFAC pour réactualiser le cache
                    $sifacQuery = new SifacQueryClass();
                    $sifacfunctions = new SifacFunctions();
                    $responseRoles = new ResponseClass();                            
                    if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode DIRECT SQL ///////////////////////////////////////
                        $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                        $sifacQuery->selectclause = ['agr_name','text'];
                        $sifacQuery->fromclause = 'agr_define';
                        $sifacQuery->whereclause = [
                                                    "startswith(agr_name, 'YP') or startswith(agr_name, 'YC') or startswith(agr_name, 'YS')"
                                                    ];
                    }
                    else if($sqlMode == SifacSqlMode::QUERY_ECC)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode ECC SQL ///////////////////////////////////////////
                        $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                        $sifacQuery->selectclause = array('AGR_NAME');
                        $sifacQuery->fromclause = 'AGR_DEFINE';
                        $sifacQuery->whereclause = ["AGR_NAME LIKE 'YC_%' ",
                                                    " OR AGR_NAME LIKE 'YP_%' ",
                                                    " OR AGR_NAME LIKE 'YS_%'"];
                    }
                    else
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode POSTGRE SQL ///////////////////////////////////////
                        // $sifacQuery->querytype = SifacSqlMode::QUERY_POSTGRE;
                        // $sifacQuery->selectclause = ['agr_name','text'];
                        // $sifacQuery->fromclause = 'agr_define';
                        // $sifacQuery->whereclause = ['or=(agr_name.like.YC_*,agr_name.like.YS_*,agr_name.like.YP_*)'];
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                        error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                        return $response;
                    }
                    ///////////////////////////////////////////////////////////

                    $responseRoles = $sifacfunctions->readSifacTable($sifacQuery);

                    if ($responseRoles->status == ResponseStatus::STATUT_KO)
                    {
                        $error = "Erreur lors de la lecture des rôles YS, YC et YP : " . $responseRoles->description;
                        error_log(basename(__FILE__) . stripAccents(" " . $error));  
                        // $response->status = ResponseStatus::STATUT_KO;
                        $response->description = $error;
                        // $response->data = array();
                    }
                    elseif (count($responseRoles->data)==0)
                    {
                        $error = "Aucune information disponible pour les rôles SIFAC YS, YP et YC.";
                        error_log(basename(__FILE__) . stripAccents(" " . $error));  
                        // $response->status = ResponseStatus::STATUT_OK;
                        $response->description = $error;
                        // $response->data = array();
                    }
                    else
                    {
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->description = '';
                        $response->data = array();
                        foreach ($responseRoles->data as $roleData)
                        {
                            $role = new SifacRoleClass();
                            $role->load($roleData);
                            $response->data[$role->name] = $role;
                        }
                        if (in_array($sqlMode,[SifacSqlMode::QUERY_DIRECT,SifacSqlMode::QUERY_POSTGRE]))
                        {
                            error_log(basename(__FILE__) . stripAccents(" On est en mode " . $sifacQuery->querytype->value . " => Les descriptions sont déjà presentes."));  
                        }
                        // Si on est en mode SQL ECC, on doit demander la description des rôles
                        else if ($sqlMode == SifacSqlMode::QUERY_ECC)
                        {
                            error_log(basename(__FILE__) . stripAccents(" On est en mode " . $sifacQuery->querytype->value . " => On récupère les descriptions."));  
                            $this->fillSifacRolesDescription($response->data,$sqlMode);
                        }
                        else
                        {
                            error_log(basename(__FILE__) . stripAccents(" On est en mode " . $sifacQuery->querytype->value . " => Aucune consigne pour la récupération des descriptions."));  
                        }

                        ksort($response->data);

                        // On ecrit le nouveau token dans le fichier
                        $expirationDate = date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s', time()) . ' +' . DUREE_VALIDITE_CACHE_ROLE_SIFAC . ' minutes'));
                        $arrayContent = array();
                        $jsonText = array();
                        foreach($response->data as $roleData)
                        {
                            $jsonText[] = array('name' => $roleData->name, 'description' => $roleData->description);
                        }
                        $arrayContent[self::SQLMODE] = $sqlMode->value;
                        $arrayContent[self::EXPIRATIONDATE] = $expirationDate;
                        $arrayContent[self::SIFACROLE] = $jsonText;
                        // On remet le pointeur au début du fichier (rewind), puis ecrit dans le fichier (fwrite), puis on flush (fflush)
                        // Si une des opérations échoue, on génère une erreur
                        $dataToWrite = json_encode($arrayContent,JSON_PRETTY_PRINT);
                        // $dataToWrite = json_encode($arrayContent);

                        if (rewind($fileDescriptor) === false
                         or ftruncate($fileDescriptor,0) === false
                         or ($bytesWritten = fwrite($fileDescriptor, $dataToWrite)) === false 
                         or $bytesWritten != strlen($dataToWrite)
                         or fflush($fileDescriptor) === false)
                        {
                            error_log(basename(__FILE__) . stripAccents(" Erreur : Un problème interne s'est produit lors de l'écriture des rôles dans le cache."));
                        }
                        else
                        {
                            error_log(basename(__FILE__) . stripAccents(" La mise à jour du cache des rôles s'est bien passée => " . basename($sifacRolesFilename) . "."));
                        }
                        // var_dump($arrayContent);
                    }
                }
                // On construit la réponse avec le contenu du fichier (qu'on vient d'actualiser ou pas)
                $response->status = ResponseStatus::STATUT_OK;
                // $response->description = '';
                $response->data = array();
                foreach($arrayContent[self::SIFACROLE] as $roleInfos)
                {
                    $role = new SifacRoleClass();
                    $role->load($roleInfos);
                    $response->data['NOM'][$role->name] = $role;
                    $response->data['DESCRIPTION'][$role->description] = $role;
                }
            }
            else
            {
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = "Erreur : Impossible de vérouiller le fichier des roles " . basename($sifacRolesFilename) . ".";
                $response->data = array();
            }
            fclose($fileDescriptor);
            // On repositionne les droits sur le fichier au cas où il ne serait pas bon
            chmod("$sifacRolesFilename", $roleFileAccess);

            // error_log(basename(__FILE__) . stripAccents(" result_json => " . print_r($response,true)));
            return $response;
        }

        /**
         * Supprime (vide) le fichier de cache contenant la liste des rôles SIFAC.
         *
         * Le fichier est vidé (ftruncate) sous verrou exclusif (flock), avec la même
         * logique de tentative de verrouillage que getSifacRolesList() (5 secondes
         * maximum). Si le fichier n'existe pas, l'opération échoue simplement (rien
         * à supprimer).
         *
         * @return ResponseClass STATUT_KO si le fichier n'existe pas, si le verrou n'a
         *                        pas pu être obtenu, ou si la troncature a échoué ;
         *                        STATUT_OK si le fichier a été correctement vidé.
         */
        public function deleteSifacRoleCache() :ResponseClass
        {
            $response = new ResponseClass();

            $sifacRolesFilename = TEMP_DIR . '/sifac_roles.cache';
            // Si le fichier contenant le token existe 
            if (file_exists($sifacRolesFilename))
            {
                // En mode C+, si le fichier n'existe pas il est créé
                $fileDescriptor = fopen($sifacRolesFilename,'c+');
                $lockFlag = false;
                if ($fileDescriptor !== false)
                {
                    // $lockFlag = flock($fileDescriptor,LOCK_EX);
                    $start = microtime(true);
                    $lockFlag = true;
                    while (!flock($fileDescriptor, LOCK_EX | LOCK_NB)) 
                    {
                        if ((microtime(true) - $start) > 5) 
                        {
                            $lockFlag = false;
                            break;
                        }
                        usleep(100000);
                    }
                }
                // On vérifie si on a un accès exclusif au fichier
                if ($lockFlag)
                {
                    if (rewind($fileDescriptor) === false
                        or ftruncate($fileDescriptor,0) === false
                        or fflush($fileDescriptor) === false
                    )
                    {
                        $description = "Impossible d'effacer le cache des rôles SIFAC.";
                        error_log(basename(__FILE__) . stripAccents(" $description"));
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->description = $description;
                        $response->data = [];
                    }
                    else
                    {
                        $description = "Le cache des rôles SIFAC a été effacé.";
                        error_log(basename(__FILE__) . stripAccents(" $description"));
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->description = $description;
                        $response->data = [];
                    }
                }
                else
                {
                    $description = "Impossible de vérouiller le fichier contenant le cache des rôles SIFAC.";
                    error_log(basename(__FILE__) . stripAccents(" $description"));
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->description = $description;
                    $response->data = [];
                }
                flock($fileDescriptor, LOCK_UN);
                fclose($fileDescriptor);
            }
            else
            {
                $description = "Le fichier contenant le cache des rôles n'existe pas.";
                error_log(basename(__FILE__) . stripAccents(" $description"));
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $description;
                $response->data = [];
            }

            return $response;
        }


        /**
         * Récupère la liste des rôles SIFAC attribués à un utilisateur donné,
         * à partir de la table agr_users.
         *
         * En mode QUERY_ECC, complète automatiquement la description de chaque
         * rôle via fillSifacRolesDescription().
         *
         * @param SifacUserClass $sifacUser Utilisateur SIFAC (utilise sa propriété ->login).
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT,
         *                         QUERY_ECC ou QUERY_POSTGRE. Paramètre obligatoire.
         * @return ResponseClass STATUT_KO en cas d'erreur de lecture ; STATUT_OK avec
         *                        $response->data = tableau indexé numériquement
         *                        d'objets SifacRoleClass (status=SAVE_TO_SIFAC), trié
         *                        par nom de rôle (ksort). data vide et description
         *                        explicative si l'utilisateur n'a aucun rôle.
         */
        public function getSifacRoleByUser(SifacUserClass $sifacUser, SifacSqlMode $sqlMode) :ResponseClass
        {
            $response = new ResponseClass();
            $login = mb_strtoupper($sifacUser->login);
            error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Le login est " . $login));  

            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['agr_name','text','from_dat', 'to_dat', 'col_flag'];
                $sifacQuery->fromclause = 'agr_users';
                $sifacQuery->whereclause = [
                                            "uname eq '" . $login . "'"
                                            ];
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                ///////////////////////////////////////////////////////////
                // Mode SQL ECC ///////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = array('AGR_NAME','FROM_DAT','TO_DAT','COL_FLAG');
                $sifacQuery->fromclause = 'AGR_USERS';
                $sifacQuery->whereclause = ["UNAME = '" . $login . "' "];
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            ///////////////////////////////////////////////////////////
            // error_log(basename(__FILE__) . stripAccents(" Avant le readsifactable dans " . __FUNCTION__));
            $responseRoles = $this->readSifacTable($sifacQuery);
            if ($responseRoles->status == ResponseStatus::STATUT_KO)
            {
                $error = "Erreur lors de la lecture des rôles de l'utilisateur $login";
                error_log(basename(__FILE__) . stripAccents(" " . $error));  
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $error;
                $response->data = array();
            }
            elseif (count($responseRoles->data)==0)
            {
                $error = "Aucun rôle SIFAC pour l'utilisateur $login";
                error_log(basename(__FILE__) . stripAccents(" " . $error));  
                $response->status = ResponseStatus::STATUT_OK;
                $response->description = $error;
                $response->data = array();
            }
            else
            {
                $response->status = ResponseStatus::STATUT_OK;
                $response->description = '';
                $response->data = array();
                foreach ($responseRoles->data as $roleData)
                {
                    $role = new SifacRoleClass();
                    $role->load($roleData);
                    $role->status = SifacRoleClass::SAVE_TO_SIFAC;
                    $response->data[] = $role;
                }
            }
            if ($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                error_log(basename(__FILE__) . stripAccents(" On est en mode " . $sifacQuery->querytype->value . " => On récupère les descriptions des rôles de l'utilisateur $login."));  
                $this->fillSifacRolesDescription($response->data, $sqlMode);
            }
            ksort($response->data);
            return $response;
        }


        /**
         * Lit une table SIFAC via l'API REST exposée par l'entrepôt de données
         * PostgreSQL (SIFAC_ENTREPOT_URL), en mode lecture seule.
         *
         * Construit l'URL de requête à partir des clauses select/where de
         * $sifacQuery, récupère un token d'accès via getSifacToken(), puis
         * exécute la requête via cURL. Les clés des lignes retournées sont
         * mises en majuscules (array_change_key_case_recursive).
         *
         * @param SifacQueryClass $sifacQuery Requête (table, colonnes, clauses where).
         * @return ResponseClass STATUT_KO en cas d'échec du token ou d'erreur cURL/HTTP,
         *                        STATUT_OK sinon (data vide si aucun résultat).
         */
        private function readSifacTable_PostGreSQL(SifacQueryClass $sifacQuery) :ResponseClass
        {
            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_OK;

            error_log(basename(__FILE__) . stripAccents(' Appel en mode POSTGRE : ' . $sifacQuery->fromclause));

            $curl = curl_init();
            $paramsString = "";

            $fullurl = SIFAC_ENTREPOT_URL . mb_strtolower($sifacQuery->fromclause);

            $select = mb_strtolower('select=' . implode(',', $sifacQuery->selectclause));
            $clause = rawurlencode(trim(implode('&' ,$sifacQuery->whereclause))); // implode('&', $sifacQuery->whereclause);
            // $clause = urlencode(trim(implode('&' ,$sifacQuery->whereclause))); // implode('&', $sifacQuery->whereclause);
            $dummy = 'dummy=' .  time();

            $fullurl .= '?' . $dummy . '&'. $select . '&' . $clause;
            error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] " . $fullurl));
            // var_dump($fullurl);

            $opts = [
                CURLOPT_URL => $fullurl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_PROXY => ETAB_PROXY_URL
            ];
    
            $accessToken = $this->getSifacToken();
            if ($accessToken == '')
            {
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = 'Impossible de récupérer un jeton SIFAC.';
                return $response;
            }
            $header = [];
            $header[] = "authorization: Bearer " . $accessToken;
            $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
            $header[] = "Pragma: no-cache";
            $header[] = "Expires: 0";
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

            // curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: Bearer $accessToken"));
    
            curl_setopt_array($curl, $opts);
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            $json = curl_exec($curl);
            $error = curl_error ($curl);
            $httpcode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($httpcode !== 200 and $error=="")
            {
                $error = __FUNCTION__ . " : Code retour HTTP => $httpcode : " . HTTP_ERROR_MSG[$httpcode];
            }
            if ($error != "")
            {
                $error = __FUNCTION__ . " Erreur Curl : Lecture table " . $sifacQuery->fromclause .  " =>  " . $error;
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $error;
                return $response;
            }
            $data = json_decode($json, true);

            if (count($data) == 0)
            {
                $response->status = ResponseStatus::STATUT_OK;
                $response->description = "La requête n'a renvoyée aucune valeur.";
                $response->data = [];
                return $response;
            }

            // $data = array_change_key_case($data,CASE_UPPER);
            $data = array_change_key_case_recursive($data,CASE_UPPER);
            $response->data = $data;
            $response->status = ResponseStatus::STATUT_OK;
            $response->description = "";

            return $response;
        }

        /**
         * Lit une table SIFAC via l'appel SOAP RFC_READ_TABLE (système ECC classique).
         *
         * Construit les tableaux FIELDS/OPTIONS attendus par la fonction SAP
         * à partir des clauses select/where de $sifacQuery, instancie un
         * \SoapClient (WSDL_LIRETABLE, identifiants SAP_LOGIN/SAP_PASSWD),
         * puis découpe chaque ligne retournée selon le séparateur DATA_SEPARATOR.
         *
         * @param SifacQueryClass $sifacQuery Requête (table, colonnes, clauses where).
         * @return ResponseClass STATUT_KO si l'appel SOAP échoue (\SoapFault interne,
         *                        non propagée), STATUT_OK sinon (data vide si
         *                        aucun résultat).
         */
        private function readSifacTable_ECC(SifacQueryClass $sifacQuery) :ResponseClass
        {
            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_OK;

            $fieldsarray = [];
            foreach ($sifacQuery->selectclause as $columnname)
            {
                $fieldsarray[] = array('FIELDNAME' => $columnname,
                                    'OFFSET' => 0,
                                    'LENGTH' => 0,
                                    'TYPE' => '',
                                    'FIELDTEXT' => '');
            }
            // error_log(basename(__FILE__) . stripAccents(" Le fieldsarray = " . print_r($fieldsarray,true)));

            $wherearray = [];
            foreach ($sifacQuery->whereclause as $where)
            {
                $wherearray[] = array('TEXT' => mb_strtoupper($where));
            }
            // error_log(basename(__FILE__) . stripAccents(" Le wherearray = " . print_r($wherearray,true)));

            try {
                $client = new \SoapClient(WSDL_LIRETABLE,array('login' => SAP_LOGIN,'password' => SAP_PASSWD));
                $response_READTABLE = $client->RFC_READ_TABLE(
                    array('DATA' => array(), 
                        'FIELDS' => $fieldsarray,
                        'OPTIONS' => $wherearray,
                        'QUERY_TABLE' => $sifacQuery->fromclause,
                        'DELIMITER' => self::DATA_SEPARATOR
                        ));
            } catch (\SoapFault $fault) {
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = "Erreur : La fonction " . __FUNCTION__ . " a rencontré une erreur interne.<br>" . $fault->faultstring;
            }

            // error_log(basename(__FILE__) . stripAccents(" readtable SIFAC response_READTABLE = " . print_r($response_READTABLE,true)));

            if ($response->status != ResponseStatus::STATUT_KO)
            {
                $response_READTABLE = json_decode(json_encode($response_READTABLE), true);
                // error_log(basename(__FILE__) . stripAccents(" L'appel à SIFAC est ok => On va lire les résultats " . print_r($response,true)));

                /// On récupère les résultats et on les met dans le tableau résultat
                if (isset($response_READTABLE['DATA']['item']) and count($response_READTABLE['DATA']['item'])>0)
                {
                    // error_log(basename(__FILE__) . stripAccents(" Le DATA[ITEM] est set et il y a des réponses"));
                    foreach ($response_READTABLE['DATA']['item'] as $itemkey => $item)
                    {
                        if (is_string($item))
                        {
                            $data = explode(self::DATA_SEPARATOR,$item);
                        }
                        else
                        {
                            $data = explode(self::DATA_SEPARATOR,$item['WA']);
                        }
                        // error_log(basename(__FILE__) . stripAccents(" data = " . print_r($data,true) . " itemkey = " . $itemkey));

                        $resultset = [];
                        foreach($data as $datakey => $dataresult)
                        {
                            // error_log(basename(__FILE__) . stripAccents(" J'ai le dataresult = " . $dataresult . " datakey = " . $datakey));
                            // $resultset[] = $dataresult;
                            // error_log(basename(__FILE__) . stripAccents(" La colonne correspondante = " . $fieldsarray[$datakey]['FIELDNAME']));
                            $resultset[$fieldsarray[$datakey]['FIELDNAME']] = $dataresult;
                        }

                        // error_log(basename(__FILE__) . stripAccents(" J'ai le resultset = " . print_r($resultset,true)));
                        $response->data[] = $resultset;
                        $response->status = ResponseStatus::STATUT_OK;
                    }
                }
                else
                {
                    $response->status = ResponseStatus::STATUT_OK;
                    $response->description = "La requête n'a renvoyée aucune valeur.";
                }
            }
            return $response;
        }

        /**
         * Lit une table SIFAC via l'API REST OData exposée par SIFAC_DIRECT_URL,
         * avec pagination automatique (paramètres $select/$filter, top/skip).
         *
         * Boucle tant que le nombre de lignes retournées égale $sifacQuery->top
         * (pagination), en réutilisant un token d'accès via getSifacToken() à
         * chaque appel. Les métadonnées OData (__METADATA) sont retirées des
         * résultats, et les clés sont mises en majuscules.
         *
         * @param SifacQueryClass $sifacQuery Requête (table, colonnes, clauses
         *                                    where, top/skip pour la pagination).
         * @return ResponseClass STATUT_KO en cas d'échec du token, d'erreur cURL/HTTP
         *                        ou de format de réponse invalide ; STATUT_OK sinon
         *                        (data vide si aucun résultat).
         */
        private function readSifacTable_Direct(SifacQueryClass $sifacQuery) :ResponseClass
        {
            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_OK;

            error_log(basename(__FILE__) . stripAccents(' Appel en mode direct : ' . $sifacQuery->fromclause));

            $paramsString = "";

            $url = SIFAC_DIRECT_URL . "?nom=" . mb_strtolower($sifacQuery->fromclause);
            $select = '%24select=' . rawurlencode(implode(',', $sifacQuery->selectclause));
            // var_dump($select);
            $clause = '%24filter=' . rawurlencode(trim(implode(' ' ,$sifacQuery->whereclause)));
            // var_dump($clause);
            $dummy = 'dummy=' .  time();

            $exitloop = false;
            $data = [];

            while (!$exitloop)
            {
                $curl = curl_init();

                $fullurl = $url . '&' . $dummy . '&' . $select . '&' . $clause . '&top=' . $sifacQuery->top . '&skip=' . $sifacQuery->skip;
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $fullurl"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] " . $fullurl));

                $opts = [
                    CURLOPT_URL => $fullurl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_PROXY => ETAB_PROXY_URL
                ];
        
                $accessToken = $this->getSifacToken();
                if ($accessToken == '')
                {
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->description = 'Impossible de récupérer un jeton SIFAC.';
                    return $response;
                }
                $header = [];
                $header[] = "authorization: Bearer " . $accessToken;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
                // curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: Bearer $accessToken"));
        
                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                $json = curl_exec($curl);
                $error = curl_error ($curl);
                $httpcode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpcode !== 200 and $error=="")
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpcode : " . HTTP_ERROR_MSG[$httpcode];
                }
                if ($error != "")
                {
                    $error = __FUNCTION__ . " Erreur Curl : Lecture table " . $sifacQuery->fromclause .  " =>  " . $error;
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->description = $error;
                    return $response;
                }
                $tempdata = json_decode($json, true);
                $tempdata = array_change_key_case_recursive($tempdata,CASE_UPPER);

                if (!isset($tempdata['D']['RESULTS']))
                {
                    $error = "Erreur Curl : Format de la réponse non valide.";
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->description = $error;
                    return $response;
                }
                $tempdata = $tempdata['D']['RESULTS'];

                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . ' ' . __LINE__ . ' ' . print_r($data,true)));
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . ' ' . __LINE__ . ' ' . print_r($tempdata,true)));
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . ' ' . __LINE__ . ' tempdata count = ' . count($tempdata) . ' top = ' . $top));

                // var_dump($data,$tempdata);
                $data = array_merge($data,$tempdata);
                // var_dump($data);

                if (count($tempdata) == $sifacQuery->top)
                {
                    $sifacQuery->skip += $sifacQuery->top;
                }
                else
                {
                    $exitloop = true;
                }
            }

            // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . ' ' . __LINE__ . ' data count = ' . count($data)));

            if (count($data) == 0)
            {
                $response->status = ResponseStatus::STATUT_OK;
                $response->description = "La requête n'a renvoyée aucune valeur.";
                $response->data = [];
                return $response;
            }

            foreach($data as $key => $datainfos)
            {
                if (isset($datainfos['__METADATA']))
                {
                    // On supprime en passant par la clé de $data car $datainfos est une copie de l'élément du tableau
                    // On va unset la variable $datainfos mais pas l'élément du tableau $data[$key]
                    unset($data[$key]['__METADATA']);
                }
            }
            // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " On est pret pour retourner les valeurs."));
            // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " data = " . print_r($data,true)));


            $response->data = $data;
            $response->status = ResponseStatus::STATUT_OK;
            $response->description = "";

            // var_dump($response->data);

            return $response;            
        }

        /**
         * Point d'entrée unique pour lire une table SIFAC, quel que soit le mode
         * de connexion configuré sur la requête.
         *
         * Initialise les valeurs par défaut de pagination (top=100, skip=0 si non
         * renseignées dans $sifacQuery), puis délègue à la méthode privée
         * correspondante selon $sifacQuery->querytype :
         * - QUERY_POSTGRE => readSifacTable_PostGreSQL()
         * - QUERY_ECC     => readSifacTable_ECC()
         * - QUERY_DIRECT  => readSifacTable_Direct()
         *
         * @param SifacQueryClass $sifacQuery Requête (table, colonnes, clauses where,
         *                                    type de connexion, top/skip).
         * @return ResponseClass STATUT_KO si le type de requête est inconnu ou si
         *                        la méthode déléguée échoue ; STATUT_OK sinon, avec
         *                        $response->data = tableau de lignes (tableaux
         *                        associatifs, clés en majuscules).
         */
        public function readSifacTable(SifacQueryClass $sifacQuery) :ResponseClass
        {
            $response = new ResponseClass();

            if ($sifacQuery->top == -1)
            {
                $sifacQuery->top = 100;
            }
            if ($sifacQuery->skip == -1)
            {
                $sifacQuery->skip = 0;
            }

            if ($sifacQuery->querytype == SifacSqlMode::QUERY_POSTGRE)
            {
                $response = $this->readSifacTable_PostGreSQL($sifacQuery);
                return $response;
            }
            elseif ($sifacQuery->querytype == SifacSqlMode::QUERY_ECC)
            {
                $response = $this->readSifacTable_ECC($sifacQuery);
                return $response;

            }
            elseif ($sifacQuery->querytype == SifacSqlMode::QUERY_DIRECT)
            {
                $response = $this->readSifacTable_Direct($sifacQuery);
                return $response;
            }
            else
            {
                $response->data = [];
                $response->description = 'Le type de reqète ' . $sifacQuery->querytype->value . ' est inconnu !';
                $response->status = ResponseStatus::STATUT_KO;
                return $response;
            }
        }

        /**
         * Récupère l'historique des missions (ordres de mission) d'un agent pour
         * une année donnée, avec leur statut de paiement.
         *
         * Identifie d'abord le matricule SIFAC (PERNR) de l'agent : en priorité via
         * son numéro agent SIHAM (LDAP_AGENT_NUMAGENTSIHAM_ATTR) recherché dans
         * pa0105 ; si absent ou sans correspondance, recherche par approximation
         * sur nom/prénom/date de naissance dans pa0002. Parcourt ensuite en cascade
         * PTRV_HEAD (missions), PTRV_SHDR (montants), PTRV_ROT_AWKEY, BKPF et BSAK
         * pour déterminer si et quand chaque mission a été payée.
         *
         * @param string $uid  UID LDAP de l'agent.
         * @param int    $year Année de référence (filtre sur DATV1 LIKE '$year%').
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT
         *                         ou QUERY_ECC (QUERY_POSTGRE non géré par cette
         *                         méthode). Paramètre obligatoire.
         * @return ResponseClass STATUT_KO si l'agent est ambigu (plusieurs matricules
         *                        correspondants) ou en cas d'erreur de lecture d'une
         *                        des tables SIFAC ; STATUT_OK avec
         *                        $response->data[missionid] = objet SifacMissionClass
         *                        (paiement = "non indemnisée" tant qu'aucun
         *                        rapprochement comptable n'est trouvé).
         */
        public function getSifacAgentMissions(string $uid, int $year, SifacSqlMode $sqlMode) :ResponseClass
        {
            $matricule = '';
            $nom = '';
            $prenom = '';
            $naissance = '';
            $sihamagentid = '';

            // var_dump(__FUNCTION__ . " : Le mode de requêtage est : ",$sqlMode);

            // On demande à LDAP les attributs de l'agent
            $ldapFunctions = new LdapFunctions();
            $response = $ldapFunctions->getLDAPInfos("$uid",
                          [LDAP_AGENT_UID_ATTR], 
                          [LDAP_AGENT_NOM_ATTR,LDAP_AGENT_PRENOM_ATTR,LDAP_AGENT_BIRTHDAY_ATTR,LDAP_AGENT_NUMAGENTSIHAM_ATTR],
                          ldapsearchclass::LDAP_OPTION_ONE_RESPONSE);

            if ($response->status == ResponseStatus::STATUT_OK and count($response->data)>0)
            {
                if (isset($response->data[0][LDAP_AGENT_NOM_ATTR])) $nom = $response->data[0][LDAP_AGENT_NOM_ATTR];
                if (isset($response->data[0][LDAP_AGENT_PRENOM_ATTR])) $prenom = $response->data[0][LDAP_AGENT_PRENOM_ATTR];
                if (isset($response->data[0][LDAP_AGENT_BIRTHDAY_ATTR])) $naissance = $response->data[0][LDAP_AGENT_BIRTHDAY_ATTR];
                if (isset($response->data[0][LDAP_AGENT_NUMAGENTSIHAM_ATTR])) $sihamagentid = $response->data[0][LDAP_AGENT_NUMAGENTSIHAM_ATTR];

                if ($sihamagentid != '')
                {
                    $sifacQuery = new SifacQueryClass();
                    $dateDuJour = date('Ymd');
                    if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode DIRECT SQL ///////////////////////////////////////
                        $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                        $sifacQuery->selectclause = ['pernr'];
                        $sifacQuery->fromclause = 'pa0105';
                        $sifacQuery->whereclause = ["usrid eq '$sihamagentid'",
                                                    " and subty eq 'ZEXT'", 
                                                    " and usrty eq 'ZEXT'" , 
                                                    " and begda le '$dateDuJour'", 
                                                    " and endda ge '$dateDuJour'"
                                                    ];
                    }
                    else if($sqlMode == SifacSqlMode::QUERY_ECC)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode SQL ECC ///////////////////////////////////////////
                        $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                        $sifacQuery->selectclause = array('PERNR');
                        $sifacQuery->fromclause = 'PA0105';
                        $sifacQuery->whereclause = array("USRID = '$sihamagentid'", 
                                                         " AND SUBTY = 'ZEXT'", 
                                                         " AND USRTY = 'ZEXT'" , 
                                                         " AND BEGDA <= '$dateDuJour'", 
                                                         " AND ENDDA >= '$dateDuJour'"
                                                        );
                    }
                    else
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode POSTGRE SQL ///////////////////////////////////////
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                        error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                        return $response;
                    }
                    ///////////////////////////////////////////////////////////
                    // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " La requete pour la recherche dans SIFAC est : " . print_r($sifacQuery,true)));
                    $response = $this->readSifacTable($sifacQuery);
                    if ($response->status == ResponseStatus::STATUT_KO)
                    {
                        return $response;
                    }
                    // Si pas de correspondance => On doit chercher par approximations
                    if (!isset($response->data[0]['PERNR']))
                    {
                        error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Pas de correspondance dans SIFAC => recherche par approximations."));
                        // On force la variable à vide comme si on n'avait pas trouvé le numéro agent dans LDAP
                        $sihamagentid = '';
                        // On force le matricule à vide => En théorie inutile car déjà vide !
                        $matricule = '';
                    }
                    // Si trop de correspondance
                    elseif (count($response->data) != 1)
                    {
                        $response->data = [];
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->description = 'Plusieurs matricules correspondent à la recherche.';
                        return $response;
                    }
                    else
                    {
                        $matricule = $response->data[0]['PERNR'];
                        error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Correspondance trouvée dans SIFAC => uid $uid => numagent $sihamagentid, matricule SIFAC $matricule ."));
                    }
                }

                // Si matricule est vide : 
                //  * Soit on n'a pas trouvé le numéro agent dans LDAP
                //  * Soit on n'a pas trouvé de correspondance dans SIFAC
                // On va donc chercher dans SIFAC une correspondance par approximations

                // var_dump(__FUNCTION__ . " : Le matricule est $matricule");

                if ($matricule == '')
                {
                    $nom = '%' . mb_strtoupper(preg_replace("#[^A-Z0-9]+#i", "%", trim(stripAccents($nom)))) . '%';
                    $prenom = '%' . mb_strtoupper(preg_replace("#[^A-Z0-9]+#i", "%", trim(stripAccents($prenom)))) . '%';

                    // Si plusieurs '%' se suivent, on le remplace par un seul
                    $nom = preg_replace("#[%]+#i", "%", $nom);
                    $prenom = preg_replace("#[%]+#i", "%", $prenom);

                    // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " => Le nom = $nom   Le prénom = $prenom "));

                    $naissancearray = explode("-",$naissance);
                    $anneenaissance = $naissancearray[0];
                    $moisnaissance = $naissancearray[1];

                    $response->data = [];
                    $response->status = ResponseStatus::STATUT_OK;
                    $response->description = '';

                    $sifacQuery = new SifacQueryClass();

                    if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode DIRECT SQL ///////////////////////////////////////
                        $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                        $sifacQuery->selectclause = ['pernr'];
                        $sifacQuery->fromclause = 'pa0002';
                        $sifacQuery->whereclause = [];
                        $sifacQuery->whereclause[] = "(";
                        $nameparttab = explode('%',$nom);
                        $firstfound = false;
                        foreach ($nameparttab as $index => $namepart)
                        {
                            if ($namepart == '')
                            {
                                continue;
                            }

                            // if (count($sifacQuery->whereclause) == 0)
                            // if ($index == 0)
                            if (!$firstfound)
                            {
                                $sifacQuery->whereclause[] = "substringof('$namepart',nchmc)";
                                $firstfound = true;
                            }
                            else
                            {
                                $sifacQuery->whereclause[] = "and substringof('$namepart',nchmc)";
                            }
                        }

                        $sifacQuery->whereclause[] = ") and (";

                        $firstfound = false;
                        $nameparttab = explode('%',$prenom);
                        foreach ($nameparttab as $index => $namepart)
                        {
                            if ($namepart == '')
                            {
                                continue;
                            }
                            // if (count($sifacQuery->whereclause) == 0)
                            // if ($index == 0)
                            if (!$firstfound)
                            {
                                $sifacQuery->whereclause[] = "substringof('$namepart',vnamc)";
                                $firstfound = true;
                            }
                            else
                            {
                                $sifacQuery->whereclause[] = "and substringof('$namepart',vnamc)";
                            }
                        }
                        $sifacQuery->whereclause[] = ")";
                        $sifacQuery->whereclause[] = "and gbjhr eq '$anneenaissance'";
                        $sifacQuery->whereclause[] = "and gbmon eq '$moisnaissance'";
                    }
                    else if($sqlMode == SifacSqlMode::QUERY_ECC)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode SQL ECC ///////////////////////////////////////////
                        $sifacQuery->selectclause = array('PERNR');
                        $sifacQuery->fromclause = 'PA0002';
                        $sifacQuery->whereclause = array("NCHMC LIKE '$nom'",
                                                         " AND VNAMC LIKE '$prenom'", 
                                                         " AND GBJHR = '$anneenaissance'", 
                                                         " AND GBMON = '$moisnaissance'");
                    }
                    else
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode POSTGRE SQL ///////////////////////////////////////
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                        error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                        return $response;
                    }
                    ///////////////////////////////////////////////////////////
                    // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " La requete pour la recherche dans SIFAC est : " . print_r($sifacQuery,true)));
                    // var_dump(__FUNCTION__ . " La requete pour la recherche dans SIFAC est : " . print_r($sifacQuery,true));
                    $response = $this->readSifacTable($sifacQuery);
                    // var_dump(__FUNCTION__ . " : response->status (PA0002) => ",$response->status);
                    // var_dump(__FUNCTION__ . " : La réponse complète : " . print_r($response,true));

                    if ($response->status == ResponseStatus::STATUT_KO)
                    {
                        return $response;
                    }
                    // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Le résultat de la recherche dans SIFAC est : " . print_r($response,true)));

                    if (!isset($response->data[0]['PERNR']))
                    {
                        $response->data = [];
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->description = 'L\'agent n\'existe pas dans SIFAC.';
                        return $response;
                    }
                    elseif (count($response->data) != 1)
                    {
                        $response->data = [];
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->description = 'Plusieurs matricules correspondent à la recherche.';
                        return $response;
                    }
                    $matricule = $response->data[0]['PERNR'];
                }

                // var_dump(__FUNCTION__ . " : Le matricule est $matricule");


                $response->data = [];
                $response->status = ResponseStatus::STATUT_OK;
                $response->description = '';

                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    ///////////////////////////////////////////////////////////
                    // Mode DIRECT SQL ///////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['pernr', 'reinr', 'kunde', 'datv1', 'datb1', 'hdvrs', 'zort1'];
                    $sifacQuery->fromclause = 'ptrv_head';
                    // Tip ! On cherche les missions dont la date de début est dans l'année YYYY
                    //      ==> On cherche ceux dont la date est >= YYYY0101 et <= YYYY1231
                    $sifacQuery->whereclause = ["schem ne '10'",
                                                " and pernr eq '$matricule'", 
                                                " and datv1 ge '" . $year . "0101'", 
                                                " and datv1 le '" . $year . "1231'"
                                                ];
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    ///////////////////////////////////////////////////////////
                    // Mode SQL ECC ///////////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('PERNR', 'REINR', 'KUNDE', 'DATV1', 'DATB1', 'HDVRS', 'ZORT1');
                    $sifacQuery->fromclause = 'PTRV_HEAD';
                    $sifacQuery->whereclause = array("SCHEM <> '10'"," AND DATV1 LIKE '$year%'", " AND PERNR = '$matricule'");
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                ///////////////////////////////////////////////////////////
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " J'appelle la méthode read_sifac_table : " . $sifacQuery->fromclause . " = " . print_r($response_PTRV_HEAD['data'],true)));
                $response_PTRV_HEAD = $this->readSifacTable($sifacQuery);
                // var_dump(__FUNCTION__ . " : response->status (PTRV_HEAD) => ",$response_PTRV_HEAD->status);
                // var_dump(__FUNCTION__ . " : La réponse complète : " . print_r($response_PTRV_HEAD,true));
                if ($response_PTRV_HEAD->status == ResponseStatus::STATUT_KO)
                {
                    $response = $response_PTRV_HEAD;
                    return $response;
                }

                foreach ($response_PTRV_HEAD->data as $PTRV_HEAD_data)
                {
                    $PTRV_HEAD_PERNR = $PTRV_HEAD_data['PERNR'];
                    $PTRV_HEAD_REINR = $PTRV_HEAD_data['REINR'];
                    $PTRV_HEAD_KUNDE= $PTRV_HEAD_data['KUNDE'];
                    $PTRV_HEAD_DATV1= $PTRV_HEAD_data['DATV1'];
                    $PTRV_HEAD_DATB1= $PTRV_HEAD_data['DATB1'];
                    $PTRV_HEAD_HDVRS= $PTRV_HEAD_data['HDVRS'];
                    $PTRV_HEAD_ZORT1= $PTRV_HEAD_data['ZORT1'];

                    $sifacMission = new SifacMissionClass();
                    $sifacMission->matricule = trim($PTRV_HEAD_PERNR);
                    $sifacMission->motif = trim($PTRV_HEAD_KUNDE);
                    $sifacMission->destination = trim($PTRV_HEAD_ZORT1);
                    $sifacMission->paiement = "non indemnisée";
                    $sifacMission->datepaiement = "";
                    $sifacMission->missionid = ltrim(trim($PTRV_HEAD_REINR), '0');
                    $sifacMission->datedebut = trim($PTRV_HEAD_DATV1);
                    $sifacMission->datefin = trim($PTRV_HEAD_DATB1);

                    $response->data["$PTRV_HEAD_REINR"] = $sifacMission;

                    $sifacQuery = new SifacQueryClass();
                    if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode DIRECT SQL ///////////////////////////////////////
                        $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                        $sifacQuery->selectclause = ['sum_reimbu', 'trip_total'];
                        $sifacQuery->fromclause = 'ptrv_shdr';
                        $sifacQuery->whereclause = ["pernr eq '$PTRV_HEAD_PERNR'",
                                                    " and reinr eq '$PTRV_HEAD_REINR'"
                                                    ];
                    }
                    else if($sqlMode == SifacSqlMode::QUERY_ECC)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode SQL ECC ///////////////////////////////////////////
                        $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                        $sifacQuery->selectclause = array('SUM_REIMBU', 'TRIP_TOTAL');
                        $sifacQuery->fromclause = 'PTRV_SHDR';
                        $sifacQuery->whereclause = array("PERNR = '$PTRV_HEAD_PERNR'"," AND REINR = '$PTRV_HEAD_REINR'");
                    }
                    else
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode POSTGRE SQL ///////////////////////////////////////
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                        error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                        return $response;
                    }
                    ///////////////////////////////////////////////////////////

                    $response_PTRV_SHDR = $this->readSifacTable($sifacQuery);
                    // var_dump(__FUNCTION__ . " : response->status (PTRV_SHDR) => ",$response_PTRV_SHDR->status);
                    if ($response_PTRV_SHDR->status == ResponseStatus::STATUT_KO)
                    {
                        $response = $response_PTRV_SHDR;
                        return $response;
                    }

                    // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " J'ai call la méthode read_sifac_table : " . $sifacQuery->fromclause . " => " . print_r($response_PTRV_SHDR['data'],true)));
                    foreach ($response_PTRV_SHDR->data as $PTRV_SHDR_data)
                    {
                        $PTRV_SHDR_SUM_REIMBU = $PTRV_SHDR_data['SUM_REIMBU'];
                        $PTRV_SHDR_TRIP_TOTAL = $PTRV_SHDR_data['TRIP_TOTAL'];

                        $sifacQuery = new SifacQueryClass();
                        if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                        {
                            ///////////////////////////////////////////////////////////
                            // Mode DIRECT SQL ///////////////////////////////////////
                            $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                            $sifacQuery->selectclause = ['awref'];
                            $sifacQuery->fromclause = 'ptrv_rot_awkey';
                            $sifacQuery->whereclause = ["pernr eq '$PTRV_HEAD_PERNR'",
                                                        " and reinr eq '$PTRV_HEAD_REINR'", 
                                                        " and pdvrs eq '$PTRV_HEAD_HDVRS'"
                                                        ];
                        }
                        else if($sqlMode == SifacSqlMode::QUERY_ECC)
                        {
                            ///////////////////////////////////////////////////////////
                            // Mode SQL ECC ///////////////////////////////////////////
                            $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                            $sifacQuery->selectclause = array('AWREF');
                            $sifacQuery->fromclause = 'PTRV_ROT_AWKEY';
                            $sifacQuery->whereclause = array("PERNR = '$PTRV_HEAD_PERNR'"," AND REINR = '$PTRV_HEAD_REINR'", " AND PDVRS = '$PTRV_HEAD_HDVRS'");
                        }
                        else
                        {
                            ///////////////////////////////////////////////////////////
                            // Mode POSTGRE SQL ///////////////////////////////////////
                            $response->status = ResponseStatus::STATUT_KO;
                            $response->data = [];
                            $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                            error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                            return $response;
                        }
                        ///////////////////////////////////////////////////////////
                        $response_PTRV_ROT_AWKEY = $this->readSifacTable($sifacQuery);
                        // var_dump(__FUNCTION__ . " : response->status (PTRV_ROT_AWKEY) => ",$response_PTRV_ROT_AWKEY->status);
                        if ($response_PTRV_ROT_AWKEY->status == ResponseStatus::STATUT_KO)
                        {
                            $response = $response_PTRV_ROT_AWKEY;
                            error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Une erreur lors de l'appel read_sifac_table : " . $sifacQuery->fromclause . " : " . $response->description));
                            return $response;
                        }

                        // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " J'ai call la méthode read_sifac_table : " . $sifacQuery->fromclause . " = " . print_r($response_PTRV_ROT_AWKEY['data'],true)));
                        foreach($response_PTRV_ROT_AWKEY->data as $PTRV_ROT_AWKEY_data)
                        {
                            $PTRV_ROT_AWKEY_AWREF = $PTRV_ROT_AWKEY_data['AWREF'];

                            $sifacQuery = new SifacQueryClass();
                            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                            {
                                ///////////////////////////////////////////////////////////
                                // Mode DIRECT SQL ///////////////////////////////////////
                                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                                $sifacQuery->selectclause = ['bukrs','belnr','gjahr'];
                                $sifacQuery->fromclause = 'bkpf';
                                $sifacQuery->whereclause = ["awkey eq '$PTRV_ROT_AWKEY_AWREF'"];
                            }
                            else if($sqlMode == SifacSqlMode::QUERY_ECC)
                            {
                                ///////////////////////////////////////////////////////////
                                // Mode SQL ECC ///////////////////////////////////////////
                                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                                $sifacQuery->selectclause = array('BUKRS','BELNR','GJAHR');
                                $sifacQuery->fromclause = 'BKPF';
                                $sifacQuery->whereclause = array("AWKEY = '$PTRV_ROT_AWKEY_AWREF'");
                            }
                            else
                            {
                                ///////////////////////////////////////////////////////////
                                // Mode POSTGRE SQL ///////////////////////////////////////
                                $response->status = ResponseStatus::STATUT_KO;
                                $response->data = [];
                                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                                return $response;
                            }
                            ///////////////////////////////////////////////////////////
                            // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Avant l'appel de read_sifac_table : " . $sifacQuery->fromclause . " : " . print_r($sifacQuery,true)));
                            $response_BKPF = $this->readSifacTable($sifacQuery);
                            // var_dump(__FUNCTION__ . " : response->status (BKPF) => ",$response_BKPF->status);

                            if ($response_BKPF->status == ResponseStatus::STATUT_KO)
                            {
                                $response = $response_BKPF;
                                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Une erreur lors de l'appel read_sifac_table : " . $sifacQuery->fromclause . " : " . $response->description));
                                return $response;
                            }
                            // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " J'ai call la méthode read_sifac_table : " . $sifacQuery->fromclause . " = " . print_r($response_BKPF['data'],true)));
                            foreach($response_BKPF->data as $BKPF_data)
                            {
                                $BKPF_BUKRS = $BKPF_data['BUKRS'];
                                $BKPF_BELNR = $BKPF_data['BELNR'];
                                $BKPF_GJAHR = $BKPF_data['GJAHR'];

                                $sifacQuery = new SifacQueryClass();
                                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                                {
                                    ///////////////////////////////////////////////////////////
                                    // Mode DIRECT SQL ///////////////////////////////////////
                                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                                    $sifacQuery->selectclause = ['augdt'];
                                    $sifacQuery->fromclause = 'bsak';
                                    $sifacQuery->whereclause = ["bukrs eq '$BKPF_BUKRS'", 
                                                                " and gjahr eq '$BKPF_GJAHR'", 
                                                                " and belnr eq '$BKPF_BELNR'"
                                                                ];
                                }
                                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                                {
                                    ///////////////////////////////////////////////////////////
                                    // Mode SQL ECC ///////////////////////////////////////////
                                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                                    $sifacQuery->selectclause = array('AUGDT');
                                    $sifacQuery->fromclause = 'BSAK';
                                    $sifacQuery->whereclause = array("BUKRS = '$BKPF_BUKRS'", "AND GJAHR = '$BKPF_GJAHR'", "AND BELNR = '$BKPF_BELNR'");
                                }
                                else
                                {
                                    ///////////////////////////////////////////////////////////
                                    // Mode POSTGRE SQL ///////////////////////////////////////
                                    $response->status = ResponseStatus::STATUT_KO;
                                    $response->data = [];
                                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                                    return $response;
                                }
                                ///////////////////////////////////////////////////////////

                                $response_BSAK = $this->readSifacTable($sifacQuery);
                                // var_dump(__FUNCTION__ . " : response->status (BSAK) => ",$response_BSAK->status);
                                if ($response_BSAK->status == ResponseStatus::STATUT_KO)
                                {
                                    $response = $response_BSAK;
                                    error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " Une erreur lors de l'appel read_sifac_table : " . $sifacQuery->fromclause . " : " . $response->description));
                                    return $response;
                                }
                                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " J'ai call la méthode read_sifac_table : " . $sifacQuery->fromclause . " = " . print_r($response_BSAK['data'],true)));

                                foreach($response_BSAK->data as $BSAK_data)
                                {
                                    $BSAK_AUGDT = $BSAK_data['AUGDT'];
                                    
                                    $sifacMission = new SifacMissionClass();
                                    $sifacMission->matricule = trim($PTRV_HEAD_PERNR);
                                    $sifacMission->motif = trim($PTRV_HEAD_KUNDE);
                                    $sifacMission->destination = trim($PTRV_HEAD_ZORT1);
                                    $sifacMission->paiement = str_replace('.',",",trim($PTRV_SHDR_SUM_REIMBU)) . " €";
                                    $sifacMission->datepaiement = formatdate(trim($BSAK_AUGDT));
                                    $sifacMission->missionid = ltrim(trim($PTRV_HEAD_REINR), '0');
                                    $sifacMission->datedebut = trim($PTRV_HEAD_DATV1);
                                    $sifacMission->datefin = trim($PTRV_HEAD_DATB1);

                                    $response->data["$PTRV_HEAD_REINR"] = $sifacMission;
                                    $response->description = '';
                                    $response->status = ResponseStatus::STATUT_OK;
                                }
                            }
                        }
                    }
                }
            }
            if ($response->status == '')
            {
                $response->data = array();
                $response->description = 'Une erreur s\'est produite.';
                $response->status = ResponseStatus::STATUT_KO;
            }
            return $response;
        }

        /**
         * Récupère le référentiel des structures financières SIFAC (CFI) actives,
         * avec leur hiérarchie et leur responsable.
         *
         * Pour chaque CFI trouvé dans FMFCTR (centre financier 1010, actif), va
         * chercher son CFI parent dans FMHISV, son libellé/description dans
         * FMFCTRT, puis l'adresse mail du responsable (BOSSID) via une résolution
         * SIFAC ? EPPN ? LDAP.
         *
         * @param string[] $financialStructureName Liste de codes CFI (FICTR) à filtrer ;
         *                                          tableau vide = tous les CFI actifs.
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT
         *                         ou QUERY_ECC (QUERY_POSTGRE non géré par cette
         *                         méthode). Paramètre obligatoire.
         * @return ResponseClass STATUT_KO si une des tables échoue, si FMHISV ou
         *                        FMFCTRT ne renvoie pas exactement une ligne, ou si
         *                        le mail du responsable est introuvable dans LDAP ;
         *                        STATUT_OK sinon avec $response->data[FICTR] =
         *                        ['PARENT_ST','FICTR','BEZEICH','BESCHR','BOSSID',
         *                        'BOSSNAME','MAIL'].
         */
        public function getSifacFinancialStructures(array $financialStructureName, SifacSqlMode $sqlMode) :ResponseClass
        {
            $t0 = microtime(true);
            $response = new ResponseClass();

            $sifacQuery = new SifacQueryClass();

            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['fictr', 'fikrs', 'bossid', 'bossname','bezeich','beschr'];
                $sifacQuery->fromclause = 'fmfctr';
                $sifacQuery->whereclause = ["fikrs eq '1010'", 
                                            " and datbis gt '" . date("Ymd") . "'"
                                            ];
                if (count($financialStructureName) >0)
                {
                    $sifacQuery->whereclause[] = "and (fictr eq '' ";
                    foreach($financialStructureName as $cfi)
                    {
                        $sifacQuery->whereclause[] = "or fictr eq '" . trim($cfi) . "'";
                    }
                    $sifacQuery->whereclause[] = ")";
                }
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                ///////////////////////////////////////////////////////////
                // Mode SQL ECC ///////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = array('FICTR', 'FIKRS', 'BOSSID', 'BOSSNAME');
                $sifacQuery->fromclause = 'FMFCTR';
                $sifacQuery->whereclause = array("FIKRS = '1010'"," AND DATBIS > '" . date("Ymd") . "'");
                if (count($financialStructureName) >0)
                {
                    $sifacQuery->whereclause[] = "AND (FICTR = '' ";
                    foreach($financialStructureName as $cfi)
                    {
                        $sifacQuery->whereclause[] = "OR FICTR = '" . trim($cfi) . "'";
                    }
                    $sifacQuery->whereclause[] = ")";
                }
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            ///////////////////////////////////////////////////////////
            
            // error_log(basename(__FILE__) . stripAccents(" Fonction : " . __FUNCTION__ . " => sifacquery = " . print_r($sifacQuery,true)));
            $response_FMFCTR = $this->readSifacTable($sifacQuery);
            if ($response_FMFCTR->status == ResponseStatus::STATUT_KO)
            {
                return $response_FMFCTR;
            }
            // error_log(basename(__FILE__) . stripAccents(" Fonction : " . __FUNCTION__ . " => response_FMFCTR = " . print_r($response_FMFCTR,true)));

            foreach ($response_FMFCTR->data as $row)
            {
                $FMFCTR_FICTR = trim($row['FICTR'] . '');
                $FMFCTR_FIKRS = trim($row['FIKRS'] . '');
                $FMFCTR_BOSSID = trim($row['BOSSID'] . '');
                $FMFCTR_BOSSNAME = trim($row['BOSSNAME'] . '');

                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    ///////////////////////////////////////////////////////////
                    // Mode DIRECT SQL ///////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['parent_st'];
                    $sifacQuery->fromclause = 'fmhisv';
                    $sifacQuery->whereclause = ["fikrs eq '$FMFCTR_FIKRS'", 
                                                " and fistl eq '$FMFCTR_FICTR'",
                                                " and hivarnt eq '" . str_pad(date('y'),3,'0',STR_PAD_LEFT) . "'"
                                                ];
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    ///////////////////////////////////////////////////////////
                    // Mode SQL ECC ///////////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('PARENT_ST');
                    $sifacQuery->fromclause = 'FMHISV';
                    $sifacQuery->whereclause = array("FIKRS = '" . $FMFCTR_FIKRS . "'"," AND FISTL = '" . $FMFCTR_FICTR . "'"," AND HIVARNT = '" . str_pad(date('y'),3,'0',STR_PAD_LEFT) . "'");
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                ///////////////////////////////////////////////////////////

                // error_log(basename(__FILE__) . stripAccents(" Fonction : " . __FUNCTION__ . " => sifacquery = " . print_r($sifacQuery,true)));

                $response_FMHISV = $this->readSifacTable($sifacQuery);
                if ($response_FMHISV->status == ResponseStatus::STATUT_KO)
                {
                    return $response_FMHISV;
                }
                // error_log(basename(__FILE__) . stripAccents(" Fonction : " . __FUNCTION__ . " => response_FMHISV = " . print_r($response_FMHISV,true)));

                if (count($response_FMHISV->data) == 0)
                {
                    $response_FMHISV->status = ResponseStatus::STATUT_KO;
                    $response_FMHISV->description = "La requête sur la table FMHISV n'a retourné aucune valeur : " . print_r($response_FMHISV->data,true);
                    $response_FMHISV->data = [];
                    return $response_FMHISV;
                }
                if (count($response_FMHISV->data) != 1)
                {
                    $response_FMHISV->status = ResponseStatus::STATUT_KO;
                    $response_FMHISV->description = "La requête sur la table FMHISV a retourné plus d'une valeur : " . print_r($response_FMHISV->data,true);
                    $response_FMHISV->data = [];
                    return $response_FMHISV;
                }
                $FMHISV_PARENT_ST = trim($response_FMHISV->data[0]['PARENT_ST'] . '');

                // En mode Direct ou PostGre, le libellé et la description du CFI sont déjà inclus dans la table FMFCTRT (colonnes BEZEICH et BESCHR)
                if ($sifacQuery->querytype != SifacSqlMode::QUERY_ECC)
                {
                    $FMFCTRT_BEZEICH = trim($row['BEZEICH'] . '');
                    $FMFCTRT_BESCHR = trim($row['BESCHR'] . '');
                }
                // En mode ECC, on doit chercher la désignation et la description du CFi dans la table FMFCTRT
                else
                {
                    $sifacQuery = new SifacQueryClass();
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('BEZEICH', 'BESCHR');
                    $sifacQuery->fromclause = 'FMFCTRT';
                    $sifacQuery->whereclause = array("FIKRS = '" . $FMFCTR_FIKRS . "'"," AND FICTR = '" . $FMFCTR_FICTR . "'"," AND DATBIS > '" . date("Ymd") . "'");
                    $response_FMFCTRT = $this->readSifacTable($sifacQuery);
                    if ($response_FMFCTRT->status == ResponseStatus::STATUT_KO)
                    {
                        return $response_FMFCTRT;
                    }

                    if (count($response_FMFCTRT->data) != 1)
                    {
                        $response_FMHISV->status = ResponseStatus::STATUT_KO;
                        $response_FMHISV->description = "La requête sur la table FMFCTRT a retourné plus d'une valeur.";
                        $response_FMHISV->data = [];
                        return $response_FMHISV;
                    }
                    $FMFCTRT_BEZEICH = trim($response_FMFCTRT->data[0]['BEZEICH'] . '');
                    $FMFCTRT_BESCHR = trim($response_FMFCTRT->data[0]['BESCHR'] . '');
                }

                $eppn = $this->getEppnFromSifacLogin($FMFCTR_BOSSID,$sqlMode);
                // On demande à LDAP le mail du responsable de CFi
                $ldapFunctions = new LdapFunctions();
                $responseLdap = $ldapFunctions->getLDAPInfos("$eppn", [LDAP_AGENT_EPPN_ATTR], [LDAP_AGENT_MAIL_ATTR],ldapsearchclass::LDAP_OPTION_ONE_RESPONSE);

                // error_log(basename(__FILE__) . stripAccents(" Fonction : " . __FUNCTION__ . " => response_ldap = " . print_r($responseLdap,true)));

                if ($responseLdap->status == ResponseStatus::STATUT_OK)
                {
                    if (count($responseLdap->data)==0)
                    {
                        $ldapmail = '';
                    }
                    else
                    {
                        $ldapmail = $responseLdap->data[0][LDAP_AGENT_MAIL_ATTR];
                    }
                    $response->data[$FMFCTR_FICTR] = ['PARENT_ST' => $FMHISV_PARENT_ST,
                                                        'FICTR' => $FMFCTR_FICTR,
                                                        'BEZEICH' => $FMFCTRT_BEZEICH,
                                                        'BESCHR' => $FMFCTRT_BESCHR,
                                                        'BOSSID' => $FMFCTR_BOSSID,
                                                        'BOSSNAME' => $FMFCTR_BOSSNAME,
                                                        'MAIL' => $ldapmail
                                                        ];
                }
                else
                {
                    $response = new ResponseClass();
                    $response->data = array();
                    $response->description = "Impossible de récupérer le mail du responsable du CFi " . $FMFCTR_FICTR . " (login sifac = $FMFCTR_BOSSID - nom SIFAC : $FMFCTR_BOSSNAME).";
                    $response->status = ResponseStatus::STATUT_KO;
                    return $response;
                }
            }
            $response->description = '';
            $response->status = ResponseStatus::STATUT_OK;

            $t1 = microtime(true);
            error_log(basename(__FILE__) . stripAccents(" La durée d'exécution de la fonction est " . __FUNCTION__ . " = " . ($t1-$t0) . " secondes."));

            // error_log(basename(__FILE__) . stripAccents(" Fonction : " . __FUNCTION__ . " => response = " . print_r($response,true)));

            return $response;
        }

        /**
         * Filtre une liste de logins pour ne conserver que les comptes SIFAC
         * réellement actifs et synchronisés (table USR02), selon les critères :
         * CODVN <> 'X', compte non expiré (GLTGB), valide depuis au moins 9 jours
         * (GLTGV) et CLASS='SYNCHRO'.
         *
         * Accepte indifféremment des entrées au format BNAME ou UNAME (les doublons
         * de login dans $loginList sont dédupliqués en interne).
         *
         * @param array<int,array{BNAME?:string,UNAME?:string}> $loginList Liste de logins
         *                                  à vérifier, chaque entrée portant la clé
         *                                  'BNAME' et/ou 'UNAME'.
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT
         *                         ou QUERY_POSTGRE ou QUERY_ECC. Paramètre obligatoire
         * @return array<int,array{BNAME:string,UNAME:string}>|string Tableau des comptes
         *                  valides (avec BNAME et UNAME renseignés) en cas de succès ;
         *                  message d'erreur (string) si la lecture de la table USR02 échoue.
         *                  ATTENTION : type de retour hétérogène à tester avec is_string().
         */
        public function checkUserAccountIsAvailable(array $loginList, SifacSqlMode $sqlMode) :array|string
        {
            $knownuser = [];

            $removeduser = [];

            $sifacQuery = new SifacQueryClass();
            // Si on est en mode DIRECT SQL ou en mode POSTGRE SQL, on ne gère pas les dates de validité des comptes SIFAC => Ils sont tous valides
            if (in_array($sqlMode, [SifacSqlMode::QUERY_DIRECT,SifacSqlMode::QUERY_POSTGRE]))
            {
                $response = new ResponseClass();
                $response->status = ResponseStatus::STATUT_OK;
                $response->description = '';
                foreach ($loginList as $login)
                {
                    $name = '';
                    if (isset($login['BNAME'])) 
                    {
                        $name = $login['BNAME'];
                    }
                    elseif (isset($login['UNAME'])) 
                    {
                        $name = $login['UNAME'];
                    }
                    if ($name != '')
                    {
                        $response->data[]['BNAME'] = $name;
                        $response->data[array_key_last($response->data)]['UNAME'] = $name;
                    }
                }
            }
            
            else //SifacSqlMode::QUERY_DIRECT
            {
                $sifacQuery->selectclause = array('BNAME');
                $sifacQuery->fromclause = 'USR02';
                $sifacQuery->whereclause = array("CODVN <> 'X'", 
                                                " AND (GLTGB = '00000000'", 
                                                " OR GLTGB > '" . date('Ymd') . "')", 
                                                " AND GLTGV <= '" . date('Ymd', strtotime(' + 9 days')) . "'",
                                                " AND CLASS = 'SYNCHRO'",
                                                " AND (BNAME = ''"
                                                );
                foreach($loginList as $login)
                {
                    $name = '';
                    if (isset($login['BNAME'])) 
                    {
                        $name = $login['BNAME'];
                    }
                    elseif (isset($login['UNAME'])) 
                    {
                        $name = $login['UNAME'];
                    }
                    if (!isset($knownuser[$name]))
                    {
                        $sifacQuery->whereclause[] = " OR BNAME = '" . $name . "'";
                        $knownuser[$name] = ResponseStatus::STATUT_KO;
                    }
                    else
                    {
                        $removeduser[$name] = $name;
                    }
                }
                $sifacQuery->whereclause[] = ')';
                $response = $this->readSifacTable($sifacQuery);
                if ($response->status == ResponseStatus::STATUT_KO)
                {
                    $response->description = 'Table : USR02 => ' . $response->description;
                    return $response->description;
                }
                foreach($removeduser as $login)
                {
                    if ($knownuser[$login] == ResponseStatus::STATUT_OK)
                    {
                        $response->data[]['BNAME'] = $login;
                    }
                }
                // On rajoute l'entrée en UNAME dans le tableau résultat car le tableau d'entrée peut-être du BNAME ou du UNAME ou un mixte des deux.
                foreach($response->data as $key => $bname)
                {
                    $response->data[$key]['UNAME'] = $bname['BNAME'];
                    $knownuser[$bname['BNAME']] = ResponseStatus::STATUT_OK;
                }
            }
            return $response->data;
        }

        /**
         * Retourne les logins des utilisateurs disposant d'un accès à une
         * transaction SIFAC donnée, via les rôles YS/YC autorisant ce code
         * transaction (S_TCODE).
         *
         * Recherche les rôles concernés dans agr_1251, puis pour chacun les
         * utilisateurs actuellement affectés dans agr_users, en ne conservant que
         * les comptes encore actifs (checkUserAccountIsAvailable).
         *
         * @param string $sifacTransaction Code transaction SIFAC (ex: 'ME21N').
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT,
         *                         QUERY_ECC ou QUERY_POSTGRE. Paramètre obligatoire.
         * @return ResponseClass STATUT_KO en cas d'erreur de lecture d'une des tables ;
         *                        STATUT_OK sinon avec $response->data[login] = login.
         */
        public function getUserWithTransaction(string $sifacTransaction, SifacSqlMode $sqlMode) :ResponseClass
        {
            $t0 = microtime(true);

            $response = new ResponseClass();

            $transaction = trim(mb_strtoupper($sifacTransaction));
            $sifacQuery = new SifacQueryClass();

            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                /////////////////////////////////////////////////////////////
                ///// MODE DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['agr_name'];
                $sifacQuery->fromclause = 'agr_1251';
                $sifacQuery->whereclause = ["object eq 'S_TCODE'",
                                            " and field eq 'TCD'",
                                            " and low eq '$transaction'",
                                            " and deleted eq false",
                                            " and (startswith(agr_name, 'YS') or startswith(agr_name, 'YC'))"
                                        ];
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                /////////////////////////////////////////////////////////////
                ///// MODE ECC SQL //////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = array('AGR_NAME');
                $sifacQuery->fromclause = 'AGR_1251';
                $sifacQuery->whereclause = array("OBJECT = 'S_TCODE'",
                                                    " AND FIELD = 'TCD'", 
                                                    " AND DELETED = ' '", 
                                                    " AND LOW = '$transaction'", 
                                                    " AND (AGR_NAME LIKE 'YS%'", 
                                                        " OR AGR_NAME LIKE 'YC%')"
                                                );
            }
            else
            {
                ///////////////////////////////////////////////////////////
                ///// MODE POSTGRE SQL ////////////////////////////////////
                // $sifacQuery->querytype = SifacSqlMode::QUERY_POSTGRE;
                // $sifacQuery->selectclause = ['agr_name'];
                // $sifacQuery->fromclause = 'agr_1251';
                // $sifacQuery->whereclause = ['object=eq.S_TCODE',
                //                             'field=eq.TCD',
                //                             'deleted=eq.0',
                //                             'low=eq.' . $transaction,
                //                             'or=(agr_name.like.YS*,agr_name.like.YC*)'
                //                             ];
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            /////////////////////////////////////////////////////////
            // error_log(basename(__FILE__) . stripAccents(" Avant le readsifactable dans " . __FUNCTION__ . " pour la table " . $sifacQuery->fromclause));
            // error_log(basename(__FILE__) . stripAccents(" sifacquery = " . print_r($sifacQuery,true)));
            $response_AGR_1251 = $this->readSifacTable($sifacQuery);
            if ($response_AGR_1251->status == ResponseStatus::STATUT_KO)
            {
                $response_AGR_1251->description = 'Table : AGR_1251 => ' . $response_AGR_1251->description;
                return $response_AGR_1251;
            }

            foreach ($response_AGR_1251->data as $AGR_1251_row)
            {
                $AGR_1251_AGR_NAME = trim($AGR_1251_row['AGR_NAME'] . '');

                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    /////////////////////////////////////////////////////////////
                    ///// MODE DIRECT SQL ///////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['uname'];
                    $sifacQuery->fromclause = 'agr_users';
                    $sifacQuery->whereclause = ["agr_name eq '$AGR_1251_AGR_NAME'",
                                                " and from_dat le '" . date('Ymd') . "'",
                                                " and to_dat ge '" . date('Ymd') . "'"
                                            ];
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    /////////////////////////////////////////////////////////////
                    ///// MODE ECC SQL //////////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('UNAME');
                    $sifacQuery->fromclause = 'AGR_USERS';
                    $sifacQuery->whereclause = array("AGR_NAME = '$AGR_1251_AGR_NAME'", 
                                                     " AND FROM_DAT <= '" . date('Ymd') . "'", 
                                                     " AND TO_DAT >= '" . date('Ymd') . "'"
                                                    );
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                /////////////////////////////////////////////////////////
                // error_log(basename(__FILE__) . stripAccents(" Avant le readsifactable dans " . __FUNCTION__));
                $response_AGR_USERS = $this->readSifacTable($sifacQuery);
                // error_log(basename(__FILE__) . stripAccents(" Après le readsifactable dans " . __FUNCTION__));
                if ($response_AGR_USERS->status == ResponseStatus::STATUT_KO)
                {
                    $response_AGR_USERS->description = 'Table : AGR_USERS => ' . $response_AGR_USERS->description;
                    return $response_AGR_USERS;
                }

                $result = $this->checkUserAccountIsAvailable($response_AGR_USERS->data, $sqlMode);
                if (is_string($result))
                {
                    error_log(basename(__FILE__) . stripAccents(" Une erreur s'est produite dans " . __FUNCTION__ . " lors de la vérification des comptes : $result"));
                }
                else
                {
                    $response_AGR_USERS->data = $result;
                }
                foreach ($response_AGR_USERS->data as $AGR_USERS_row)
                {
                    $AGR_USERS_UNAME = trim($AGR_USERS_row['UNAME'] . '');
                    $response->data[$AGR_USERS_UNAME] = $AGR_USERS_UNAME;
                }
            }

            $response->description = '';
            $response->status = ResponseStatus::STATUT_OK;
            $t1 = microtime(true);
            error_log(basename(__FILE__) . stripAccents(" La durée d'exécution de la fonction est " . __FUNCTION__ . " = " . ($t1-$t0) . " secondes."));

            return $response;
        }

        /**
         * Détermine, pour une liste d'utilisateurs (ou tous si vide), les structures
         * financières (CFI) auxquelles ils ont accès via leurs rôles SIFAC YP.
         *
         * Pour chaque rôle YP (table AGR_1251 sur F_FICA_FSG/FM_AUTHGRC), recherche
         * les utilisateurs actifs affectés (AGR_USERS, filtrés par
         * checkUserAccountIsAvailable et par $userList si fourni), puis résout le
         * CFI associé (UST12) et son libellé (FMFCTRT). VON='*' est traduit en
         * "Tous CFI" / "Accès complet".
         *
         * @param string[] $userList Liste de logins à filtrer ; tableau vide = tous
         *                            les utilisateurs concernés par un rôle YP.
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT,
         *                         QUERY_ECC ou QUERY_POSTGRE. Paramètre obligatoire.
         * @return ResponseClass STATUT_KO en cas d'erreur de lecture d'une des tables ;
         *                        STATUT_OK sinon avec $response->data[login][FICTR] =
         *                        ['UNAME','VON','BEZEICH','BESCHR'].
         */
        public function getFinancialStructureByUser(array $userList, SifacSqlMode $sqlMode) :ResponseClass
        {
            $t0 = microtime(true);
            $listeutilisateurs = array_map('mb_strtoupper',$userList);

            $response = new ResponseClass();

            // error_log(basename(__FILE__) . stripAccents(" Avant lire table : AGR_1251"));

            $sifacQuery = new SifacQueryClass();

            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                /////////////////////////////////////////////////////////////
                ///// MODE DIRECT SQL
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = ['agr_name', 'auth'];
                $sifacQuery->fromclause = 'agr_1251';
                $sifacQuery->whereclause = array("object eq 'F_FICA_FSG'",
                                                " and field eq 'FM_AUTHGRC'", 
                                                " and startswith(agr_name, 'YP')"
                                                );
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                /////////////////////////////////////////////////////////////
                ///// MODE ECC SQL
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = array('AGR_NAME', 'AUTH');
                $sifacQuery->fromclause = 'AGR_1251';
                $sifacQuery->whereclause = array("OBJECT = 'F_FICA_FSG'",
                                                " AND FIELD = 'FM_AUTHGRC'", 
                                                " AND AGR_NAME LIKE 'YP%'"
                                                );
            }
            else
            {
                ///////////////////////////////////////////////////////////
                ///// MODE POSTGRE SQL
                // $sifacQuery->querytype = SifacSqlMode::QUERY_POSTGRE;
                // $sifacQuery->selectclause = ['agr_name','auth'];
                // $sifacQuery->fromclause = 'agr_1251';
                // $sifacQuery->whereclause = ['object=eq.F_FICA_FSG',
                //                             'field=eq.FM_AUTHGRC',
                //                             'agr_name=like.YP*'
                //                             ];
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            /////////////////////////////////////////////////////////

            $response_AGR_1251 = $this->readSifacTable($sifacQuery);
            if ($response_AGR_1251->status == ResponseStatus::STATUT_KO)
            {
                $response_AGR_1251->description = 'Table : AGR_1251 => ' . $response_AGR_1251->description;
                return $response_AGR_1251;
            }

            foreach ($response_AGR_1251->data as $AGR_1251_row)
            {
                $AGR_1251_AGR_NAME = trim($AGR_1251_row['AGR_NAME'] . '');
                $AGR_1251_AUTH = trim($AGR_1251_row['AUTH'] . '');

                // var_dump($AGR_1251_AGR_NAME,$AGR_1251_AUTH);

                // error_log(basename(__FILE__) . stripAccents(" Avant lire table : AGR_USERS"));
                // On recherche la liste des utilisateurs SIFAC avec ce rôle
                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    /////////////////////////////////////////////////////////////
                    ///// MODE DIRECT SQL
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['uname'];
                    $sifacQuery->fromclause = 'agr_users';
                    $sifacQuery->whereclause = array("agr_name eq '$AGR_1251_AGR_NAME'",
                                                    " and from_dat le '" . date('Ymd') . "'", 
                                                    " and to_dat ge '" . date('Ymd') . "'"
                                                    );
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    /////////////////////////////////////////////////////////////
                    ///// MODE ECC SQL
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('UNAME');
                    $sifacQuery->fromclause = 'AGR_USERS';
                    $sifacQuery->whereclause = array("AGR_NAME = '$AGR_1251_AGR_NAME'",
                                                    " AND FROM_DAT <= '" . date('Ymd') . "'", 
                                                    " AND TO_DAT >= '" . date('Ymd') . "'"
                                                    );
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                /////////////////////////////////////////////////////////////
                // error_log(basename(__FILE__) . stripAccents(" Avant le readsifactable dans " . __FUNCTION__));
                $response_AGR_USERS = $this->readSifacTable($sifacQuery);
                if ($response_AGR_USERS->status == ResponseStatus::STATUT_KO)
                {
                    $response_AGR_USERS->description = 'Table : AGR_USERS => ' . $response_AGR_USERS->description;
                    return $response_AGR_USERS;
                }

                $result = $this->checkUserAccountIsAvailable($response_AGR_USERS->data, $sqlMode);
                if (is_string($result))
                {
                    error_log(basename(__FILE__) . stripAccents(" Une erreur s'est produite dans " . __FUNCTION__ . " lors de la vérification des comptes : $result"));
                }
                else
                {
                    $response_AGR_USERS->data = $result;
                }
                foreach ($response_AGR_USERS->data as $key => $AGR_USERS_row)
                {
                    if (count($listeutilisateurs) != 0 and !in_array($AGR_USERS_row['UNAME'], $listeutilisateurs))
                    {
                        // Il y a des login dans la liste et l'utilisateur n'est pas dedans => On le supprime
                        unset($response_AGR_USERS->data[$key]);
                    }
                }
                if (count($response_AGR_USERS->data) == 0)
                {
                    // Pas d'utilisateur associé à ce rôle => On passe au CFI suivant
                    continue;
                }
                // On recherche le nom du CFI associé à ce rôle
                // error_log(basename(__FILE__) . stripAccents(" Avant lire table : UST12"));
                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    /////////////////////////////////////////////////////////////
                    ///// MODE DIRECT SQL
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['von'];
                    $sifacQuery->fromclause = 'ust12';
                    $sifacQuery->whereclause = array("objct eq 'F_FICA_FSG'",
                                                    " and field eq 'FM_AUTHGRC'", 
                                                    " and auth eq '$AGR_1251_AUTH'"
                                                    );
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    /////////////////////////////////////////////////////////////
                    ///// MODE ECC SQL
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('VON');
                    $sifacQuery->fromclause = 'UST12';
                    $sifacQuery->whereclause = array("OBJCT = 'F_FICA_FSG'",
                                                    " AND FIELD = 'FM_AUTHGRC'", 
                                                    " AND AUTH = '$AGR_1251_AUTH'"
                                                    );
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                /////////////////////////////////////////////////////////////

                $response_UST12 = $this->readSifacTable($sifacQuery);
                if ($response_UST12->status == ResponseStatus::STATUT_KO)
                {
                    // error_log(basename(__FILE__) . stripAccents(" Erreur lors de la lecture de la table : " . $sifacQuery->fromclause));
                    $response_UST12->description = 'Table : UST12 => ' . $response_UST12->description;
                    return $response_UST12;
                }
                // On ne doit avoir qu'une seule réponse
                $UST12_VON = '';
                if (isset($response_UST12->data[0]))
                {
                    $UST12_VON = trim($response_UST12->data[0]['VON'] . '');
                }

                if ($UST12_VON == "*")
                {
                    $FMFCTRT_BEZEICH = 'Tous CFI';
                    $FMFCTRT_BESCHR = 'Accès complet';
                }
                else
                {
                    // On cherche le descriptif long et cours du CFI trouvé au dessus
                    $sifacQuery = new SifacQueryClass();
                    if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                    {
                        /////////////////////////////////////////////////////////////
                        ///// MODE DIRECT SQL
                        // En mode direct, la description du CFi se trouve dans la table FMFCTR
                        $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                        $sifacQuery->selectclause = ['bezeich', 'beschr'];
                        $sifacQuery->fromclause = 'fmfctr';
                        $sifacQuery->whereclause = array("fikrs eq '1010'",
                                                        " and fictr eq '$UST12_VON'", 
                                                        " and datab le '" . date('Ymd') . "'",
                                                        " and datbis ge '" . date('Ymd') . "'"
                                                        );
                        // error_log(basename(__FILE__) . stripAccents(" Avant lire table : FMFCTR"));
                    }
                    else if($sqlMode == SifacSqlMode::QUERY_ECC)
                    {
                        /////////////////////////////////////////////////////////////
                        ///// MODE ECC SQL
                        $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                        $sifacQuery->selectclause = array('BEZEICH', 'BESCHR');
                        $sifacQuery->fromclause = 'FMFCTRT';
                        $sifacQuery->whereclause = array("FIKRS = '1010'",
                                                        " AND FICTR = '$UST12_VON'", 
                                                        " AND DATAB <= '" . date('Ymd') . "'",
                                                        " AND DATBIS >= '" . date('Ymd') . "'"
                                                        );
                        // error_log(basename(__FILE__) . stripAccents(" Avant lire table : FMFCTRT"));
                    }
                    else
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode POSTGRE SQL ///////////////////////////////////////
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                        error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                        return $response;
                    }
                    /////////////////////////////////////////////////////////////

                    $response_FMFCTRT = $this->readSifacTable($sifacQuery);
                    if ($response_FMFCTRT->status == ResponseStatus::STATUT_KO)
                    {
                        $response_FMFCTRT->description = 'Table : FMFCTRT => ' . $response_FMFCTRT->description;
                        return $response_FMFCTRT;
                    }
                    if (count($response_FMFCTRT->data) == 0)
                    {
                        $FMFCTRT_BEZEICH = 'Non défini';
                        $FMFCTRT_BESCHR = 'Non défini';
                    }
                    else
                    {
                        // On ne doit avoir qu'une seule réponse
                        $FMFCTRT_BEZEICH = trim($response_FMFCTRT->data[0]['BEZEICH'] . '');
                        $FMFCTRT_BESCHR = trim($response_FMFCTRT->data[0]['BESCHR'] . '');
                    }
                }

                // error_log(basename(__FILE__) . stripAccents(" Avant for AGR_USERS : " . print_r($response_AGR_USERS->data,true)));
                foreach ($response_AGR_USERS->data as $AGR_USERS_row)
                {
                    // error_log(basename(__FILE__) . stripAccents(" La row : " . print_r($row,true)));
                    $AGR_USERS_UNAME = $AGR_USERS_row['UNAME'];
                    $response->data[$AGR_USERS_UNAME][$UST12_VON] = ['UNAME' => $AGR_USERS_UNAME,
                                                                        'VON' => $UST12_VON,
                                                                        'BEZEICH' => $FMFCTRT_BEZEICH,
                                                                        'BESCHR' => $FMFCTRT_BESCHR
                                                        ];
                    // error_log(basename(__FILE__) . stripAccents(" Je viens d'ajouter un élément : " . print_r($response->data[$AGR_USERS_UNAME],true)));
                }

            }
            $response->description = '';
            $response->status = ResponseStatus::STATUT_OK;
            $t1 = microtime(true);
            error_log(basename(__FILE__) . stripAccents(" La durée d'exécution de la fonction est " . __FUNCTION__ . " = " . ($t1-$t0) . " secondes."));

            // error_log(basename(__FILE__) . stripAccents(" Le result_json final : " . print_r($response,true)));

            return $response;
        }

        /**
         * Identifie les ordonnateurs (utilisateurs ayant accès à la transaction
         * ME21N) parmi une liste d'utilisateurs, avec le détail des CFI sur
         * lesquels ils sont habilités.
         *
         * Croise getFinancialStructureByUser() (CFI par utilisateur) avec
         * getUserWithTransaction('ME21N') (habilités ME21N), résout les CFI
         * concernés via getSifacFinancialStructures(), puis complète l'identité de
         * chaque ordonnateur (nom, prénom, mail) via LDAP. Le marqueur de CFI '*'
         * est développé en l'ensemble des CFI racine (PARENT_ST vide).
         * Les utilisateurs sans correspondance LDAP exploitable sont ignorés
         * silencieusement.
         *
         * @param string[] $userList Liste de logins utilisateurs à examiner.
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC.
         * @return ResponseClass STATUT_KO si une des étapes intermédiaires échoue ;
         *                        STATUT_OK sinon avec $response->data[login][FICTR] =
         *                        ['PARENT_ST','FICTR','BEZEICH','BESCHR','LOGIN',
         *                        'FULLNAME','FIRSTNAME','LASTNAME','MAIL','LOGIN2'].
         */
        public function getAuthorizingOfficierUser(array $userList, SifacSqlMode $sqlMode) :ResponseClass
        {
            $t0 = microtime(true);
            $response = new ResponseClass();

            $responseUserCfi = $this->getFinancialStructureByUser($userList,$sqlMode);
            if ($responseUserCfi->status != ResponseStatus::STATUT_OK)
            {
                return $responseUserCfi;
            }

            $responseOrdo = $this->getUserWithTransaction('ME21N',$sqlMode);
            if ($responseOrdo->status != ResponseStatus::STATUT_OK)
            {
                return $responseOrdo;
            }

            $arrayordocfi = array_intersect_key($responseUserCfi->data, $responseOrdo->data);

            // On récupère tous les CFI contenu dans le tableau associé aux utilisateurs
            // error_log(basename(__FILE__) . stripAccents(" Le tableau arrayordocfi => " . print_r($arrayordocfi, true)));
            $cfifilter = [];
            // foreach($arrayordocfi as $cfiarray)
            // {
            //     $cfifilter = array_merge($cfifilter,array_keys($cfiarray));
            //     // error_log(basename(__FILE__) . stripAccents(" Le TEMP tableau cfifilter => " . print_r($cfifilter, true)));
            // }
            // $cfifilter = array_unique($cfifilter);
            // // error_log(basename(__FILE__) . stripAccents(" Le tableau cfifilter => " . print_r($cfifilter, true)));

            // On ne demande que les CFi interressants
            $responseCfi = $this->getSifacFinancialStructures($cfifilter,$sqlMode);
            if ($responseCfi->status != ResponseStatus::STATUT_OK)
            {
                return $responseCfi;
            }
            $cfiracinetab = [];
            // error_log(basename(__FILE__) . stripAccents("fonction " . __FUNCTION__ . " => response_cfi : " . print_r($responseCfi,true)));

            foreach($responseCfi->data as $cfi => $cfidata)
            {
                if ($cfidata['PARENT_ST'] == '')
                {
                    $cfiracinetab[$cfi] = $cfidata;
                }
            }
            // error_log(basename(__FILE__) . stripAccents(" cfiracinetab : " . print_r($cfiracinetab,true)));


            foreach($arrayordocfi as $login => $cfitab)
            {

                //////////////////////////////////////////////
                //////////////////////////////////////////////
                /// Il faut récupérer l'eppn de l'utilisateur SIFAC (à partir du login)
                /// Puis il faut interroger LDAP pour récupérer les infos de l'agent (Nom, Prénom, Adresse mail, Display name)
                //////////////////////////////////////////////
                $eppn = $this->getEppnFromSifacLogin($login, $sqlMode);

                $ldapFunctions = new LdapFunctions();
                $responseLdap = $ldapFunctions->getLDAPInfos("$eppn",
                                [LDAP_AGENT_EPPN_ATTR],
                                [LDAP_AGENT_NOM_ATTR,LDAP_AGENT_PRENOM_ATTR,LDAP_AGENT_DISPLAYNAME_ATTR,LDAP_AGENT_MAIL_ATTR],
                                ldapsearchclass::LDAP_OPTION_ONE_RESPONSE
                                );

                $nom = 'Inconnu';
                $prenom = 'Inconnu';
                $displayname = $nom . ' ' . $prenom;
                $mail = 'nobody@etablissement.fr';
                if ($responseLdap->status == ResponseStatus::STATUT_OK)
                {
                    if (count($responseLdap->data)>0)
                    {
                        if (isset($responseLdap->data[0][LDAP_AGENT_NOM_ATTR])) $nom = $responseLdap->data[0][LDAP_AGENT_NOM_ATTR];
                        if (isset($responseLdap->data[0][LDAP_AGENT_PRENOM_ATTR])) $prenom = $responseLdap->data[0][LDAP_AGENT_PRENOM_ATTR];
                        if (isset($responseLdap->data[0][LDAP_AGENT_DISPLAYNAME_ATTR])) $displayname = $responseLdap->data[0][LDAP_AGENT_DISPLAYNAME_ATTR];
                        if (isset($responseLdap->data[0][LDAP_AGENT_MAIL_ATTR])) $mail = $responseLdap->data[0][LDAP_AGENT_MAIL_ATTR];
                    }
                    else
                    {
                        // Il n'y a pas de correspondance dans LDAP => L'agent n'existe plus ? 
                        continue;
                    }
                }
                else
                {
                    // La réponse de LDAP n'est pas correcte, donc on ne traite pas l'agent
                    continue;
                }
                foreach($cfitab as $cfi => $cfidata)
                {
                    if ($cfi != '*')
                    {
                        if (!isset($responseCfi->data[$cfi]))
                        {
                            // Si le CFI n'est pas dans le référentiel des CFI de l'établissement
                            // on ne le traite pas. C'est sans doute un CFI "technique"
                            continue;
                        }
                        $response->data[$login][$cfi] = ['PARENT_ST' => $responseCfi->data[$cfi]['PARENT_ST'],
                                                            'FICTR' => $cfi,
                                                            'BEZEICH' => $cfidata['BEZEICH'],
                                                            'BESCHR' => $cfidata['BESCHR'],
                                                            'LOGIN' => $login,
                                                            'FULLNAME' => $displayname,
                                                            'FIRSTNAME' => $prenom,
                                                            'LASTNAME' => $nom,
                                                            'MAIL' =>$mail,
                                                            'LOGIN2' => $login
                                                            ];
                    }
                    else
                    {
                        foreach ($cfiracinetab as $cfiracinekey => $cfiracinedata)
                        {
                            $response->data[$login][$cfiracinekey] = ['PARENT_ST' => '',
                                                                'FICTR' => $cfiracinekey,
                                                                'BEZEICH' => $cfiracinedata['BEZEICH'],
                                                                'BESCHR' => $cfiracinedata['BESCHR'],
                                                                'LOGIN' => $login,
                                                                'FULLNAME' => $displayname,
                                                                'FIRSTNAME' => $prenom,
                                                                'LASTNAME' => $nom,
                                                                'MAIL' => $mail,
                                                                'LOGIN2' => $login
                                                                ];
                        }
                    }
                }
            }
            $response->status = ResponseStatus::STATUT_OK;
            $response->description = '';
            $t1 = microtime(true);
            error_log(basename(__FILE__) . stripAccents(" La durée d'exécution de la fonction est " . __FUNCTION__ . " = " . ($t1-$t0) . " secondes."));

            return $response;
        }

        /**
         * Retourne la liste des agents ayant eu au moins une mission depuis une
         * année de référence, avec leurs informations d'identité et leurs missions.
         *
         * Parcourt PTRV_HEAD (missions depuis $year) pour construire la liste des
         * matricules concernés, complète chaque mission avec son statut
         * (autorisation/calcul, table PTRV_PERIO), exclut les agents ayant déjà une
         * fiche complétée (PA0105 sur SUBTY 0010/0002/ZEXT), puis enrichit avec les
         * informations PA0001 (catégorie de personnel, établissement 1010 uniquement,
         * contrat en cours) et PA0002 (identité, date de naissance).
         *
         * @param int $year Année de référence (les missions antérieures sont ignorées).
         * @param SifacSqlMode $sqlMode Mode de requête SIFAC : SifacSqlMode::QUERY_DIRECT
         *                         ou QUERY_ECC (QUERY_POSTGRE non géré par cette
         *                         méthode). Paramètre obligatoire.
         * @return ResponseClass STATUT_KO en cas d'erreur de lecture d'une des tables ;
         *                        STATUT_OK sinon avec $response->data[PERNR] =
         *                        ['PERNR','NCHMC','VNAMC','GBJHR','GBMON','GBTAG',
         *                        'NACHN','VORNA','GBDAT','PERSG','missions' => tableau
         *                        de SifacMissionClass indexé par missionid].
         */
        public function getAllAgentsMissions(int $year, SifacSqlMode $sqlMode) :ResponseClass 
        {
            $t0 = microtime(true);
            $response = new ResponseClass();

            $sifacQuery = new SifacQueryClass();
            if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                $sifacQuery->selectclause = array('pernr', 'reinr', 'kunde', 'datv1', 'datb1', 'hdvrs', 'zort1');
                $sifacQuery->fromclause = 'ptrv_head';
                $sifacQuery->whereclause = array("datv1 ge '" . $year . "0101'");
            }
            else if($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                /////////////////////////////////////////////////////////////
                // MODE ECC SQL /////////////////////////////////////////////
                $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                $sifacQuery->selectclause = array('PERNR', 'REINR', 'KUNDE', 'DATV1', 'DATB1', 'HDVRS', 'ZORT1');
                $sifacQuery->fromclause = 'PTRV_HEAD';
                $sifacQuery->whereclause = array("DATV1 >= '" . $year . "0101'");
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            ///////////////////////////////////////////////////////////

            $response_PTRV_HEAD = $this->readSifacTable($sifacQuery);
            if ($response_PTRV_HEAD->status == ResponseStatus::STATUT_KO)
            {
                $response_PTRV_HEAD->description = 'Table : PTRV_HEAD => ' . $response_PTRV_HEAD->description;
                return $response_PTRV_HEAD;
            }

            $whereMatriculeArray = [];
            $whereMissionIdArray = [];
            $missionArray = [];
            if ($sqlMode == SifacSqlMode::QUERY_ECC)
            {
                ///////////////////////////////////////////////////////////
                // Mode DIRECT SQL ///////////////////////////////////////
                $whereMatriculeArray[] = "(PERNR eq '' ";
                $whereMissionIdArray[] = "(REINR eq '' ";
            }
            else if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
            {
                /////////////////////////////////////////////////////////////
                // MODE ECC SQL /////////////////////////////////////////////
                $whereMatriculeArray[] = "(PERNR = '' ";
                $whereMissionIdArray[] = "(REINR = '' ";
            }
            else
            {
                ///////////////////////////////////////////////////////////
                // Mode POSTGRE SQL ///////////////////////////////////////
                $response->status = ResponseStatus::STATUT_KO;
                $response->data = [];
                $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                return $response;
            }
            foreach ($response_PTRV_HEAD->data as $row)
            {
                if (!isset($whereMatriculeArray[trim($row['PERNR'] . '')]))
                {
                    // error_log(basename(__FILE__) . stripAccents(" row = " . print_r($row,true)));
                    $whereString = '';
                    if ($sqlMode == SifacSqlMode::QUERY_ECC)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode DIRECT SQL ///////////////////////////////////////
                        if (count($whereMatriculeArray) > 0) 
                        {
                            $whereString = " or ";
                        }
                        $whereString = $whereString . " PERNR eq '" . trim($row['PERNR'] . '') . "'";
                    }
                    else if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                    {
                        /////////////////////////////////////////////////////////////
                        // MODE ECC SQL /////////////////////////////////////////////
                        if (count($whereMatriculeArray) > 0) 
                        {
                            $whereString = " OR ";
                        }
                        $whereString = $whereString . " PERNR = '" . trim($row['PERNR'] . '') . "'";
                    }
                    else
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode POSTGRE SQL ///////////////////////////////////////
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                        error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                        return $response;
                    }
                    $whereMatriculeArray[trim($row['PERNR'] . '')] = $whereString;
                }
                if (!isset($whereMissionIdArray[trim($row['REINR'] . '')]))
                {
                    $whereString = '';

                    if ($sqlMode == SifacSqlMode::QUERY_ECC)
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode DIRECT SQL ///////////////////////////////////////
                        if (count($whereMissionIdArray) > 0) 
                        {
                            // error_log(basename(__FILE__) . stripAccents(" On a deja des élément => " . print_r($wherearray,true)));
                            $whereString = " or ";
                        }
                        $whereString = $whereString . " reinr eq '" . trim($row['REINR'] . '') . "'";
                    }
                    else if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                    {
                        /////////////////////////////////////////////////////////////
                        // MODE ECC SQL /////////////////////////////////////////////
                        if (count($whereMissionIdArray) > 0) 
                        {
                            // error_log(basename(__FILE__) . stripAccents(" On a deja des élément => " . print_r($wherearray,true)));
                            $whereString = " OR ";
                        }
                        $whereString = $whereString . " REINR = '" . trim($row['REINR'] . '') . "'";
                    }
                    else
                    {
                        ///////////////////////////////////////////////////////////
                        // Mode POSTGRE SQL ///////////////////////////////////////
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->data = [];
                        $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                        error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                        return $response;
                    }
                    $whereMissionIdArray[trim($row['REINR'] . '')] = $whereString;
                }
                $sifacMission = new SifacMissionClass();
                $sifacMission->matricule = trim($row['PERNR'] . '');
                $sifacMission->motif = trim($row['KUNDE'] . '');
                $sifacMission->destination = trim($row['ZORT1'] . '');
                $sifacMission->paiement = '';
                $sifacMission->datepaiement = '';
                $sifacMission->missionid = ltrim(trim($row['REINR'] . ''), '0');
                $sifacMission->datedebut = trim($row['DATV1'] . '' );
                $sifacMission->datefin = trim($row['DATB1'] . '' );

                $missionArray[$sifacMission->matricule][$sifacMission->missionid] = $sifacMission;

            }
            $whereMatriculeArray[] = ")";
            $whereMissionIdArray[] = ")";

            // var_dump($missionArray);

            if (count($whereMatriculeArray)>0)
            {
                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    ///////////////////////////////////////////////////////////
                    // Mode DIRECT SQL ///////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['pernr','reinr','perio','antrg', 'abrec'];
                    $sifacQuery->fromclause = 'ptrv_perio';
                    $sifacQuery->whereclause = $whereMissionIdArray;
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    /////////////////////////////////////////////////////////////
                    // MODE ECC SQL /////////////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('PERNR','REINR','PERIO','ANTRG', 'ABREC');
                    $sifacQuery->fromclause = 'PTRV_PERIO';
                    $sifacQuery->whereclause = $whereMissionIdArray;
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                ///////////////////////////////////////////////////////////

                $response_PTRV_PERIO = $this->readSifacTable($sifacQuery);
                if ($response_PTRV_PERIO->status == ResponseStatus::STATUT_KO)
                {
                    $response_PTRV_PERIO->description = 'Table : PTRV_PERIO => ' . $response_PTRV_PERIO->description;
                    return $response_PTRV_PERIO;
                }
                foreach($response_PTRV_PERIO->data as $row)
                {
                    // var_dump($row);
                    if (isset($missionArray[trim($row['PERNR'])][ltrim(trim($row['REINR'] . ''), '0')]))
                    {
                        // var_dump(trim($row['ANTRG']), trim($row['ABREC']));
                        $missionArray[trim($row['PERNR'])][ltrim(trim($row['REINR'] . ''), '0')]->autorisation = trim($row['ANTRG']);
                        $missionArray[trim($row['PERNR'])][ltrim(trim($row['REINR'] . ''), '0')]->calcul = trim($row['ABREC']);
                    }
                }

                // On cherche les agents qui ont déjà la fiche complétée (avec le mail, le numéro agent ou l'uid)
                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    ///////////////////////////////////////////////////////////
                    // Mode DIRECT SQL ///////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['pernr'];
                    $sifacQuery->fromclause = 'pa0105';
                    $sifacQuery->whereclause = $whereMatriculeArray;
                    $sifacQuery->whereclause[] = " and (subty eq '0010'";
                    $sifacQuery->whereclause[] = " or subty eq '0002'";
                    $sifacQuery->whereclause[] = " or subty eq 'ZEXT')";
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    /////////////////////////////////////////////////////////////
                    // MODE ECC SQL /////////////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('PERNR');
                    $sifacQuery->fromclause = 'PA0105';
                    $sifacQuery->whereclause = $whereMatriculeArray;
                    $sifacQuery->whereclause[] = " AND (SUBTY = '0010'";
                    $sifacQuery->whereclause[] = " OR SUBTY = '0002'";
                    $sifacQuery->whereclause[] = " OR SUBTY = 'ZEXT')";
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                ///////////////////////////////////////////////////////////
                $response_PA0105 = $this->readSifacTable($sifacQuery);
                if ($response_PA0105->status == ResponseStatus::STATUT_KO)
                {
                    $response_PA0105->description = 'Table : PA0105 => ' . $response_PA0105->description;
                    return $response_PA0105;
                }
                foreach ($response_PA0105->data as $row)
                {
                    // Le row représente un agent qui a au moins déjà un élément de compléter
                    // Donc on ne le traite plus
                    if (isset($whereMatriculeArray[trim($row['PERNR'] . '')]))
                    {
                        error_log(basename(__FILE__) . stripAccents(" L'agent  = " . trim($row['PERNR'] . '') . " a déjà des informations de saisies => On l'ignore."));
                        
                        unset($whereMatriculeArray[trim($row['PERNR'] . '')]);
                    }
                }

                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    ///////////////////////////////////////////////////////////
                    // Mode DIRECT SQL ///////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['pernr', 'persg', 'werks'];
                    $sifacQuery->fromclause = 'pa0001';
                    $sifacQuery->whereclause = $whereMatriculeArray;
                    $sifacQuery->whereclause[] = " and endda ge '" . date('Ymd') . "'";
                    $sifacQuery->whereclause[] = " and begda le '" . date('Ymd') . "'";
                    $sifacQuery->whereclause[] = " and bukrs eq '1010'";
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    /////////////////////////////////////////////////////////////
                    // MODE ECC SQL /////////////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('PERNR', 'PERSG', 'WERKS');
                    $sifacQuery->fromclause = 'PA0001';
                    $sifacQuery->whereclause = $whereMatriculeArray;
                    $sifacQuery->whereclause[] = " AND ENDDA >= '" . date('Ymd') . "'";
                    $sifacQuery->whereclause[] = " AND BEGDA <= '" . date('Ymd') . "'";
                    $sifacQuery->whereclause[] = " AND BUKRS = '1010'";
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                ///////////////////////////////////////////////////////////
                // error_log(basename(__FILE__) . stripAccents(" sifacquery->whereclause = " . print_r($sifacQuery->whereclause,true)));
                $response_PA0001 = $this->readSifacTable($sifacQuery);
                if ($response_PA0001->status == ResponseStatus::STATUT_KO)
                {
                    $response_PA0001->description = 'Table : PA0001 => ' . $response_PA0001->description;
                    return $response_PA0001;
                }
                $PA0001_array = [];
                foreach ($response_PA0001->data as $row)
                {
                    if ($row['WERKS'] == '9999')
                    {
                        // On ignore les personnes extérieurs à l'établissement car ils n'auront pas d'identifiant
                        // continue;
                    }
                    $PERNR = trim($row['PERNR'] . '');
                    $PA0001_array["$PERNR"] = trim($row['PERSG'] . '');
                }

                $sifacQuery = new SifacQueryClass();
                if ($sqlMode == SifacSqlMode::QUERY_DIRECT)
                {
                    ///////////////////////////////////////////////////////////
                    // Mode DIRECT SQL ///////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_DIRECT;
                    $sifacQuery->selectclause = ['pernr', 'nchmc', 'vnamc', 'gbjhr', 'gbmon', 'gbtag', 'nachn', 'vorna', 'gbdat'];
                    $sifacQuery->fromclause = 'pa0002';
                    $sifacQuery->whereclause = $whereMatriculeArray;
                }
                else if($sqlMode == SifacSqlMode::QUERY_ECC)
                {
                    /////////////////////////////////////////////////////////////
                    // MODE ECC SQL /////////////////////////////////////////////
                    $sifacQuery->querytype = SifacSqlMode::QUERY_ECC;
                    $sifacQuery->selectclause = array('PERNR', 'NCHMC', 'VNAMC', 'GBJHR', 'GBMON', 'GBTAG', 'NACHN', 'VORNA', 'GBDAT');
                    $sifacQuery->fromclause = 'PA0002';
                    $sifacQuery->whereclause = $whereMatriculeArray;
                }
                else
                {
                    ///////////////////////////////////////////////////////////
                    // Mode POSTGRE SQL ///////////////////////////////////////
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->data = [];
                    $response->description = __FUNCTION__ . " : Mode SQL non pris en charge.";
                    error_log(basename(__FILE__) . stripAccents(" " . $response->description));
                    return $response;
                }
                ///////////////////////////////////////////////////////////

                $response_PA0002 = $this->readSifacTable($sifacQuery);
                if ($response_PA0002->status == ResponseStatus::STATUT_KO)
                {
                    $response_PA0002->description = 'Table : PA0002 => ' . $response_PA0002->description;
                    return $response_PA0002;
                }
                foreach ($response_PA0002->data as $row)
                {
                    $PERNR = trim($row['PERNR'] . '');
                    if (!isset($PA0001_array["$PERNR"]))
                    {
                        //error_log(basename(__FILE__) . stripAccents(" Impossible de trouver le PA0001_array[PERNR] pour PERNR = $PERNR."));
                        continue;
                    }
                    $PERSG = trim($PA0001_array["$PERNR"] . '');
                    if (!isset($missionArray[$PERNR]))
                    {
                        $missionArray[$PERNR] = [];
                    }
                    $response->data["$PERNR"] = [   'PERNR' => $PERNR,
                                                    'NCHMC' => trim($row['NCHMC'] . ''),
                                                    'VNAMC' => trim($row['VNAMC'] . ''),
                                                    'GBJHR' => trim($row['GBJHR'] . ''),
                                                    'GBMON' => trim($row['GBMON'] . ''),
                                                    'GBTAG' => trim($row['GBTAG'] . ''),
                                                    'NACHN' => trim($row['NACHN'] . ''),
                                                    'VORNA' => trim($row['VORNA'] . ''),
                                                    'GBDAT' => trim($row['GBDAT'] . ''),
                                                    'PERSG' => $PERSG,
                                                    'missions' => $missionArray[$PERNR]
                                                    ];
                }
            }

            $response->status = ResponseStatus::STATUT_OK;
            $response->description = '';
            $t1 = microtime(true);
            error_log(basename(__FILE__) . stripAccents(" La durée d'exécution de la fonction est " . __FUNCTION__ . " = " . ($t1-$t0) . " secondes."));

            // error_log(basename(__FILE__) . stripAccents("  result_json " . __FUNCTION__ . " = " . print_r($response,true)));
            return $response;
        }

    }

?>