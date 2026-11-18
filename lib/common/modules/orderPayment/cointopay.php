<?php
/**
 * Cointopay payment module for osCommerce 4.
 *
 * Install path:  lib/common/modules/orderPayment/cointopay.php
 *
 * Also copy your OLD library folder (init.php, version.php, lib/...) next to it:
 *   lib/common/modules/orderPayment/cointopay/
 *
 * Callback controller: frontend/controllers/CointopayController.php (separate file).
 */

namespace common\modules\orderPayment;

use common\classes\modules\ModulePayment;
use common\classes\modules\ModuleStatus;
use common\classes\modules\ModuleSortOrder;

require (__DIR__ . "/lib/cointopay/init.php");
require (__DIR__ . "/lib/cointopay/version.php");

class cointopay extends ModulePayment
{
    public $code = 'cointopay';
    public $title;
    public $description;
    public $enabled = false;
    public $sort_order = 0;
    public $merchant_id;
    public $security_code;

    protected $defaultTranslationArray = [
        'MODULE_PAYMENT_COINTOPAY_TEXT_TITLE'       => 'Cointopay (Cryptocurrency)',
        'MODULE_PAYMENT_COINTOPAY_TEXT_DESCRIPTION' => 'Pay with Bitcoin and other cryptocurrencies via Cointopay',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->title       = defined('MODULE_PAYMENT_COINTOPAY_TEXT_TITLE') ? MODULE_PAYMENT_COINTOPAY_TEXT_TITLE : 'Cointopay';
        $this->description = defined('MODULE_PAYMENT_COINTOPAY_TEXT_DESCRIPTION') ? MODULE_PAYMENT_COINTOPAY_TEXT_DESCRIPTION : '';

        if (!defined('MODULE_PAYMENT_COINTOPAY_STATUS')) {
            return;
        }

        $this->enabled       = (MODULE_PAYMENT_COINTOPAY_STATUS === 'True');
        $this->sort_order    = defined('MODULE_PAYMENT_COINTOPAY_SORT_ORDER') ? (int) MODULE_PAYMENT_COINTOPAY_SORT_ORDER : 0;
        $this->merchant_id   = MODULE_PAYMENT_COINTOPAY_MERCHANT_ID;
        $this->security_code = MODULE_PAYMENT_COINTOPAY_SECURITY_CODE;
    }

    /**
     * Admin settings. osCommerce 4 installs/removes these automatically,
     * so the old install()/remove()/keys()/check() methods are not needed.
     */
    public function configure_keys()
    {
        $statusFn = '\\common\\helpers\\Order::get_order_status_name';

        return [
            'MODULE_PAYMENT_COINTOPAY_STATUS' => [
                'title'        => 'Enable Cointopay Module',
                'value'        => 'False',
                'description'  => 'Cointopay International',
                'sort_order'   => '0',
                'set_function' => 'tep_cfg_select_option(array(\'True\', \'False\'), ',
            ],
            'MODULE_PAYMENT_COINTOPAY_MERCHANT_ID' => [
                'title'       => 'Merchant Id',
                'value'       => '0',
                'description' => 'Your Cointopay Merchant Id',
                'sort_order'  => '1',
            ],
            'MODULE_PAYMENT_COINTOPAY_SECURITY_CODE' => [
                'title'       => 'Security Code',
                'value'       => '0',
                'description' => 'Your Cointopay Security Code',
                'sort_order'  => '2',
            ],
            'MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID' => [
                'title'        => 'Set Paid Order Status',
                'value'        => '0',
                'description'  => 'Status in your store when Cointopay order status is paid. Create a "Paid [Cointopay]" status first.',
                'sort_order'   => '3',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => $statusFn,
            ],
            'MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID' => [
                'title'        => 'Set Failed Order Status',
                'value'        => '0',
                'description'  => 'Status in your store when Cointopay order status is failed.',
                'sort_order'   => '4',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => $statusFn,
            ],
            'MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID' => [
                'title'        => 'Set Paidnotenough Order Status',
                'value'        => '0',
                'description'  => 'Status in your store when Cointopay order status is paidnotenough.',
                'sort_order'   => '5',
                'set_function' => 'tep_cfg_pull_down_order_statuses(',
                'use_function' => $statusFn,
            ],
            'MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET' => [
                'title'       => 'Callback Secret Key (do not edit)',
                'value'       => bin2hex(random_bytes(16)),
                'description' => 'Generated at install. Used to authenticate the callback URL.',
                'sort_order'  => '6',
            ],
            'MODULE_PAYMENT_COINTOPAY_SORT_ORDER' => [
                'title'       => 'Sort order of display',
                'value'       => '0',
                'description' => 'Lowest is displayed first.',
                'sort_order'  => '7',
            ],
        ];
    }

