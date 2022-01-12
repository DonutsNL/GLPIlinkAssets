<?php


$app = new linkAssets();


class linkAssets{

	private $limitTickets 	= 100;
	private $DB 			= null;
	private $assets 		= array();
	private $tickets 		= array();
	private $startTime 		= null;

	public function __construct(){
		$paths = pathinfo($_SERVER['SCRIPT_FILENAME']);
		if(file_exists($paths['dirname']."/classes/db.class.php")){
			include_once($paths['dirname']."/classes/db.class.php");
		}else{
			// Fall back using a relative path
			include_once('./classes/db.class.php');
		}

		// Set some execution properties;
		set_time_limit(0);
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache'); 
		header("Refresh:0");
		echo "Limited at: {$this->limitTickets} tickets \n";
		echo "Refresh is ON so we will keep working while browser is open.\n";


		$this->DB = new db('GLPI_DATABASE', 'DATABASE_HOST', 'DB_USER', 'DB_PASSWORD');
		$this->buildAssets();
		$this->buildTickets();
		$this->startTime = microtime(true);;
		$this->processTickets();
	}

	private function sendBrowserUpdate($ticketCount = 0, $currentCount = 0, $tId = 0, $message = false){
		$sapi 		= php_sapi_name();
		$endTime 	= microtime(true);
		$execTime 	= ($endTime - $this->startTime);
		$execTime 	= round($execTime, 2);
		// Doe niets als we vanuit CLI/CRON gestart zijn
		if($sapi != 'cli'){
			if(strstr($message, 'MATCHED')){
				echo "Evaluated: $currentCount/$ticketCount after: $execTime sec; \t CurrentTicket: $tId; \t MatchFound: $message \n";
			}elseif(strstr($message, 'ERROR')){
				echo "Evaluation ended in ERROR state for ticket: $tId; \n";
			}else{
				echo "Evaluated: $currentCount/$ticketCount after: $execTime sec; \t CurrentTicket: $tId; \n";
			}
			ob_flush();
			flush();
		}
	}

	private function buildAssets(){
		$sql = "SELECT 		glpi_computers.id AS cid,
							glpi_computers.name,
							glpi_computers.is_deleted,
							glpi_ipaddresses.id AS iid,
							glpi_ipaddresses.name AS ip,
     						glpi_ipaddresses.mainitems_id,
							glpi_ipaddresses.mainitemtype
		        FROM 		glpi_computers
		        LEFT JOIN 	glpi_ipaddresses 
				ON 			glpi_computers.id = glpi_ipaddresses.mainitems_id
		        WHERE 		1=1
		        AND 		glpi_computers.is_deleted = '0'";
	
		$itter=0;
		$result = $this->DB->query($sql);
		while($row = $result->fetch_array(MYSQLI_ASSOC)){
		        // Waar halen we onze sleutelwoorden vandaan?
		        $this->assets[$row['cid']]['host']  = $this->cleanRaw($row['name']);
		        $this->assets[$row['cid']]['ip']    = $this->cleanRaw($row['ip']);
		        $this->assets[$row['cid']]['type']  = $this->cleanRaw($row['mainitemtype']);
		        $itter++;
		}
	}

	private function buildTickets(){
		$sql = "SELECT 		glpi_tickets.id AS tid,
               				glpi_tickets.name,
               				glpi_tickets.content
        		FROM   		glpi_tickets
        		LEFT JOIN 	amis_tickets_assets_link 
				ON 			amis_tickets_assets_link.ticket_id = glpi_tickets.id
        		WHERE  		1=1
        		AND    		amis_tickets_assets_link.ticket_id IS NULL
        		AND    		glpi_tickets.is_deleted = '0'
        		LIMIT 		{$this->limitTickets}";


		$result = $this->DB->query($sql);
		
		while($row = $result->fetch_assoc()){
        	$title		= $row['name'];
        	$content 	= $row['content'];
	
	        	//$this->tickets[$row['tid']]['search'] 	=  $this->buildArray($title, $content);;
	        $this->tickets[$row['tid']]['raw'] 	=  $row['content'];
			$this->tickets[$row['tid']]['name']	=  $row['name'];
			$this->tickets[$row['tid']]['ticketid'] =  $row['tid'];
		}
	}

