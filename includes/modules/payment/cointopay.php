<?php

class cointopay
{
  public $code;
  public $title;
  public $description;
  public $enabled;

  function cointopay()
  {
    $this->code             = 'cointopay';
    $this->title            = MODULE_PAYMENT_COINTOPAY_TEXT_TITLE;
    $this->description      = MODULE_PAYMENT_COINTOPAY_TEXT_DESCRIPTION;
    $this->merchant_id      = MODULE_PAYMENT_COINTOPAY_MERCHANT_ID;
    $this->security_code    = MODULE_PAYMENT_COINTOPAY_SECURITY_CODE;
    $this->testMode         = ((MODULE_PAYMENT_COINTOPAY_TEST == 'True') ? true : false);
    $this->enabled          = ((MODULE_PAYMENT_COINTOPAY_STATUS == 'True') ? true : false);
  }

  function javascript_validation()
  {
    return false;
  }

  function selection()
  {
    return array('id' => $this->code, 'module' => $this->title);
  }

  function pre_confirmation_check()
  {
    return false;
  }

  function confirmation()
  {
    return false;
  }

  function process_button()
  {
    return false;
  }

  function before_process()
  {
    return false;
  }

  function after_process()
  {
    global $insert_id, $order;

    $info = $order->info;

    $configuration = tep_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key='STORE_NAME' limit 1");
    $configuration = tep_db_fetch_array($configuration);
    $products = tep_db_query("select oc.products_id, oc.products_quantity, pd.products_name from " . TABLE_ORDERS_PRODUCTS . " as oc left join " . TABLE_PRODUCTS_DESCRIPTION . " as pd on pd.products_id=oc.products_id  where orders_id=" . intval($insert_id));

    $description = array();
    foreach ($products as $product) {
      $description[] = $product['products_quantity'] . ' × ' . $product['products_name'];
    }

    $callback = tep_href_link('cointopay_callback.php', $parameters='', $connection='NONSSL', $add_session_id=true, $search_engine_safe=true, $static=true );

    $params = array(
      'order_id'         => $insert_id,
      'price'            => number_format($info['total'], 2, '.', ''),
      'currency'         => $info['currency'],
      'callback_url'     => $this->flash_encode($callback . "?token=" . MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET),
      'cancel_url'       => tep_href_link(FILENAME_CHECKOUT_PAYMENT),
      'success_url'      => tep_href_link(FILENAME_CHECKOUT_SUCCESS),
      'title'            => $configuration->fields['configuration_value'] . ' Order #' . $insert_id,
      'description'      => join($description, ', ')
    );
    require_once(dirname(__FILE__) . "/cointopay/init.php");
    require_once(dirname(__FILE__) . "/cointopay/version.php");

    $order = \Cointopay\Merchant\Order::createOrFail($params, array(), array(
      'merchant_id' => MODULE_PAYMENT_COINTOPAY_MERCHANT_ID,
      'security_code' => MODULE_PAYMENT_COINTOPAY_SECURITY_CODE,
      'user_agent' => 'Cointopay - osCommerce Extension v' . COINTOPAY_OSCOMMERCE_EXTENSION_VERSION));
    echo "<pre>";
    print_r($order);exit;
    $_SESSION['cart']->reset(true);
    tep_redirect($order->shortURL);

    return false;
  }

  function get_error()
  {
    return false;
  }

  function check()
  {
    if (!isset($this->_check)) {
      $check_query  = tep_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_COINTOPAY_STATUS'");
      $this->_check = tep_db_num_rows($check_query);
    }

    return $this->_check;
  }

  function install()
  {
    $callbackSecret = md5('zencart_' . mt_rand());

    tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Cointopay Module', 'MODULE_PAYMENT_COINTOPAY_STATUS', 'False', 'Cointopay International', '6', '0', 'tep_cfg_select_option(array(\'True\', \'False\'), ', now())");
    tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Merchant Id', 'MODULE_PAYMENT_COINTOPAY_MERCHANT_ID', '0', 'Your Cointopay Merchant Id', '6', '0', now())");
    tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Security Code', 'MODULE_PAYMENT_COINTOPAY_SECURITY_CODE', '0', 'Your Cointopay Security Code', '6', '0', now())");
    tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Paid Order Status', 'MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID', '8', 'Status in your store when Cointopay order status is paid.<br />(\'Paid\' recommended)', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
    tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Failed Order Status', 'MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID', '9', 'Status in your store when Cointopay order status is failed.<br />(\'Failed\' recommended)', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
    tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Paidnotenough Order Status', 'MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID', '10', 'Status in your store when Cointopay order status is paidnotenough.<br />(\'Paidnotenough\' recommended)', '6', '6', 'tep_cfg_pull_down_order_statuses(', 'tep_get_order_status_name', now())");
    tep_db_query("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function) values ('Callback Secret Key (do not edit)', 'MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET', '$callbackSecret', '', '6', '6', now(), 'cointopay_censorize')");

    $paid_status =  tep_db_query("select * from " . TABLE_ORDERS_STATUS . " where orders_status_id = 8");
    if(!$paid_status){
      tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id,language_id, orders_status_name,   public_flag, downloads_flag) values ('8', '1', 'Paid [Cointopay]', '1', '0')");
    }
    $failed_status =  tep_db_query("select * from " . TABLE_ORDERS_STATUS . " where orders_status_id = 9");
    if(!$failed_status){
      tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id,language_id, orders_status_name,   public_flag, downloads_flag) values ('9', '1', 'Failed [Cointopay]', '1', '0')");
    }
    $paidnotenough_status =  tep_db_query("select * from " . TABLE_ORDERS_STATUS . " where orders_status_id = 10");

    if(!$paidnotenough_status){
      tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id,language_id, orders_status_name,   public_flag, downloads_flag) values ('10', '1', 'Paidnotenough [Cointopay]', '1', '0')");
    }

  }

  function remove ()
  {
    tep_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key LIKE 'MODULE\_PAYMENT\_COINTOPAY\_%'");
  }

  function keys()
  {
    return array(
      'MODULE_PAYMENT_COINTOPAY_STATUS',
      'MODULE_PAYMENT_COINTOPAY_MERCHANT_ID',
      'MODULE_PAYMENT_COINTOPAY_SECURITY_CODE',
      'MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID',
      'MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID',
      'MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID'
    );
  }
  public function flash_encode ($input)
   {
       return rawurlencode(utf8_encode($input));
   }
}
function cointopay_censorize($value) {
  return "(hidden for security reasons)";
}