    public function describe_status_key()
    {
        return new ModuleStatus('MODULE_PAYMENT_COINTOPAY_STATUS', 'True', 'False');
    }

    public function describe_sort_key()
    {
        return new ModuleSortOrder('MODULE_PAYMENT_COINTOPAY_SORT_ORDER');
    }

    public function isOnline()
    {
        return true;
    }

    public function selection()
    {
        return ['id' => $this->code, 'module' => $this->title];
    }

    public function javascript_validation()
    {
        return false;
    }

    public function pre_confirmation_check()
    {
        return false;
    }

    public function confirmation()
    {
        return false;
    }

    public function process_button()
    {
        return false;
    }

    public function after_process()
    {
        return false;
    }

    /**
     * Order is saved at this point: create the Cointopay order and redirect the customer.
     */
    public function before_process()
    {
		$this->_save_order();
            $order   = $this->manager->getOrderInstance();
        $orderId = (int) ($order->order_id ?? 0);
        if (!$orderId) {
            global $insert_id;
            $orderId = (int) $insert_id;
        }

        $info  = $order->info;
        $total = isset($info['total_inc_tax']) ? $info['total_inc_tax'] : $info['total'];

        $lines = [];
        if (!empty($order->products) && is_array($order->products)) {
            foreach ($order->products as $product) {
                $lines[] = $product['qty'] . ' x ' . $product['name'];
            }
        }

        $storeName = defined('STORE_NAME') ? STORE_NAME : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');

        // The same URL handles both the gateway callback and the customer cancel redirect.
        $callback = tep_href_link('cointopay/callback', 'token=' . MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET, 'SSL');

        $params = [
            'order_id'     => $orderId,
            'price'        => number_format((float) $total, 2, '.', ''),
            'currency'     => 'USD', //$info['currency'],
            'callback_url' => $this->flashEncode($callback),
            'cancel_url'   => $this->flashEncode($callback),
            'success_url'  => tep_href_link('checkout/success', '', 'SSL'),
            'title'        => trim($storeName . ' Order #' . $orderId),
            'description'  => implode(', ', $lines),
        ];

        try {
            $cpOrder = \Cointopay\Merchant\Order::createOrFail($params, [], [
                'merchant_id'   => MODULE_PAYMENT_COINTOPAY_MERCHANT_ID,
                'security_code' => MODULE_PAYMENT_COINTOPAY_SECURITY_CODE,
                'user_agent'    => 'Cointopay - osCommerce 4 Extension v' . (defined('COINTOPAY_OSCOMMERCE_EXTENSION_VERSION') ? COINTOPAY_OSCOMMERCE_EXTENSION_VERSION : '1'),
            ]);
        } catch (\Throwable $e) {
			$this->log('ERROR creating Cointopay order #' . $orderId . ': ' . get_class($e) . ': ' . $e->getMessage()
				. ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
			$this->failAndRedirect($orderId, $e->getMessage());
		}

        tep_redirect($cpOrder->shortURL);

        //return false;
    }
	
	private function failAndRedirect($orderId, $reason = '')
	{
		$this->rememberError($reason);

		if ($orderId > 0) {
			// Order::doCancel() needs this language constant, which may not be loaded on the frontend.
			if (!defined('TEXT_ORDER_PRODUCT_DO_CANCEL_RESTOCK')) {
				define('TEXT_ORDER_PRODUCT_DO_CANCEL_RESTOCK', 'Product restocked after order cancellation');
			}
			try {
				\common\helpers\Order::doCancel($orderId);
			} catch (\Throwable $e) {
				$this->log('ERROR cancelling order #' . $orderId . ': ' . $e->getMessage());
			}
		}
		tep_redirect(tep_href_link(
			defined('FILENAME_CHECKOUT_PAYMENT') ? FILENAME_CHECKOUT_PAYMENT : 'checkout',
			'payment_error=' . $this->code . '&error=gateway',
			'NONSSL',
			true
		));
		exit();
	}
	
	 private function rememberError($reason)
	{
		$reason = strip_tags((string) $reason);
		foreach ([
			defined('MODULE_PAYMENT_COINTOPAY_MERCHANT_ID') ? (string) MODULE_PAYMENT_COINTOPAY_MERCHANT_ID : '',
			defined('MODULE_PAYMENT_COINTOPAY_SECURITY_CODE') ? (string) MODULE_PAYMENT_COINTOPAY_SECURITY_CODE : '',
			defined('MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET') ? (string) MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET : '',
		] as $secret) {
			if (strlen($secret) > 3) {
				$reason = str_replace($secret, '***', $reason);
			}
		}
		$reason = preg_replace('/[<>"\'&]/', '', $reason);
		$reason = trim(preg_replace('/\s+/', ' ', $reason));
		$reason = rtrim(mb_substr($reason, 0, 200), ". ");

		try {
			if (isset(\Yii::$app) && \Yii::$app->has('session')) {
				\Yii::$app->session->set('cointopay_error', $reason);
				return;
			}
		} catch (\Throwable $e) {
			// fall back to the plain session below
		}
		if (isset($_SESSION)) {
			$_SESSION['cointopay_error'] = $reason;
		}
	}

	private function takeError()
	{
		$reason = '';
		try {
			if (isset(\Yii::$app) && \Yii::$app->has('session')) {
				$reason = (string) \Yii::$app->session->get('cointopay_error', '');
				\Yii::$app->session->remove('cointopay_error');
			}
		} catch (\Throwable $e) {
			$reason = '';
		}
		if ($reason === '' && isset($_SESSION['cointopay_error'])) {
			$reason = (string) $_SESSION['cointopay_error'];
			unset($_SESSION['cointopay_error']);
		}
		return $reason;
	}

	private function failAndRedirectt($orderId)
	{
		if ($orderId > 0) {
			// Order::doCancel() needs this language constant, which may not be loaded on the frontend.
			if (!defined('TEXT_ORDER_PRODUCT_DO_CANCEL_RESTOCK')) {
				define('TEXT_ORDER_PRODUCT_DO_CANCEL_RESTOCK', 'Product restocked after order cancellation');
			}
			try {
				\common\helpers\Order::doCancel($orderId);
			} catch (\Throwable $e) {
				$this->log('ERROR cancelling order #' . $orderId . ': ' . $e->getMessage());
			}
		}
		tep_redirect(tep_href_link(
			defined('FILENAME_CHECKOUT_PAYMENT') ? FILENAME_CHECKOUT_PAYMENT : 'checkout',
			'payment_error=' . $this->code . '&error=unavailable',
			'NONSSL',
			true
		));
		exit();
	}

    public function get_error()
    {
       $generic = defined('MODULE_PAYMENT_COINTOPAY_TEXT_ERROR_UNAVAILABLE')
			? MODULE_PAYMENT_COINTOPAY_TEXT_ERROR_UNAVAILABLE
			: 'Cointopay is currently unavailable. Please try again or choose another payment method.';

		$reason = $this->takeError();
		$this->log('get_error called, reason=' . ($reason !== '' ? $reason : '(none)')
			. '; GET keys=' . implode(',', array_keys($_GET)));
		$message = $reason !== ''
			? 'Cointopay could not process your payment: ' . $reason . '. Please try again or choose another payment method.'
			: $generic;

		return [
			'title' => defined('MODULE_PAYMENT_COINTOPAY_TEXT_ERROR') ? MODULE_PAYMENT_COINTOPAY_TEXT_ERROR : 'An error has occurred',
			'error' => $message,
		];
    }
	
	/*
	 * Store the order in the database, and set $this->order_id
	 */
	public function _save_order() {
		global $languages_id;

		if (!empty($this->order_id) && $this->order_id > 0) {
			return;
		}

		$order = $this->manager->getOrderInstance();

		$order->save_order();

		$order->save_details();

		$order->save_products(false);

		$stock_updated = false;

		$this->order_id = $order->order_id;
	}

    public function flashEncode($input)
    {
        return rawurlencode((string) $input);
    }

	/**
	 * Debug log: runtime/logs/cointopay.log (falls back to the PHP error log).
	 * Never logs the merchant ID or security code.
	 */
	private function log($message)
	{
		$line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
		$file = null;
		try {
			if (class_exists('\\Yii', false) && \Yii::getAlias('@runtime', false)) {
				$file = \Yii::getAlias('@runtime') . '/logs/cointopay.log';
			}
		} catch (\Throwable $e) {
			$file = null;
		}
		if ($file && @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false) {
			return;
		}
		error_log('cointopay: ' . $message);
	}
}
