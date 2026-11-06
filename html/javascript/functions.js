/**
 * @file functions.js
 * @description
 * Bibliothèque de fonctions JavaScript.
 *
 * @author Pascal COMTE / Université Paris 1 Panthéon-Sorbonne
 * @version 1.0
 */

    /**
     * Convertit une date française (JJ/MM/AAAA) en format SIFAC (AAAAMMJJ).
     *
     * Une chaîne vide (après trim) est retournée telle quelle. Si la date n'est
     * pas valide (voir dateIsValid), retourne false plutôt qu'une chaîne.
     *
     * @param {string} dateString Date au format français (JJ/MM/AAAA).
     * @returns {(string|boolean)} Date au format SIFAC (AAAAMMJJ), la chaîne vide
     *                              si l'entrée était vide, ou false si la date
     *                              n'est pas valide.
     */
    function frenchDateToSifacFormat(dateString)
    {
        dateString = dateString.trim();
        if (dateString == "")
        {
            return dateString;
        }
        if (!dateIsValid(dateString))
        {
            return false;
        }
        return dateString.replace(/^(\d{2})(.)(\d{2})(.)(\d{4})$/g,"$5$3$1");
    }

    /**
     * Convertit une date SIFAC (AAAAMMJJ) en format français (JJ/MM/AAAA).
     *
     * Contrairement à frenchDateToSifacFormat, une entrée vide ou falsy retourne
     * directement false (et non une chaîne vide).
     *
     * @param {string} dateString Date au format SIFAC (AAAAMMJJ).
     * @returns {(string|boolean)} Date au format français (JJ/MM/AAAA), ou false
     *                              si l'entrée est vide, mal formée, ou si la date
     *                              n'est pas valide.
     */
    function sifacDateToFrenchFormat(dateString)
    {
        if (!dateString)
        {
            return false;
        }

        dateString = dateString.trim();

        const regex = /^(\d{4})(\d{2})(\d{2})$/;
        if (dateString.match(regex) === null) {
            return false;
        }
        let frenchdate = dateString.replace(/^(\d{4})(\d{2})(\d{2})$/g,"$3/$2/$1");
        if (!dateIsValid(frenchdate))
        {
            return false;
        }
        return frenchdate;
    }

    /**
     * Vérifie si une date au format JJ/MM/AAAA est valide (existe réellement dans
     * le calendrier, ex. rejette 31/02/2024).
     *
     * @param {string} dateStr Date à vérifier (format JJ/MM/AAAA attendu).
     * @returns {boolean} true si la date est valide, false sinon (y compris si le
     *                     format ne correspond pas à JJ/MM/AAAA).
     */
    function dateIsValid(dateStr)
    {
        const regex = /^\d{2}\/\d{2}\/\d{4}$/;

        if (dateStr.match(regex) === null) {
            return false;
        }

        const [day, month, year] = dateStr.split('/');
        const isoFormattedStr = `${year}-${month}-${day}`;
        const date = new Date(isoFormattedStr);
        const timestamp = date.getTime();

        if (typeof timestamp !== 'number' || Number.isNaN(timestamp)) {
            return false;
        }
        return date.toISOString().startsWith(isoFormattedStr);
    }

    /**
     * Initialise le tri sur les colonnes d'un tableau HTML, et simule un clic
     * initial sur une colonne pour afficher l'indicateur de tri par défaut.
     *
     * Ne fait rien (avec un message en console) si la table n'existe pas dans le
     * document ou si columnNumber dépasse le nombre de colonnes.
     *
     * @param {string} tableName Id HTML de la table à trier (sans le '#').
     * @param {number} columnNumber Index de la colonne sur laquelle simuler un
     *                                clic initial (tri par défaut).
     * @returns {void}
     */
    function sortTableInit(tableName,columnNumber)
    {
        let table = document.querySelector('#'+tableName); 
        if (table)
        {
            let thlist = table.querySelector('.entete').querySelectorAll('th');
            thlist.forEach(th => { 
                                    if (th.querySelector('.sortindicator')!==null) 
                                    { 
                                        th.addEventListener('click', sortColumn) 
                                        th.asc = true
                                    }
                                });
            if (columnNumber < thlist.length)
            {
                thlist[columnNumber].click(); // On simule le clic sur la colonne columnNumber pour faire afficher la flêche
            }
            else
            {
                console.log("Attention : La colonne " + columnNumber.toString() + " ne fait pas partie du tableau " + tableName);
            }
        }
        else
        {
            console.log("Attention : La table " + tableName + " n'existe pas dans le document");
        }
    }

    function resortTable(tableName)
    {
        let table = document.querySelector('#'+tableName); 
        if (table)
        {
            let thlist = table.querySelector('.entete').querySelectorAll('th');
            thlist.forEach(th => { 
                                    if (th.querySelector('.sortindicator')!==null) 
                                    { 
                                        if (th.querySelector('.sortindicator').textContent.trim() != '')
                                        {
                                            th.asc = !th.asc;
                                            th.click();
                                        }
                                    }
                                });
        }
    }

    /**
     * Trie un tableau HTML selon la colonne cliquée, et met à jour l'indicateur
     * visuel de tri (flèche montante/descendante) sur l'en-tête concerné.
     *
     * Destinée à être utilisée comme gestionnaire d'événement 'click' sur un
     * élément <th> (voir sortTableInit) : s'appuie sur `this` pour identifier la
     * colonne, ne prend donc pas de paramètre explicite.
     *
     * @this {HTMLTableCellElement} L'élément <th> sur lequel le clic a eu lieu.
     * @returns {void}
     */
    function sortColumn()
    {
        let th = this;

        const currentSortIndicator = th.querySelector('.sortindicator')
        if (currentSortIndicator!==null)
        {
            const table = th.closest('table');
            const tbody = table.querySelector('tbody');
        
            if (currentSortIndicator.innerText.trim().length>0)
            {
                th.asc = !th.asc
            }
        
            Array.from(tbody.querySelectorAll('tr'))
                .sort(comparer(Array.from(th.parentNode.children).indexOf(th), th.asc))
                .forEach(tr => tbody.appendChild(tr) );
        
            for (let thIndex = 0 ; thIndex < table.querySelector('.entete').querySelectorAll('th').length; thIndex++)
            {
                if (th.parentNode.children[thIndex]!==null)
                {
                    let thSortIndicator = th.parentNode.children[thIndex].querySelector('.sortindicator');
                    if (thSortIndicator!==null)
                    {
                        thSortIndicator.innerText = ' ';
                    }
                }
            }
        
            if (currentSortIndicator!==null)
            {
                if (th.asc)
                {
                    currentSortIndicator.innerHTML = '&darr;'; // flêhe qui descend
                }
                else
                {
                    currentSortIndicator.innerHTML = '&uarr;'; // flêche qui monte
                }
            }
        }
    }

    /**
     * Met à jour l'attribut 'datetime' de l'élément <time> associé à un champ de
     * saisie de date (converti au format SIFAC via frenchDateToSifacFormat).
     *
     * Le <time> recherché est soit un enfant de input, soit un enfant de son
     * parentNode.
     *
     * @param {HTMLElement} [input] Champ de saisie contenant la nouvelle date. Si
     *                               omis (ex. utilisation directe comme gestionnaire
     *                               d'événement), utilise `this`.
     * @returns {void}
     */
    function updateTimeElement(input)
    {
        // console.log('updateTimeElement is fired');
        if (!input)
        {
            input = this;
        }
        let timeElement = input.querySelector('time');
        if (!timeElement)
        {
            timeElement = input.parentNode.querySelector('time');
        }
        if (timeElement)
        {
            // timeElement.removeAttribute('datetime');
            timeElement.setAttribute('datetime',frenchDateToSifacFormat(input.value));
        }
    }

    /**
     * Génère une fonction de comparaison pour trier des lignes de tableau
     * selon la valeur d'une colonne. Les valeurs numériques sont comparées
     * numériquement, sinon la comparaison est lexicographique.
     *
     * @param {number} columnIndex Index de la colonne à trier.
     * @param {boolean} [ascending=true] Sens du tri.
     * @returns {(rowA: HTMLTableRowElement, rowB: HTMLTableRowElement) => number}
     */
    const comparer = (columnIndex, ascending = true) => {
    return (rowA, rowB) => {

        const valueA = getCellValue(rowA, columnIndex);
        const valueB = getCellValue(rowB, columnIndex);

        const v1 = ascending ? valueA : valueB;
        const v2 = ascending ? valueB : valueA;

        const bothNumeric =
        v1 !== '' &&
        v2 !== '' &&
        !isNaN(v1) &&
        !isNaN(v2);

        if (bothNumeric) {
        return Number(v1) - Number(v2);
        }

        return String(v1).localeCompare(String(v2));
    };
    };

    /**
     * Retourne la valeur d'une cellule dans une ligne de tableau, pour les
     * besoins du tri (voir comparer).
     *
     * Le contenu de la cellule est interprété selon ce qu'elle contient :
     * - un <time> : valeur de son attribut 'datetime'
     * - un <input type='checkbox'> : état coché (boolean)
     * - un <input type='text'> : sa valeur
     * - un <button> : defaultValue (non trié)
     * - sinon : innerText / textContent de la cellule
     * Si la valeur obtenue est une chaîne vide (après trim), defaultValue est
     * retourné à la place.
     *
     * @param {(HTMLTableRowElement|jQuery)} tr Ligne du tableau, en élément DOM
     *                                           natif ou objet jQuery (converti
     *                                           automatiquement).
     * @param {(number|string)} selector Index (number) de la cellule, ou nom de
     *                                    classe (string) recherché dans la ligne.
     * @param {*} [defaultValue=''] Valeur retournée si la classe n'est pas
     *                               trouvée, si le contenu n'est pas géré (ex.
     *                               autre type d'input), ou si la valeur trouvée
     *                               est une chaîne vide.
     * @returns {*} Valeur de la cellule (string, boolean, ou defaultValue).
     */
    function getCellValue(tr, selector, defaultValue = '')
    {
        let returnValue = '';
        let idx;

        if (Number.isInteger(selector))
        {
            idx = selector
        }
        else // On suppose que c'est un nom de classe
        {

            let cell = $(tr.querySelector('.' + selector));
            if (!cell || cell.length == 0)
            {
                console.log("La classe " + selector + " n'a pas été trouvée dans la ligne !");
                return defaultValue;
            }
            idx = cell.index();
        }

        if (tr.constructor.name == 'jQuery' )
        {
            // On converti le TR en jQuery en JavaScript
            tr = tr[0];
        }

        // Si on a un time dans le td, alors on trie sur l'attribut datetime
        if (tr.children[idx].querySelector('time')!==null) 
        {
            returnValue = tr.children[idx].querySelector('time').getAttribute('datetime');
        }
        else if (tr.children[idx].querySelector('input'))
        {
            let input = tr.children[idx].querySelector('input');
            if (input.type.toLowerCase() == 'checkbox')
            {
                return input.checked;
            }
            else if (input.type.toLowerCase() == 'text')
            {
                returnValue = input.value;
            }
            else
            {
                console.log("Je ne sais pas ce que c'est comme input !");
                return defaultValue;
            }
        }
        else if (tr.children[idx].querySelector('button'))
        {
            return defaultValue;
        }
        else
        {

            returnValue = tr.children[idx].innerText || tr.children[idx].textContent;
        }
        if (returnValue.toString().trim() == '')
        {
            return defaultValue;
        }
        return returnValue;
    };


    /**
     * Donne le focus au champ de recherche affiché par select2 lors de
     * l'ouverture de sa liste déroulante.
     *
     * Le champ '.select2-search__field' est recherché dans tout le document
     * (et non dans un conteneur précis), car select2 l'ajoute dynamiquement en
     * fin de document à l'ouverture. Ne fait rien si aucun champ n'est trouvé.
     *
     * Note : utilise document.querySelector, qui ne retourne jamais un tableau ;
     * la vérification Array.isArray(inputSelect2List) n'est donc jamais vraie et
     * ne s'exécute jamais en pratique.
     *
     * @returns {void}
     */
    function selectToSetFocus()
    {
        // L'objet de class "select2-search__field" est ajouté dynamiquement en fin de document lorsqu'on ouvre le select2
        // => On doit donc rechercher la class "select2-search__field" dans tout le document
        let inputSelect2;
        let inputSelect2List = document.querySelector(".select2-search__field"); 
        if (inputSelect2List)
        {
            if (Array.isArray(inputSelect2List))
            {
                inputSelect2 = inputSelect2List[0];
            }
            else
            {
                inputSelect2 = inputSelect2List;
            }
            if (inputSelect2)
            {
                inputSelect2.focus();
            }
        }
    }

    /**
     * Permet de vérifier que la chaine passée en paramètre est une URL valide
     * @param {string} stringToTest
     *        Chaine contenant l'URL supposée à vérifier.
     * @return {boolean}
     *  true si la chaine est une URL valide
     *  false si la chaine n'est pas une URL valide
     */
    function isValidHttpUrl(stringToTest) 
    {
        let url;
        
        try {
            url = new URL(stringToTest);
        } catch (_) {
            return false;  
        }

        return url.protocol === "http:" || url.protocol === "https:";
    }

    // Initialisation au chargement du document : active select2 sur les listes
    // déroulantes '.listuserciamclass' (recherche en français), et force le focus
    // sur le champ de recherche select2 à l'ouverture de chaque liste déroulante
    // (voir selectToSetFocus).
    $(document).ready(function() {
        $('.listuserciamclass').select2({language: "fr"});

        // On force le focus sur l'input de recherche lorsqu'on clique sur l'ouverture de la combo d'un select2
        var select2ContainerList = document.getElementsByClassName("select2-container");
        // console.log(select2ContainerList);
        if (select2ContainerList)
        {
            for (let index = 0; index < select2ContainerList.length; index++)
            {
                let select2Container = select2ContainerList[index];
                // console.log(select2Container.classList);
                select2Container.addEventListener('click',selectToSetFocus);
            }
        }

    });

    /**
     * Récupère la liste des rôles SIFAC via un Web Service et alimente l'objet
     * `listeCompleteSifacRole` avec les rôles métiers (YC_) et périmètres (YP_).
     *
     * La fonction interroge le Web Service en mode GET (ou POST si réactivé) et analyse
     * la réponse JSON retournée. En cas de succès, les rôles sont répartis dans
     * les propriétés `ROLEMETIER` (rôles préfixés par `YC_`) et `ROLEPERIMETRE` (rôles préfixés par `YP_`) de l'objet fourni.
     * 
     * @async
     * 
     * @param {string} fullWSURL
     *        URL complète du Web Service à appeler.
     *
     * @param {string} sqlMode
     *        Mode SQL à transmettre au Web Service (ex. "DIRECT", "ECC", etc.).
     *
     * @param {Object} listeCompleteSifacRole
     *        Objet à alimenter avec les rôles récupérés.
     * @param {Object.<string, string>} listeCompleteSifacRole.ROLEMETIER
     *        Dictionnaire des rôles métiers (clé = nom du rôle, valeur = description).
     * @param {Object.<string, string>} listeCompleteSifacRole.ROLEPERIMETRE
     *        Dictionnaire des rôles de périmètre (clé = nom du rôle, valeur = description).
     * 
     * @returns {Promise<void>}
     *          Ne retourne rien directement, mais modifie l'objet `listeCompleteSifacRole`.
     *
     * @description
     * - Appelle le Web Service `sifacroleliste`.
     * - Vérifie le statut HTTP et le statut applicatif (`data.status`).
     * - Classe les rôles selon leur préfixe :
     *      - `YC_` → ROLEMETIER
     *      - `YP_` → ROLEPERIMETRE
     * - Journalise les erreurs dans la console et affiche un message d'erreur à l'utilisateur.
     * 
     * @note
     * Les exceptions générées lors de l'appel au Web Service ou du décodage JSON
     * sont interceptées, journalisées et signalées à l'utilisateur.
     * Elles ne sont pas propagées à l'appelant.
     *
     * @note
     * Suppose que listeCompleteSifacRole.ROLEMETIER et .ROLEPERIMETRE sont déjà
     * initialisés (objets) avant l'appel. Un rôle dont le nom ne commence ni par
     * 'YC_' ni par 'YP_' est silencieusement ignoré.
    */
    async function getSifacRoles(fullWSURL, sqlMode, listeCompleteSifacRole)
    {

        if (!fullWSURL || fullWSURL.trim() == '' || !isValidHttpUrl(fullWSURL))
        {
            showModalSingleButton('error','Appel d\'un WS','Ok',"L'adresse du WS n'est pas ou est mal définie.");
            return;
        }

        // Nettoyage de l'URL en supprimant les doubles "anti-slash" non nécessaires
        fullWSURL = fullWSURL.replace(/([^:]\/)\/+/g, "$1");

        let methode = 'sifacroleliste';
        let reponse;
        let data;

        try {
            /////////////////////// Mode POST ///////////////////////
            // let postParams = {  method: "POST",
            //                 headers: {
            //                             'Accept': 'application/json',
            //                             'Content-Type': 'application/json'
            //                             },
            //                 body: JSON.stringify({'methode' : 'sifacroleliste', 'sqlmode' : sqlMode})
            //                 };
            // reponse = await fetch(fullWSURL,postParams);
            ////////////////////////////////////////////////////////
            /////////////////////// Mode GET ///////////////////////
            reponse = await fetch(fullWSURL + "?methode=" + methode + "&sqlmode=" + encodeURIComponent(sqlMode));
            ////////////////////////////////////////////////////////

            data = await reponse.json();
            // On vérifie que la réponse a un format correct : 
            // Existance des proprités status, description et data qui sont toutes les 3 obligatoires
            if (!data || !data.hasOwnProperty('status') || !data.hasOwnProperty('description') || !data.hasOwnProperty('data'))
            {
                throw new Error("Format de la réponse incorrect.");
            }
        } 
        catch (exception) 
        {
            console.log("Une exception s'est produite lors de l'appel au WS : " + exception);
            let statutInfo = "Erreur WS - méthode : " + methode + " : " + exception;
            showModalSingleButton('error','Récupération des rôles SIFAC','Ok',statutInfo);
            return;
        }

        // L'appel du WS s'est bien passé => On a eu une réponse (ok ou pas mais on a une réponse)
        if (reponse.ok !== true)
        {
            console.log("La réponse lors de l'appel au WS n'est pas ok - fonction : getSifacRoles : " + reponse.statusText);
            let statutInfo = "Erreur WS - méthode : " + methode + " : " + reponse.statusText;
            showModalSingleButton('error','Récupération des rôles SIFAC','Ok',statutInfo);
            return;
        }
        else if (data.status.toUpperCase() == 'OK')
        {
            let roleInfos = data.data;
            if (roleInfos && typeof roleInfos === "object" && Array.isArray(roleInfos.NOM))
            {
                for (let role of roleInfos.NOM)
                {
                    let nomRole = role.name;
                    let descriptionRole = role.description;
                    if (nomRole.startsWith('YC_'))
                    {
                        listeCompleteSifacRole.ROLEMETIER[nomRole] = descriptionRole;
                    }
                    else if (nomRole.startsWith('YP_'))
                    {
                        listeCompleteSifacRole.ROLEPERIMETRE[nomRole] = descriptionRole;
                    }
                }
            }
            else
            {
                console.log("Format inattendu : roleInfos.NOM n'est pas un tableau.");
            }
        }
        else
        {
            console.log("Une erreur s'est produite lors de la recherche de la liste des rôles SIFAC : " + data.description);
            let statutInfo = "Une erreur s'est produite lors de la recherche de la liste des rôles SIFAC : " + data.description;
            showModalSingleButton('error','Récupération des rôles SIFAC','Ok',statutInfo);
            return;
        }
    }

    /**
     * Appelle un Web Service interne chargé de vider un fichier temporaire.
     *
     * La méthode à exécuter est lue dans l'attribut HTML `methode` de l'élément
     * déclencheur (par exemple : `delciamtoken`, `delsifactoken` ou
     * `delrolecache`). Le Web Service est appelé en POST et doit retourner un
     * objet JSON contenant les propriétés `status`, `description` et `data`.
     *
     * Une boîte de dialogue d'information ou d'erreur est affichée selon le
     * résultat de l'opération.
     *
     * @async
     *
     * @param {string} fullWSURL
     *        URL complète du Web Service.
     *
     * @param {HTMLElement} [menuItem]
     *        Élément HTML ayant déclenché l'appel. Si omis,
     *        `document.activeElement` est utilisé.
     *
     * @returns {Promise<boolean>}
     *          Promesse toujours résolue avec `false`.
     */
    async function callInternalWSForDelete(fullWSURL, menuItem)
    {
        let reponse;
        let jsonData;

        if (!fullWSURL || fullWSURL.trim() == '' || !isValidHttpUrl(fullWSURL))
        {
            showModalSingleButton('error','Appel d\'un WS','Ok',"L'adresse du WS n'est pas ou est mal définie.");
            return false;
        }

        // Nettoyage de l'URL en supprimant les doubles "anti-slash" non nécessaires
        fullWSURL = fullWSURL.replace(/([^:]\/)\/+/g, "$1");

        if (!menuItem)
        {
            menuItem = document.activeElement;
        }
        if (menuItem)
        {
            let methode = menuItem.getAttribute('methode');
            let params = { 
                            "methode" : methode 
                        };

            try 
            {
                let postParams = { method: "POST",
                            headers: {
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/json',
                                        },
                                body: JSON.stringify(params)
                            };
                reponse = await fetch(fullWSURL,postParams);
                jsonData = await reponse.json();
                // On vérifie que la réponse a un format correct : 
                // Existance des proprités status, description et data qui sont toutes les 3 obligatoires
                if (!jsonData || !jsonData.hasOwnProperty('status') || !jsonData.hasOwnProperty('description') || !jsonData.hasOwnProperty('data'))
                {
                    throw new Error("Format de la réponse incorrect.");
                }
            } 
            catch (exception) 
            {
                console.log("Une exception s'est produite lors de l'appel au WS : " + exception);
                let statutInfo = "Erreur WS - méthode : " + methode + " : " + exception;
                showModalSingleButton('error',menuItem.innerHTML,'Ok',statutInfo);
                return false;
            }

            // L'appel du WS s'est bien passé => On a eu une réponse (ok ou pas mais on a une réponse)
            if (reponse.ok !== true)
            {
                console.log("La réponse lors de l'appel au WS n'est pas ok - fonction : deleteTempFile : " + reponse.statusText);
                let statutInfo = "Erreur WS - méthode : " + methode + " - " + reponse.status + " " + reponse.statusText;
                showModalSingleButton('error',menuItem.innerHTML,'Ok',statutInfo);
            }
            else 
            {
                if (jsonData.status.toUpperCase() == 'OK')
                {
                    showModalSingleButton('info',menuItem.innerHTML,'Ok',jsonData.description);
                }
                else
                {
                    showModalSingleButton('error',menuItem.innerHTML,'Ok',jsonData.description);
                }
            }
        }
        return false;
    }

    /**
     * Crée une identité (utilisateur) via un Web Service et affiche le résultat
     * de l'opération dans un tableau HTML contenu dans `outputDiv`.
     *
     * Le Web Service `createciamident` est appelé en POST (JSON) avec le corps
     * `{ methode, sqlmode, inputdata }`. Il doit retourner un objet JSON contenant
     * les 3 propriétés obligatoires `status`, `description` et `data`. En cas de
     * succès (`status` = 'OK', insensible à la casse), `data` contient
     * l'identifiant de l'utilisateur créé (non affiché actuellement).
     *
     * Chaque appel (avec une URL valide) ajoute une ligne au tableau
     * (`table.tableausimple`, créé au premier appel puis réutilisé) :
     *   - cellule 1 : `inputData` ;
     *   - cellule 2 : le statut, d'abord « En attente de traitement » (classe
     *     `waitingclass`), puis le message de succès (classe `celinfo`) ou
     *     d'erreur (classe `celerror`).
     * Cela permet d'enchaîner plusieurs créations successives (création en masse).
     *
     * @async
     *
     * @param {string} fullWSURL
     *        URL complète (http/https) du Web Service à appeler. Les doubles "/"
     *        non nécessaires (hors "://") sont supprimés avant l'appel.
     *
     * @param {string} sqlMode
     *        Mode SQL transmis au Web Service (propriété `sqlmode`),
     *        ex. "DIRECT", "ECC", etc.
     *
     * @param {string} inputData
     *        Donnée d'entrée décrivant l'identité à créer, transmise au Web
     *        Service (propriété `inputdata`) et affichée dans la 1re cellule de
     *        la ligne (ou en préfixe du message si l'URL est invalide).
     *
     * @param {HTMLElement} outputDiv
     *        Conteneur HTML recevant le tableau de résultats. Obligatoire : il est
     *        utilisé dans tous les cas, y compris lorsque l'URL est invalide.
     *
     * @returns {Promise<void>}
     *          Ne retourne rien ; le résultat est affiché dans `outputDiv`.
     *
     * @description
     * - Si `fullWSURL` est vide ou invalide (`isValidHttpUrl`) : ajoute dans
     *   `outputDiv` un `<label>` d'erreur (précédé d'un `<br>` s'il en existe déjà
     *   un), sans créer de tableau ni ajouter de ligne, puis s'arrête.
     * - Sinon, crée le tableau de résultats dans `outputDiv` s'il n'existe pas
     *   encore, puis y ajoute une ligne « en attente », appelle le Web Service
     *   `createciamident` et vérifie le statut HTTP, le format de la réponse
     *   (présence de `status`, `description`, `data`) puis le statut applicatif
     *   (`data.status`).
     * - Affiche le résultat dans la 2e cellule de la ligne. Les exceptions (appel
     *   WS, décodage JSON) et les erreurs applicatives sont journalisées dans la
     *   console ; les erreurs HTTP et de format sont uniquement affichées.
     * - Lorsqu'il ne reste plus aucune ligne en attente (`waitingclass`) dans le
     *   tableau, affiche une boîte de dialogue d'information « Fin de l'opération »
     *   via `showModalSingleButton`. Les autres appels à cette fonction (résultat
     *   de chaque création) sont commentés.
     *
     * @note
     * Les exceptions générées lors de l'appel au Web Service ou du décodage JSON
     * sont interceptées et affichées dans la ligne concernée. Elles ne sont pas
     * propagées à l'appelant.
     *
     * @note
     * Si l'URL est invalide, les erreurs sont affichées sous forme de `<label>`
     * (et non dans le tableau) : si `outputDiv` contient à la fois un tableau et
     * des `<label>`, ceux-ci sont ajoutés après le tableau.
     *
     * @see isValidHttpUrl
     * @see showModalSingleButton
     */
    async function createIdentity(fullWSURL, sqlMode, inputData, outputDiv)
    {
        const WAITINGCLASS = 'waitingclass'

        let label;
        let br;
        let methode = 'createciamident';
        let reponse;
        let data;
        let table;
        let tableCell;
        let statutInfo = '';

        if (!fullWSURL || fullWSURL.trim() == '' || !isValidHttpUrl(fullWSURL))
        {
            if (outputDiv.getElementsByTagName('label').length >0)
            {
                br = outputDiv.ownerDocument.createElement('br');
                outputDiv.appendChild(br);
            }

            label = outputDiv.ownerDocument.createElement('label');
            label.classList.add('celerror');
            label.innerHTML = inputData + ' ' + "L'adresse du WS n'est pas ou est mal définie.";
            outputDiv.appendChild(label);

            // showModalSingleButton('error','Appel d\'un WS','Ok',"L'adresse du WS n'est pas ou est mal définie.");
            return;
        }

        // Nettoyage de l'URL en supprimant les doubles "anti-slash" non nécessaires
        fullWSURL = fullWSURL.replace(/([^:]\/)\/+/g, "$1");

        table = outputDiv.ownerDocument.getElementById('resulttable');
        if (!table)
        {
            table = outputDiv.ownerDocument.createElement('table');
            table.classList.add('tableausimple');
            table.id = 'resulttable';
            table.name = table.id;
            outputDiv.appendChild(table);
        }

        let tableLigne = table.insertRow();
        tableCell = tableLigne.insertCell(0);
        tableCell.classList.add('cellulesimple');
        tableCell.innerHTML = inputData;
        tableCell = tableLigne.insertCell(1);
        tableCell.classList.add('cellulesimple', WAITINGCLASS);
        tableCell.innerHTML = 'En attente de traitement';

        try {
            /////////////////////// Mode POST ///////////////////////
            let postParams = {  method: "POST",
                            headers: {
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/json'
                                        },
                            body: JSON.stringify({'methode' : methode, 'sqlmode' : sqlMode, 'inputdata' : inputData})
                            };
            reponse = await fetch(fullWSURL,postParams);
            ////////////////////////////////////////////////////////
            /////////////////////// Mode GET ///////////////////////
            // reponse = await fetch(fullWSURL + "?methode=" + methode + "&sqlmode=" + encodeURIComponent(sqlMode));
            ////////////////////////////////////////////////////////

            if (reponse.ok === true)
            {
                data = await reponse.json();
                // On vérifie que la réponse a un format correct : 
                // Existance des proprités status, description et data qui sont toutes les 3 obligatoires
                if (!data || !data.hasOwnProperty('status') || !data.hasOwnProperty('description') || !data.hasOwnProperty('data'))
                {
                    // throw new Error("Format de la réponse incorrect.");
                    statutInfo = "Erreur WS - méthode : " + methode + " : Format de la réponse incorrect.";
                }
            }
            else
            {
                data = new Object();
                data.status = 'KO';
                data.description = 'Erreur HTTP ' + reponse.status + ' : ' + reponse.statusText;
                data.data = [];
                statutInfo = "Erreur WS - méthode : " + methode + " : " + data.description;
            }
        } 
        catch (exception) 
        {
            console.log("Une exception s'est produite lors de l'appel au WS : " + exception);
            statutInfo = "Erreur WS - méthode : " + methode + " : " + exception;
        }

        if (statutInfo.length != 0)
        {
            tableCell.classList.remove(WAITINGCLASS);
            tableCell.classList.add('celerror');
            tableCell.innerHTML = statutInfo.replace(/(<br\s*\/?>)+/gi, '<br>');
            // showModalSingleButton('error','Création d\'un utilisateur','Ok',statutInfo);
        }
        else if (data.status.toUpperCase() == 'OK')
        {
            let userid 
            userid = data.data;
            // let statutInfo = "L'utilisateur a été créé " + userid;
            statutInfo = "L'utilisateur a été créé.";

            tableCell.classList.remove(WAITINGCLASS);
            tableCell.classList.add('celinfo');
            tableCell.innerHTML = statutInfo.replace(/(<br\s*\/?>)+/gi, '<br>');
            // showModalSingleButton('error','Création d\'un utilisateur','Ok',statutInfo);
        }
        else
        {
            statutInfo = "Une erreur s'est produite lors de la création de l'utilisateur : " + data.description
            console.log(statutInfo);

            tableCell.classList.remove(WAITINGCLASS);
            tableCell.classList.add('celerror');
            tableCell.innerHTML = statutInfo.replace(/(<br\s*\/?>)+/gi, '<br>');
            // showModalSingleButton('error','Création d\'un utilisateur','Ok',statutInfo);
        }

        if (table.getElementsByClassName(WAITINGCLASS).length == 0)
        {
            showModalSingleButton('info','Création en masse d\'utilisateurs','Ok','Fin de l\'opération');
        }
    }


