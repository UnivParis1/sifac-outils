<link rel="stylesheet" type="text/css" href="css/menubar-navigation.css?dummy=<?php echo rand(1,9999999999); ?>" media="all" />
<script src="javascript/menubar-navigation.js?dummy=<?php echo rand(1,9999999999); ?>"></script>

<?php
    global $casUid;

    $text = '';
    $backcolor = '#0f47dc';
    $textcolor = '#57dc0f';
    if (defined('TYPE_ENVIRONNEMENT') and is_array(TYPE_ENVIRONNEMENT))
    {
        if (isset(TYPE_ENVIRONNEMENT['texte'])) $text = TYPE_ENVIRONNEMENT['texte'];
        if (isset(TYPE_ENVIRONNEMENT['couleurtexte'])) $textcolor = TYPE_ENVIRONNEMENT['couleurtexte'];
        if (isset(TYPE_ENVIRONNEMENT['couleurfond'])) $backcolor = TYPE_ENVIRONNEMENT['couleurfond'];
    }
    if (trim($text) != '')
    {
        echo "<table style=\"text-align:center; width:100%; border:none; font-size:x-large; font-weight:bold; color:$textcolor; background-color:$backcolor\"><tr><td>$text</td><tr></table>";
    }
    $displaylibelle = 'groupes ';
    if (ENABLE_ROLE_CIAM)
    {
        $displaylibelle .= 'et rôles';
    }
    $displaylibelle = trim($displaylibelle);

?>

    <!-- Fenètre modale -->
    <div id="divmodal" class="divmodal">
        <div class="questiondialog divmodalcontent" > 
            <div class="divmodalheader">
                <h2>
<?php
                $typearray = array('question','suppression','ajout', 'error', 'info');
                foreach ($typearray as $type)
                {
                    $path = imagepath() . "/" . $type . "_logo.png";
                    if (file_exists($path))
                    {
                        list($width, $height, $imagetype) = getimagesize("$path");
                        $typeimage = image_type_to_extension($imagetype,false);
                        if ($typeimage===false) // Si on n'a pas pu déterminé le type d'image => On récupère l'extension du fichier
                        {
                            error_log(basename(__FILE__) . " " . stripAccents("imagetype = $imagetype => extension non définie"));
                            $typeimage = pathinfo($path, PATHINFO_EXTENSION);
                        }
                        $data = file_get_contents($path);
                        $base64 = 'data:image/' . $typeimage . ';base64,' . base64_encode($data);
                        echo "<img class='img". $type ." imagedialog' id='img". $type ."' name='img". $type ."' src='" . chunk_split($base64) . "' hidden>" . PHP_EOL;
                    }
                }
                echo "&nbsp;";
