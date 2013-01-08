<?php

class tabledef_GeoCache extends uTableDef {
	public function SetupFields() {
		$this->AddField('geocache_id',ftNUMBER);
		$this->AddField('update',ftTIMESTAMP);
		$this->SetFieldProperty('update','extra','ON UPDATE CURRENT_TIMESTAMP');
		$this->SetFieldProperty('update','default','current_timestamp');
		$this->AddField('request',ftVARCHAR,100);
		$this->AddField('lat',ftFLOAT,'10,6');
		$this->AddField('lon',ftFLOAT,'10,6');
		$this->AddField('sw_lat',ftFLOAT,'10,6');
		$this->AddField('sw_lon',ftFLOAT,'10,6');
		$this->AddField('ne_lat',ftFLOAT,'10,6');
		$this->AddField('ne_lon',ftFLOAT,'10,6');

		$this->SetPrimaryKey('geocache_id');
		$this->SetIndexField('request');
	}
	protected $customscript = '
		DROP FUNCTION IF EXISTS CalculateDistance;
		CREATE FUNCTION CalculateDistance (lat1 float, lon1 float, lat2 float, lon2 float, miles bool)
			RETURNS float
			DETERMINISTIC
			COMMENT \'Geolocation distance between two lat/lon points\'
		BEGIN
			DECLARE result float;
			SET result = 3956 * 2 * ASIN(SQRT( POWER(SIN((lat1 -lat2) * pi()/180 / 2), 2) + COS(lat1 * pi()/180) * COS(lat2 * pi()/180) * POWER(SIN((lon1 -lon2) * pi()/180 / 2), 2) ));
			IF miles = true THEN
				SET result = result * 0.621371192;
			END IF;
			RETURN result;
		END';
}

uEvents::AddCallback('InitComplete','GeoLocation::Init');
class GeoLocation {
	public static function Init() {
		modOpts::AddOption('geolocation_default_region','Default Region','GeoLocation',$init='UK',$fieldType=itTEXT,$values=NULL);
	}
	public static function GetThreshold($points,$fallback = 50) {
		if (!$points) return $fallback;
		return max($fallback,self::CalculateDistance($points['southwest'],$points['northeast']));
	}

	public static function CacheAddress($address,$lat,$lon,$sw_lat,$sw_lon,$ne_lat,$ne_lon) {
	//	if (!$address || !$pos) return FALSE;
		$cache = self::GetCachedAddress($address,0);
		if (!$cache)
			$res = database::query('INSERT INTO tabledef_GeoCache (`request`,`lat`,`lon`,`sw_lat`,`sw_lon`,`ne_lat`,`ne_lon`) VALUES (?,?,?,?,?,?,?)',
				array($address, $lat, $lon, $sw_lat, $sw_lon, $ne_lat, $ne_lon));
		else
			$res = database::query('UPDATE tabledef_GeoCache SET `lat` = ?, `lon` = ?, `sw_lat` = ?, `sw_lon` = ?, `ne_lat` = ?, `ne_lon` = ?, `update` = CURRENT_TIMESTAMP WHERE `request` = ?',
				array($lat, $lon, $sw_lat, $sw_lon, $ne_lat, $ne_lon, $address));

		return TRUE;
	}
	public static function GetCachedAddress($address,$expires=3) {
		$expires = $expires && is_numeric($expires) ? ' AND SUBDATE(NOW(), INTERVAL '.$expires.' DAY) < `update`' : '';
		$res = database::query('SELECT `lat`,`lon`,`sw_lat`,`sw_lon`,`ne_lat`,`ne_lon` FROM tabledef_GeoCache WHERE `request` = ?'.$expires,array($address));
		if (!$res || !($row = $res->fetch(PDO::FETCH_NUM))) return FALSE;
		if (!$row || !$row[0] || !$row[1] || !$row[2] || !$row[3] || !$row[4] || !$row[5]) return FALSE; // if any of the items are empty, refresh
		return $row;
	}

	public static function GetPos($address) {
		if (is_array($address)) return $address;
		$address = trim($address);
		if (empty($address)) return NULL;

		$cached = self::GetCachedAddress($address,5); if ($cached !== FALSE) return $cached;
		
		$result = false;
		foreach (self::$callbacks as $callback) {
			try {
				$result = call_user_func_array($callback,array($address));
			} catch (Exception $e) {}
			if ($result) break;
		}
		if (!$result) return $result;
		
		self::CacheAddress($address,$result[0],$result[1],$result[2],$result[3],$result[4],$result[5]);
		return $result;
	}
	
	private static $callbacks = array(array('GeoLocation','getLatLonGoogle'),array('GeoLocation','getLatLonYahoo'));
	public static function RegisterCallback($callback) {
		if (!is_callable($callback)) return FALSE;
		self::$callbacks[] = $callback;
		return TRUE;
	}
	
	public static function getLatLonGoogle($address) {
		//http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address=$address
		$region = modOpts::GetOption('geolocation_default_region');
		if ($region) $region = "&region=$region";
		$out = curl_get_contents('http://maps.googleapis.com/maps/api/geocode/xml?sensor=false'.$region.'&address='.urlencode($address));
		$xml = simplexml_load_string($out);
		if (!$xml || (string)$xml->status !== 'OK') return FALSE;

		return array(
			(float)$xml->result->geometry->location->lat, (float)$xml->result->geometry->location->lng,
			(float)$xml->result->geometry->viewport->southwest->lat, (float)$xml->result->geometry->viewport->southwest->lng,
			(float)$xml->result->geometry->viewport->northeast->lat, (float)$xml->result->geometry->viewport->northeast->lng);
	}
	public static function getLatLonYahoo($address) {
		//http://where.yahooapis.com/geocode?q=1600+Pennsylvania+Avenue,+Washington,+DC&appid=[yourappidhere]
		$region = modOpts::GetOption('geolocation_default_region');
		if ($region) $region = "&locale=$region";
		$out = curl_get_contents('http://where.yahooapis.com/geocode?appid=aa6sMN6k&flags=X'.$region.'&q='.urlencode($address));
		$xml = simplexml_load_string($out);
		if (!$xml || $xml->Found == 0) return FALSE;

		return array(
			(float)$xml->Result->latitude, (float)$xml->Result->longitude,
			(float)$xml->Result->boundingbox->south, (float)$xml->Result->boundingbox->west,
			(float)$xml->Result->boundingbox->north, (float)$xml->Result->boundingbox->east);
	}

	public static $defaultUnit = 'M';
	public static function CalculateDistance($cPos,$sPos,$unit = NULL) {
		if ($unit === NULL) $unit = self::$defaultUnit;
		if (!$cPos || !$sPos) return NULL;
		if (is_string($cPos)) $cPos = GoogleMaps::GetPos($cPos);
		if (is_string($sPos)) $sPos = GoogleMaps::GetPos($sPos);
		list($lat1,$lon1) = array_values($cPos);
		list($lat2,$lon2) = array_values($sPos);

		if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return NULL;

		$theta = $lon1 - $lon2;
		$dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
		$dist = acos($dist);
		$dist = rad2deg($dist);
		$miles = $dist * 60 * 1.1515;

		$unit = strtoupper($unit);
		if ($unit == "K") {
			return ($miles * 1.609344);
		} else if ($unit == "N") {
			return ($miles * 0.8684);
		}

		return $miles;
	}
}