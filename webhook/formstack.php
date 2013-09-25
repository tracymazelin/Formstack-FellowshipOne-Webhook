<?php

/**
 * Formstack Class
 * @requires FellowshipOne Class
 * @author Tracy Mazelin
 * @copyright Tracy Mazelin
 * A formstack webhook to be used to insert form submission data directly into Fellowship One.
 */

namespace webhook;
use \DateTime;

require_once('fellowshipone.php');

class Formstack extends FellowshipOne{

	public static $f1;
	public $person = array();
	public $payment = array();
	public $timestamp;
	public $id = array();
	protected $modelsPath = "cache/models/";

	const ADDR_DIS = 4;
	const NAME_DIS = 3;


	/**
	 * contructor - instantiate f1, set date object.
	 * @return void
	 */
	public function __construct(){
		self::$f1 = parent::forge();
		$date = new DateTime('now');
		$this->timestamp = $date->format(DATE_ATOM);
		
		/* 
		  Debuging
		  $_POST['Name'] = "first = Jimmy\nlast = Test";
		  $_POST['Address'] = "address = 123 Main St\ncity = Kansas City\nstate = MO\nzip = 11111";
		  $_POST['Email'] = "test@test.com";
		  $_POST['Payment_Amount'] = "50";
		  $_POST['HandshakeKey'] = "UseStringSetupInFormstack";
		  echo "<pre>";		 
		 */

  	}

	/**
	 * Check Valid Handshake Key
	 * @return boolean 
	 */
	public function verifyHandshake(){
		if (!isset($_POST['HandshakeKey']) || $_POST['HandshakeKey'] !== "cbcattxwebhook"){
			error_log($this->timestamp.": Handshake failed. \n", 3, "logs/error.log");	
			header('HTTP/1.0 403 Forbidden');
  			exit();
		}
		return true;
	}

	/**
	 * Name and address input
	 * @param  string $type input type
	 * @return array
	 */
	public function splitInput($type){
		if (isset($_POST[$type])){
		$_POST[$type] = filter_var($_POST[$type], FILTER_SANITIZE_STRING);
		$parts=explode("\n",$_POST[$type]);
	
		$input = array();
			foreach($parts as $line) {
			list($key, $value) = explode('=', $line, 2);
			$input[trim($key)] = trim($value);
			}
		} return $input;
	}

	/**
	 * Get and assign Formstack values
	 * @return void
	 */
	public function setInput(){
		$name = $this->splitInput("Name");
		$address = $this->splitInput("Address");
		$this->person['firstname'] = ucfirst($name['first']);
		$this->person['lastname'] = ucfirst($name['last']);
		$this->person['address'] = ucwords($address['address']);
		$this->person['city'] = ucwords($address['city']);
		$this->person['state'] = strtoupper($address['state']);
		$this->person['zip'] = $address['zip'];
		$this->person['email'] = filter_var($_POST['Email'], FILTER_SANITIZE_EMAIL);
		$this->id['person'] = null;
		$this->id['household'] = null;
	}


	/**
	 * Test whether payment present
	 * @return boolean
	 */
	public function checkPayment(){
		if (isset($_POST['Amount'])){
			$this->payment['amount'] = $_POST['Amount'];
			return true;
		} return false;
	}

	/**
	 * fuzzy match strings with max distance
	 * @source http://geertvandeweyer.zymichost.com/index.php?page=read&id=11
	 * @param string $query
	 * @param string $target
	 * @param int $distance
	 * @todo write method to use this function for smarter search/match
	 * @return array
	 */
	public function fuzzyMatch($query,$target,$distance) {
			if ($distance == 0) {
				$length = strlen($query);
				if ($length > 10) {
					$distance = 4;
				}
				elseif ($length > 6) {
					$distance = 3;
				}
				else {
					$distance = 2;
				}
			}
			$lev = levenshtein(strtolower($query), strtolower($target));
			if ($lev <= $distance) {
				return array('match' => true, 'distance' => $lev, 'max_distance' => $distance);
			}
			else {
				return array('match' => false, 'distance' => $lev, 'max_distance' => $distance);
			}
		}

