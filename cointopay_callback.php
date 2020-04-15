<?php

require('includes/application_top.php');

$token = MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET;
if ($token == '' || $_GET['token'] != $token)
  throw new Exception('Token: ' . $_GET['token'] . ' do not match');

global $db;

$order_id = $_REQUEST['CustomerReferenceNr'];
$merchant_id      = MODULE_PAYMENT_COINTOPAY_MERCHANT_ID;
$api_key          = MODULE_PAYMENT_COINTOPAY_API_KEY;

$order = tep_db_query("select orders_id from " . TABLE_ORDERS . " where orders_id = '" . intval($order_id) . "' limit 1");

if (tep_db_num_rows($order) <= 0)
  throw new Exception('Order #' . $order_id . ' does not exists');

if(!isset($_GET['ConfirmCode']))
{
	echo 'We have detected changes in your order. Your order has been halted.';exit;
}
$data = [
		   'mid' => $merchant_id ,
		   'TransactionID' =>  $_GET['TransactionID'] ,
		   'ConfirmCode' => $_GET['ConfirmCode']
	   ];
$transactionData = fn_cointopay_transactiondetail($data);
if(200 !== $transactionData['status_code']){
	echo $transactionData['message'];exit;
}
else{
	if($transactionData['data']['Security'] != $_GET['ConfirmCode']){
		echo "Data mismatch! ConfirmCode doesn\'t match";
		exit;
	}
	elseif($transactionData['data']['CustomerReferenceNr'] != $_GET['CustomerReferenceNr']){
		echo "Data mismatch! CustomerReferenceNr doesn\'t match";
		exit;
	}
	elseif($transactionData['data']['TransactionID'] != $_GET['TransactionID']){
		echo "Data mismatch! TransactionID doesn\'t match";
		exit;
	}
	elseif($transactionData['data']['AltCoinID'] != $_GET['AltCoinID']){
		echo "Data mismatch! AltCoinID doesn\'t match";
		exit;
	}
	elseif($transactionData['data']['MerchantID'] != $_GET['MerchantID']){
		echo "Data mismatch! MerchantID doesn\'t match";
		exit;
	}
	elseif($transactionData['data']['coinAddress'] != $_GET['CoinAddressUsed']){
		echo "Data mismatch! coinAddress doesn\'t match";
		exit;
	}
	elseif($transactionData['data']['SecurityCode'] != $_GET['SecurityCode']){
		echo "Data mismatch! SecurityCode doesn\'t match";
		exit;
	}
	elseif($transactionData['data']['inputCurrency'] != $_GET['inputCurrency']){
		echo "Data mismatch! inputCurrency doesn\'t match";
		exit;
	}
	elseif($transactionData['data']['Status'] != $_GET['status']){
		echo "Data mismatch! status doesn\'t match. Your order status is ".$transactionData['data']['Status'];
		exit;
	}
	
}
/*$response = fn_cointopay_validate_order($data);

if($response->Status !== $_GET['status'])
{
   echo 'We have detected different order status. Your order status is '.$response->Status;exit;
}
if($response->CustomerReferenceNr !== $_GET['CustomerReferenceNr'])
{
	echo 'Your order has been halted. Your CustomerReferenceNr is '.$response->CustomerReferenceNr;exit;
}*/
$redirect_url = '';
if($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 0 ){
   $cg_order_status = MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID;
   $redirect_url  = tep_href_link(FILENAME_CHECKOUT_SUCCESS);
}elseif ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 1) {
   $cg_order_status = MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID;
   $redirect_url  = tep_href_link(FILENAME_CHECKOUT_PAYMENT);
}elseif ($_REQUEST['status']== 'failed') {
   $cg_order_status = MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID;
   $redirect_url  = tep_href_link(FILENAME_CHECKOUT_PAYMENT);
}
else{
  $cg_order_status = NULL;
  $redirect_url  = tep_href_link(FILENAME_CHECKOUT_PAYMENT);
}
if ($cg_order_status)
  tep_db_query("update ". TABLE_ORDERS. " set orders_status = " . $cg_order_status . " where orders_id = ". intval($order_id));
if($_REQUEST['status']== 'paid'){
  tep_db_query("update ". TABLE_ORDERS_STATUS_HISTORY. " set orders_status_id = " . $cg_order_status .", comments ='Payment completed notification from Cointopay' where orders_id = ". intval($order_id));
}
if($_REQUEST['status']== 'failed'){
  tep_db_query("update ". TABLE_ORDERS_STATUS_HISTORY. " set orders_status_id = " . $cg_order_status .", comments ='Payment failed notification from Cointopay' where orders_id = ". intval($order_id));
}
if($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 1){
 tep_db_query("update ". TABLE_ORDERS_STATUS_HISTORY. " set orders_status_id = " . $cg_order_status .", comments ='Payment failed notification from Cointopay because notenough' where orders_id = ". intval($order_id));
}

tep_redirect($redirect_url);
echo 'OK';
function  fn_cointopay_validate_order($data)
   {
       $params = array(
       "authentication:1",
       'cache-control: no-cache',
       );
       $ch = curl_init();
       curl_setopt_array($ch, array(
       CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
       //CURLOPT_USERPWD => $this->apikey,
       CURLOPT_POSTFIELDS => 'MerchantID='.$data['mid'].'&Call=QA&APIKey=_&output=json&TransactionID='.$data['TransactionID'].'&ConfirmCode='.$data['ConfirmCode'],
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_SSL_VERIFYPEER => false,
       CURLOPT_HTTPHEADER => $params,
       CURLOPT_USERAGENT => 1,
       CURLOPT_HTTPAUTH => CURLAUTH_BASIC
       )
       );
       $response = curl_exec($ch);
       $results = json_decode($response);
       return $results;
       exit();
}
function  fn_cointopay_transactiondetail($data)
{
       $params = array(
       "authentication:1",
       'cache-control: no-cache',
       );
       $ch = curl_init();
       curl_setopt_array($ch, array(
       CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
       //CURLOPT_USERPWD => $this->apikey,
       CURLOPT_POSTFIELDS => 'Call=Transactiondetail&MerchantID='.$data['mid'].'&output=json&ConfirmCode='.$data['ConfirmCode'].'&APIKey=a',
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_SSL_VERIFYPEER => false,
       CURLOPT_HTTPHEADER => $params,
       CURLOPT_USERAGENT => 1,
       CURLOPT_HTTPAUTH => CURLAUTH_BASIC
       )
       );
       $response = curl_exec($ch);
       $results = json_decode($response, true);
       /*if($results->CustomerReferenceNr)
       {
           return $results;
       }*/
       return $results;
       exit();
}