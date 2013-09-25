<?php 

namespace webhook;

require_once('webhook/formstack.php');	

$registrant = new Formstack();


$registrant->verifyHandshake();
$registrant->setInput();

if(!$registrant->matchNameEmail()){
	if (!$registrant->matchNameAddress()){
		$registrant->createHousehold();
		$registrant->createPerson($statusId = "110");
		$registrant->createAddress();
		$registrant->createCommunications();	
	}
} 

$registrant->createGroupMember($groupId = "");  

if($registrant->checkPayment()){
	$registrant->createContributionReceipt($fundId = "");
}

$registrant->sendWebhookResponse();


// In case id's are needed, uncomment one or more below
// 
//echo "<pre>";
//print_r($registrant->getFundIds());
//print_r($registrant->getStatusIds());

?>