	/**
	 * Try matching on Firstname, Lastname, and Email
	 * @return boolean
	 */
	public function matchNameEmail(){
		$criteria = array(
			'searchFor' => $this->person['firstname']. " " .$this->person['lastname'],
			'communication' => $this->person['email'],
			'include'=> 'addresses, communications',	
			);

		try {
			$search = self::$f1->people()->search($criteria)->get();
		} catch(\webhook\Exception $e){
			$this->errorLog($e);
		}		
		
		if($search['results']['@count'] == 1){
			$this->id['person'] = $search['results']['person'][0]['@id'];
			return true;

		} else	// if there are more than 1, return the record that was updated last

		if($search['results']['@count'] >= 1){
			foreach($search['results']['person'] as $person){
				$updated[] = $person['lastUpdatedDate'];
			}
			foreach ($updated as $date){
				$datestring[] = strtotime($date);
			}
				
			$maxDate=max($datestring); 
     		while(list($key,$value)=each($datestring)){ 
			    if($value==$maxDate)$x=$key; 
			  } 
			$this->id['person'] = $search['results']['person'][$x]['@id'];
			return true;

		} else return false;
	}


	/**
	 * On Failed name, email match, try name with address
	 * @return boolean
	 */
	public function matchNameAddress(){
		$criteria = array(
			'searchFor' => $this->person['firstname']. " " .$this->person['lastname'],
			'address' => $this->person['address'],
			'include'=> 'addresses, communications',	
			);

		try {
			$search = self::$f1->people()->search($criteria)->get();
		} catch(\webhook\Exception $e){
			$this->errorLog($e);
		}		

		if($search['results']['@count'] == 1){
			$this->id['person'] = $search['results']['person'][0]['@id'];
			return true;
		}  else	// if there are more than 1, return the record that was updated last

		if($search['results']['@count'] >= 1){
			foreach($search['results']['person'] as $person){
				$updated[] = $person['lastUpdatedDate'];
			}
			foreach ($updated as $date){
				$datestring[] = strtotime($date);
			}
				
			$maxDate=max($datestring); 
     		while(list($key,$value)=each($datestring)){ 
			    if($value==$maxDate)$x=$key; 
			  } 
			$this->id['person'] = $search['results']['person'][$x]['@id'];
			return true;

		} else return false;
	}	

		
	/**
	 * Create New Household in F1
	 * @return void
	 */
	public function createHousehold(){
		$model = $this->fetchModel('households');
		$model['household']['householdName'] = $this->person['firstname'].' '.$this->person['lastname'];
		$model['household']['householdSortName'] = $this->person['lastname'];
		$model['household']['householdFirstName'] = $this->person['firstname'];
		try {
			$r = self::$f1->households()->create($model);
			$this->id['household'] = $r->response['household']['@id'];
		} catch(\webhook\Exception $e){
			$this->errorLog($e);
			}
	}

	/**
	 * Create New Person in F1
	 * @param  int $statusId F1 Status
	 * @return void
	 */
	public function createPerson($statusId){
		$model = $this->fetchModel('people');		
		$model['person']['@householdID'] = $this->id['household']; 
		$model['person']['firstName'] = $this->person['firstname'];
		$model['person']['lastName'] = $this->person['lastname'];
		$model['person']['householdMemberType']['@id'] = "1";
		$model['person']['status']['@id'] = $statusId; 
		$model['person']['status']['date'] = $this->timestamp;
			
		try {
			$r = self::$f1->people()->create($model);
			print_r($r);
			$this->id['person'] = $r->response['person']['@id'];
			
		} catch(\webhook\Exception $e){
			$this->errorLog($e);
			}		
	}

	/**
	 * parse Address
	 * @return array
	 */
	public function parseAddress(){
		if ($_POST['Address']){
		$parts=explode("\n",$_POST['Address']);
	
		$address = array();
			foreach($parts as $line) {
			list($key, $value) = explode('=', $line, 2);
			$address[trim($key)] = trim($value);
			}
		}
		return $address;
	}
	
