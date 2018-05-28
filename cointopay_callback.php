<?php

require('includes/application_top.php');

$token = MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET;
if ($token == '' || $_GET['token'] != $token)
  throw new Exception('Token: ' . $_GET['token'] . ' do not match');

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
