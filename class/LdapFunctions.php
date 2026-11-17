<?php
    require_once('ResponseClass.php');

    /**
     * Classe utilitaire permettant d'effectuer des recherches dans l'annuaire LDAP.
     *
     * Cette classe encapsule les appels aux fonctions LDAP PHP afin de :
     * - se connecter au serveur LDAP
     * - effectuer une recherche avec filtres
     * - récupérer les attributs demandés
     *
     * Les résultats sont retournés sous forme d'objet ResponseClass.
     *
     * @author Pascal COMTE / Université Paris 1 Panthéon-Sorbonne
     */
    class LdapFunctions
    {
        private function mergeArraysNoDuplicateKeys(array $resultArray, string $uniqueField) :array
        {
            // error_log(basename(__FILE__) . stripAccents(" Début de la fonction " . __FUNCTION__));
            $keys = array();
            $r = array();
            foreach ($resultArray as $one_array) 
            {
                // error_log(basename(__FILE__) . stripAccents(" one_array = " . print_r($one_array,true)));

                $key = $one_array[$uniqueField];
                // error_log(basename(__FILE__) . stripAccents(" key = " . $key));
                
                if (isset($keys[$key])) 
                {
                    $r[$key]['matchCount']++;
                    continue;
                }
                $keys[$key] = 1;
                $one_array['matchCount'] = 1;
                $r[$key] = $one_array;
            }

            // On trie décroissant par nombre de count
            usort($r, function ($a, $b) {                
                return $b['matchCount'] <=> $a['matchCount']; // Utilisation de l'opérateur "spaceship" (<=>) à partir de PHP 7
            });

            return $r;
        }

        /**
         * Effectue une recherche dans l'annuaire LDAP.
         *
         * Si $filter n'est pas vide, construit un filtre en OU recherchant
         * $searchInfo (échappé) dans chacun des attributs listés. Si $filter est
         * vide, $searchInfo est utilisé tel quel comme filtre LDAP brut (sans
         * échappement dans ce cas). Sauf si l'option LDAP_OPTION_FULL_SEARCH est
         * positionnée, la constante LDAP_EXTRACRITERE est systématiquement ajoutée
         * (ET) au filtre construit.
         *
         * Pour chaque attribut demandé dans $restriction, seule la première valeur
         * est conservée dans le résultat ; les valeurs supplémentaires d'un
         * attribut multivalué sont ignorées.
         *
         * @param string $searchInfo Valeur recherchée dans LDAP, ou filtre LDAP
         *                           brut si $filter est vide.
         * @param string[] $filter Liste des attributs LDAP sur lesquels rechercher
         *                         $searchInfo (recherche en OU). Vide = $searchInfo
         *                         est utilisé directement comme filtre LDAP.
         * @param string[] $restriction Liste des attributs LDAP à retourner (seule
         *                              la première valeur de chaque attribut est
         *                              conservée).
         * @param int $options Options de recherche (bitmask de ldapsearchclass).
         *  Options possibles :
         *      - ldapsearchclass::LDAP_OPTION_ONE_RESPONSE : si plus d'une entrée
         *        est trouvée, la réponse est considérée comme une absence de
         *        correspondance exploitable (STATUT_OK, data vide)
         *      - ldapsearchclass::LDAP_OPTION_FULL_SEARCH : ignore LDAP_EXTRACRITERE
         * 
         * @return ResponseClass Résultat contenant :
         *                       - status : ResponseStatus::STATUT_OK|STATUT_KO
         *                       - description : message associé
         *                       - data : tableau indexé numériquement de résultats
         *                         LDAP (un tableau associatif attribut => valeur
         *                         par entrée) ; vide si aucune correspondance, si
         *                         plusieurs correspondances avec
         *                         LDAP_OPTION_ONE_RESPONSE, ou en cas d'erreur
         *                         (connexion, bind, réponse non conforme).
         */
        public function getLDAPInfos(string $searchInfo, array $filter, array $restriction, int $options) :ResponseClass
        {
            if (!defined('LDAP_AGENT_CN_ATTR'))
            {
                define('LDAP_AGENT_CN_ATTR','');
            }

            $response = new ResponseClass();
            $response->status = ResponseStatus::STATUT_OK;

            $conLdap = null;
            $info = [];

            // On remplace les suites d'espace par un seul
            $searchInfo = preg_replace('/\\s+/', ' ', $searchInfo);
            // On va passer toutes la chaine en minuscule
            $searchInfo = mb_strtolower($searchInfo);

            // On va essayer de chercher la chaine complète en premier
            // Donc on l'ajoute dans le tableau des informations à chercher
            $searchInfoArray = array_merge([$searchInfo], explode(' ', $searchInfo));

            // On supprime les doublons + réindexer propre du tableau
            $searchInfoArray = array_values(array_unique($searchInfoArray));
            // error_log(basename(__FILE__) . stripAccents(" Les critères de recherche => " . print_r($searchInfoArray,true)));

            $prefix = '';
            foreach ($searchInfoArray as $index => $searchInfoItem)
            {

                // Si le critère de recherche a moins de 3 caractère et que ce n'est pas le dernier du tableau
                // On le concatène avec le critère de recherche suivant
                if (strlen($searchInfoItem) < 3 and $index < count($searchInfoArray)-1)
                {
                    if (strlen($prefix) != 0) $prefix .= ' ';
                    $prefix .= $searchInfoItem;
                    continue;
                }
                $searchInfoItem = $prefix . ' ' . $searchInfoItem;

                // On remplace les suites d'espace par un seul (si besoin)
                $searchInfo = preg_replace('/\\s+/', ' ', $searchInfoItem);
                // error_log(basename(__FILE__) . stripAccents(" On va chercher dans LDAP => " . $searchInfo));
                $prefix = '';

                try {
                    $conLdap = @ldap_connect(LDAPSERVER);
                    if (!$conLdap)
                    {
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->description = "Une erreur s'est produite lors de la connexion à LDAP.<br>Veuillez vérifier la configuration.";
                        $response->data = [];
                        return $response;
                    }
                    else
                    {
                        ldap_set_option($conLdap, LDAP_OPT_PROTOCOL_VERSION, 3);
                        if (!ldap_bind($conLdap, LDAPLOGIN, LDAPPASSWD))
                        {
                            $response->status = ResponseStatus::STATUT_KO;
                            $response->description = "Une erreur s'est produite lors de l'appel à LDAP bind.<br>Veuillez vérifier la configuration.";
                            $response->data = [];
                            return $response;
                        }
                        else
                        {
                            $filtre = "";
                            if (count($filter) > 0)
                            {
                                foreach($filter as $nomFiltre)
                                {
                                    if (trim($nomFiltre . '') == '')
                                    {
                                        continue;
                                    }
                                    if ($filtre == '') $filtre = "(|";
                                    if (in_array($nomFiltre, [LDAP_AGENT_NOM_ATTR, LDAP_AGENT_PRENOM_ATTR, LDAP_AGENT_MAIL_ATTR, LDAP_AGENT_DISPLAYNAME_ATTR, LDAP_AGENT_CN_ATTR]))
                                    {
                                        $filtre  = $filtre . "(" . mb_strtolower($nomFiltre) . "=" . ldap_escape($searchInfo, '', LDAP_ESCAPE_FILTER) . "*)";
                                    }
                                    else
                                    {
                                        $filtre  = $filtre . "(" . mb_strtolower($nomFiltre) . "=" . ldap_escape($searchInfo, '', LDAP_ESCAPE_FILTER) . ")";
                                    }
                                }
                                if ($filtre != '')
                                {
                                    $filtre  = $filtre . ')';
                                }
                            }
                            else
                            {
                                $filtre = $searchInfo;
                            }
                            if ($filtre != '') 
                            {
                                if (trim(LDAP_EXTRACRITERE) != '' and !($options&ldapsearchclass::LDAP_OPTION_FULL_SEARCH))
                                {
                                    $filtre = '(&' . $filtre . ' ' . LDAP_EXTRACRITERE . ')';
                                }
                            }
                            // error_log(basename(__FILE__) . stripAccents(" LDAP filtre => $filtre"));
                            if (!in_array(LDAP_AGENT_EPPN_ATTR, $restriction))
                            {
                                $restriction[] = LDAP_AGENT_EPPN_ATTR;
                            }
                            // error_log(basename(__FILE__) . stripAccents(" LDAP restriction => " . print_r($restriction,true)));

                            // $filtre = ldap_escape($filtre, '', LDAP_ESCAPE_FILTER);
                            $dn = LDAPSEARCHBASE;
                            $sr = @ldap_search($conLdap, $dn, $filtre, $restriction);
                            $info = @ldap_get_entries($conLdap, $sr);
                        }
                    }
                } catch (\Exception $error) {
                    $response->status = ResponseStatus::STATUT_KO;
                    $response->description = "Une erreur s'est produite lors de l'appel à LDAP.<br>" . $error->getMessage();
                    $response->data = [];
                    return $response;
                }
                // error_log(basename(__FILE__) . stripAccents(" LDAP Result = " . print_r($info,true)));

                if ($response->status != ResponseStatus::STATUT_KO)
                // if ($response->description == '')
                {
                    if (!isset($info['count']))
                    {
                        $response->status = ResponseStatus::STATUT_KO;
                        $response->description = "Une erreur s'est produite lors de l'appel à LDAP.<br>La réponse n'est pas conforme : count manquant.";
                        $response->data = [];
                        return $response;
                    }
                    $count = $info['count'];
                    if ($count == 0)
                    {
                        // error_log(basename(__FILE__) . stripAccents(" Aucune correspondance trouvée avec $searchInfo"));
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->description = 'Aucune correspondance n\'a été trouvée dans LDAP.';
                        // $response->data = []; 
                    }
                    elseif (($count >= 2) and ($options&ldapsearchclass::LDAP_OPTION_ONE_RESPONSE))
                    {
                        // error_log(basename(__FILE__) . stripAccents(" Plusieurs correspondances trouvées pour $searchInfo"));
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->description = 'Plusieurs correspondances ont été trouvées dans LDAP.<br>Veuillez préciser votre recherche.';
                        $response->data = []; 
                    }
                    else
                    {
                        $response->status = ResponseStatus::STATUT_OK;
                        $response->description = 'L\'appel à LDAP s\'est bien passé.';
                        // error_log(basename(__FILE__) . stripAccents(" infos après la récup LDAP = " . print_r($info, true)));
                        unset($info['count']);
                        foreach($info as $ldapElement)
                        {
                            $index = count($response->data);
                            foreach ($restriction as $nomRestriction)
                            {            
                                // error_log(basename(__FILE__) . stripAccents(" Construction du LDAP Result nomfiltre = $nomRestriction"));
                                if (isset($ldapElement[$nomRestriction][0]))
                                { 
                                    $response->data[$index][$nomRestriction] = $ldapElement[$nomRestriction][0]; 
                                }
                            }
                        }
                    }
                }
                @ldap_unbind($conLdap);
            }

            // error_log(basename(__FILE__) . stripAccents(" LDAP Result = " . print_r($response->data,true)));
            $response->data = $this->mergeArraysNoDuplicateKeys($response->data, LDAP_AGENT_EPPN_ATTR);

            // @ldap_unbind($conLdap);
            return $response;            
        }
    }
?>