	/**
	 * Add address to person record
	 * @return void
	 */
	public function createAddress(){
		$this->parseAddress();
		$model = $this->fetchModel('addresses');
		$model['address']['household']['@id'] = $this->id['household'];
    	$model['address']['addressType']['@id'] = "1"; //Primary;
		$model['address']['address1'] = $this->person['address'];
		$model['address']['city'] = $this->person['city'];
		$model['address']['postalCode'] = $this->person['zip'];
		$model['address']['stProvince'] = $this->person['state'];
    	$model['address']['addressDate'] = $this->timestamp;
        $model['address']['addressComment'] = "Formstack Event Registration";

        try {
    	$address = self::$f1->addresses($this->id['household'])->create($model);
    	} catch(\webhook\Exception $e){
    		$this->errorLog($e);
    	}   	
	}

	/**
	 * Add email to F1
	 * @return void
	 */
	public function createCommunications(){
		$model = $this->fetchModel('communications');
		$model['communication']['communicationType']['@id'] = "4";
		$model['communication']['communicationValue'] = $this->person['email'];
		$model['communication']['preferred'] = "true"; 

		try{
        	self::$f1->households_communications($this->id['household'])->create($model);
        } catch(\webhook\Exception $e){
    		$this->errorLog($e);
    	}
	}

	/**
	 * Add person to Group
	 * @param  int $groupId
	 * @return void
	 */
	public function createGroupMember($groupId){
		$model = $this->fetchModel('groups_members');
		$model['member']['group']['@id'] = $groupId;
		$model['member']['person']['@id'] = $this->id['person'];
		$model['member']['memberType']['@id'] = "2"; //member
		try {
			self::$f1->groups_members($groupId)->create($model);
		} catch(\webhook\Exception $e){
			$this->errorLog($e);
		}
	}

	/**
	 * Error Logging
	 * @param  array $e
	 * @return error_log
	 */
	public function errorLog($e){
		$error = $this->timestamp."\n";
		$error .= "-------------------------- \n";
		$error .= "{$e->extra['method']} {$e->extra['url']} \n";
		$error .= "{$e->response} \n\n";
		return error_log($error, 3, "logs/error.log");
	}

	/**
	 * If Payment Form add Receipt
	 * @param  ind $fundId 
	 * @return void
	 */
	public function createContributionReceipt($fundId){
		$model = $this->fetchModel('contributionReceipts');
		$model['contributionReceipt']['amount'] = $this->payment['amount'];
		$model['contributionReceipt']['fund']['@id'] = $fundId;
		$model['contributionReceipt']['person']['@id'] = $this->id['person'];
		$model['contributionReceipt']['contributionType']['@id'] = "3"; //credit card
		$model['contributionReceipt']['receivedDate'] = $this->timestamp;

		try{
			self::$f1->contributionreceipts()->create($model);
		} catch(\webhook\Exception $e){
    		$this->errorLog($e);
    	}
	}

	/**
	 * Utility Method to get Fund Ids
	 * @return array
	 */
	public function getFundIds(){
		self::$f1->funds()->list()->get();
		return self::$f1->response;
	}

	/**
	 * Utility method to get group type ids
	 * @return array
	 */
	public function getGroupTypeIds(){
		self::$f1->grouptypes()->list()->get();
		return self::$f1->response;
	}

	/**
	 * Utility method to get group ids
	 * @return array
	 */
	public function getGroupIds($typeId){
		self::$f1->grouptypes_groups($typeId)->list()->get();
		return self::$f1->response;
	}

	/**
	 * Utility method to get status ids
	 * @return array
	 */
	public function getStatusIds(){
		return self::$f1->people_statuses()->list()->get();
	}


	/**
	 * Utility method to add resource model to cache
	 * @return void
	 */
	public function createModel($type){
		$model = self::$f1->$type($id)->new()->get();
		$fileName = "cache/models/".$type.".json";
		file_put_contents($fileName,json_encode($model));
	}


	/**
	 * Utility method to get resource model from cache
	 * @param  string $type resource type
	 * @return json model
	 */
	public function fetchModel($type){
		$filename = $this->modelsPath.$type.".json";
		$model = json_decode(file_get_contents($filename),true);
		return $model;		
	}

	/**
	 * Send a success response
	 * @return void
	 */
	public function sendWebhookResponse(){
		header('HTTP/1.0 200 OK');
		exit();
	}
	
}