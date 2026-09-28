<?php
namespace Jankx\Extensions\GenerateVietqr;

use Jankx\Extensions\AbstractExtension;

class GenerateVietqrExtension extends AbstractExtension
{
    protected static $instance;

    public function __construct()
    {
        $this->register_autoloader();
        parent::__construct();
    }

    protected function register_autoloader()
    {
        spl_autoload_register(function ($class) {
            $prefix = 'Jankx\\Extensions\\GenerateVietqr\\';
            $base_dir = __DIR__ . '/src/';
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) !== 0) {
                return;
            }
            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
            if (file_exists($file)) {
                require $file;
            }
        });
    }

    public function init(): void
    {
        self::$instance = $this;
    }

    public static function get_instance(): ?self
    {
        return self::$instance;
    }

    public function register_hooks(): void
    {
        add_filter('jankx/ecommerce/order_detail/after_payment_info', [$this, 'renderQrCode'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, '/tai-khoan-cua-toi/') === false) {
            return;
        }

        wp_enqueue_style(
            'jankx-vietqr',
            trailingslashit($this->get_extension_url()) . 'assets/vietqr.css',
            [],
            '1.0.0'
        );
    }

    public function renderQrCode(string $content, $order): string
    {
        $gateway = $order->getPaymentMethod();
        if ($gateway === 'qrviet') {
            // The qrviet gateway renders its own dynamic QR card (hooked at
            // priority 20). Only provide the static VietQR fallback when that
            // card cannot be shown (missing transaction or QR image).
            if ($this->qrvietHasDynamicQr($order)) {
                return $content;
            }
        } elseif ($gateway !== 'bank_transfer') {
            return $content;
        }

        $status = $order->getStatus();
        if (!in_array($status, ['pending', 'processing'], true)) {
            return $content;
        }

        $bankConfig = get_option('jankx_built_in_gateway_bank_transfer', []);
        $bankName = $bankConfig['bank_name'] ?? '';
        $accountNumber = $bankConfig['account_number'] ?? '';
        $accountHolder = $bankConfig['account_holder'] ?? '';

        if (empty($bankName) || empty($accountNumber)) {
            return $content;
        }

        $bankBin = $this->getBankBin($bankName);
        if (empty($bankBin)) {
            return $content;
        }

        $amount = (int) $order->getTotal();
        $orderNumber = $order->getOrderNumber();
        $paymentContent = function_exists('Jankx\\Extensions\\Ecommerce\\jankx_payment_content')
            ? \Jankx\Extensions\Ecommerce\jankx_payment_content($order)
            : $orderNumber;
        $accountNameUpper = $this->removeDiacritics(mb_strtoupper($accountHolder, 'UTF-8'));

        $qrUrl = sprintf(
            'https://img.vietqr.io/image/%s-%s-compact.png?amount=%d&addInfo=%s&accountName=%s',
            $bankBin,
            $accountNumber,
            $amount,
            rawurlencode($paymentContent),
            rawurlencode($accountNameUpper)
        );

        $output = '<div class="jankx-od-card jankx-od-card--vietqr">';
        $output .= '<div class="jankx-od-card-head">';
        $output .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="8" height="8" rx="1"/><rect x="14" y="2" width="8" height="8" rx="1"/><rect x="2" y="14" width="8" height="8" rx="1"/><rect x="14" y="14" width="4" height="4" rx="0.5"/><line x1="22" y1="14" x2="22" y2="22"/><line x1="14" y1="22" x2="22" y2="22"/></svg>';
        $output .= '<h3 class="jankx-od-card-title">' . esc_html__('Quét mã VietQR để thanh toán', 'jankx') . '</h3>';
        $output .= '</div>';
        $output .= '<div class="jankx-vietqr-content">';
        $output .= '<div class="jankx-vietqr-image">';
        $output .= '<img src="' . esc_url($qrUrl) . '" alt="VietQR - ' . esc_attr($paymentContent) . '" width="280" height="280" loading="lazy">';
        $output .= '</div>';
        $output .= '<div class="jankx-vietqr-info">';
        $output .= '<p><strong>' . esc_html__('Ngân hàng:', 'jankx') . '</strong> ' . esc_html($bankName) . '</p>';
        $output .= '<p><strong>' . esc_html__('Số TK:', 'jankx') . '</strong> ' . esc_html($accountNumber) . '</p>';
        $output .= '<p><strong>' . esc_html__('Chủ TK:', 'jankx') . '</strong> ' . esc_html($accountHolder) . '</p>';
        $output .= '<p><strong>' . esc_html__('Số tiền:', 'jankx') . '</strong> <span class="jankx-vietqr-amount">' . esc_html(number_format($amount, 0, ',', '.')) . ' ₫</span></p>';
        $output .= '<p><strong>' . esc_html__('Nội dung CK:', 'jankx') . '</strong> <code>' . esc_html($paymentContent) . '</code></p>';
        $output .= '<p class="description">' . esc_html__('Mở ứng dụng ngân hàng → Quét mã QR → Xác nhận thanh toán.', 'jankx') . '</p>';
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';

        return $content . $output;
    }

    /**
     * Whether the qrviet gateway can render its own dynamic QR card
     * (mirrors the conditions in QrVietPaymentGatewayExtension::renderOrderDetailQr).
     */
    protected function qrvietHasDynamicQr($order): bool
    {
        $txnClass = 'Jankx\Extensions\PaymentSystem\Models\Transaction';
        $transactionId = method_exists($order, 'getPaymentTransactionId') ? $order->getPaymentTransactionId() : 0;
        if (!$transactionId || !class_exists($txnClass)) {
            return false;
        }

        $transaction = new $txnClass((int) $transactionId);

        return $transaction->getId() && $transaction->getMeta('_qr_image') !== '';
    }

    protected function getBankBin(string $bankName): string
    {
        $bins = [
            'Vietcombank'       => '970436',
            'VCB'               => '970436',
            'BIDV'              => '970418',
            'Agribank'          => '970405',
            'Vietinbank'        => '970415',
            'Techcombank'       => '970407',
            'MB Bank'           => '970422',
            'MB'                => '970422',
            'VPBank'            => '970432',
            'TPBank'            => '970423',
            'Sacombank'         => '970403',
            'ACB'               => '970406',
            'ABBANK'            => '970425',
            'Eximbank'          => '970431',
            'HDBank'            => '970408',
            'MSB'               => '970426',
            'VIB'               => '970404',
            'OceanBank'         => '970443',
            'NCB'               => '970441',
            'SHB'               => '970440',
            'PVcomBank'         => '970410',
            'LienVietPostBank'  => '970435',
            'SeABank'           => '970409',
            'VietCapitalBank'   => '970433',
            'BAOVIET Bank'      => '970434',
            'PG Bank'           => '970430',
            'Indovina Bank'     => '970437',
            'HongLeong Bank'    => '970442',
            'Woori Bank'        => '970448',
            'Kien Long Bank'    => '970452',
            'GP Bank'           => '970438',
        ];

        $normalized = $this->removeDiacritics(mb_strtolower($bankName, 'UTF-8'));

        // Exact match first
        foreach ($bins as $name => $bin) {
            $normalizedName = mb_strtolower($name, 'UTF-8');
            if ($normalized === $normalizedName) {
                return $bin;
            }
        }

        // Partial match (either direction)
        foreach ($bins as $name => $bin) {
            $normalizedName = mb_strtolower($name, 'UTF-8');
            if (mb_strpos($normalized, $normalizedName) !== false || mb_strpos($normalizedName, $normalized) !== false) {
                return $bin;
            }
        }

        // Fuzzy: check if first 5 chars match (handles common typos like "vietcomhank" → "vietcombank")
        foreach ($bins as $name => $bin) {
            $normalizedName = mb_strtolower($name, 'UTF-8');
            if (mb_substr($normalized, 0, 5) === mb_substr($normalizedName, 0, 5) && mb_strlen($normalized) >= 5) {
                return $bin;
            }
        }

        return '';
    }

    protected function removeDiacritics(string $str): string
    {
        $str = str_replace(
            ['à','á','ả','ã','ạ','ầ','ấ','ẩ','ẫ','ậ','ằ','ắ','ẳ','ẵ','ặ','è','é','ẻ','ẽ','ẹ','ề','ế','ể','ễ','ệ','ì','í','ỉ','ĩ','ị','ò','ó','ỏ','õ','ọ','ồ','ố','ổ','ỗ','ộ','ờ','ớ','ở','ỡ','ợ','ù','ú','ủ','ũ','ụ','ừ','ứ','ử','ữ','ự','ỳ','ý','ỷ','ỹ','ỵ','đ'],
            ['a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','e','e','e','e','e','e','e','e','e','e','i','i','i','i','i','o','o','o','o','o','o','o','o','o','o','o','o','o','o','u','u','u','u','u','u','u','u','u','y','y','y','y','y','d'],
            $str
        );
        return $str;
    }
}
