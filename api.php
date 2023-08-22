<?php
use Illuminate\Database\Capsule\Manager as DB;
require_once '../init.php';

define("CONTENT_TYPE", "application/json");
define("CHARSET", "UTF-8");
define("TOKEN", "HASH");
define("CURRENCY", "TRY");
define("CURRENCY_RATE", 1);
define("ORDER_PREFIX", "INV-");
define("PRODUCT_CODE_PREFIX", "SRV-");
define("PRODUCT_NAME_PREFIX", "Sunucu Hizmeti - ");
define("CREDIT_PRODUCT_ID", 999999);
define("CREDIT_PRODUCT_CODE", PRODUCT_CODE_PREFIX . "CREDIT");


function token() {
    $headers = apache_request_headers();

    if (isset($headers['token'])) {
        return $headers['token'];
    } else if (isset($headers['Token'])) {
        return $headers['Token'];
    } else {
        return null;
    }
}

function request() {
    return json_decode(file_get_contents("php://input"), true) ?? [];
}

function response($code, $body = []) {
    http_response_code($code);
    header("Content-Type: " . CONTENT_TYPE);
    header("Charset: " . CHARSET);
    echo json_encode($body);
}

function orderStatus() {
    return [
        'OrderStatus' => [
            [
                'Id' => 1,
                'Value' => "Paid",
            ],
            [
                'Id' => 2,
                'Value' => "Unpaid",
            ],
            [
                'Id' => 3,
                'Value' => "Refunded",
            ],
            [
                'Id' => 4,
                'Value' => "Cancelled",
            ]
        ]
    ];
}

function status() {
    global $request;
    $status = orderStatus();
    $statusIndex = array_search($request['orderStatusId'], array_column($status['OrderStatus'], "Id"));
    return $status['OrderStatus'][$statusIndex]['Value'];
}

function paymentMethods() {
    return [
        'PaymentMethods' => [
            [
                'Id' => 1,
                'Value' => "PayTR",
            ],
            [
                'Id' => 2,
                'Value' => "Havale-EFT İle Ödendi",
            ],
            [
                'Id' => 3,
                'Value' => "Papara",
            ]
        ]
    ];
}

function paymentMethodId($gateway) {
    $methods = paymentMethods();
    $methodIndex = array_search(paymentMethod($gateway), array_column($methods['PaymentMethods'], "Value"));
    return $methods['PaymentMethods'][$methodIndex]['Id'];
}

function paymentMethod($gateway) {
    switch ($gateway) {
        case 'banktransfer':
            return 'Havale-EFT İle Ödendi';
        case 'paytr':
            return 'PayTR';
        case 'papara':
            return 'Papara';
        default:
            return 'Kredi Kartı İle Ödendi';    
    }
}

function kdv($action, $amount, $rate = 20) {
    if ($action == "add") {
        $data = (float)$amount * (1 + $rate / 100);
        return number_format($data, '2', '.', '');
    } else if ($action == "remove") {
        $data = (float)$amount / (1 + $rate / 100);
        return number_format($data, '2', '.', '');
    } else if ($action == "kdv-excluded") {
        $data = (float)kdv('add', $amount, $rate) - $amount;
        return number_format($data, '2', '.', '');
    } else if ($action == "kdv-included") {
        $data = (float)$amount - kdv('remove', $amount, $rate);
        return number_format($data, '2', '.', '');
    } else {
        return false;
    }
}

function moneyFormat($amount) {
    return number_format($amount, '2', '.', '');
}

if (token() != TOKEN) {
    response(403, ['error' => 'Access forbidden']);
    exit;
}

$endpoint = $_GET['endpoint'] ?? 'orders';

