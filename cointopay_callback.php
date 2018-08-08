<?php

require('includes/application_top.php');

$token = MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET;

if ($token == '' || $_GET['token'] != $token)
    throw new Exception('Token: ' . $_GET['token'] . ' do not match');

$configuration = tep_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key='MODULE_PAYMENT_COINTOPAY_MERCHANT_ID' limit 1");
$configuration = tep_db_fetch_array($configuration);
//response validation
$validate = true;
$merchant_id = $configuration['configuration_value'];
$transaction_id = $_REQUEST['TransactionID'];
$confirm_code = $_REQUEST['ConfirmCode'];

$url = "https://app.cointopay.com/v2REAPI?MerchantID=$merchant_id&Call=QA&APIKey=_&output=json&TransactionID=$transaction_id&ConfirmCode=$confirm_code";
$curl = curl_init($url);
curl_setopt_array($curl, array(
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_SSL_VERIFYPEER => 0
));
$result = curl_exec($curl);
$result = json_decode($result, true);

if(!$result || !is_array($result)) {
    $validate = false;
}else{
    if($_REQUEST['status'] != $result['Status']) {
        $validate = false;
    }
}
if(!$validate) {
    throw new Exception('Invalid request! Unable to verify with Cointopay.');
};


global $db;

$order_id = $_REQUEST['CustomerReferenceNr'];

$order = tep_db_query("select orders_id from " . TABLE_ORDERS . " where orders_id = '" . intval($order_id) . "' limit 1");

if (tep_db_num_rows($order) <= 0)
    throw new Exception('Order #' . $order_id . ' does not exists');

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
