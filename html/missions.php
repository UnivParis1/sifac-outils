<?php
    require_once ("../vendor/autoload.php");
    require_once ("../config/config.php");
    require_once ("../include/fonctions.php");
    // Initialise la variable $casUid à partir du ticket CAS
    require_once ("../include/casconnexion.php");

    global $casUid;

    if (!defined('SQLMODE'))
    {
        define('SQLMODE',SifacSqlMode::QUERY_DIRECT);
    }
    $anneeRef = date('Y');
    if (isset($_GET['anneeref']))
    {
        // if (is_numeric($_GET['anneeref']))
        if (preg_match('/^\d{4}$/',$_GET['anneeref']))
        {
            $anneeRef = $_GET['anneeref'];
        }
    }

    if ($anneeRef > date('Y') or $anneeRef <= (date('Y') - INTERVAL_MISSIONS))
    {
        showMessage(MSGERROR,"Attention : L'année de référence n'est pas valable.<br>L'année de référence est forcée à l'année courante.");
        $anneeRef = date('Y');
    }

?>

<!doctype html>
<html>
<head>
    <meta name="viewport" content="initial-scale=1.0" width=device-width>
    <link rel="stylesheet" href="./css/application.css?dummy=<?php echo rand(1,9999999999); ?>" type="text/css" media="all" />
    <style>
        .missionbody { 
            font-family: Arial,Helvetica,Geneva,sans-serif;
            color: #000000;
            font-weight: normal;
            font-size: 15px;
        }

        .missiontable {
            border-collapse: collapse;
            border-color: #999999;
            border-style: solid;
            border-width: 1px;
        }

        .missionheader {
            border-color: #999999;
            font-family: Arial,Helvetica,Geneva,sans-serif;
            font-size: 10pt;
            font-weight: bold;
            text-align: left;
            background-color: #CCCCCC;
            color: #669966;
            vertical-align: bottom;
        }

        .missiontable th {
            color: #000 !important;
            padding: 0.5em !important;
            border-style: solid;
            border-width: 1px;
        }

        .missiontable td {
            background-color: #fff !important;
            padding: 0.5em !important;
            vertical-align: middle;
            border-style: solid;
            border-width: 1px;
        }

        .missioncell.align_right {
            text-align: right !important;
        }

        .missioncell {
            font-family: Arial,Helvetica,Geneva,sans-serif;
            font-size: 10pt;
            font-weight: normal;
            color: #000000;
            vertical-align: baseline;
            background-color: #e9e8e8;
            border-color: #999999;
            border-collapse: collapse;
        }
    </style>
</head>
<body class='missionbody'>
<form id='submitform' name='submitform' method="GET">
    Vos frais de mission pour 
    <select id='anneeref' name='anneeref' onchange="this.parentNode.submit();">
    <?php
        for ($annee = date('Y') - INTERVAL_MISSIONS + 1; $annee <= date('Y'); $annee++)
        {
            $selected = '';
            if ($annee == $anneeRef)
            {
                $selected = ' selected ';
            }
            echo "<option value='$annee' $selected>$annee</option>";
        }
    ?>
    </select>
</form>

<br>
<?php

    $sifacFunctions = new SifacFunctions();
    $response = $sifacFunctions->getSifacAgentMissions($casUid, $anneeRef, SQLMODE);

    if ($response->status == ResponseStatus::STATUT_OK)
    {
        echo "<table id='missiontable' class='missiontable'>";
        echo "  <thead>";
        echo "      <tr>";
        echo "          <th class='missionheader'>Motif</th>";
        echo "          <th class='missionheader'>Destination</th>";
        echo "          <th class='missionheader'>Montant remboursé</th>";
        echo "          <th class='missionheader'>Mise en paiement le</th>";
        echo "          <th class='missionheader'>Ordre de Mission</th>";
        echo "          <th class='missionheader'>Période</th>";
        echo "      </tr>";
        echo "  </thead>";
        echo "  <tbody>";
        $data = $response->data;
        if (count($data) > 0)
        {
            foreach($data as $mission)
            {
                echo "      <tr>";
                echo "<td class='missioncell'>" . htmlspecialchars($mission->motif) . "</td>";
                echo "<td class='missioncell'>" . htmlspecialchars($mission->destination) . "</td>";
                echo "<td class='missioncell align_right'>" . $mission->paiement . "</td>";
                echo "<td class='missioncell'>" . $mission->datepaiement . "</td>";
                echo "<td class='missioncell'>" . $mission->missionid . "</td>";
                echo "<td class='missioncell'>" . mb_strtolower("du " . datetextuelle($mission->datedebut) . " au " . datetextuelle($mission->datefin)) . "</td>";
                echo "      </tr>";
            }
        }
        else
        {
            echo "      <tr>";
            echo "          <td class='missioncell' colspan='6'>Aucune mission pour l'année $anneeRef</td>";
            echo "      </tr>";
        }
        echo "  </tbody>";
        echo "</table>";
    }
    else
    {
        $error = "Une erreur s'est produite lors de la récupération des missions => " . $response->description;
        showMessage(MSGERROR,$error);
        echo "<br><br>";
        error_log(basename(__FILE__) . stripAccents(" $error"));
    }

?>

</body>
</html>