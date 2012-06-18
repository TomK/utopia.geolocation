<?php

class tabledef_GeoCache extends uTableDef {
	public function SetupFields() {
		$this->AddField('geocache_id',ftNUMBER);
		$this->AddField('update',ftTIMESTAMP);
		$this->SetFieldProperty('update','extra','ON UPDATE CURRENT_TIMESTAMP');
		$this->SetFieldProperty('update','default','current_timestamp');
		$this->AddField('request',ftVARCHAR,100);
		$this->AddField('response',ftLONGTEXT);

		$this->SetPrimaryKey('geocache_id');
		$this->SetIndexField('request');
	}
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

	public static function CacheAddress($address,$pos) {
	//	if (!$address || !$pos) return FALSE;
		$cache = self::GetCachedAddress($address,0);
		if (!$cache)
			$res = sql_query("INSERT INTO tabledef_GeoCache (`request`,`response`) VALUES ('".mysql_real_escape_string($address)."','".mysql_real_escape_string(json_encode($pos))."')");
		else
			$res = sql_query("UPDATE tabledef_GeoCache SET `response` = '".mysql_real_escape_string(json_encode($pos))."', `update` = CURRENT_TIMESTAMP WHERE `request` = '".mysql_real_escape_string($address)."'");

		return TRUE;
	}
	public static function GetCachedAddress($address,$expires=3) {
		$expires = $expires && is_numeric($expires) ? ' AND SUBDATE(NOW(), INTERVAL '.$expires.' DAY) < `update`' : '';
		$res = sql_query("SELECT * FROM tabledef_GeoCache WHERE `request` = '".mysql_real_escape_string($address)."'".$expires);
		if (!$res || !mysql_num_rows($res)) return FALSE;
		$row = mysql_fetch_assoc($res);
		return json_decode($row['response'],true);
	}

	public static function GetPos($address,$region=true,$firstOnly = true,$dropPostCode=true) {
		if (is_array($address)) return $address;
		$address = trim($address);
		if (empty($address)) return NULL;
		if ($region === TRUE) {
			$region = self::GeoIP($_SERVER['REMOTE_ADDR']);
			if (!$region) $region = modOpts::GetOption('geolocation_default_region');
		}
		if ($region == $address) $region = '';
		if (!is_string($region)) $region = '';
		else $region = ','.$region;

		$cached = self::GetCachedAddress($address.$region,0); if ($cached !== FALSE) return $cached;
		
		timer_start('GMaps Lookup: '.$address);
		// trim letters from end of postcode

		$r = $region ? '&region='.$region : ''; 

		$out = curl_get_contents('http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address='.urlencode($address).$r);

		$arr = json_decode($out,true);
		if (!$arr) return NULL;

		switch ($arr['status']) {
			case 'OK': break;
			case 'ZERO_RESULTS':
				self::CacheAddress($address.$region,'');
				return FALSE;
			default:
				DebugMail('GMaps request not OK',$address."\n\n".print_r($arr,true));
				return NULL;
				break;
		}
		
		$row = reset($arr['results']);
		if (!$row) return NULL;

		// locality, administrative_area_level_2, administrative_area_level_1
		$newFormattedAddress = array();
		if ($row && isset($row['address_components'])) {
			foreach($row['address_components'] as $c) {
				if (/*!isset($newFormattedAddress[0]) &&*/ array_search('locality',$c['types']) !== FALSE)
					$newFormattedAddress[0] = $c['long_name'];
                                if (/*!isset($newFormattedAddress[1]) &&*/ array_search('administrative_area_level_2',$c['types']) !== FALSE)
                                        $newFormattedAddress[1] = $c['long_name'];
                                if (/*!isset($newFormattedAddress[2]) &&*/ array_search('administrative_area_level_1',$c['types']) !== FALSE)
                                        $newFormattedAddress[2] = $c['long_name'];
			}
		}
		if (count($newFormattedAddress) >= 2) $row['formatted_address'] = implode(', ',$newFormattedAddress);

		$ret = array(
			$row['geometry']['location']['lat'],
			$row['geometry']['location']['lng'],
			isset($row['geometry']['bounds']) ? $row['geometry']['bounds'] : $row['geometry']['viewport'],
			$row['formatted_address'],
			$row
		);
		
		self::CacheAddress($address.$region,$ret);
		self::CacheAddress($ret[3].$region,$ret);
		return $ret;
	}

	public static function CalculateDistance($cPos,$sPos,$unit = 'M') {
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

	public static function GeoIP($ip) {
		$cached = self::GetCachedAddress($ip);
		if ($cached !== FALSE) return $cached;
echo 'nocache ip';
		$region = curl_get_contents('http://api.hostip.info/country.php?ip='.$ip);
		if (strlen($region)>2 || $region == 'XX') $region = '';
		//DebugMail('GeoIP Lookup',$region ? $region : 'Not Found');

		self::CacheAddress($ip,$region);
		return $region;
	}
}
