<?php
/**
 * BEST File Generator (Komerční banka bulk payment format)
 *
 * Receives a JSON array of e-shop orders via POST, filters those with status "refunded",
 * and outputs a BEST file that KB MojeBanka Business can import as a batch payment order.
 *
 * Endpoint: POST /api/generate.php
 * Input:    JSON array of order objects
 * Output:   text/plain file download
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// Only POST is accepted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Validate that the decoded payload is a JSON array of orders
if (!is_array($data) || (count($data) > 0 && !isset($data[0]))) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON structure: array of orders expected.']);
    exit;
}

/**
 * Calculate the next business day from the given date.
 * Skips weekends and fixed Czech public holidays, including
 * moveable Easter holidays (Good Friday & Easter Monday).
 *
 * @param  \DateTime $date  Reference date
 * @return \DateTime        The next business day
 */
function getNextBusinessDay(\DateTime $date): \DateTime {
    // Fixed Czech public holidays (month-day)
    $holidays = [
        '01-01', '05-01', '05-08', '07-05', '07-06',
        '09-28', '10-28', '11-17', '12-24', '12-25', '12-26'
    ];

    $d = clone $date;
    $d->modify('+1 day'); // Start searching from the day after

    // Pre-compute Easter holidays and recalculate only if the year changes
    $cachedYear = null;
    $goodFridayStr = '';
    $easterMondayStr = '';

    while (true) {
        $dayOfWeek = $d->format('N');
        $isWeekend = ($dayOfWeek >= 6);
        $md = $d->format('m-d');

        // Recalculate Easter holidays only when the year changes
        $year = (int)$d->format('Y');
        if ($year !== $cachedYear) {
            $cachedYear = $year;
            $easterDate = new \DateTime("$year-03-21");
            $easterDate->modify('+' . easter_days_pure($year) . ' days');

            $goodFriday = clone $easterDate;
            $goodFriday->modify('-2 days');
            $goodFridayStr = $goodFriday->format('Y-m-d');

            $easterMonday = clone $easterDate;
            $easterMonday->modify('+1 day');
            $easterMondayStr = $easterMonday->format('Y-m-d');
        }

        $currentDate = $d->format('Y-m-d');
        $isHoliday = in_array($md, $holidays) ||
                     $currentDate === $goodFridayStr ||
                     $currentDate === $easterMondayStr;

        if (!$isWeekend && !$isHoliday) {
            return $d;
        }
        $d->modify('+1 day');
    }
}

/**
 * Pure PHP replacement for easter_days(). 
 * Had issues with the calendar extension on Railway deployment.
 * Uses the Anonymous Gregorian algorithm.
 * Essentially the same as the easter_days() function, but without the calendar extension.
 * (This is why I don't like PHP)
 * 
 * @param int $year  The year to calculate Easter for
 * @return int       The number of days between March 21st and Easter Sunday
 */
function easter_days_pure(int $year): int {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;

    $easter = new \DateTime("$year-$month-$day");
    $march21 = new \DateTime("$year-03-21");
    return (int)$march21->diff($easter)->days;
}

/**
 * Produce a SWIFT/BEST-safe ASCII string from arbitrary UTF-8 input.
 * Uses a tiered fallback: intl Transliterator > iconv > manual Czech map.
 * (Had some issues with my linux setup, so I added fallbacks)
 *
 * Allowed output characters (SWIFT set): a-z A-Z 0-9 / - ? : ( ) . , ' + space
 *
 * @param  string $string  Input (UTF-8)
 * @return string          SWIFT-safe ASCII output
 */
function toSwiftSafe(string $string): string {
    if ($string === '') return '';

    // Tier 1: intl Transliterator (locale-independent)
    if (class_exists('\Transliterator')) {
        $t = \Transliterator::createFromRules(
            ':: Any-Latin; :: Latin-ASCII; :: NFD; :: [:Nonspacing Mark:] Remove; :: NFC;',
            \Transliterator::FORWARD
        );
        $string = $t?->transliterate($string) ?? $string;

    // Tier 2: iconv with explicit locale guard
    } elseif (function_exists('iconv')) {
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        $string = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string) ?: $string;

    // Tier 3: manual Czech map
    } else {
        $chars = [
            'á'=>'a', 'č'=>'c', 'ď'=>'d', 'é'=>'e', 'ě'=>'e', 'í'=>'i', 'ň'=>'n', 'ó'=>'o',
            'ř'=>'r', 'š'=>'s', 'ť'=>'t', 'ú'=>'u', 'ů'=>'u', 'ý'=>'y', 'ž'=>'z',
            'Á'=>'A', 'Č'=>'C', 'Ď'=>'D', 'É'=>'E', 'Ě'=>'E', 'Í'=>'I', 'Ň'=>'N', 'Ó'=>'O',
            'Ř'=>'R', 'Š'=>'S', 'Ť'=>'T', 'Ú'=>'U', 'Ů'=>'U', 'Ý'=>'Y', 'Ž'=>'Z'
        ];
        $string = strtr($string, $chars);
    }

    // Keep only SWIFT-allowed characters
    return preg_replace('/[^a-zA-Z0-9 \/\-\?:\(\)\.,\'\+]/', '', $string);
}