	// Function that itterates over the tickets, does evaluation of the assets
	// updates the amis_link_assets table and pushes an update to the browser;
	private function processTickets(){
		$ticketCount = count($this->tickets);
		$currentCount = 1;
		foreach($this->tickets as $ticketId => $properties){
			// Merge the content that we want to evaluate;
			$content = $properties['name'] ."||". $properties['raw'];
			// Do the evaluation.
			if($return = $this->doCompare($content)){
				foreach($return as $assetId => $details){

					if(array_key_exists('host', $details) && array_key_exists('ip', $details)){
						$hit = 'both';
					}elseif(array_key_exists('host', $details)){
						$hit = 'host';
					}elseif(array_key_exists('ip', $details)){
						$hit = 'ip';
					}else{
						$hit = 'none';
					}

					if($this->linkAssetToTicket($ticketId, $assetId, $hit)){
						$host = (array_key_exists('host', $details)) ? $details['host'] : $details['ip'];
						$message = "MATCHED assetId: $assetId => $host";
					}else{
						$message = "ERROR - skipped processing";
					}
				}
			}else{
				$this->updateLinkAssetsNoMatch($ticketId);
				$message = "NOMATCH";
			}
			
			$this->sendBrowserUpdate($ticketCount, $currentCount, $ticketId, $message);
			$currentCount++;
		}
	}

	// This is the heart of the script it does the actual comparison.
	private function doCompare($content){
		$match 	= false;
		$hits 	= false;

		// Laat gefilterde content zien ter beoordeling.
		// echo "<pre>".$this->cleanRaw($content);
		foreach($this->assets as $id => $values){
			$matches	= false;
			$ip 		= str_replace('.','\.',$this->cleanRaw($values['ip']));
			$host		= $this->cleanRaw($values['host']);
			$type 		= $values['type'];

			$pattern = "/(?<host> $host )/";
			// Sometimes ackwardly formatted hostnames are interpreted as modifiers.
			// Just @ ignore these and continue.
			@preg_match($pattern, $this->cleanRaw($content), $matches);
			if(is_array($matches)){
				if(array_key_exists('host', $matches)){
					$match = true;
					if(is_array($hits)){
						if(!array_key_exists($id, $hits)){
							$hits[$id] = array ('id'		=>  $id,
												'host'  	=>  $matches['host'],
												'type'	 	=>  $type);
						}else{
							$hits[$id]['host'] = $matches['host'];
						}
					}else{
						$hits[$id] = array ('id'		=>  $id,
											'host'  	=>  $matches['host'],
											'type'	 	=>  $type);
					}
				}
			}
			// do hostname only parse (hostname).domein.tld;
			if(strstr($host, '.')){
				$hostParts = explode('.', $host);
				$host = (array_key_exists('0', $hostParts)) ? $hostParts['0'] : false;
				if($host){
					$pattern = "/(?<host> $host )/";
					// Sometimes ackwardly formatted hostnames are interpreted as modifiers.
					// Just @ ignore these and continue.
					@preg_match($pattern, $this->cleanRaw($content), $matches);
					if(is_array($matches)){
						if(array_key_exists('host', $matches)){
							$match = true;
							if(is_array($hits)){
								if(!array_key_exists($id, $hits)){
									$hits[$id] = array ('id'		=>  $id,
														'host'  	=>  $matches['host'],
														'type'	 	=>  $type);
								}else{
									$hits[$id]['host'] = $matches['host'];
								}
							}else{
								$hits[$id] = array ('id'		=>  $id,
													'host'  	=>  $matches['host'],
													'type'	 	=>  $type);
							}
						}
					}
				}
			}

			// do ip parse
			if(strlen($ip) >= 5){
				$pattern = "/(?<ip> $ip )/";
				// Sometimes ackwardly formatted hostnames are interpreted as modifiers.
				// Just @ ignore these and continue.
				@preg_match($pattern, $this->cleanRaw($content), $matches);
				if(is_array($matches)){
					if(array_key_exists('ip', $matches)){
						$match = true;
						if(is_array($hits)){
							if(!array_key_exists($id, $hits)){
								$hits[$id] = array ('id'		=>  $id,
													'ip'	 	=>  $matches['ip'],
													'type'	 	=>  $type);
							}else{
								$hits[$id]['ip'] = $matches['ip'];
							}
						}else{
							$hits[$id] = array ('id'		=>  $id,
												'ip'	 	=>  $matches['ip'],
												'type'	 	=>  $type);
						}
					}
				}
			}			
		}
		return ($match) ? $hits : false;
	}


