<?php
header('Content-Type: text/html; charset=utf-8');
?>
<html>
<head>
<title>EPC Test avec SOLR</title>
</head>
<body>
<?php

// Page simple pour tester l'utilisation de Solr pour la collationment de la base 
// de donnee de l'EPC avec celle du denombrement. Pour chaque menage dans le ZD
// qui a ete identifiee dans l'EPC, utiliser Solr pour essayer de trouver le meme
// menage dans la base de denombrement. Affiche un tableau avec les menages de
// l'EPC dans la premiere collone et les menages trouve par Solr dans la
// deuxieme collone.

// Il faut que la base du denombrement soit deja importee dans Solr sous la
// collection "epc" et accesssible sur http://localhost:8983/solr/epc
// Voir solrsetup.md pour les details.

// Chercher un individu dans l'index de Solr a
// partir du nom, prenom, sexe et age
function requetSolr($nom, $prenom, $sexe, $age) {
	
	//  Initialisation de curl
	$curlHandle = curl_init();

	// Definir l'url
	$url = "http://localhost:8983/solr/epc/query?q=";
	
	// Les ^ apres chaque termes multiplies les scores des termes individuelles
	// Le nom et prenom ont des valeurs inferieur car le score de base du texte
	// et proportionelle a la longeur moyenne du champ mais pour les champs
	// numerique dont age et sexe le score de base sera 1 s'il est trouve.
	// On pourra, par exemple, avoir un score de base pour le nom ou prenom
	// de 10 ou 15.
	
	if ($nom)
		$url .= 'nom:' . urlencode($nom) . '^2';
	
	if ($prenom)
		$url .= '%20prenom:' . urlencode($prenom) . '^1';
		
	$url .= '%20sexe:' . $sexe . '^5';

	// Chercher age +/- 5 ans
	$url .= '%20age:[' . ($age - 5) . '%20TO%20' . ($age + 5) .']^5';
	
	// Preferer le chef du menage
	$url .= '%20lien:10^5';
	
	// Dans le resultats on ne veut que numero de structure, numero de menage,
	// numero de person et le score y attribuer par Solr
	$url .= '&fl=nrStr,nrMen,nrPers,score';
	
	curl_setopt($curlHandle, CURLOPT_URL, $url);
	
	// Retour de la reponse (ne pas l'afficher)
	curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);

	// Lancer la requete
	$reponse = curl_exec($curlHandle);

	// Fermer curl
	curl_close($curlHandle);

	// Convertir du JSON au PHP array
	return json_decode($reponse, true)['response']['docs'];		
}

// Si Solr trouve deux individu dans un meme menage on ne doit pas afficher le menage deux fois.
function enleverMenagesDupliques($menages)
{
	$resultat = array();
	foreach ($menages as $orig) {
		$garder = true;
		foreach ($menages as $dupl) {
			if ($orig === $dupl)
				continue;
			if ($orig['nrStr'] == $dupl['nrStr'] and $orig['nrMen'] == $dupl['nrMen'] and $orig['score'] < $dupl['score'])
				$garder = false;
		}
		if ($garder)
			array_push($resultat, $orig);
	}
	return $resultat;
}

