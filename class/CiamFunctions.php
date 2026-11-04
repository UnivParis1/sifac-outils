<?php
    require_once('ResponseClass.php');
    require_once('CiamGroupClass.php');

    /**
     * Classe utilitaire permettant d'interagir avec le CIAM (Customer Identity and Access Management).
     *
     * Cette classe fournit des méthodes pour :
     * - récupérer un token d'accès CIAM avec mise en cache locale
     * - récupérer les informations CIAM d'un utilisateur
     * - créer une identité utilisateur dans le CIAM
     * - modifier une identité utilisateur
     * - supprimer une identité utilisateur
     * - créer un groupe dans le CIAM
     * - modifier les membres ou une autre propriété d'un groupe
     * - lister les membres d'un groupe
     * - supprimer un groupe
     *
     * Les appels sont réalisés via l'API SCIM exposée par le CIAM.
     *
     * @author Pascal COMTE / Université Paris 1 Panthéon-Sorbonne
     */
    class CiamFunctions
    {

        /**
         * Récupère un token d'accès CIAM valide.
         *
         * Le token est mis en cache dans un fichier local avec gestion de verrou (flock)
         * afin d'éviter les accès concurrents.
         *
         * Si le token est absent, invalide ou expiré, un nouveau token est généré via l'API CIAM.
         *
         * @return string Token d'accès valide, ou chaîne vide en cas d'erreur
         *
         */
        public function getCiamToken() :string
        {
            $token = '';
            $expirationDate = '';
            $expireDate = '1900-01-01 01:00:00';
            $tokenAccess = 0600; // rw- --- ---

            $paramsString = array();

            $newTokenNeeded = false;
            $ciamTokenFilename = TEMP_DIR . '/ciam_token.lck';
            $path = dirname("$ciamTokenFilename");
            $fileExists = false;
            // Si le dossier n'existe pas, on le crée et on positionne les droits dessus
            if (!file_exists($path))
            {
                mkdir("$path",0700,true);
                chmod("$path", 0700);
            }

            // Si le fichier contenant le token existe, on positionne les droits dessus 
            if (file_exists($ciamTokenFilename))
            {
                chmod("$ciamTokenFilename", $tokenAccess);
                $fileExists = true;
            }
            // En mode C+, si le fichier n'existe pas il est créé
            $fileDesc = fopen($ciamTokenFilename,'c+');
            $lockFlag = false;
            if ($fileDesc !== false)
            {
                // $lockFlag = flock($fileDesc,LOCK_EX);
                $start = microtime(true);
                $lockFlag = true;
                while (!flock($fileDesc, LOCK_EX | LOCK_NB)) 
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
                if ($fileExists)
                {
                    // $json = fread($fileDesc, filesize($ciamTokenFilename)+1); 
                    rewind($fileDesc);
                    $json = stream_get_contents($fileDesc);
                }
                if (!$json)
                {
                    // Si la lecture retourne false => On regénère un token
                    error_log(basename(__FILE__) . stripAccents(" Impossible de lire le contenu du fichier token " . basename($ciamTokenFilename) . " => On regénère le token"));
                    $newTokenNeeded = true;
                }
                else
                {
                    $arrayContent = json_decode($json,true);
                    // var_dump($arrayContent);
                    if (!isset($arrayContent['token']) or !isset($arrayContent['expirationdate']))
                    {
                        // Mauvaises données lues => On regénère un token
                        error_log(basename(__FILE__) . stripAccents(" Mauvaises données lues dans le fichier token " . basename($ciamTokenFilename) . " => On regénère le token"));
                        error_log(basename(__FILE__) . stripAccents(" " . print_r($arrayContent,true)));
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
                            error_log(basename(__FILE__) . stripAccents(" Le token " . basename($ciamTokenFilename) . " a expiré => On regénère le token"));
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
                    $paramsString["client_id"] = CIAM_LOGINID;
                    $paramsString["client_secret"] = CIAM_LOGINSECRET;
                    $paramsString["grant_type"] = "client_credentials";
                    $paramsString["scope"] = "scim";

                    $curl = curl_init();
                    $opts = [
                        CURLOPT_URL => CIAM_TOKEN_URL, 
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query($paramsString, '', '&'), 
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_PROXY => ETAB_PROXY_URL,
                        // CURLOPT_VERBOSE => true
                    ];
                    curl_setopt_array($curl, $opts);
                    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                    $header = array();
                    $header[] = "Content-Type: application/x-www-form-urlencoded";
                    $header[] = 'authorization: Basic '. base64_encode(CIAM_LOGINID . ":" . CIAM_LOGINSECRET);
                    $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                    $header[] = "Pragma: no-cache";
                    $header[] = "Expires: 0";
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                    $json = curl_exec($curl);
                    $response = (array)json_decode($json, true);

                    $error = curl_error ($curl);
                    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    if ($httpCode !== 200 and $error=="")
                    {
                        $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
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
                        if (ftruncate($fileDesc,0) === false
                         or rewind($fileDesc) === false
                         or fwrite($fileDesc, json_encode(array('expirationdate' => $expirationDate, 'token' => $token),JSON_PRETTY_PRINT)) === false
                         or fflush($fileDesc) === false
                        )
                        {
                            error_log(basename(__FILE__) . stripAccents(" Le token a été généré mais impossible de l'enregistrer dans le fichier " . basename($ciamTokenFilename) . "."));
                            flock($fileDesc, LOCK_UN);
                            fclose($fileDesc);
                            return $token;
                        }
                        error_log(basename(__FILE__) . stripAccents(" Le token a été généré et correctement enregistré dans le fichier " . basename($ciamTokenFilename) . "."));
                    }
                }
                flock($fileDesc, LOCK_UN);
                fclose($fileDesc);
                // On repositionne les droits sur le fichier au cas où il ne serait pas bon
                chmod("$ciamTokenFilename", $tokenAccess);
            }
            else // On n'a pas réussi à avoir le lock
            {
                error_log(basename(__FILE__) . stripAccents(" Impossible de récupérer le lock sur le fichier " . basename($ciamTokenFilename) . "."));
                fclose($fileDesc);       
            }

            // error_log(basename(__FILE__) . stripAccents(" Après la fermeture - La taille est " . filesize($ciamTokenFilename)+1));
            //var_dump($token);
            return $token;

        }

        /**
         * Supprime (vide) le fichier de cache contenant le token CIAM.
         *
         * Le fichier est vidé (ftruncate) sous verrou exclusif (flock), avec la même
         * logique de tentative de verrouillage que getCiamToken() (5 secondes maximum).
         * Si le fichier n'existe pas, l'opération échoue simplement (rien à supprimer).
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (vide)
         *   - description (string message de confirmation ou d'erreur)
         *
         * Cas particuliers :
         * - Fichier de cache inexistant : STATUT_KO
         * - Verrou non obtenu dans le délai imparti : STATUT_KO
         */
        public function deleteCiamTokenFile() :ResponseClass
        {
            $response = new ResponseClass();

            $ciamTokenFilename = TEMP_DIR . '/ciam_token.lck';
            // Si le fichier contenant le token existe 
            if (file_exists($ciamTokenFilename))
            {
                // En mode C+, si le fichier n'existe pas il est créé
                $fileDesc = fopen($ciamTokenFilename,'c+');
                $lockFlag = false;
                if ($fileDesc !== false)
                {
                    // $lockFlag = flock($fileDesc,LOCK_EX);
                    $start = microtime(true);
                    $lockFlag = true;
                    while (!flock($fileDesc, LOCK_EX | LOCK_NB)) 
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
                    if (rewind($fileDesc) === false
                        or ftruncate($fileDesc,0) === false
                        or fflush($fileDesc) === false
                    )
                    {
                        $description = "Impossible d'effacer le jeton du CIAM.";
                        error_log(basename(__FILE__) . stripAccents(" $description"));
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->description = $description;
                        $response->data = [];
                    }
                    else
                    {
                        $description = "Le jeton CIAM a été effacé.";
                        error_log(basename(__FILE__) . stripAccents(" $description"));
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->description = $description;
                        $response->data = [];
                    }
                }
                else
                {
                    $description = "Impossible de vérouiller le fichier contenant le jeton CIAM.";
                    error_log(basename(__FILE__) . stripAccents(" $description"));
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->description = $description;
                    $response->data = [];
                }
                flock($fileDesc, LOCK_UN);
                fclose($fileDesc);
            }
            else
            {
                $description = "Le fichier contenant le jeton CIAM n'existe pas.";
                error_log(basename(__FILE__) . stripAccents(" $description"));
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = $description;
                $response->data = [];
            }

            return $response;
        }


        /**
         * Récupère la liste des identités présentes dans le CIAM.
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (array liste des identités SCIM)
         *   - description (string message d’erreur si KO)
         * 
         */
        public function getCiamIdentitiesInfos() :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $count = 50;
                $startIndex = 1;

                $exitloop = false;
                while (!$exitloop)
                {
                    $curl = curl_init();
                    $url = CIAM_USER_URL . '?dummy=' .  time() . '&startIndex=' . $startIndex . '&count=' . $count; //. "?filter=" . rawurlencode('externalId eq "' . $eppn . '"');
                    // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                    error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));

                    $opts = [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_PROXY => ETAB_PROXY_URL,
                        // CURLOPT_TIMEOUT => 30,
                        // CURLOPT_VERBOSE => true
                    ];
                    curl_setopt_array($curl, $opts);
                    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                    $header = array();
                    $header[] = "accept: application/json";
                    $header[] = "authorization: Bearer " . $token;
                    $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                    $header[] = "Pragma: no-cache";
                    $header[] = "Expires: 0";
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                    $json = curl_exec($curl);
                    $response = (array)json_decode($json, true);
                    $error = curl_error ($curl);
                    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    if ($httpCode !== 200 and $error=="")
                    {
                        $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                    }
                    if ($error != "")
                    {
                        $error = __FUNCTION__ . " : Erreur Curl => " . $error;
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                        $resultJson->status = ResponseStatus::STATUT_KO;
                        $resultJson->description = "$error";
                        $resultJson->data = [];
                        return $resultJson;
                    }
                    if (!isset($response['totalResults']))
                    {
                        $error = __FUNCTION__ . " : Format de la réponse non conforme. Absence de 'totalResults'";
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                        $resultJson->status = ResponseStatus::STATUT_KO;
                        $resultJson->description = "$error";
                        $resultJson->data = [];
                        return $resultJson;
                    }

                    if (!isset($response['Resources']))
                    {
                        $error = __FUNCTION__ . " : Format de la réponse n'est pas valide => Absence de Resources.";
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                        $resultJson->status = ResponseStatus::STATUT_KO;
                        $resultJson->description = "$error";
                        $resultJson->data = [];
                        return $resultJson;
                    }

                    $resultJson->data = array_merge($resultJson->data, $response['Resources']);
                    if (count($response['Resources']) != $count)
                    {
                        $exitloop = true;
                    }
                    else
                    {
                        $startIndex += $count;
                    }
                }

                if (count($resultJson->data) == 0)
                {
                    $error = __FUNCTION__ . " : Aucun agent dans le CIAM AMUE";
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                    $resultJson->data = [];
                    $resultJson->status = ResponseStatus::STATUT_OK;
                }
                else
                {
                    $resultJson->status = ResponseStatus::STATUT_OK;
                    $resultJson->description = '';
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;

        }


        /**
         * Récupère les informations CIAM d’un utilisateur à partir de son identifiant CIAM.
         *
         * @param string $ciamId Identifiant unique CIAM
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (array données utilisateur SCIM)
         *   - description (string en cas d'erreur)
         *
         * Cas particuliers :
         * - 404 : aucun agent ne correspond à l'identifiant CIAM fourni
         * - autre code HTTP : erreur
         *
         */
        public function getCiamIdentitiesInfosFromCiamId(string $ciamId) :ResponseClass
        {
            // $ciamId = urldecode($ciamUser->id);

            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $curl = curl_init();
                $url = CIAM_USER_URL . "/" . $ciamId. '?dummy=' .  time();
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));

                $opts = [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_PROXY => ETAB_PROXY_URL,
                    // CURLOPT_VERBOSE => true
                ];
                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "accept: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);

                $error = curl_error ($curl);
                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if($httpCode === 404)
                {
                        $error = "Aucun agent n'a pu être identifié.";
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                }
                elseif ($httpCode !== 200 and $error=="")
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }
                
                if ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl (ciamid = $ciamId) => " . $error;
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                }
                else
                {
                    $resultJson->data[] = $response;
                    $resultJson->status = ResponseStatus::STATUT_OK;
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }


        /**
         * Récupère les informations CIAM d’un utilisateur à partir de son EPPN.
         *
         * @param string $eppn EPPN de l'utilisateur (eduPersonPrincipalName)
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (array utilisateur ou vide)
         *   - description (message si aucun ou plusieurs résultats)
         *
         */
        public function getCiamIdentitiesInfosFromEppn(string $eppn) :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $curl = curl_init();
                $url = CIAM_USER_URL . "?filter=" . rawurlencode('additionalInformation.eduPersonPrincipalName eq "' . $eppn . '"') . "&dummy=" .  time();
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));

                $opts = [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_PROXY => ETAB_PROXY_URL,
                    // CURLOPT_VERBOSE => true
                ];
                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "accept: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);
                $error = curl_error ($curl);

                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpCode !== 200 and $error=="")
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }

                if ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl (eppn = $eppn) => " . $error;
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                }
                elseif (isset($response['totalResults']))
                {
                    // var_dump($response['totalResults']);
                    if ($response['totalResults'] == 1)
                    {
                        $resultJson->data = $response['Resources'];
                        $resultJson->status = ResponseStatus::STATUT_OK;
                    }
                    elseif ($response['totalResults'] == 0)
                    {
                        $error = __FUNCTION__ . " : Aucun agent ne correspond à l'EPPN $eppn dans le CIAM AMUE";
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                        $resultJson->description = $error;
                        $resultJson->status = ResponseStatus::STATUT_OK;
                    }
                    else
                    {
                        $error = __FUNCTION__ . " : Erreur => Plusieurs agents correspondent à l'EPPN $eppn dans le CIAM AMUE";
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                        $resultJson->status = ResponseStatus::STATUT_KO;
                    }
                }
            }
            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }

        /**
         * Crée une identité utilisateur dans le CIAM.
         *
         * @param LdapUserClass $ldapAgent Données LDAP source
         * @param CiamUserClass $ciamUser Objet CIAM à créer
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (string JSON de la ressource créée)
         *   - description (message d'erreur)
         *
         * Cas particuliers :
         * - 201 : création OK
         * - 409 : utilisateur déjà existant
         *
         */
        public function createCiamUserResource(LdapUserClass $ldapAgent, CiamUserClass $ciamUser) :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $curl = curl_init();
                $url = CIAM_USER_URL;
                $json = htmlspecialchars_decode($ciamUser->jsonString($ldapAgent,true));

                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " " . $ciamUser->jsonString($ldapAgent,true)); echo "</pre>";
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] " . $json));

                $opts = [
                    CURLOPT_URL => $url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_PROXY => ETAB_PROXY_URL,
                    // CURLOPT_VERBOSE => true
                ];
                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "Content-Type: application/json";
                $header[] = "accept: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);
                $error = curl_error ($curl);
                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

                if ($httpCode == 409 and $error=="") // La création d'identité retourne 409 si la ressource existe déjà
                {
                    $error = "L'identité existe déjà (eppn : " . $ldapAgent->eppn . ", identité : " . $ldapAgent->displayname . ").";
                }
                elseif ($httpCode !== 201 and $error=="") // La création d'identité retourne 201 si tout OK
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }
                elseif ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl => " . $error;
                }
                elseif (isset($response['id']) and trim($response['id'] . "") != '') // Si un id de ressource est défini => On peut le retourner
                {
                    $resultJson->data[] = json_encode($response); 
                    $resultJson->status = ResponseStatus::STATUT_OK;
                    error_log(basename(__FILE__) . stripAccents(" L'id du nouvel utilisateur est : " . $resultJson->data[0]));
                }
                else
                {
                    $error = __FUNCTION__ . " : Erreur interne";
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }

        /**
         * Supprime une identité utilisateur du CIAM.
         *
         * @param CiamUserClass $ciamUser Utilisateur à supprimer (id requis)
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (vide)
         *   - description (message d’erreur)
         *
         * Cas particuliers :
         * - 204 : suppression OK
         * - 404 : ressource inexistante
         *
         */
        public function deleteCiamUserResource(CiamUserClass $ciamUser) :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';
            
            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $ciamId = $ciamUser->id;

                $curl = curl_init();
                $url = CIAM_USER_URL . "/" . $ciamId;
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));

                $opts = [
                    CURLOPT_URL => $url,
                    CURLOPT_CUSTOMREQUEST => "DELETE",
                    CURLOPT_POSTFIELDS => "{}",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_PROXY => ETAB_PROXY_URL,
                    // CURLOPT_VERBOSE => true
                ];

                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "accept: */*";
                $header[] = "Content-Type: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);
                $error = curl_error ($curl);
                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

                if ($httpCode == 404 and $error=="") // La suppression d'identité retourne 404 si la ressource n'existe pas
                {
                    $error = "L'identité n'existe pas.";
                }
                elseif ($httpCode !== 204 and $error=="") // La suppression d'identité retourne 204 si tout OK
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }
                elseif ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl => " . $error;
                }
                else  /// On a donc un code HTTP 204 et pas d'erreur => tout est ok
                {
                    // error_log(basename(__FILE__) . stripAccents(" " . htmlentities(json_encode($response))));
                    $resultJson->data = [];
                    $resultJson->status = ResponseStatus::STATUT_OK;
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }


        /**
         * Modifie une identité utilisateur dans le CIAM.
         *
         * @param LdapUserClass $ldapAgent Données LDAP source
         * @param CiamUserClass $ciamUser Utilisateur CIAM à modifier
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (string JSON de la ressource modifiée)
         *   - description (message d’erreur)
         *
         * Cas particuliers :
         * - 200 : modification OK
         * - 404 : ressource inexistante
         *
         */        
        public function modifyCiamUserResource(LdapUserClass $ldapAgent, CiamUserClass $ciamUser) :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $ciamId = $ciamUser->id;

                $curl = curl_init();
                $url = CIAM_USER_URL . "/" . $ciamId;
                $json = $ciamUser->jsonString($ldapAgent);

                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " " . $ciamUser->jsonString($ldapAgent,true)); echo "</pre>";
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] " . $ciamUser->jsonString($ldapAgent,true)));

                $opts = [
                    CURLOPT_URL => $url,
                    CURLOPT_CUSTOMREQUEST => "PUT",
                    CURLOPT_POSTFIELDS => $json,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_PROXY => ETAB_PROXY_URL,
                    // CURLOPT_VERBOSE => true
                ];

                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "Content-Type: application/json";
                $header[] = "accept: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);
                $error = curl_error ($curl);
                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

                if ($httpCode == 404 and $error=="") // La modification d'identité retourne 404 si la ressource n'existe pas
                {
                    $error = "L'identité n'existe pas.";
                }
                elseif ($httpCode !== 200 and $error=="") // La modification d'identité retourne 200 si tout OK
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }
                elseif ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl => " . $error;
                }
                elseif (isset($response['id']) and trim($response['id'] . "") != '') // Si un id de ressource est défini => On peut le retourner
                {
                    // error_log(basename(__FILE__) . stripAccents(" " . htmlentities(json_encode($response))));
                    $resultJson->data[] =json_encode($response); 
                    $resultJson->status = ResponseStatus::STATUT_OK;
                }
                else
                {
                    $error = __FUNCTION__ . " : Erreur interne";
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }


        /**
         * Récupère la liste des groupes d'accès dans le CIAM.
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|ResponseStatus::STATUT_KO)
         *   - data (array liste des groupes)
         *   - description (string message d’erreur)
         */
        public function getListCiamGroupInfos() :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $count = 30;
                $startIndex = 1;
                $exitloop = false;
                while (!$exitloop)
                {
                    $curl = curl_init();
                    $url = CIAM_GROUP_URL . '?dummy=' .  time() . '&startIndex=' . $startIndex . '&count=' . $count;
                    // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                    error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));

                    $opts = [
                                CURLOPT_URL => $url,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_SSL_VERIFYPEER => false,
                                CURLOPT_PROXY => ETAB_PROXY_URL,
                                // CURLOPT_VERBOSE => true
                            ];
                    curl_setopt_array($curl, $opts);
                    curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                    $header = array();
                    $header[] = "accept: application/json";
                    $header[] = "authorization: Bearer " . $token;
                    $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                    $header[] = "Pragma: no-cache";
                    $header[] = "Expires: 0";
                    
                    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                    $json = curl_exec($curl);
                    $response = (array)json_decode($json, true);
                    $error = curl_error ($curl);
                    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    if ((int) $httpCode !== 200 and $error=="")
                    {
                        $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                    }

                    if ($error != "")
                    {
                        $error = __FUNCTION__ . " : Erreur Curl (Liste complète) => " . $error;
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                        $resultJson->status = ResponseStatus::STATUT_KO;
                        $resultJson->description = "$error";
                        $resultJson->data = [];
                        return $resultJson;
                    }

                    if (!isset($response['Resources']))
                    {
                        $error = __FUNCTION__ . " : Format de la réponse n'est pas valide => Absence de Resources.";
                        error_log(basename(__FILE__) . stripAccents(" " . $error));
                        $resultJson->status = ResponseStatus::STATUT_KO;
                        $resultJson->description = "$error";
                        $resultJson->data = [];
                        return $resultJson;
                    }

                    $resultJson->data = array_merge($resultJson->data, $response['Resources']);

                    if (count($response['Resources']) != $count)
                    {
                        $exitloop = true;
                    }
                    else
                    {
                        $startIndex += $count;
                    }
                }
                $resultJson->status = ResponseStatus::STATUT_OK;
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $resultJson->description = "$error";
                $resultJson->data = [];
            }
            return $resultJson;
        }

        /**
         * Récupère les informations d’un groupe CIAM à partir de son identifiant.
         *
         * Cette méthode interroge l’API SCIM des groupes pour récupérer les
         * informations détaillées d’un groupe spécifique.
         *
         * @param string $groupId Identifiant unique du groupe CIAM
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|ResponseStatus::STATUT_KO)
         *   - data (array) Données du groupe retournées par l’API SCIM
         *   - description (string) Message d’erreur en cas d’échec
         *
         * Cas particuliers :
         * - 200 : récupération OK
         * - autre code HTTP : erreur
         */
        public function getCiamGroupInfos(string $groupId) :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $curl = curl_init();
                $url = CIAM_GROUP_URL . '/' . $groupId . '?dummy=' .  time();
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));

                $opts = [
                            CURLOPT_URL => $url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_PROXY => ETAB_PROXY_URL,
                            // CURLOPT_VERBOSE => true
                        ];
                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "accept: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);
                $error = curl_error ($curl);
                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpCode !== 200 and $error=="")
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }

                if ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl (groupid = $groupId) => " . $error;
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                }
                else
                {
                    $resultJson->data[] = $response;
                    $resultJson->status = ResponseStatus::STATUT_OK;
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }

        /**
         * Modifie un groupe d'accès dans le CIAM.
         *
         * Utilisée après l'ajout ou la suppression de membres d'un groupe. Si le
         * groupe n'a pas été modifié (CiamGroupClass::isModified() retourne false),
         * aucun appel n'est effectué auprès du CIAM : la méthode retourne directement
         * l'état courant du groupe, sans sauvegarde.
         *
         * @param CiamGroupClass $ciamGroup Groupe CIAM à modifier (id requis).
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (array) Données du groupe (non modifié, ou modifié tel que
         *     renvoyé par l'API SCIM)
         *   - description (string) Message d'information ou d'erreur
         *
         * Cas particuliers :
         * - Groupe non modifié : STATUT_OK, aucun appel API, description explicative
         * - Token CIAM inaccessible : STATUT_KO
         * - Code HTTP différent de 200 : STATUT_KO
         */
        public function modifyCiamGroupInfos(CiamGroupClass $ciamGroup) :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            if (!$ciamGroup->isModified())
            {
                $resultJson->status = ResponseStatus::STATUT_OK;
                $resultJson->description = "Le groupe n'est pas modifié. Aucune sauvegarde nécessaire.";
                $resultJson->data[] = json_decode($ciamGroup->jsonString(),true);

                // $response = $this->getCiamGroupInfos($ciamGroup->id);
                // if ($response->status == ResponseStatus::STATUT_KO)
                // {
                //     return $response;
                // }
                // $resultJson->data = $response->data;

                return $resultJson;
            }

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $groupId = $ciamGroup->id;


                $curl = curl_init();
                $url = CIAM_GROUP_URL . '/' . $groupId;
                // $json = $ciamGroup->jsonString();
                $json = $ciamGroup->modifyJsonString(true);

                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " " . $ciamGroup->modifyJsonString(true)); echo "</pre>";
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] ") . $json);

                $opts = [
                            CURLOPT_URL => $url,
                            CURLOPT_CUSTOMREQUEST => "PUT",
                            CURLOPT_POSTFIELDS => $json,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_PROXY => ETAB_PROXY_URL,
                            // CURLOPT_VERBOSE => true
                        ];
                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "accept: application/json";
                $header[] = "Content-Type: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);


                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);
                $error = curl_error ($curl);
                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpCode !== 200 and $error=="")
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }

                if ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl (groupid = $groupId) => " . $error;
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                }
                else
                {
                    $resultJson->data[] = $response;
                    $resultJson->status = ResponseStatus::STATUT_OK;
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }

        /**
         * Crée un groupe d'accès dans le CIAM.
         *
         * Cette méthode appelle l'API SCIM des groupes pour créer un nouveau groupe
         * à partir de son nom d'affichage (displayname, également utilisé comme externalId).
         * Si le groupe contient des membres (ciammembers), ils sont rattachés dès la création.
         *
         * @param CiamGroupClass $ciamGroup Groupe CIAM à créer (displayname requis, ciammembers optionnel)
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (array) Données du groupe créé telles que renvoyées par l'API SCIM
         *   - description (string) Message d'erreur en cas d'échec
         *
         * Cas particuliers :
         * - 201 : création OK
         * - Token CIAM inaccessible : STATUT_KO
         * - Autre code HTTP : STATUT_KO
         */
        public function createCiamGroupInfos(CiamGroupClass $ciamGroup) :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $curl = curl_init();
                $url = CIAM_GROUP_URL;

                $jsonArray = [
                    "schemas" => ["urn:ietf:params:scim:schemas:core:2.0:Group"],
                    "id" => "",
                    "externalId" => $ciamGroup->displayname,
                    "meta" => ["resourceType" => "Group"],
                    "displayName" => $ciamGroup->displayname
                ];

                if (count($ciamGroup->ciammembers)>0)
                {
                    foreach($ciamGroup->ciammembers as $member)
                    {
                        $jsonArray["members"][] = ["value" => $member];
                    }
                }

                $jsonString = json_encode($jsonArray,JSON_UNESCAPED_SLASHES+JSON_PRETTY_PRINT);
                // var_dump($jsonString);

                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " " . $ciamGroup->modifyJsonString(true)); echo "</pre>";
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] " . $ciamGroup->modifyJsonString(true)));

                $opts = [
                            CURLOPT_URL => $url,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $jsonString,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_PROXY => ETAB_PROXY_URL,
                            // CURLOPT_VERBOSE => true
                        ];
                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "Content-Type: application/json";
                $header[] = "accept: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);
                $error = curl_error ($curl);
                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpCode !== 201 and $error=="")
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }

                if ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl => " . $error;
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                }
                else
                {
                    $resultJson->data[] = $response;
                    $resultJson->status = ResponseStatus::STATUT_OK;
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }

        /**
         * Supprime un groupe d'accès du CIAM.
         *
         * Cette méthode appelle l'API SCIM des groupes pour supprimer le groupe
         * correspondant à l'identifiant du groupe fourni.
         *
         * @param CiamGroupClass $ciamGroup Groupe CIAM à supprimer (id requis)
         *
         * @return ResponseClass Résultat contenant :
         *   - status (ResponseStatus::STATUT_OK|STATUT_KO)
         *   - data (array) Réponse de l'API SCIM (vide en cas de suppression réussie)
         *   - description (string) Message d'erreur en cas d'échec
         *
         * Cas particuliers :
         * - 204 : suppression OK
         * - Token CIAM inaccessible : STATUT_KO
         * - Autre code HTTP (404 si groupe inexistant) : STATUT_KO
         */
        public function deleteCiamGroup(CiamGroupClass $ciamGroup) :ResponseClass
        {
            $resultJson = new ResponseClass();
            $resultJson->status = ResponseStatus::STATUT_KO;
            $resultJson->data = [];
            $error = '';

            $token = $this->getCiamToken();
            if (trim($token) == '')
            {
                $error = "Impossible de récupérer le token à partir du CIAM";
            }
            else
            {
                $groupId = $ciamGroup->id;

                $curl = curl_init();
                $url = CIAM_GROUP_URL . '/' . $groupId;
                $json = '';

                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " $url"); echo "</pre>";
                error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] $url"));
                // echo "<pre>"; print_r(__FUNCTION__ . " " . __LINE__ . " " . $ciamGroup->modifyJsonString(true)); echo "</pre>";
                // error_log(basename(__FILE__) . stripAccents(" " . __FUNCTION__ . " " . __LINE__ . " [DEBUG] " . $ciamGroup->modifyJsonString(true)));

                $opts = [
                            CURLOPT_URL => $url,
                            CURLOPT_CUSTOMREQUEST => "DELETE",
                            CURLOPT_POSTFIELDS => $json,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_PROXY => ETAB_PROXY_URL,
                            // CURLOPT_VERBOSE => true
                        ];
                curl_setopt_array($curl, $opts);
                curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

                $header = array();
                $header[] = "Content-Type: application/json";
                $header[] = "accept: application/json";
                $header[] = "authorization: Bearer " . $token;
                $header[] = "Cache-Control: no-cache, no-store, must-revalidate";
                $header[] = "Pragma: no-cache";
                $header[] = "Expires: 0";
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);


                $json = curl_exec($curl);
                $response = (array)json_decode($json, true);
                $error = curl_error ($curl);
                $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpCode !== 204 and $error=="")
                {
                    $error = __FUNCTION__ . " : Code retour HTTP => $httpCode : " . HTTP_ERROR_MSG[$httpCode];
                }

                if ($error != "")
                {
                    $error = __FUNCTION__ . " : Erreur Curl (groupid = $groupId) => " . $error;
                    error_log(basename(__FILE__) . stripAccents(" " . $error));
                }
                else
                {
                    $resultJson->data[] = $response;
                    $resultJson->status = ResponseStatus::STATUT_OK;
                }
            }

            if ($resultJson->status == ResponseStatus::STATUT_KO)
            {
                error_log(basename(__FILE__) . stripAccents(" " . $error));
                $resultJson->description = "$error";
                $resultJson->data = [];
            }

            return $resultJson;
        }

        public function checkAndCreateIdentity(string $lineInfos, SifacSqlMode $sqlmode) :ResponseClass
        {
            $response = new ResponseClass();
            $sifacFunctions = new SifacFunctions();
            $ciamfunctions = new CiamFunctions();

            $fullSifacRoleArray = ['NOM' => [], 'DESCRIPTION' => []];
            // Si les rôles SIFAC sont gérés dans le CIAM, il faut mettre en cache les rôles SIFAC
            if (SIFACROLES_INTO_CIAM)
            {
                // On récupère tous les rôles SIFAC
                $sifacFunctions = new SifacFunctions();
                $resultJson = $sifacFunctions->getSifacRolesList($sqlmode);
                if ($resultJson->status == ResponseStatus::STATUT_OK)
                {
                    $fullSifacRoleArray = $resultJson->data;
                    if (trim($resultJson->description . '') != '')
                    {
                        $resultJson->status = ResponseStatus::STATUT_KO;
                        return $resultJson;
                        // showMessage(MSGWARNING,$resultJson->description);
                    }
                }
                else
                {
                    return $resultJson;
                    // showMessage(MSGERROR,"Erreur lors de la récupération des rôles SIFAC : " . $resultJson->description);  
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

            $infos = explode(';',$lineInfos);
            $agent=trim($infos[0]);
            if ($agent=='')
            {
                $response = new ResponseClass();
                $response->status = ResponseStatus::STATUT_KO;
                $response->description = "L'identifiant de l'agent est vide";
                return $response;
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
                return $response;
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
                    $displayName = htmlspecialchars($response->data[0][LDAP_AGENT_DISPLAYNAME_ATTR]);
                    $eppn = htmlspecialchars($response->data[0][LDAP_AGENT_EPPN_ATTR]);
                    $mail = htmlspecialchars($response->data[0][LDAP_AGENT_MAIL_ATTR]);
                    $nom = htmlspecialchars($response->data[0][LDAP_AGENT_NOM_ATTR]);
                    $prenom = htmlspecialchars($response->data[0][LDAP_AGENT_PRENOM_ATTR]);
                    $uid = htmlspecialchars($response->data[0][LDAP_AGENT_UID_ATTR]);
                }
                else
                {
                    $response->description .= "<br>Critère de recherche : $agent";
                    $response->status = ResponseStatus::STATUT_KO;
                    return $response;
                }
            }

            $ciamUser = new CiamUserClass();
            
            if (trim($eppn . '') != '')
            {
                $ciamActive = true;

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
                            return $response;
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
                            return $response;
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
                                    $responseModifyGroup = $ciamfunctions->modifyCiamGroupInfos($ciamGroup);
                                    if ($responseModifyGroup->status == ResponseStatus::STATUT_KO)
                                    {
                                        error_log(basename(__FILE__) . " " . stripAccents(" Erreur lors de la modification des groupes => " . $responseModifyGroup->description . "."));  
                                    }
                                }
                            }
                        }
                    }
                }

            }
            if ($response->status == ResponseStatus::STATUT_OK)
            {
                $response->description = "L'agent $agent a été créé avec l'identifiant : " . $ciamUser->id;
                $response->data[0] = $ciamUser->id;
                // showMessage(MSGINFO, "L'agent $agent a été créé avec l'identifiant : " . $ciamUser->id);
            }
            return $response;
        }

    }
?>