?>
                <label id='labelmodalheader' name='labelmodalheader' >Modal Header</label>
                </h2>
            </div>

            <p>
                <label id='questionlabeltext' >Question label text.</label>
            </p>
            <menu class='nopadding centeraligntext'>
                <button id="questionconfirmBtn" value="" class='sifacbouton sifacvalidebouton'>Ok</button>  <!-- javaconfirmbutton -->
                <button id="questioncancelBtn" value="cancel" class='sifacbouton sifacannulerbouton'>Annuler</button> <!-- javacancelbutton -->
            </menu>
        </div>
    </div>

    <script>

        // Récupération des objets de la fenêtre modale
        var divModal = document.querySelector('#divmodal'); 
        var divModalConfirmBtn = divModal.querySelector('#questionconfirmBtn');
        var divModalLabelText = divModal.querySelector('#questionlabeltext');
        var divModalCancelBtn = divModal.querySelector('#questioncancelBtn'); 
        var labelModalHeader = divModal.querySelector('#labelmodalheader');
        var imgModalList = divModal.querySelectorAll(".imagedialog")

        // Bouton "Annuler"/"Non" de la fenêtre modale : la ferme et nettoie les
        // attributs sifac-* posés sur les boutons par askConfirmBeforeSave.
        divModalCancelBtn.onclick = function() 
        {
            divModal.style.display = "none";
            removeSifacAttr(divModalConfirmBtn);
            removeSifacAttr(divModalCancelBtn);
            hideModalImage();

            return false;
        }

        // Bouton "Ok"/"Oui" de la fenêtre modale : la ferme, puis appelle la
        // fonction de callback désignée par ATTR_CALLBACK_FCT (si présente) en
        // lui passant l'élément ayant déclenché l'action initiale.
        divModalConfirmBtn.onclick = function()
        {
            divModal.style.display = "none";

            let activeElementId = divModalConfirmBtn.getAttribute('<?php echo ATTR_ACTIVE_ELEMENT ?>');
            let submitButton = document.querySelector('#' + activeElementId); 
            let callback = divModalConfirmBtn.getAttribute('<?php echo ATTR_CALLBACK_FCT ?>');
            removeSifacAttr(divModalConfirmBtn);
            removeSifacAttr(divModalCancelBtn);
            if (callback)
            {
                // On appelle la fonction de callback !
                window[callback](submitButton);
            }
        }

        /**
         * Callback 'select' du widget d'autocomplétion jQuery UI (mode
         * LDAP_MODE_WSGROUPS, voir identites.php / autocompleteUser.js).
         *
         * Appelée lorsque l'utilisateur sélectionne une personne dans les
         * suggestions : vide les données de la page précédente, ajoute un
         * champ caché 'ciamaction' valant LOAD_USER_RESOURCE, renseigne le
         * champ '#agent' avec le nom affiché de la personne choisie, puis
         * soumet le formulaire.
         *
         * @this {HTMLElement} L'élément déclenchant l'autocomplétion (le champ '#agent').
         * @param {Event} event Événement jQuery UI (non utilisé).
         * @param {{item: {displayName: string}}} ui Objet jQuery UI décrivant
         *                                            l'élément sélectionné.
         * @returns {boolean} Toujours false (empêche le comportement par défaut
         *                     du widget, le formulaire étant déjà soumis manuellement).
         */
        function selectItem(event, ui) 
        {
            clearPageData();

            let closestForm = this.closest('form');
            let inputAction = document.createElement('input');
            // inputAction.type = 'text';
            inputAction.type = 'hidden';
            inputAction.name = 'ciamaction';
            inputAction.id = inputAction.name;
            inputAction.value = '<?php echo LOAD_USER_RESOURCE; ?>';
            console.log(closestForm);
            closestForm.appendChild(inputAction);

            let form = $(this).closest("form");
            form.find('#agent').val(ui.item.displayName);
            form.submit();
            return false;
        };

        /**
         * Met à jour la cellule "description" d'une ligne de rôle SIFAC en
         * fonction de l'option actuellement sélectionnée dans son <select>.
         *
         * Utilisée comme gestionnaire de l'événement select2 'select2:select'
         * (voir addEmptyRoleLine) ; s'appuie sur document.activeElement pour
         * retrouver l'élément concerné plutôt que sur un paramètre. Rend
         * également modifiables (readOnly = false) les champs de date de
         * début/fin dès qu'un rôle est sélectionné (valeur non vide).
         *
         * @returns {void}
         */
        function modifyDisplay()
        {
            let activeElement;
            if (document.activeElement.tagName.toLowerCase() != 'select')
            {
                activeElement = document.activeElement.closest('tr').querySelector('select');
                let select2Display = document.activeElement.querySelector('.select2-selection__rendered');
                if (activeElement.value != "")
                {
                    select2Display.innerHTML = activeElement.value;
                }
            }
            else
            {
                activeElement = document.activeElement;
            }
            let selectedItem = activeElement.options[activeElement.selectedIndex];
            let currentTableRow = activeElement.closest("tr");
            let descrInput = currentTableRow.cells[1].querySelector('input');
            console.log(currentTableRow.cells[1]);
            console.log(descrInput);
            if (descrInput)
            {
                descrInput.value = selectedItem.dataset.description;
            }
            else
            {
                currentTableRow.cells[1].innerHTML =  selectedItem.dataset.description;
            }
            if (selectedItem.value != "")
            {
                currentTableRow.cells[2].querySelector("input").readOnly = false;
                currentTableRow.cells[3].querySelector("input").readOnly = false;
            }
        }

        /**
         * Ajoute une nouvelle ligne vide dans le tableau des rôles SIFAC d'un
         * utilisateur, permettant de lui affecter un rôle supplémentaire.
         *
         * La ligne créée contient : un <select> des rôles disponibles
         * (roleList), un champ description en lecture seule (rempli via
         * modifyDisplay au moment de la sélection), deux champs de date
         * (masqués tant qu'aucun rôle n'est choisi) avec calendrier jQuery UI
         * associé, et un bouton "Annuler" supprimant la ligne. Un index
         * aléatoire (non déjà présent dans le tableau) est généré pour
         * identifier la ligne et ses champs de façon unique.
         *
         * @param {HTMLElement} button Bouton "Ajouter un rôle" ayant déclenché
         *                              l'appel ; son parentElement doit contenir
         *                              le tableau des rôles, et son attribut
         *                              'tag' doit désigner (par id) la source
         *                              de roleList.
         * @param {Object<string,string>} roleList Rôles disponibles, sous la
         *                                          forme { nomDuRole: description }.
         * @returns {void}
         */
        function addEmptyRoleLine(button, roleList)
        {
            let listeRoleUtilisateur = button.parentElement.querySelectorAll("table")[0];
            let tbody = listeRoleUtilisateur.querySelector("tbody");

            // On détermine un index aléatoire et on vérifie qu'il n'est pas déjà présent dans le tableau des rôles SIFAC => Test sur les tr
            let indexOk = false;
            let newIndex = 0;
            while(!indexOk)
            {
                newIndex = Math.floor(Math.random() * 1000000000);
                let searchNode = "tr[<?php echo ATTR_INDEX; ?>='" + newIndex + "']"; 
                //let searchNode = "td.<?php echo SIFACFINVALIDITECELLULE; ?> input[<?php echo ATTR_INDEX; ?>='" + newIndex + "']"
                let testElement = tbody.querySelector(searchNode);
                if (!testElement)
                {
                    indexOk = true;
                }
            }

            let listeRole = document.querySelector('#'+button.getAttribute("tag")); 
            let ligne = tbody.insertRow();
            ligne.classList.add("lignerole","nouvelleligne");
            ligne.setAttribute("<?php echo ATTR_INDEX; ?>", newIndex);

            // Cellule avec le select/option
            let cellule = ligne.insertCell();
            cellule.classList.add('cellulesimple','<?php echo SIFACNOMROLECELLULE; ?>');
            let newSelect = document.createElement("select");
            var newOption = document.createElement("option");
            newOption.text = "Veuillez selectionner un rôle";
            newOption.value = "";
            newOption.dataset.description = "";
            newSelect.add(newOption);

            for (let index in roleList)
            {
                var newOption = document.createElement("option");
                newOption.text = index; 
                newOption.value = index;
                newOption.title = roleList[index];
                newOption.dataset.description = roleList[index];
                newSelect.add(newOption);
            }
            cellule.appendChild(newSelect);
            cellule.classList.add('cellulesimple');
            // On modifie la taille du select en ajoutant des pixels => Cela va élargir la cellule contenant => Elargir la colonne courante
            $(newSelect).width(Math.round($(newSelect).width()) + 30);
            $(newSelect).select2();
            newSelect.id = 'sifacrolename[' + newIndex + ']';
            newSelect.name = newSelect.id;
            $(newSelect).on('select2:select', modifyDisplay);
            // setTimeout(selectToSetFocus,1) => Le timeout 1 permet de laisser le temps de fermer un autre select2 ouvert
            // avant de donner le focus sur la zone de selection
            $(newSelect).on('select2:open', function () { setTimeout(selectToSetFocus,1) });

            // Cellule avec la description => vide
            cellule = ligne.insertCell();
            cellule.classList.add('cellulesimple','<?php echo SIFACDESCRIPTIONROLECELLULE; ?>');
            var input = document.createElement("input");
            input.type = "text";
            input.classList.add("noborder", "width300");
            input.id = "sifacdescriptionrole[" + newIndex + "]";
            input.size = 30;
            input.readOnly = true;
            input.tabIndex = -1;
            input.name = input.id;
            input.value = '';
            input.setAttribute('<?php echo ATTR_INDEX; ?>', newIndex )
            cellule.appendChild(input);
            // cellule.innerHTML = "";

            // Cellule avec la date de début
            cellule = ligne.insertCell();
            cellule.classList.add('cellulesimple','<?php echo SIFACDEBUTVALIDITECELLULE; ?>', 'hidden');
            cellule.innerHTML = "";
            var input = document.createElement("input");
            // var calendrierId = "calendrierdebut[" + tbody.querySelectorAll('tr').length + "]";
            var calendrierId = "calendrierdebut[" + newIndex + "]";
            input.type = "text";
            input.classList.add("calendrier");
            input.id = calendrierId;
            input.name = calendrierId;
            input.size = 10;
            input.readOnly = true;
            input.value = '';
            input.setAttribute('<?php echo ATTR_INDEX; ?>', newIndex )
            cellule.appendChild(input);
            $(input).on("change",function () { updateTimeElement(tbody.querySelector("td.<?php echo SIFACDEBUTVALIDITECELLULE; ?> input[<?php echo ATTR_INDEX; ?>='" + newIndex + "']")); });
            var timeElement = document.createElement('time');
            timeElement.setAttribute('datetime','');
            cellule.appendChild(timeElement);
            $(".calendrier").datepicker({});

            // Cellule avec la date de fin
            cellule = ligne.insertCell();
            cellule.classList.add('cellulesimple','<?php echo SIFACFINVALIDITECELLULE; ?>', 'hidden');
            cellule.innerHTML = "";
            var input = document.createElement("input");
            // var calendrierId = "calendrierfin[" + tbody.querySelectorAll('tr').length + "]";
            var calendrierId = "calendrierfin[" + newIndex + "]";
            input.type = "text";
            input.classList.add("calendrier");
            input.id = calendrierId;
            input.name = calendrierId;
            input.size = 10;
            input.readOnly = true;
            input.value = '';
            input.setAttribute('<?php echo ATTR_INDEX; ?>', newIndex )
            cellule.appendChild(input);
            $(input).on("change",function () { updateTimeElement(tbody.querySelector("td.<?php echo SIFACFINVALIDITECELLULE; ?> input[<?php echo ATTR_INDEX; ?>='" + newIndex + "']")); });
            var timeElement = document.createElement('time');
            timeElement.setAttribute('datetime','');
            cellule.appendChild(timeElement);
            $(".calendrier").datepicker({});

            // Cellule avec le boutton "Annuler"
            cellule = ligne.insertCell();
            cellule.classList.add('cellulesimple','centeraligntext');
            var button = document.createElement("button");
            button.innerHTML = "Annuler";
            button.type = 'button';
            button.classList.add("sifacbouton","sifacannulerbouton");
            $(button).on('click', function() { let table = document.activeElement.closest('table'); table.deleteRow(document.activeElement.closest('tr').rowIndex); })
            cellule.appendChild(button);
        }

        /**
         * Affiche une fenêtre modale de confirmation (Oui/Non) avant d'exécuter
         * une action, et positionne le callback à appeler en cas de validation.
         *
         * Cas particulier : si l'action est DELETE_USER_RESOURCE et que
         * l'identité affichée correspond à l'utilisateur CAS actuellement
         * connecté (comparaison insensible à la casse sur le login LDAP),
         * affiche un message d'erreur bloquant à la place de la confirmation,
         * pour empêcher un agent de supprimer sa propre identité CIAM.
         *
         * @param {HTMLElement} htmlElement Élément à l'origine de la demande ;
         *                       ses attributs personnalisés (libellé, action,
         *                       en-tête de la modale) pilotent le contenu affiché.
         * @param {string} callback Nom de la fonction globale à appeler si
         *                          l'utilisateur confirme (Oui).
         * @returns {void}
         */
        function askConfirmBeforeSave(htmlElement, callback)
        {
            let elementid = htmlElement.id;    
            let libelle =  htmlElement.getAttribute('<?php echo ATTR_LIBELLE; ?>');
            let action = htmlElement.getAttribute('<?php echo ATTR_ACTION; ?>');
            let headerText = htmlElement.getAttribute('<?php echo ATTR_MODALHEADER; ?>');

            if (action == '<?php echo DELETE_USER_RESOURCE; ?>')
            {
                let tableLdapInfoUser = document.querySelector("#tableldapinfouser");
                if (tableLdapInfoUser)
                {
                    let ldapUidCellule = tableLdapInfoUser.querySelector('.<?php echo LDAPUIDCELLULE ?>');
                    if (ldapUidCellule)
                    {
                        let tr = $(ldapUidCellule).closest('tr');
                        if (getCellValue(tr,$(ldapUidCellule).index()).trim().toLowerCase() == '<?php echo $casUid; ?>'.trim().toLowerCase())
                        {
                            showModalSingleButton('error','Suppression d\'une identité','Ok','Impossible de supprimer votre propre identité du CIAM.');
                            return;
                        }
                    }
                }
            }
<?php
            if (!cas_user_check_access($casUid, CIAMGROUPACCESS_ADMIN))
            {
?>
            if (action == '<?php echo MODIFY_USER_RESOURCE; ?>')
            {
                let tableLdapInfoUser = document.querySelector("#tableldapinfouser");
                if (tableLdapInfoUser)
                {
                    let ldapUidCellule = tableLdapInfoUser.querySelector('.<?php echo LDAPUIDCELLULE ?>');
                    if (ldapUidCellule)
                    {
                        let tr = $(ldapUidCellule).closest('tr');
                        if (getCellValue(tr,$(ldapUidCellule).index()).trim().toLowerCase() == '<?php echo $casUid; ?>'.trim().toLowerCase())
                        {
                            showModalSingleButton('error','Modification d\'une identité','Ok','Impossible de modifier votre propre identité du CIAM.');
                            return;
                        }
                    }
                }
            }
<?php
            }
?>
            hideModalImage('question');
            
            divModalLabelText.innerHTML = libelle
            labelModalHeader.innerHTML = headerText;
            divModalLabelText.parentElement.classList.add('centeraligntext');
            divModalCancelBtn.textContent = "Non";
            divModalCancelBtn.classList.add("sifacannulerbouton");
            divModalCancelBtn.setAttribute('<?php echo ATTR_ACTIVE_ELEMENT; ?>',elementid);
            divModalCancelBtn.hidden = false;
            divModalConfirmBtn.textContent = "Oui";
            divModalConfirmBtn.classList.add("sifacvalidebouton");
            divModalConfirmBtn.setAttribute('<?php echo ATTR_ACTIVE_ELEMENT; ?>',elementid);
            divModalConfirmBtn.setAttribute('<?php echo ATTR_ACTION; ?>',action);
            divModalConfirmBtn.setAttribute('<?php echo ATTR_CALLBACK_FCT; ?>',callback);
            divModalConfirmBtn.hidden = false;
            divModal.style.display = "block";
        };

        /**
         * Affiche une fenêtre modale à un seul bouton (généralement pour afficher une alerte/une erreur).
         *
         * @param {string} typeModal Liste des types d'affichage de la fenêtre modale ('question','suppression','ajout', 'error', 'info' - voir liste dans la variable PHP $typearray).
         * @param {string} libelleEntete Libellé affiché dans l'entête de la fenêtre modale.
         * @param {string} libelleBouton Libellé affiché dans le bouton de la fenêtre modale.
         * @param {string} description Texte pour expliquer la raison de l'affichage.
         * @param {string} callback Nom de la fonction a appeler après la fermeture de la fenêtre modale.
         * @returns {void}
        */
        function showModalSingleButton(typeModal, libelleEntete, libelleBouton, description, callback)
        {
            hideModalImage(typeModal);
            labelModalHeader.innerHTML = libelleEntete;
            divModalCancelBtn.textContent = libelleBouton;
            divModalCancelBtn.hidden = false;
            divModalCancelBtn.classList.add('sifacokbouton');
            divModalCancelBtn.removeAttribute('<?php echo ATTR_CALLBACK_FCT; ?>');
            if (callback && callback.trim() != '')
            {
                divModalCancelBtn.setAttribute('<?php echo ATTR_CALLBACK_FCT; ?>',callback);
            }
            let elementid = document.activeElement;  
            // Si on vient de cliquer sur un élément du menu, on le ferme avant d'afficher la fenêtre modale
            if ((elementid != null) && (elementid.role != null) && (elementid.role.includes('menuitem')))
            {
                // On recherche l'élément de niveau 2
                let niveau2 = elementid.closest('ul.niveau2');
                if (niveau2)
                {
                    // On cherche le lien (tag "a") qui est frêre du niveau 2 (fils du père)
                    let anchor = niveau2.parentElement.querySelector('a');
                    // Si on le trouve, on clique dessus pour fermer le menu de niveau 2
                    if (anchor)
                    {
                        anchor.click();
                    }
                }
            }
            divModalCancelBtn.setAttribute('<?php echo ATTR_ACTIVE_ELEMENT; ?>',elementid);
            divModalConfirmBtn.hidden = true;
            divModalLabelText.parentElement.classList.add('centeraligntext');
            divModalLabelText.innerHTML = description;
            divModal.style.display = "block";
            divModalCancelBtn.focus();
        }

        /**
         * Soumet le formulaire contenant fromButton, en y ajoutant un champ
         * caché 'ciamaction' dont la valeur est l'attribut ATTR_ACTION du
         * bouton. Ne fait rien si fromButton est absent/falsy.
         *
         * @param {HTMLElement} fromButton Bouton à l'origine du rechargement,
         *                       devant porter l'attribut ATTR_ACTION.
         * @returns {void}
         */
        function reloadPage(fromButton)
        {
            // console.log(fromButton);

            if (fromButton)
            {
                let closestForm = fromButton.closest('form');

                let inputAction = document.createElement('input');
                //inputAction.type = 'text';
                inputAction.type = 'hidden';
                inputAction.name = 'ciamaction';
                inputAction.id = inputAction.name;
                inputAction.value = fromButton.getAttribute('<?php echo ATTR_ACTION; ?>');
                closestForm.appendChild(inputAction);

                closestForm.submit();
            }
        }

        /**
         * Masque toutes les icônes de la fenêtre modale, sauf celle
         * correspondant à exception (ex. 'error', 'question'...), dont le nom
         * d'image est 'img' + exception. Réinitialise aussi les classes CSS
         * d'alignement/de style ajoutées par askConfirmBeforeSave ou
         * showModalSingleButton.
         *
         * @param {string} [exception=""] Suffixe désignant l'icône à laisser
         *                                 visible ; aucune icône n'est
         *                                 affichée si omis.
         * @returns {void}
         */
        function hideModalImage(exception = "")
        {
            for (let indexImg = 0 ; indexImg < imgModalList.length ; indexImg++)
            {
                imgModalList[indexImg].hidden = true;
                if (imgModalList[indexImg].name == 'img' + exception)
                {
                    imgModalList[indexImg].hidden = false;
                }
            }
            divModalLabelText.parentElement.classList.remove('centeraligntext');
            divModalCancelBtn.classList.remove('sifacannulerbouton');
            divModalCancelBtn.classList.remove('sifacokbouton');
            divModalConfirmBtn.classList.remove('sifacvalidebouton');
        }

        /**
         * Supprime tous les attributs HTML dont le nom commence par 'sifac-'
         * sur l'élément donné (utilisé pour nettoyer les boutons de la fenêtre
         * modale entre deux utilisations).
         *
         * @param {HTMLElement} htmlElement Élément dont les attributs 'sifac-*'
         *                                   doivent être supprimés.
         * @returns {void}
         */
        function removeSifacAttr(htmlElement)
        {
            let listeAttr = [];
            let index;
            for (index = 0 ; index < htmlElement.attributes.length ; index++)
            {
                let attr = htmlElement.attributes[index];
                if (attr.name.startsWith('sifac-'))
                {
                    listeAttr.push(attr.name);
                }
            }
            for (index = 0 ; index < listeAttr.length ; index++)
            {
                htmlElement.removeAttribute(listeAttr[index]);
            }
        }

        /**
         * Réinitialise l'affichage de la page avant le chargement d'une
         * nouvelle identité : vide les tableaux d'informations LDAP/CIAM/rôles
         * SIFAC (en retirant leur <tbody>) et décoche toutes les cases de
         * rôles CIAM. Ne fait rien pour les éléments absents de la page.
         *
         * @returns {void}
         */
        function clearPageData()
        {
            let tableLdapInfoUser = document.querySelector("#tableldapinfouser");
            if (tableLdapInfoUser)
            {
                tableLdapInfoUser.querySelectorAll('tbody').forEach((tbody) => tableLdapInfoUser.removeChild(tbody));
            }
            let userRoleTable = document.querySelector("#userroletable");
            if (userRoleTable)
            {
                userRoleTable.querySelectorAll('tbody').forEach((tbody) => userRoleTable.removeChild(tbody));
            }
            let tableCiamInfoUser = document.querySelector("#tableciaminfouser");
            if (tableCiamInfoUser)
            {
                tableCiamInfoUser.querySelectorAll('tbody').forEach((tbody) => tableCiamInfoUser.removeChild(tbody));
            }
            let ciamCheckRoles = document.querySelectorAll('.checkciamrole');
            ciamCheckRoles.forEach((ciamcheck) => ciamcheck.checked = false);
        }
    </script>