/**
 * Build a single fixed-width BEST record line (351 characters).
 * Each field is placed at a specific byte offset with enforced width.
 *
 * @param  array $fields  Associative array [byteOffset => [value, width, padChar, padDir]]
 * @return string         Fixed-width line padded with spaces to 351 chars
 */
function createFixedRecord(array $fields): string {
    $line = str_repeat(' ', 351);

    foreach ($fields as $offset => [$val, $width, $padChar, $padDir]) {
        $valStr = (string)$val;

        // Guard: non-ASCII would corrupt byte offsets
        if (preg_match('/[^\x00-\x7F]/', $valStr)) {
            throw new \InvalidArgumentException(
                "Non-ASCII value at offset $offset: '$valStr'"
            );
        }

        if ($offset + $width > 351) {
            throw new \LengthException(
                "Field at offset $offset (width $width) overflows record boundary."
            );
        }

        // Truncate or pad to exact field width
        $valStr = substr($valStr, 0, $width);
        $valStr = str_pad($valStr, $width, $padChar, $padDir);

        $line = substr_replace($line, $valStr, $offset, $width);
    }

    return $line;
}

// ─── Configuration ──────────────────────────────────────────────────────────

define('PAYER_BANK_CODE', '0100');
define('PAYER_ACCOUNT_NO', '1233791040247');

// ─── Generate BEST file content ─────────────────────────────────────────────

$output = [];
$today = new \DateTime();
$nextBusinessDayDt = getNextBusinessDay($today);
$todayDate = $today->format('Ymd');
$todayShortDate = $today->format('ymd');
$nextBusinessDay = $nextBusinessDayDt->format('Ymd');

// Header record (line type "HI")
$headerFields = [
//  offset => [value,         width, pad,  dir          ]
    0  => ['HI',               2, ' ', STR_PAD_RIGHT],
    2  => ['BEST',             9, ' ', STR_PAD_RIGHT],
    11 => [$todayShortDate,    6, ' ', STR_PAD_RIGHT],
];
$output[] = createFixedRecord($headerFields);

$count = 0;
$totalSum = 0;
$seqNo = 1;
const MAX_SEQ_NO = 99999; // 5-char field width limit

// Pre-filter refunded orders
$refundedOrders = array_filter($data, fn($o) => ($o['status'] ?? '') === 'refunded');

// MojeBanka Business limit: max 400 payments per batch file
// (KB BEST spec – "The number of orders that Direct Banking can process")
if (count($refundedOrders) > 400) {
    http_response_code(400);
    echo json_encode([
        'error' => 'MojeBanka Business limit exceeded: maximum 400 orders per day.'
    ]);
    exit;
}