switch ($endpoint) {
    case 'orderStatus':
        response(200, orderStatus());
        break;
    case 'paymentMethods':
        response(200, paymentMethods());
        break;
    case 'orders':
        $request = request();
        $invoiceStartDateTime = date('Y-m-d H:i:s', strtotime($request['startDateTime']));
        $invoiceEndDateTime = date('Y-m-d H:i:s', strtotime($request['endDateTime']));

        # Belirli tarihten önceki faturaların görünmemesini ve fatura kesilmemesini istiyorsanız bu seçeneği aktif edin.
        # Whmcs entegrasyondan, Özel Entegrasyona geçerken kullandım.
        /*if ($invoiceStartDateTime < '2023-01-02 00:00:00') {
            $invoiceStartDateTime = '2023-01-02 00:00:00';
        }*/

        $invoiceStatus = status();

        $orders = [];

        $invoices = DB::table('tblinvoices')
            ->select('tblinvoices.*', 'tblclients.firstname as client_firstname', 'tblclients.lastname as client_lastname', 
                'tblclients.companyname as client_company', 'tblclients.email as client_email', 'tblclients.country as client_country', 
                'tblclients.city as client_city', 'tblclients.state as client_state', 'tblclients.address1 as client_address1', 
                'tblclients.address2 as client_address2', 'tblclients.phonenumber as client_phonenumber')
            ->join('tblclients','tblclients.id', '=', 'tblinvoices.userid')
            ->where('tblinvoices.datepaid', '>=', $invoiceStartDateTime)
            ->where('tblinvoices.datepaid', '<=', $invoiceEndDateTime)
            ->where('tblinvoices.total', '>', 0)
            ->where('tblinvoices.status', '=', $invoiceStatus)
            ->orderByDesc('tblinvoices.datepaid')
            ->get();

        if (!empty($invoices)) {
            foreach ($invoices as $invoice) {
                $usedCredit = $invoice->credit;
                $invoiceTotal = $invoice->total;
                $invoiceSubTotal = $invoice->subtotal;

                if ($invoice->datepaid < "2023-07-10 00:00:00") {
                    $kdvRate = 18;
                } else {
                    $kdvRate = 20;
                }

                $products = [];
                $productsTotal = 0.00;
                $transactionTotal = 0.00;
                $overPaid = 0.00;
                
                # fatura kalemleri
                $invoiceItems = DB::table('tblinvoiceitems')
                    ->where('invoiceid', '=', $invoice->id)
                    ->get();

                # fatura ödemeleri ve iadeleri
                $invoiceTransactions = DB::table('tblaccounts')
                    ->where('invoiceid', '=', $invoice->id)
                    ->get();

                # faturaya yapılan nihai ödeme
                foreach ($invoiceTransactions as $invoiceTransaction) {
                    # ödeme
                    if ($invoiceTransaction->amountin > 0) {
                        $transactionTotal += $invoiceTransaction->amountin;
                    }
                    
                    # iade
                    if ($invoiceTransaction->amountout > 0) {
                        $transactionTotal -= $invoiceTransaction->amountout;
                    }
                }

                # fazla ödeme
                if ($transactionTotal > $invoice->total) {
                    $overPaid = $transactionTotal - $invoice->total;
                    $overPaidTaxExcluded = kdv("remove", $overPaid, $kdvRate);
                }

                # ürünler
                foreach ($invoiceItems as $invoiceItem) {
                    $descriptionExpl = explode("\n", $invoiceItem->description);

                    if (stristr($descriptionExpl[0], 'Bakiye Ekleyin')) {
                        $products[] = [
                            'ProductId' => CREDIT_PRODUCT_ID,
                            'ProductCode' => CREDIT_PRODUCT_CODE,
                            'ProductName' => 'Ön Ödeme',
                            'ProductQuantityType' => 'Adet',
                            'ProductQuantity' => 1,
                            'VatRate' => $kdvRate,
                            'ProductUnitPriceTaxIncluding' => moneyFormat($invoiceItem->amount),
                            'ProductUnitPriceTaxExcluding' => moneyFormat(kdv("remove", $invoiceItem->amount, $kdvRate)),
                        ];
                        
                        $productsTotal += moneyFormat($invoiceItem->amount);
                    } else {
                        $products[] = [
                            'ProductId' => $invoiceItem->id,
                            'ProductCode' => PRODUCT_CODE_PREFIX . $invoiceItem->id, 
                            'ProductName' => PRODUCT_NAME_PREFIX . trim($descriptionExpl[0]),
                            'ProductQuantityType' => 'Adet',
                            'ProductQuantity' => 1,
                            'VatRate' => $kdvRate,
                            'ProductUnitPriceTaxIncluding' => moneyFormat(kdv("add", $invoiceItem->amount, $kdvRate)),
                            'ProductUnitPriceTaxExcluding' => moneyFormat($invoiceItem->amount),
                        ];
                        
                        $productsTotal += moneyFormat(kdv("add", $invoiceItem->amount, $kdvRate));
                    }
                }
                
                # fazla ödenen tutarı ön ödeme olarak tanımlama
                if ($overPaid > 0) {
                    $products[] = [
                        'ProductId' => CREDIT_PRODUCT_ID,
                        'ProductCode' => CREDIT_PRODUCT_CODE,
                        'ProductName' => 'Ön Ödeme',
                        'ProductQuantityType' => 'Adet',
                        'ProductQuantity' => 1,
                        'VatRate' => intval($kdvRate),
                        'ProductUnitPriceTaxIncluding' => moneyFormat($overPaid),
                        'ProductUnitPriceTaxExcluding' => moneyFormat($overPaidTaxExcluded),
                    ];

                    $productsTotal += moneyFormat($overPaid);
                }

                # Siparişler ve yenileme faturaları
                $orders[] = [
                    'OrderId' => $invoice->id,
                    'OrderCode' => ORDER_PREFIX  . $invoice->id,
                    'OrderDate' => date('d.m.Y H:i:s', strtotime($invoice->datepaid)),
                    'InvoiceDate' => date('d.m.Y H:i:s', strtotime($invoice->datepaid)),
                    'CustomerId' => $invoice->userid,
                    'BillingName' => trim(sprintf("%s %s", $invoice->client_firstname, $invoice->client_lastname)),
                    'BillingAddress' => trim(sprintf("%s %s", $invoice->client_address1, $invoice->client_address2)),
                    'BillingTown' => $invoice->client_state,
                    'BillingCity' => $invoice->client_city,
                    'BillingMobilePhone' => str_ireplace(['+', ' ', '.'], '', $invoice->client_phonenumber),
                    'SSNTCNo' => "11111111111",
                    'ShippingName' => trim(sprintf("%s %s", $invoice->client_firstname, $invoice->client_lastname)),
                    'ShippingAddress' => trim(sprintf("%s %s", $invoice->client_address1, $invoice->client_address2)),
                    'ShippingTown' => $invoice->client_state,
                    'ShippingCity' => $invoice->client_city,
                    'Email' => $invoice->client_email,
                    'PaymentTypeId' => paymentMethodId($invoice->paymentmethod),
                    'PaymentType' => paymentMethod($invoice->paymentmethod),
                    'Currency' => CURRENCY,
                    'CurrencyRate' => CURRENCY_RATE,
                    'TotalPaidTaxExcluding' => moneyFormat(kdv('remove', $transactionTotal, $kdvRate)),
                    'TotalPaidTaxIncluding' => moneyFormat($transactionTotal),
                    'ProductsTotalTaxExcluding' => moneyFormat(kdv('remove', $productsTotal, $kdvRate)),
                    'ProductsTotalTaxIncluding' => moneyFormat($productsTotal),
                    'DiscountTotalTaxExcluding' => moneyFormat(kdv('remove', $usedCredit, $kdvRate)),
                    'DiscountTotalTaxIncluding' => moneyFormat($usedCredit),
                    'OrderDetails' => $products,
                ];
            }
        }
    

        response(200, ['Orders' => $orders]);
        break;
        
}