<br><br>

<div name="mainmenunew" id="mainmenunew" class="mainmenu">
    <nav aria-label="Menu Principal">
        <ul class="menubar-navigation niveau1" role="menubar" aria-label="Menu Principal">
<!---------------------------------------------------
                  Menu Outils  
---------------------------------------------------->
            <li role="none">
                <a role="menuitem" aria-haspopup="true" aria-expanded="false" href="#outils">
                    OUTILS
                    <svg xmlns="http://www.w3.org/2000/svg" class="down" width="12" height="9" viewBox="0 0 12 9">
                        <polygon points="1 0, 11 0, 6 8"></polygon>
                    </svg>
                </a>
                <ul role="menu" aria-label="outils" class="niveau2">
<?php
                    if (cas_user_check_access($casUid, CIAMGROUPACCESS_MODIFY))
                    {
?>
                    <li role="none" onclick="this.getElementsByTagName('form')[0].submit();">
                        <?php $destpagename = 'identites.php'; ?>
                        <form name='gest_ident' method='post' action="<?php echo "$destpagename"; ?>">
                        </form> 
                        <a role="menuitem" href="<?php echo "$destpagename"; ?>" onclick="this.parentNode.click(); return false;">Gestion des identités</a>
                    </li>
<?php
                    }
                    if (cas_user_check_access($casUid, CIAMGROUPACCESS_CONSULT))
                    {
?>
                    <li role="none" onclick="this.getElementsByTagName('form')[0].submit();">
                        <?php $destpagename = 'listeidentites.php'; ?>
                        <form name='list_ident' method='post' action="<?php echo "$destpagename"; ?>">
                        </form>
                        <a role="menuitem" href="<?php echo "$destpagename"; ?>" onclick="this.parentNode.click(); return false;">Liste des identités</a>
                    </li>
<?php
                    }
                    if (cas_user_check_access($casUid, CIAMGROUPACCESS_CONSULT))
                    {
?>
                    <li role="none" onclick="this.getElementsByTagName('form')[0].submit();">
                        <?php $destpagename = 'listegroupes.php'; ?>
                        <form name='list_group' method='post' action="<?php echo "$destpagename"; ?>">
                        </form>
                        <a role="menuitem" href="<?php echo "$destpagename"; ?>" onclick="this.parentNode.click(); return false;">Liste des <?php echo $displaylibelle ?> CIAM</a>
                    </li>
<?php
                    }
                    if (cas_user_check_access($casUid, CIAMGROUPACCESS_CREATE))
                    {
?>
                    <li role="none" onclick="this.getElementsByTagName('form')[0].submit();">
                        <?php $destpagename = 'creationidentmasse.php'; ?>
                        <form name='create_mass' method='post' action="<?php echo "$destpagename"; ?>">
                        </form>
                        <a role="menuitem" href="<?php echo "$destpagename"; ?>" onclick="this.parentNode.click(); return false;">Créer des identités en masse</a>
                    </li>
<?php
                    }
