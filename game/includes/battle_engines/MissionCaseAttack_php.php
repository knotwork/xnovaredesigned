<?php

include_once(ROOT_PATH."includes/battle_engines/padacombat.php");

function battle_log($stringData){
	$myFile = "battle_log.txt";
	$fh = fopen($myFile, 'a') or die("can't open file");
	fwrite($fh, $stringData."\n");
	fclose($fh);
}


function MissionCaseAttack($fleetrow,$log=true){
	global $resource,$reslist,$lang;
	//Well here goes the main part of XNova, fingers crossed that it will work.
	//Get this planet
	$CurrentPlanet = doquery("SELECT * FROM {{table}} WHERE `id` = ".$fleetrow['target_id'],'planets',true);
	//Log
	if($log){
		battle_log("Attack - ".date('H:i:s').".\nFleetRow:\n".print_r($fleetrow,true)."\nCurrent planet:".print_r($CurrentPlanet,true));
	}
	//Get all fleets
	$CurrentSet = array();
	$CurrentTechno = array();
	//This fleet
	$CurrentTechno[$fleetrow['id']] = mysql_fetch_array(doquery("SELECT `".$resource[109]."`,`".$resource[110]."`,`".$resource[111]."` FROM {{table}} WHERE `id` = ".$fleetrow['owner_userid'],'users'),MYSQL_NUM);
	$CurrentSet[$fleetrow['id']] = array();
	$fleetarray = explode(";",$fleetrow['array']);
	foreach($fleetarray as $ships){
		if(strlen($ships) > 5){
			$ships = explode(",",$ships);
			$CurrentSet[$fleetrow['id']][$ships[0]] = $ships[1];
		}
	}
	//ACS?
	if($fleetrow['fleet_group'] > 0){
		//We have some acs fleets, maybe
		$acs = doquery("SELECT * FROM {{table}} WHERE `fleet_group` = '".$fleetrow['fleet_group']."' AND `mission` = 2 AND `fleet_mess` = 0",'fleets');
		while($acsrow = mysql_fetch_assoc($acs)){
			$CurrentTechno[$acsrow['id']] = mysql_fetch_array(doquery("SELECT `".$resource[109]."`,`".$resource[110]."`,`".$resource[111]."` FROM {{table}} WHERE `id` = ".$acsrow['owner_userid'],'users'),MYSQL_NUM);
			$CurrentSet[$acsrow['id']] = array();
			$fleetarray = explode(";",$acsrow['array']);
			foreach($fleetarray as $ships){
				if(strlen($ships) > 5){
					$ships = explode(",",$ships);
					$CurrentSet[$acsrow['id']][$ships[0]] = $ships[1];
				}
			}
		}
	}
	//Get defenders stuff
	$TargetSet = array();
	$TargetTechno = array();
	foreach($reslist['dbattle'] as $e){ $TargetSet[0][$e] = $CurrentPlanet[$resource[$e]]; }
	foreach($reslist['fleet']   as $e){ $TargetSet[0][$e] = $CurrentPlanet[$resource[$e]]; }
	$TargetTechno[0] = mysql_fetch_array(doquery("SELECT `".$resource[109]."`,`".$resource[110]."`,`".$resource[111]."` FROM {{table}} WHERE `id` = ".$CurrentPlanet['id_owner'],'users'),MYSQL_NUM);
	//ACS?
	//We have some acs fleets, maybe
	$acs = doquery("SELECT * FROM {{table}} WHERE `target_id` = '".$fleetrow['targetid']."' AND `mission` = 5 AND `fleet_mess` = 0 AND `arrival` < '".$fleetrow['arrival']."' AND `arrival`+`hold_time` > '".$fleetrow['arrival']."'",'fleets');
	while($acsrow = mysql_fetch_assoc($acs)){
		$TargetTechno[$acsrow['id']] = mysql_fetch_array(doquery("SELECT `".$resource[109]."`,`".$resource[110]."`,`".$resource[111]."` FROM {{table}} WHERE `id` = ".$acsrow['owner_userid'],'users'),MYSQL_NUM);
		$TargetSet[$acsrow['id']] = array();
		$fleetarray = explode(";",$acsrow['array']);
		foreach($fleetarray as $ships){
			if(strlen($ships) > 5){
				$ships = explode(",",$ships);
				$TargetSet[$acsrow['id']][$ships[0]] = $ships[1];
			}
		}
	}
	//Log
	if($log){
		battle_log("Data given to the battle engine:");
		battle_log("PadaCombatSac(".$CurrentSet.", ".$TargetSet.", ".$CurrentTechno.", ".$TargetTechno.", ".$planeta_atacante.", ".$CurrentPlanet.",  ".$fleetrow['fleet_start_time'].");");
	}
	//Do the battle
	$result = PadaCombatSac($CurrentSet, $TargetSet, $CurrentTechno, $TargetTechno, $planeta_atacante, $CurrentPlanet, $fleetrow['fleet_start_time']);
	//Calculo de la probabilidad de luna...
	$MoonChance = floor(($result['debris']['metal'] + $result['debris']['crystal']) / 100000);
	if($MoonChance > 20){ $MoonChance = 20;	}
	if(mt_rand(1, 100) < $MoonChance){
		//They get a moon!!!
		//Find if there is a moon there already?
		$lunaid = doquery("SELECT `id` FROM {{table}} WHERE `galaxy` = ".$CurrentPlanet['galaxy']." AND `system` = ".$CurrentPlanet['system']." AND `planet` = ".$CurrentPlanet['planet']." AND `planet_type` = 3 ;",'planets',true);
		if(!$lunaid['id']){
			//There is no moon - lets add one
			AddMoon($CurrentPlanet['galaxy'],$CurrentPlanet['system'],$CurrentPlanet['planet'],$MoonChance,'_DEFAULT_',$CurrentPlanet);
			$Mensaje_luna = sprintf ($lang['sys_moonbuilt'], $TargetPlanetName, $CurrentPlanet['galaxy'], $CurrentPlanet['system'], $CurrentPlanet['planet']);
		}		
	}
	$Mes_prob = sprintf($lang['sys_moonproba'], $MoonChance);
	//Ahora viene un tochaco de 140 lineas, que se dive en dos partes principales: la primera intenta añadir los mismos recursos a todas las flotas; la segunda, rellena con los recursos sobrantes las flotas que puede....
	if(!empty($result['attacker']) AND $result['battle_result'] == 'a'){
		foreach ($result['attacker'] as $id => $flota){
			foreach ($flota as $Ship => $Count) {
				$Capacidad_flota	+= $pricelist[$Ship]['capacity'] * $Count;
			}
			//$Capacidad_total += $Capacidad_flota;
			$Capacidad[$id]= $Capacidad_flota;
			unset ($Capacidad_flota);
		}
		//Lo maximo por flota
		$f_totales = count($result[attacker]);
		$total = ($planet_def['metal']+ $planet_def['crystal']+ $planet_def['deuterium'])/2;
		//Losrecursos maximos a mangar...
		$maximo_total = $total/$f_totales;
		$m_metal = $planet_def['metal']/2;
		$m_cristal = $planet_def['crystal']/2;
		$m_deuterio = $planet_def['deuterium']/2;
		//Los recursos a robar por usuario...
		$metal = ($planet_def['metal']/2) / $f_totales;
		$cristal = ($planet_def['crystal']/2) / $f_totales;
		$deuterio = ($planet_def['deuterium']/2) / $f_totales;
		//echo "m_metal = $m_metal , m_cristal= $m_cristal , m_deuterio = $m_deuterio , metal = $metal , cristal = $cristal , deuterio= $deuterio , f_totales = $f_totales , total = $total , capacidad = print_r($Capacidad)";
		if($maximo_total > 0){
			//Creamos una copia del array
			$resultatac = $result[attacker];
			//Ahora hacemos el primer llenado de las flotas, con lo que se pueda (siempre que se respete la mitad de los recursos del planeta, entre el numero de atacantes supervivientes...)
			foreach ($result[attacker] as $id => $flota){
				$resultado[$id]['metal'] = 0;
				$resultado[$id]['cristal'] = 0;
				$resultado[$id]['deuterio'] = 0;
				$maximo = $maximo_total;
				if($Capacidad[$id] > 0){
					//El acero
					if (($metal) > $Capacidad[$id] / 3) {
						$resultado[$id]['metal']   = $Capacidad[$id] / 3;
						$Capacidad[$id]	 -= $resultado[$id]['metal'];
						$m_metal -= $Capacidad[$id] / 3;
					} else {
						$resultado[$id]['metal']   = $metal;
						$Capacidad[$id]	-= $resultado[$id]['metal'];
						$m_metal -= $metal;
					}
					//El silicio (no sicilio... XD)
					if (($cristal) > $Capacidad[$id] / 2) {
						$resultado[$id]['cristal'] = $Capacidad[$id] / 2;
						$Capacidad[$id]	 -= $resultado[$id]['cristal'];
						$m_cristal -= $Capacidad[$id] / 2;
					} else {
						$resultado[$id]['cristal'] = $cristal;
						$Capacidad[$id]	 -= $resultado[$id]['cristal'];
						$m_cristal -= $cristal;
					}
					//El tritio
					if (($deuterio) > $Capacidad[$id]) {
						$resultado[$id]['deuterio']  = $Capacidad[$id];
						$Capacidad[$id]	  -= $resultado[$id]['deuterio'];
						$m_deuterio -= $Capacidad[$id];
					} else {
						$resultado[$id]['deuterio']  = $deuterio;
						$Capacidad[$id]	 -= $resultado[$id]['deuterio'];
						$m_deuterio -= $deuterio;
					}
					//Si queda espacio en la flota se suma este espacio a la capacidad de recursos restantes...Sino se descarta la flota del array
					if($Capacidad[$id] > 0){
						$cap_restante += $Capacidad[$id];
					}else{
						unset ($resultatac[$id]);
					}
										
				}
			}
			//Ahora se hace otra pasada para rellenar las naves restantes con capacidad con lo que queda...
			if(!empty($resultatac)){
				shuffle($resultatac);  //Mezclamos para que sea mas justo para los jugadores....
				foreach ($resultatac as $id => $flota) {
					if($cap_restante == 0){
						break;
					}
					if($Capacidad[$id] >= ($m_metal+ $m_cristal+ $m_deuterio)){
						$resultado[$id]['metal'] += $m_metal;
						$resultado[$id]['cristal'] += $m_cristal;
						$resultado[$id]['deuterio'] += $m_deuterio;
						$cap_restante = 0;
					}else{
						if($m_metal > 0){
							if($m_metal >= $Capacidad[$id]){
								$resultado[$id]['metal'] += $Capacidad[$id];
								$m_metal -= $Capacidad[$id];
							}else{
								$resultado[$id]['metal'] += $m_metal;
								$cap_restante -= $Capacidad[$id];
							}
						}
						if($m_metal >= 0 AND $cap_restante > 0){
							if (($m_metal ) > $Capacidad[$id] / 3) {
								$resultado[$id]['metal']   += $Capacidad[$id] / 3;
								$Capacidad[$id]	 -= $resultado[$id]['metal'];
								$cap_restante -= $resultado[$id]['metal'];
								$m_metal -= $resultado[$id]['metal'];
							} else {
								$resultado[$id]['metal']   += $m_metal;
								$Capacidad[$id]	-= $resultado[$id]['metal'];
								$cap_restante -= $resultado[$id]['metal'];
								$m_metal = 0;
							}
						}	
						if($m_cristal > 0 AND $cap_restante > 0){
							if (($m_cristal) > $Capacidad[$id] / 2) {
								$resultado[$id]['cristal'] += $Capacidad[$id] / 2;
								$Capacidad[$id]	 -= $resultado[$id]['cristal'];
								$cap_restante -= $resultado[$id]['cristal'];
								$m_cristal -= $resultado[$id]['cristal'];
							} else {
								$resultado[$id]['cristal'] += $m_cristal;
								$Capacidad[$id]	 -= $resultado[$id]['cristal'];
								$cap_restante -= $resultado[$id]['cristal'];
								$m_cristal = 0;
							}
						}	
						if($m_deuterio > 0 AND $cap_restante > 0){
							if (($m_deuterio) > $Capacidad[$id]) {
								$resultado[$id]['deuterio']  += $Capacidad[$id];
								$Capacidad[$id]	  -= $resultado[$id]['deuterio'];
								$cap_restante -= $resultado[$id]['deuterio'];
								$m_deuterio -= $resultado[$id]['deuterio'];
							} else {
								$resultado[$id]['deuterio']  += $m_deuterio;
								$Capacidad[$id]	 -= $resultado[$id]['deuterio'];
								$cap_restante -= $resultado[$id]['deuterio'];
								$m_deuterio = 0;
							}
						}
						
					}
				}
			}
		}
		unset($Capacidad, $cap_restante, $resultatac, $m_deuterio, $m_cristal, $m_metal);
	}
	//Si se han destruido las flotas atacantes
	if (empty($result[attacker])){
//		doquery ("DELETE FROM {{table}} WHERE sac_id = '{$fleetrow['sac_id']}' OR  fleet_id = '{$fleetrow['fleet_id']}'", 'fleets');
		doquery ("DELETE FROM {{table}} WHERE fleet_id = '{$fleetrow['fleet_id']}'", 'fleets');
	}else{
		//Vamos una a una comprobando las flotas que se mantienen con vida. Las que no, se borran.
		foreach ($CurrentSet as $id => $flota){
			if (empty($result[attacker][$id])){
				doquery ("DELETE FROM {{table}} WHERE fleet_id = '{$id}' LIMIT 1 ", 'fleets');
			}else{
				$FleetArray = '';
				$FleetAmount = 0 ;
				foreach ($result[attacker][$id] as $Ship => $Count) {
					if($Ship !='' OR $Count !='' ){//$FleetStorage += $pricelist[$Ship]["capacity"] * $Count['count'];
						$FleetArray   .= $Ship.",".$Count.";";
						$FleetAmount  += $Count;
					}
				}
				//Actualizamos la flota para que vuelva a casa con las naves debidas	
				$QryUpdateGalaxy  = "UPDATE {{table}} SET ";
				$QryUpdateGalaxy .= " fleet_array = '{$FleetArray}' , ";
				$QryUpdateGalaxy .= " fleet_amount = '{$FleetAmount}' , ";
				$QryUpdateGalaxy .= " fleet_mess = 1 , ";
				$QryUpdateGalaxy .= " fleet_resource_metal = fleet_resource_metal + '{$resultado[$id]['metal']}', ";
				$QryUpdateGalaxy .= " fleet_resource_crystal = fleet_resource_crystal + '{$resultado[$id]['cristal']}', ";
				$QryUpdateGalaxy .= " fleet_resource_deuterium = fleet_resource_deuterium + '{$resultado[$id]['deuterio']}', ";
				$QryUpdateGalaxy .= " actualizar = fleet_end_time  ";
				$QryUpdateGalaxy .= "WHERE ";
				$QryUpdateGalaxy .= " fleet_id = '{$id}' ";
				$QryUpdateGalaxy .= "LIMIT 1 ";
				doquery( $QryUpdateGalaxy , 'fleets');
			}
		}
	}
	//Guardamos el valor del array del usuario defensor
	$defender_pl = $result[defender][0];
	unset ($result[defender][0]);
	//Si se han destruido las flotas defensoras
	if (empty($result[defender])){
		$Qry   = "DELETE FROM {{table}} ";
		$Qry  .= "WHERE ";
		$Qry  .= " mission = 5 AND ";
		$Qry  .= " target_id = '{$fleetrow['target_id']}' AND ";
		$Qry  .= " target = '{$fleetrow['target']}' AND ";
		$Qry  .= " hold_time > 0 AND fleet_mess = 0";
		doquery ($Qry, 'fleets');
	}else{
		//Vamos una a una comprobando las flotas que se mantienen con vida. Las que no, se borran.
		foreach ($TargetSet as $id => $flota){
			if (empty($result[defender][$id])){
				doquery ("DELETE FROM {{table}} WHERE fleet_id = '{$id}' LIMIT 1 ", 'fleets');
			}else{
				$FleetArray = '';
				$FleetAmount = 0 ;
				foreach ($result[defender][$id] as $Ship => $Count) {
					//Una comprobacion necesaria...
					if($Ship !='' OR $Count !='' ){
						//$FleetStorage += $pricelist[$Ship]["capacity"] * $Count['count'];
						$FleetArray   .= $Ship.",".$Count.";";
						$FleetAmount  += $Count;
					}
				}
				//Actualizamos la flota para que vuelva a casa con las naves debidas	
				$QryUpdateGalaxy  = "UPDATE {{table}} SET ";
				$QryUpdateGalaxy .= " fleet_array = '{$FleetArray}' , ";
				$QryUpdateGalaxy .= " fleet_amount = '{$FleetAmount}' ";
				//$QryUpdateGalaxy .= " fleet_mess = 1 ";
				$QryUpdateGalaxy .= "WHERE ";
				$QryUpdateGalaxy .= " fleet_id = '{$id}' ";
				$QryUpdateGalaxy .= "LIMIT 1 ";
				doquery( $QryUpdateGalaxy , 'fleets');
			}
		
		}
	}
	
	$Message.= $result['report'];
	
	$Message.= '<br><br>La batalla ha durado '.$result['rounds'].' rondas.';
	if($result['battle_result'] == 'a'){
		$Message .= "<br><br><table width=100% ><tr><td><DIV ALIGN=left>".$lang['sys_attacker_won']."<br>";
		foreach ($result[attacker] as $id => $flota){
			$Message .= sprintf($lang['sys_atac_roba'], $CurrentSet[$id]['username'], pretty_number($resultado[$id]['metal']), pretty_number($resultado[$id]['cristal']), pretty_number($resultado[$id]['deuterio']));
		}
	}elseif($result['battle_result'] == 'd'){
		$Message .= $lang['sys_both_won']."<br>";
	}else{
		$Message .= $lang['sys_defender_won']."<br>";
	}
	//El atacante ha perdido x unidades
	$Message .= "<br>".sprintf($lang['sys_attacker_lostunits'], $result['debris']['attacker'])."<br>";
	//El defensor ha perdido x unidades
	$Message .= sprintf($lang['sys_defender_lostunits'], $result['debris']['defender'])."<br>";
	//En estas coordenadas flotan x de acero, x de silicio
	$Message .= sprintf($lang['sys_gcdrunits'], pretty_number($result['debris']['metal']), pretty_number($result['debris']['crystal']))."<br>";
	//Mensajes de la probabilidad de luna
	$Message.= "<br>".$Mes_prob.$Mensaje_luna.sprintf($lang['sys_rapport_build_time'], microtime(true)- $time)."</DIV></td></tr></table>";
	
	//AÑADIMOS EL REPORTE A LA TABLA DE RW
	//Si se ha producido solo una ronda, se pone ese valor como 1 (para lo de que se ha perdido el contacto...)
	($result['rounds'] <= 2 AND $result['battle_result'] == 'v') ? $ver_o_no = 1 : $ver_o_no = 0;
	$rid   = md5($Message);
	$QryInsertRapport  = "INSERT INTO {{table}} SET ";
	$QryInsertRapport .= "`time` = '". time ( ) ."', ";
	$QryInsertRapport .= "`owners` = '".$CurrentPlanet[id_owner]."', ";
	$QryInsertRapport .= "`rid` = '". $rid ."', ";
	$QryInsertRapport .= "`a_zestrzelona` = '".$ver_o_no."', ";
	$QryInsertRapport .= "`raport` = '". addslashes ( $Message ) ."';";
	doquery( $QryInsertRapport , 'rw');

	// Creamos el mensajito coloreado que se manda a los ATACANTES
//	$raport_ini  = "<a href # OnClick=\"f( 'rw.php?raport=". $rid ."', '');\" >";
	$raport_ini  = "<a href=rw.php?raport=". $rid ." target=_new>";
	$raport_ini .= "<center>";
	if	   ($result['battle_result'] == 'a') {
		$raport_ini .= "<font color=\"green\">";
	} elseif ($result['battle_result'] == 'd') {
		$raport_ini .= "<font color=\"orange\">";
	} elseif ($result['battle_result'] == 'v') {
		$raport_ini .= "<font color=\"red\">";
	}
	$raport .= $lang['sys_mess_attack_report'] ." [". $CurrentPlanet['galaxy'] .":". $CurrentPlanet['system'] .":". $CurrentPlanet['planet'] ."] </font></a><br /><br />";
	$raport .= "<font color=\"red\">". $lang['sys_perte_attaquant'] .": ". pretty_number($result['debris']['attacker']) ."</font>";//Perdidas atacante
	$raport .= "<font color=\"green\">   ". $lang['sys_perte_defenseur'] .":". pretty_number($result['debris']['defender']) ."</font><br />" ; //Perdidas defensor
	//Ganancias
	$raport .= $lang['sys_debris'] ." ". $lang['Metal'] .":<font color=\"red\">". pretty_number($result['debris']['metal']) ."</font>   ". $lang['Crystal'] .":<font color=\"#ef51ef\">". pretty_number($result['debris']['crystal']) ."</font><br />";
	
	//Creamos un array en elq ue se asocie el nombre del atacante con el id de las flotas que le pertenecen...
	$check = array();
	foreach ($CurrentSet as $id => $flota){
		$name = $CurrentSet[$id]['id'];
		if(!$check[$name]){
			$check[$name] = array();
		}
		$check[$name] = array_merge($check[$name],array($id) );
	}
	//Luego, para no hacer que el usuario tenga que sumar un poco (es malo para el), miramos a ver si hay flotas del mismo usuario y le ponemos en una sola linea todos los recursos obtenidos
	foreach ($check as $id => $info){
		foreach($check[$id] as $nombre => $idx){
			$metal_fin[$CurrentSet[$id]['id']] += $resultado[$idx]['metal'];
			$cristal_fin[$CurrentSet[$id]['id']] += $resultado[$idx]['cristal'];
			$deuterio_fin[$CurrentSet[$id]['id']] += $resultado[$idx]['deuterio'];
			$a_restar_m += $resultado[$idx]['metal'];
			$a_restar_c += $resultado[$idx]['cristal'];
			$a_restar_d += $resultado[$idx]['deuterio'];
		}
		//Se crea el mensajillo con lo que ha ganado en total
		$raport_fin = $lang['sys_gain'] ." ". $lang['Metal'] .":<font color=\"red\">". pretty_number($metal_fin[$CurrentSet[$id]['id']]) ."</font>   ". $lang['Crystal'] .":<font color=\"#ef51ef\">". pretty_number($cristal_fin[$CurrentSet[$id]['id']]) ."</font>   ". $lang['Deuterium'] .":<font color=\"#f77542\">". pretty_number($deuterio_fin[$CurrentSet[$id]['id']]) ."</font></center><br />";
//		SendSimpleMessage ( $id, '', $fleetrow['fleet_start_time'], 5, $lang['sys_mess_tower'], $lang['sys_mess_fleetback'], $raport_ini.$raport.$raport_fin);
		PM ( $fleetrow['owner_userid'], '', $raport_ini.$raport.$raport_fin, $lang['sys_mess_fleetback'], $lang['sys_mess_tower'], 5);
	}
	
	// Creamos el mensajito coloreado que se manda a los DEFENSORES
//	$raport2  = "<a href # OnClick=\"f( 'rw.php?raport=". $rid ."', '');\" >";
	$raport2  = "<a href=rw.php?raport=". $rid ." target=_new>";
	$raport2 .= "<center>";
	if	   ($result['battle_result'] == 'v') {
		$raport2 .= "<font color=\"green\">";
	} elseif ($result['battle_result'] == 'd') {
		$raport2 .= "<font color=\"orange\">";
	} elseif ($result['battle_result'] == 'a') {
		$raport2 .= "<font color=\"red\">";
	}
	$raport2 .= $lang['sys_mess_attack_report'] ." [". $CurrentPlanet['galaxy'] .":". $CurrentPlanet['system'] .":". $CurrentPlanet['planet'] ."] </font></a><br /><br />";
	//Mandamos un mensajillo a acada defensor
	foreach ($TargetSet as $def_fleet => $info){
//		SendSimpleMessage ( $info['id'], '', $fleetrow['fleet_start_time'], 3, $lang['sys_mess_tower'], $lang['sys_mess_attack_report'], $raport2 );
		PM ( $TargetSet[$def_fleet]['owner_userid'], '', $raport2, $lang['sys_mess_attack_report'], $lang['sys_mess_tower'], 3 );
	}
	//Actualizamos el planeta defensor
	$QryUpdatePlanet = "UPDATE {{table}} SET ";
	$QryUpdatePlanet .= "metal = metal - '{$a_restar_m}', ";
	$QryUpdatePlanet .= "crystal = crystal - '{$a_restar_c}', ";
	$QryUpdatePlanet .= "deuterium = deuterium - '{$a_restar_d}' ";
	if ($TargetSet[0]){
		foreach ($TargetSet[0] as $tipo => $cantidad){
			$QryUpdatePlanet .= ', ';
			if($tipo > 400 AND $tipo < 500){  //Coprobamos si es flota o defensa, para la reconstruccion de defensas
				$total = floor(($TargetSet[0][$tipo] - $defender_pl[$tipo]) * (rand(60, 75)/100) + $defender_pl[$tipo]);
				$QryUpdatePlanet .= $resource[$tipo]. " = '".$total."'  ";
			}else{
				$QryUpdatePlanet .= $resource[$tipo]. " = '".$defender_pl[$tipo]."'  ";
			}
			
		}
	}	
	$QryUpdatePlanet .= " WHERE id = '{$planet_def['id']}' LIMIT 1 ";
	doquery( $QryUpdatePlanet , 'planets');
	//Añadimos los puntos de flotero y las cuentas de ataques...
	if  ($result['battle_result'] == 'a') {
		//GANA EL ATACANTE
		foreach ($CurrentSet as $fleet_id => $info){
			$QryUpdateOfficier = "UPDATE {{table}} SET ";
			$QryUpdateOfficier .= "`xpraid` = xpraid + 2 , ";
			$QryUpdateOfficier .= "`raidswin` = raidswin + 1 , ";
			$QryUpdateOfficier .= "`raids` = raids + 1 ";
			$QryUpdateOfficier .= "WHERE id = '" . $CurrentSet[$fleet_id]['id'] . "' ";
			$QryUpdateOfficier .= "LIMIT 1 ;";
			doquery($QryUpdateOfficier, 'users');
		}
		foreach ($TargetSet as $fleet_id => $info){
			$QryUpdateOfficier = "UPDATE {{table}} SET ";
			if($TargetSet[$fleet_id]['xpraid'] > 0){
				$QryUpdateOfficier .= "`xpraid` = xpraid - 1 , ";
			}
			$QryUpdateOfficier .= "`raidsloose` = raidsloose + 1 , ";
			$QryUpdateOfficier .= "`raids` = raids + 1 ";
			$QryUpdateOfficier .= "WHERE id = '" . $TargetSet[$fleet_id]['id'] . "' ";
			$QryUpdateOfficier .= "LIMIT 1 ;";
			doquery($QryUpdateOfficier, 'users');
		}
	} elseif ($result['battle_result'] == 'v') {
		//GANA DEFENSOR
		foreach ($TargetSet as $fleet_id => $info){
			$QryUpdateOfficier = "UPDATE {{table}} SET ";
			$QryUpdateOfficier .= "`xpraid` = xpraid + 2 , ";
			$QryUpdateOfficier .= "`raidswin` = raidswin + 1 , ";
			$QryUpdateOfficier .= "`raids` = raids + 1 ";
			$QryUpdateOfficier .= "WHERE id = '" . $TargetSet[$fleet_id]['id'] . "' ";
			$QryUpdateOfficier .= "LIMIT 1 ;";
			doquery($QryUpdateOfficier, 'users');
		}
		foreach ($CurrentSet as $fleet_id => $info){
			$QryUpdateOfficier = "UPDATE {{table}} SET ";
			if($CurrentSet[$fleet_id]['xpraid'] > 0){
				$QryUpdateOfficier .= "`xpraid` = xpraid - 1 , ";
			}
			$QryUpdateOfficier .= "`raidsloose` = raidsloose + 1 , ";
			$QryUpdateOfficier .= "`raids` = raids + 1 ";
			$QryUpdateOfficier .= "WHERE id = '" . $CurrentSet[$fleet_id]['id'] . "' ";
			$QryUpdateOfficier .= "LIMIT 1 ;";
			doquery($QryUpdateOfficier, 'users');
		}
	} elseif ($result['battle_result'] == 'd') {
		//EMPATE
		foreach ($TargetSet as $fleet_id => $info){
			$QryUpdateOfficier = "UPDATE {{table}} SET ";
			$QryUpdateOfficier .= "`xpraid` = xpraid + 1 , ";
			$QryUpdateOfficier .= "`raidsdraw` = raidsdraw + 1 , ";
			$QryUpdateOfficier .= "`raids` = raids + 1 ";
			$QryUpdateOfficier .= "WHERE id = '" . $TargetSet[$fleet_id]['id'] . "' ";
			$QryUpdateOfficier .= "LIMIT 1 ;";
			doquery($QryUpdateOfficier, 'users');
		}
		foreach ($CurrentSet as $fleet_id => $info){
			$QryUpdateOfficier = "UPDATE {{table}} SET ";
			$QryUpdateOfficier .= "`xpraid` = xpraid + 1 , ";
			$QryUpdateOfficier .= "`raidsdraw` = raidsdraw + 1 , ";
			$QryUpdateOfficier .= "`raids` = raids + 1 ";
			$QryUpdateOfficier .= "WHERE id = '" . $CurrentSet[$fleet_id]['id'] . "' ";
			$QryUpdateOfficier .= "LIMIT 1 ;";
			doquery($QryUpdateOfficier, 'users');
		}
	}
	
	//Actualizamos la galaxia para añadir escombros
	if(($result['debris']['metal'] + $result['debris']['crystal']) > 0){
		$QryUpdateGalaxy = "UPDATE {{table}} SET ";
		$QryUpdateGalaxy .= "metal = metal+'{$result['debris']['metal']}' , ";
		$QryUpdateGalaxy .= "crystal = crystal+'{$result['debris']['crystal']}' ";
		$QryUpdateGalaxy .= "WHERE galaxy = '{$planet_def['galaxy']}' AND ";
		$QryUpdateGalaxy .= "system = '{$planet_def['system']}' AND ";
		$QryUpdateGalaxy .= "planet = '{$planet_def['planet']}' LIMIT 1 ";
		doquery( $QryUpdateGalaxy , 'galaxy');
	}
	

//	if($fleetrow['fleet_end_time'] <= time()){
//			$Message = sprintf ($lang['atac_return'], GetTargetAdressLink($fleetrow, ''), $fleetrow['fleet_resource_metal'], $fleetrow['fleet_resource_crystal'], $fleetrow['fleet_resource_deuterium'] );
//			$result = RestoreFleetToPlanet ( $fleetrow, true );
//			if($result){
//				SendSimpleMessage ( $fleetrow['fleet_owner'], '', $fleetrow['fleet_end_time'], 5, $lang['sys_mess_tower'], $lang['sys_mess_fleetback'], $Message);
//				PM ( $fleetrow['owner_userid'], '', $Message, $lang['sys_mess_fleetback'], $lang['sys_mess_tower'], 5);
//				doquery("DELETE FROM {{table}} WHERE fleet_id=" . $fleetrow['fleet_id'], 'fleets');
//			}
//	}

	return $result;

}


?>