foreach ($refundedOrders as $order) {

    $orderId = $order['order_id'] ?? null;
    if (!$orderId) {
        continue;
    }

    // Variable symbol must be purely numeric (max 10 digits)
    $vsOrderId = preg_replace('/[^0-9]/', '', (string)$orderId);
    if ($vsOrderId === '') {
        continue;
    }

    $customer = $order['customer'] ?? [];
    $billing = $customer['billing_information'] ?? [];
    $refundInfo = $customer['refund_information'] ?? [];

    // Calculate total amount in cents to avoid floating-point precision issues
    $totalAmountCents = 0;

    if (isset($order['products']) && is_array($order['products'])) {
        foreach ($order['products'] as $product) {
            $totalAmountCents += (int)round(($product['price'] * $product['quantity']) * 100);
        }
    }

    if (isset($order['fees']) && is_array($order['fees'])) {
        foreach ($order['fees'] as $fee) {
            $totalAmountCents += (int)round($fee['price'] * 100);
        }
    }

    // Skip zero or negative amounts
    if ($totalAmountCents <= 0) {
        continue;
    }

    // Skip orders without a valid refund account number (must be digits only)
    if (empty($refundInfo['account_number'])) {
        continue;
    }

    $accountNumber = (string)$refundInfo['account_number'];

    if (!ctype_digit($accountNumber) || strlen($accountNumber) > 16) {
        continue;
    }

    $routingNumber = isset($refundInfo['routing_number']) ? (string)$refundInfo['routing_number'] : '';
    // Must be a valid bank code (1-4 digits only)
    if (!ctype_digit($routingNumber) || strlen($routingNumber) > 4) {
        continue;
    }

    // Amount string: $totalAmountCents already represents value x 100
    $amountStr = (string)$totalAmountCents;

    // Message for recipient
    $zpravaProPrijemce = toSwiftSafe("Creepy Studio - vraceni obj. c. " . $orderId);

    // Build partner description from billing name (last name + first name, max 30 chars)
    $firstName = toSwiftSafe($billing['first_name'] ?? '');
    $lastName = toSwiftSafe($billing['last_name'] ?? '');

    // Guard: ensure last name doesn't consume the entire 30-char slot
    $lastNameLen = strlen($lastName);
    $spaceLeft = 30 - $lastNameLen - 1; // -1 for the space separator
    $trimmedFirstName = $spaceLeft > 0 ? substr($firstName, 0, $spaceLeft) : '';

    $popisPartneraText = trim(substr($lastName, 0, 30));
    if ($trimmedFirstName !== '') {
        $popisPartneraText = trim($popisPartneraText . ' ' . $trimmedFirstName);
    }

    // Internal description ("Popis pro mě")
    $popisMe = toSwiftSafe('Vraceni obj. c. ' . $orderId);

    // Build the fixed-width detail record (line type "01")
    $rowFields = [
    //  offset => [value,              width, pad,  dir          ]
        0   => ['01',                   2, ' ', STR_PAD_RIGHT],
        2   => [$seqNo,                 5, '0', STR_PAD_LEFT ],
        7   => [$todayDate,             8, ' ', STR_PAD_RIGHT],
        15  => [$nextBusinessDay,        8, ' ', STR_PAD_RIGHT],
        23  => ['CZK',                  3, ' ', STR_PAD_RIGHT],
        26  => [$amountStr,            15, '0', STR_PAD_LEFT ],
        41  => ['0',                    1, ' ', STR_PAD_RIGHT],
        56  => [$zpravaProPrijemce,    140, ' ', STR_PAD_RIGHT],
        199 => [PAYER_BANK_CODE,        4, '0', STR_PAD_LEFT ],
        203 => [PAYER_ACCOUNT_NO,      16, '0', STR_PAD_LEFT ],
        239 => [$popisMe,              30, ' ', STR_PAD_RIGHT],
        272 => [$routingNumber,          4, '0', STR_PAD_LEFT ],
        276 => [$accountNumber,         16, '0', STR_PAD_LEFT ],
        292 => [$vsOrderId,            10, '0', STR_PAD_LEFT ],
        312 => [$popisPartneraText,     30, ' ', STR_PAD_RIGHT],
    ];

    $output[] = createFixedRecord($rowFields);
    $count++;
    $totalSum += $totalAmountCents;
    $seqNo++;

    if ($seqNo > MAX_SEQ_NO) {
        throw new \OverflowException('Sequence number exceeded ' . MAX_SEQ_NO . ' records.');
    }
}

// If no refunded orders were found, return an error
if ($count === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No refunded orders found in the provided JSON.']);
    exit;
}

// Footer record (line type "TI")
$footerFields = [
//  offset => [value,         width, pad,  dir          ]
    0  => ['TI',               2, ' ', STR_PAD_RIGHT],
    2  => ['BEST',             9, ' ', STR_PAD_RIGHT],
    11 => [$todayShortDate,    6, ' ', STR_PAD_RIGHT],
    17 => [$count,             6, '0', STR_PAD_LEFT ],
    23 => [$totalSum,         18, '0', STR_PAD_LEFT ],
];
$output[] = createFixedRecord($footerFields);

// Join all records with CRLF line endings as required by the BEST specification
$fileContent = implode("\r\n", $output) . "\r\n";

// toSwiftSafe guarantees pure ASCII output, which is a subset of Windows-1250
// No character conversion needed

// Send the file as a download
header('Content-Type: text/plain; charset=windows-1250');
header('Content-Disposition: attachment; filename="platby_kb.best"');
header('Content-Length: ' . strlen($fileContent));

echo $fileContent;