	// Update asset Link table with no match fact
	// This will prevent the re-eval of a ticket over and over again.
	private function updateLinkAssetsNoMatch($ticketId, $reason = 'none'){
		if(is_numeric($ticketId)){
			// Check if ticketId is allready registered;
			$sql = "SELECT 	ticket_id 
					FROM 	amis_tickets_assets_link 
					WHERE 	1=1
					AND		ticket_id  = '$ticketId'";
			$result = $this->DB->query($sql);
			if($result->num_rows > 0){
				// do nothing asset allready linked :)
			}else{
				// Perform the actual link and update link table.
				$sql = "INSERT INTO amis_tickets_assets_link(ticket_id, computer_id, hit, hittype, keyword) 
						VALUES('$ticketId',0,0,'$reason','none')";
				if($result = $this->DB->query($sql)){
					return true;
				}else{
					return false;
				}
			}
		}
	}

	private function linkAssetToTicket($ticketId, $assetId, $hit){
		// Check is asset is allready linked to ticket;
		$sql = "SELECT 	id 
				FROM 	glpi_items_tickets 
				WHERE 	1=1
				AND		items_id   = '$assetId'
				AND		tickets_id  = '$ticketId'";
		$result = $this->DB->query($sql);
		if($result->num_rows >= 1){
			// GRRR
			// Error: Duplicate entry 'Computer-1297-280895' for key 'glpi_items_tickets.unicity' 
			// Should not occur because of this check!
			// But is does :(
			$this->updateLinkAssetsNoMatch($ticketId, $reason = 'allready linked');
		}else{
			// Perform the actual link and update link table.
			if($this->DB->beginTransaction()){
				$sql = array();
				$sql[] = "INSERT INTO glpi_items_tickets(itemtype, items_id, tickets_id) 
							VALUES('Computer','$assetId','$ticketId')";

				$sql[] = "INSERT INTO amis_tickets_assets_link(ticket_id, computer_id, hit, hittype, keyword) 
							VALUES('$ticketId','$assetId',1,'$hit','{$this->assets[$assetId]['host']}')";
				
				foreach($sql as $key => $query){
					if(!$result = $this->DB->query($query)){
						$this->DB->rollback();
						//echo "Insert: $query failed?!";
						return false;
					}
				}

				if($this->DB->commit()){
					return true;
				}else{
					$this->DB->rollback();
					return false;
				}
			}else{
				return false;
			}
		}
		return true;
	}

	private function cleanRaw($str){
		$str = str_replace("\n", ' ',$str);
		$str = str_replace("\r", ' ',$str);
		$str = str_replace("\n\r", ' ',$str);
		$str = str_replace("\r\n", ' ',$str);
		$str = str_replace("&lt", ' ',$str);
		$str = str_replace("#", ' ',$str);
		$str = str_replace(";", ' ',$str);
		$str = str_replace("&gt", ' ',$str);
		$str = str_replace("(", '',$str);
		$str = str_replace(")", ' ',$str);
		$str = str_replace("=", ' ', $str);
		$str = str_replace(":", ' ', $str);
		$str = str_replace(",", ' ', $str);
		$str = str_replace("  ", ' ', $str);
		$str =  strtolower($str);
		$str =  trim($str);
		return $str;
	}

	private function buildArray($a, $b){
    		if(!is_array($a)){$a = explode(' ', $a);}
    		if(!is_array($b)){$b = explode(' ', $b);}
    		$work = array_merge($a, $b);
    		$work = array_unique($work, SORT_STRING);
    		return $work;
	}
}