// Cherger les membres du menage de la base MySQL
function chargerMenage($conn, $idReg, $idDep, $idArr, $idZD, $idNrStr, $idNrMen)
{
	$menage = array();
	
	$stmMembres = $conn->prepare('SELECT ID04_vill_dat_ind, ID05_quart_dat_ind, ID06_bloc_dat_ind, ID08_nrStr_dat_ind, ID10_nrMen_dat_ind,
										TRIM(P01_nomPers) AS P01_nomPers, TRIM(P01_prenomPers) AS P01_prenomPers, P02_lien, P03_sexe, P05_age 
								     FROM dat_individu 
								     WHERE ID01_reg_dat_ind=:reg AND ID02_dep_dat_ind=:dep AND ID03_arr_dat_ind=:arr AND 
									 ID07_nrZD_dat_ind=:zd AND ID08_nrStr_dat_ind = :str AND ID10_nrMen_dat_ind = :men
									 ORDER BY P00_nrPers');
	$stmMembres->execute([':reg' => $idReg, ':dep' => $idDep, ':arr' => $idArr, ':zd' => $idZD, ':str' => $idNrStr, ':men' => $idNrMen ]);
	return $stmMembres->fetchAll();	
}

// Afficher les identifiants du menage et le nom, sex et age de tous ces membres.
// Si le score n'est pas null on l'affiche aussi.
// On a l'option aussie de souligner le membre de menage qui comme numero de personne nrPersonSouligner
function afficherMenage($menage, $score, $nrPersonSouligner)
{

	echo $menage[0]['ID04_vill_dat_ind'] . '-' . $menage[0]['ID05_quart_dat_ind'] . '-' . $menage[0]['ID06_bloc_dat_ind'] . '-' 
		. $menage[0]['ID08_nrStr_dat_ind'] . '-' . $menage[0]['ID10_nrMen_dat_ind'];

	if ($score <> null)
		echo sprintf(" (%0.2f)", $score);
	echo '</br>'; 	
	echo '<table>';
	
	for ($i = 0; $i < count($menage); $i++) {
		
		$membre = $menage[$i];
		$style = ($nrPersonSouligner == $i + 1) ? 'background-color: #FFFF00' : '';
		
		echo "<tr style=\"$style\">";
		echo '<td>'.$membre['P01_nomPers'] . ', ' . $membre['P01_prenomPers'] . '</td><td>' . $membre['P03_sexe'] . '</td><td>' . $membre['P05_age'] . '</td>';
		echo '</tr>';
		
	}
	echo '</table>';
}

$idReg = 9;
$idDep = 51;
$idArr = 323;
$idZD = '004';

$connEpc = new PDO("mysql:host=localhost:3306;dbname=bucrepor_epc", "epc", "1041992");
$connEpc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$connDenombr = new PDO("mysql:host=localhost:3306;dbname=bucrepor_epc_denomb", "epc", "1041992");
$connDenombr->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Chercher tous les menages de la base de l'EPC dans le ZD
$stmtMenagesEpc = $connEpc->prepare('SELECT ID04_vill_dat_men, ID05_quart_dat_men, ID06_bloc_dat_men, ID08_nrStr_dat_men, ID09_typ_dat_men, ID10_nrMen_dat_men 
								     FROM bucrepor_epc_denomb.dat_menage
								     WHERE ID01_reg_dat_men=:reg AND ID02_dep_dat_men=:dep AND ID03_arr_dat_men=:arr AND ID07_nrZD_dat_men=:zd
								     ORDER BY ID08_nrStr_dat_men, ID10_nrMen_dat_men');
									 
$stmtMenagesEpc->execute([':reg' => $idReg, ':dep' => $idDep, ':arr' => $idArr, ':zd' => $idZD]);


echo '<table style="border-collapse: collapse;width: 100%;border: 1px solid black;"><tr><th>EPC</th><th>Denombrement</th></tr>';
while ($menageEpc = $stmtMenagesEpc->fetch())
{
	$membresEpc = chargerMenage($connEpc, $idReg, $idDep, $idArr, $idZD, $menageEpc['ID08_nrStr_dat_men'], $menageEpc['ID10_nrMen_dat_men']);
	
	if (count($membresEpc) > 0) {
		
		echo '<tr><td style="border: 1px solid black;vertical-align: top;">';
		
		afficherMenage($membresEpc, null, null);

		echo '</td>';
		echo '<td style="border: 1px solid black;vertical-align: top;">';
		
		// Chercher le chef de menage dans Solr (la base de Solr a ete construit a partir de la base de denombrement)
		$chef = $membresEpc[0];
		$resultatSolr = requetSolr($chef['P01_nomPers'], $chef['P01_prenomPers'], $chef['P03_sexe'], $chef['P05_age']);
		$resultatSolr = enleverMenagesDupliques($resultatSolr);
		
		// Montrer les premiers trois menages trouve par solr
		for ($iMenageSolr = 0; $iMenageSolr < 3 and $iMenageSolr < count($resultatSolr); $iMenageSolr++) {

			$menageSolr = $resultatSolr[$iMenageSolr];
			
			// Chercher tous les membres du menage de la base de denombrement a partir des identifiants trouve par Solr
			$nrStrDenomb = sprintf('%03d', $menageSolr['nrStr']);
			$nrMenDenomb = sprintf('%02d', $menageSolr['nrMen']);
			$membresDenombr = chargerMenage($connDenombr, $idReg, $idDep, $idArr, $idZD, $nrStrDenomb, $nrMenDenomb);

			afficherMenage($membresDenombr, $menageSolr['score'], $menageSolr['nrPers']);
			echo '<br/>';
		}
		echo '</td>';
		
		echo '</tr>';
	}
}
echo '</table>';

?>

</body>
</html>