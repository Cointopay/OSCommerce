<?php
/**
 * Cointopay callback controller for osCommerce 4.
 *
 * Install path:  frontend/controllers/CointopayController.php
 * URL:           /cointopay/callback?token=...  (replaces the old cointopay_callback.php)
 */

namespace frontend\controllers;

use Yii;
use yii\web\Controller;

class CointopayController extends Controller
{
    /** The gateway posts/redirects here without a CSRF token. */
    public $enableCsrfValidation = false;

    public function actionCallback()
    {
        $req = Yii::$app->request;
        $p   = array_merge($req->get(), $req->post());

        if (!defined('MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET') || !defined('MODULE_PAYMENT_COINTOPAY_MERCHANT_ID')) {
            return $this->errorPage(
                'Payment unavailable',
                'The Cointopay payment method is not available right now. Please try again later or choose another payment method.',
                500
            );
        }

        // 1. Authenticate the callback URL.
        $secret = (string) MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET;
        $token  = (string) ($p['token'] ?? '');
        if ($secret === '' || !hash_equals($secret, $token)) {
            return $this->errorPage(
                'Invalid link',
                'This payment link is not valid. If you were trying to pay for an order, please return to the store and try again.',
                403
            );
        }

        // 2. Find the order.
        $ordersTable = $this->table('TABLE_ORDERS', 'orders');
        $orderId     = (int) ($p['CustomerReferenceNr'] ?? 0);
        $order       = null;
        if ($orderId > 0) {
            $query = tep_db_query("select orders_id, orders_status, customers_id from " . $ordersTable . " where orders_id = '" . $orderId . "' limit 1");
            $order = tep_db_fetch_array($query);
        }
        if (!$order) {
            return $this->errorPage(
                'Order not found',
                'We could not find the order for this payment' . ($orderId > 0 ? ' (order #' . $orderId . ')' : '') . '. Please contact the store if you have been charged.',
                404
            );
        }

        if (empty($p['ConfirmCode'])) {
            return $this->errorPage(
                'Order halted',
                'We detected changes in your order, so it has been halted. Please contact the store with your order number' . ' (#' . $orderId . ').',
                400
            );
        }

        // 3. Verify the callback against Cointopay's own record of the transaction.
        $result = $this->transactionDetail(MODULE_PAYMENT_COINTOPAY_MERCHANT_ID, (string) $p['ConfirmCode']);
        if (!is_array($result) || (int) ($result['status_code'] ?? 0) !== 200 || empty($result['data'])) {
            Yii::warning('Cointopay verification failed for order #' . $orderId . ': ' . json_encode($result), 'cointopay');
            return $this->errorPage(
                'Payment could not be verified',
                isset($result['message']) && is_string($result['message']) && $result['message'] !== ''
                    ? $result['message']
                    : 'We could not verify this payment with Cointopay. Please contact the store with your order number (#' . $orderId . ').',
                502
            );
        }
        $data = $result['data'];

        // Cointopay field => callback parameter. "required" must match; "optional" only when sent.
        $required = [
            'Security'            => 'ConfirmCode',
            'CustomerReferenceNr' => 'CustomerReferenceNr',
            'TransactionID'       => 'TransactionID',
            'Status'              => 'status',
        ];
        $optional = [
            'AltCoinID'     => 'AltCoinID',
            'MerchantID'    => 'MerchantID',
            'coinAddress'   => 'CoinAddressUsed',
            'SecurityCode'  => 'SecurityCode',
            'inputCurrency' => 'inputCurrency',
        ];

        foreach ($required as $apiKey => $param) {
			if ($apiKey == 'Status') {
				if (
					 $data['Status'] !== $p['status']
					&& !isset($p['cancel'])
					&& !(
						strtolower($p['status']) === 'paid'
						&& isset($p['notenough'])
						&& $p['notenough'] == 1
					)
					&& !(
						strtolower( $data['Status']) === 'expired'
						&& strtolower($p['status']) === 'failed'
					)
				) {
					return $this->mismatchPage($orderId, $param);
				}
			}
            elseif ((string) ($data[$apiKey] ?? '') !== (string) ($p[$param] ?? '')) {
                return $this->mismatchPage($orderId, $param);
            }
        }
        foreach ($optional as $apiKey => $param) {
            if (isset($p[$param]) && (string) ($data[$apiKey] ?? '') !== (string) $p[$param]) {
                return $this->mismatchPage($orderId, $param);
            }
        }

        // 4. Map the Cointopay status to a store order status.
        $status    = (string) $p['status'];
        $notEnough = (int) ($p['notenough'] ?? 0);
        $newStatus = 0;
        $comment   = '';
        $success   = false;

        if ($status === 'paid' && $notEnough === 0) {
            $newStatus = (int) $this->setting('MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID');
            $comment   = 'Payment completed notification from Cointopay';
            $success   = true;
        } elseif ($status === 'paid' && $notEnough === 1) {
            $newStatus = (int) $this->setting('MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID');
            $comment   = 'Payment failed notification from Cointopay because notenough';
        } elseif ($status === 'failed') {
            $newStatus = (int) $this->setting('MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID');
            $comment   = 'Payment failed notification from Cointopay';
        }

        // Never downgrade an order that is already marked paid.
        $paidId      = (int) $this->setting('MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID');
        $alreadyPaid = ($paidId > 0 && (int) $order['orders_status'] === $paidId);

        if ($newStatus > 0 && !($alreadyPaid && !$success)) {
			// Cancel-type statuses call Order::doCancel(), which needs these texts loaded.
            $this->ensureCancelTexts();
            try {
                \common\helpers\Order::setStatus($orderId, $newStatus, [
                    'comments'          => $comment,
                    'customer_notified' => 0,
                ]);
            } catch (\Throwable $ex) {
                Yii::error('Cointopay setStatus failed for order #' . $orderId . ': ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine(), 'cointopay');
                return $this->errorPage(
                    'Order update failed',
                    'We received your payment notification but could not update your order. Please contact the store with your order number (#' . $orderId . ').',
                    500
                );
            }
        }

        // Clear the customer's saved basket once the payment is complete.
        /*if ($success && (int) $order['customers_id'] > 0) {
            $cid = (int) $order['customers_id'];
            tep_db_query("delete from " . $this->table('TABLE_CUSTOMERS_BASKET', 'customers_basket') . " where customers_id = '" . $cid . "'");
            tep_db_query("delete from " . $this->table('TABLE_CUSTOMERS_BASKET_ATTRIBUTES', 'customers_basket_attributes') . " where customers_id = '" . $cid . "'");
        }*/

        // 5. Successful payments go to the success page.
        if ($success) {
            return $this->redirect(tep_href_link('checkout/success', '', 'SSL'));
			/* return $this->successPage(
                'Payment Successful',
                'We received your payment, your order has been paid.',
                200
            ); */
        }

        // Anything else gets a clear message instead of a bare redirect.
        if ($status === 'paid' && $notEnough === 1) {
            return $this->errorPage(
                'Payment incomplete',
                'We received your payment, but the amount was less than the order total. Please contact the store with your order number (#' . $orderId . ') to settle the remaining balance.',
                200
            );
        }
		if (isset($p['cancel'])) {
            return $this->errorPage(
                'Payment Canceled',
                'Your payment has been cancelled, You can go back to the store and try again.',
                200
            );
        }
        if ($status === 'failed') {
            return $this->errorPage(
                'Payment failed',
                'Your payment has been failed, so your order has not been paid. You can go back to the store and try again.',
                200
            );
        }

        return $this->errorPage(
            'Payment not completed',
            'Your payment has not been confirmed yet. If you have already paid, please wait a few minutes or contact the store with your order number (#' . $orderId . ').',
            200
        );
    }

    // ---- helpers ----

    private function mismatchPage($orderId, $param)
    {
        // Details go to the log, not to the customer.
        Yii::warning('Cointopay data mismatch on "' . $param . '" for order #' . (int) $orderId, 'cointopay');
        return $this->errorPage(
            'Payment could not be verified',
            'Data mismatch! {$param} doesn\'t match',
            409
        );
    }

    /**
     * osCommerce 4 core uses language constants in Order::doCancel() that are only loaded in admin.
     * Load them if possible, otherwise define safe fallbacks so a cancel-type status doesn't crash.
     */
    private function ensureCancelTexts()
    {
        if (defined('TEXT_ORDER_PRODUCT_DO_CANCEL_RESTOCK')) {
            return;
        }
        try {
            if (class_exists('\\common\\helpers\\Translation')) {
                foreach (['admin/orders', 'admin/main', 'orders'] as $entity) {
                    \common\helpers\Translation::init($entity);
                    if (defined('TEXT_ORDER_PRODUCT_DO_CANCEL_RESTOCK')) {
                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
            // fall through to the fallback below
        }
        define('TEXT_ORDER_PRODUCT_DO_CANCEL_RESTOCK', 'Product restocked after order cancellation');
    }

    private function setting($name)
    {
        return defined($name) ? constant($name) : 0;
    }

    private function table($const, $fallback)
    {
        return defined($const) ? constant($const) : $fallback;
    }

    private function homeUrl()
    {
        try {
            $url = tep_href_link('', '', 'SSL');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return '/';
    }

    /**
     * Standalone, styled message page: red cross icon, title, message, "Return to home" button.
     */
    private function errorPage($title, $message, $statusCode = 200)
    {
        Yii::$app->response->statusCode = (int) $statusCode;
        $this->layout = false;

        $storeName = defined('STORE_NAME') ? (string) STORE_NAME : '';
        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . $e($title) . ($storeName !== '' ? ' - ' . $e($storeName) : '') . '</title>
<style>
  :root { --bg:#f4f6f9; --card:#ffffff; --text:#1f2933; --muted:#5f6b7a; --red:#d93025; --red-bg:#fdecea; --btn:#1f2933; --btn-text:#ffffff; --shadow:0 10px 30px rgba(15,23,42,.10); }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#12161c; --card:#1b212a; --text:#eef1f5; --muted:#a3adba; --red:#ff6b61; --red-bg:#3a1f1d; --btn:#eef1f5; --btn-text:#12161c; --shadow:0 10px 30px rgba(0,0,0,.5); }
  }
  * { box-sizing:border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px; background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; line-height:1.5; }
  .card { width:100%; max-width:460px; background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:40px 32px 32px; text-align:center; }
  .icon { width:84px; height:84px; margin:0 auto 20px; border-radius:50%; background:var(--red-bg); display:flex; align-items:center; justify-content:center; }
  .icon svg { width:44px; height:44px; }
  h1 { margin:0 0 10px; font-size:24px; font-weight:700; }
  p { margin:0 0 28px; font-size:16px; color:var(--muted); overflow-wrap:anywhere; }
  .btn { display:inline-block; padding:13px 28px; border-radius:10px; background:var(--btn); color:var(--btn-text); font-size:16px; font-weight:600; text-decoration:none; }
  .btn:hover, .btn:focus { opacity:.88; }
  .store { margin-top:24px; font-size:13px; color:var(--muted); }
</style>
</head>
<body>
  <main class="card" role="alert">
    <div class="icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
        <line x1="5" y1="5" x2="19" y2="19"></line>
        <line x1="19" y1="5" x2="5" y2="19"></line>
      </svg>
    </div>
    <h1>' . $e($title) . '</h1>
    <p>' . $e($message) . '</p>
    <a class="btn" href="' . $e($this->homeUrl()) . '">Return to home</a>
  </main>
</body>
</html>';

        return $this->renderContent($html);
    }

    /**
     * Standalone, styled message page: green success icon, title, message, "Return to home" button.
     */
    private function successPage($title, $message, $statusCode = 200)
    {
        Yii::$app->response->statusCode = (int) $statusCode;
        $this->layout = false;

        $storeName = defined('STORE_NAME') ? (string) STORE_NAME : '';
        $e = function ($s) {
            return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $html = '<!DOCTYPE html>
		<html lang="en">
		<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex, nofollow">
		<title>' . $e($title) . ($storeName !== '' ? ' - ' . $e($storeName) : '') . '</title>
		<style>
		  :root { --bg:#f4f6f9; --card:#ffffff; --text:#1f2933; --muted:#5f6b7a; --red:#d93025; --red-bg:#fdecea; --btn:#1f2933; --btn-text:#ffffff; --shadow:0 10px 30px rgba(15,23,42,.10); }
		  @media (prefers-color-scheme: dark) {
			:root { --bg:#12161c; --card:#1b212a; --text:#eef1f5; --muted:#a3adba; --red:#ff6b61; --red-bg:#3a1f1d; --btn:#eef1f5; --btn-text:#12161c; --shadow:0 10px 30px rgba(0,0,0,.5); }
		  }
		  * { box-sizing:border-box; }
		  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px; background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; line-height:1.5; }
		  .card { width:100%; max-width:460px; background:var(--card); border-radius:16px; box-shadow:var(--shadow); padding:40px 32px 32px; text-align:center; }
		  .icon { width:84px; height:84px; margin:0 auto 20px; border-radius:50%; background:var(--red-bg); display:flex; align-items:center; justify-content:center; }
		  .icon svg { width:44px; height:44px; }
		  h1 { margin:0 0 10px; font-size:24px; font-weight:700; }
		  p { margin:0 0 28px; font-size:16px; color:var(--muted); overflow-wrap:anywhere; }
		  .btn { display:inline-block; padding:13px 28px; border-radius:10px; background:var(--btn); color:var(--btn-text); font-size:16px; font-weight:600; text-decoration:none; }
		  .btn:hover, .btn:focus { opacity:.88; }
		  .store { margin-top:24px; font-size:13px; color:var(--muted); }
		</style>
		</head>
		<body>
		  <main class="card" role="alert">
			<div class="icon" aria-hidden="true">
			  <svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
				<line x1="5" y1="5" x2="19" y2="19"></line>
				<line x1="19" y1="5" x2="5" y2="19"></line>
			  </svg>
			</div>
			<h1>' . $e($title) . '</h1>
			<p>' . $e($message) . '</p>
			<a class="btn" href="' . $e($this->homeUrl()) . '">Return to home</a>
		  </main>
		</body>
		</html>';

        return $this->renderContent($html);
    }

    /**
     * Cointopay "Transactiondetail" API call (same request as the old module).
     */
    private function transactionDetail($merchantId, $confirmCode)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://app.cointopay.com/v2REAPI?',
            CURLOPT_POSTFIELDS     => http_build_query([
                'Call'        => 'Transactiondetail',
                'MerchantID'  => $merchantId,
                'output'      => 'json',
                'ConfirmCode' => $confirmCode,
                'APIKey'      => 'a', // value is not checked by this call
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['authentication:1', 'cache-control: no-cache'],
            CURLOPT_USERAGENT      => 'Cointopay - osCommerce 4 Extension',
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response === false ? null : json_decode($response, true);
    }
}