?>
                </ul>
            </li>
<!---------------------------------------------------
                  Menu Divers
---------------------------------------------------->
            <li role="none">
                <a role="menuitem" aria-haspopup="true" aria-expanded="false" href="#admin">
                    DIVERS
                    <svg xmlns="http://www.w3.org/2000/svg" class="down" width="12" height="9" viewBox="0 0 12 9">
                        <polygon points="1 0, 11 0, 6 8"></polygon>
                    </svg>
                </a>
                <ul role="menu" aria-label="admin" class="niveau2">
<?php
                    if (cas_user_check_access($casUid, CIAMGROUPACCESS_ADMIN))
                    {
?>
                    <li role="none">
                        <a role="menuitem" href="javascript:deleteTempFile();" methode="<?php echo INTERNALWS_DELCIAMTOKEN; ?>">Supprimer le jeton CIAM</a>
                    </li>
                    <li role="none">
                        <a role="menuitem" href="javascript:deleteTempFile();" methode="<?php echo INTERNALWS_DELSIFACTOKEN; ?>">Supprimer le jeton SIFAC</a>
                    </li>
                    <li role="none">
                        <a role="menuitem" href="javascript:deleteTempFile();" methode="<?php echo INTERNALWS_DELROLECACHE; ?>">Supprimer le cache des rôles SIFAC</a>
                    </li>
                    <li role="none" onclick="this.getElementsByTagName('form')[0].submit();">
                        <?php $destpagename = 'gestiongroupesapplicatifs.php'; ?>
                        <form name='manage_custom_ciamgroups' method='post' action="<?php echo "$destpagename"; ?>">
                        </form>
                        <a role="menuitem" href="<?php echo "$destpagename"; ?>" onclick="this.parentNode.click(); return false;">Gestion des groupes applicatifs internes</a>
                    </li>
<?php
                    }
?>
                    <li role="none">
                        <a role="menuitem" href="javascript:showVersion();">A propos de</a>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>
</div>

<script>
    /**
     * Affiche une fenêtre modale d'information avec le nom, la version
     * (SIFAC_OUTILS_VERSION) et l'auteur de l'application.
     *
     * @returns {void}
     */
    function showVersion()
    {
        let menuItem = document.activeElement;
        let versionInfos = 'Bibliothèque sifac-outils<br>';
        versionInfos = versionInfos + "Version : <?php echo SIFAC_OUTILS_VERSION; ?><br>";
        versionInfos = versionInfos + "Auteur : Pascal Comte<br>";
        versionInfos = versionInfos + "Université Paris 1 Panthéon - Sorbonne<br>";
        versionInfos = versionInfos + '<a href="https://github.com/UnivParis1/sifac-outils" target="_blank" rel="noopener">Dépôt Git</a>';
        showModalSingleButton('info',menuItem.innerHTML,'Ok',versionInfos);
    }

    /**
     * Construit l'URL du Web Service interne puis déclenche la suppression d'un
     * fichier temporaire (jeton ou cache).
     *
     * Cette fonction prépare l'appel au Web Service `internalFunctionsWS.php`
     * puis délègue le traitement à {@link callInternalWSForDelete}.
     *
     * @param {HTMLElement} [menuItem]
     *        Élément HTML ayant déclenché l'action. Son attribut `methode`
     *        indique l'opération à exécuter côté Web Service.
     *
     * @returns {void}
     */
    function deleteTempFile(menuItem)
    {
<?php
        // Si la constante n'est pas définie, on l'initialise à chaine vide.
        if (!defined('WS_SERVER_URL'))
        {
            define('WS_SERVER_URL', '');
        }
?>
        let fullWSURL = "<?php echo WS_SERVER_URL ?>/internalFunctionsWS.php";
        callInternalWSForDelete(fullWSURL, menuItem);
    }
</script>

<br><br><br>
