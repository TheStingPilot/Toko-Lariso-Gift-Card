<?php
/**
 * Manual giftcard remainder calculator.
 *
 * Usage:
 * php tests/calculate-remainder.php --subtotal=10.95 --voucher=10
 * php tests/calculate-remainder.php --subtotal=10,95 --voucher=10,00 --expected=0,95
 */

declare(strict_types=1);

function tlgc_parse_money_to_cents(string $value): int {
	$value = trim($value);
	$value = str_replace(array('EUR', '€', ' '), '', $value);

	if (str_contains($value, ',') && str_contains($value, '.')) {
		$value = str_replace('.', '', $value);
		$value = str_replace(',', '.', $value);
	} else {
		$value = str_replace(',', '.', $value);
	}

	if (! preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value)) {
		fwrite(STDERR, "Invalid money value: {$value}\n");
		exit(1);
	}

	$negative = str_starts_with($value, '-');
	$value    = ltrim($value, '-');
	$parts    = explode('.', $value, 2);
	$euros    = (int) $parts[0];
	$cents    = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
	$total    = ($euros * 100) + (int) substr($cents, 0, 2);

	return $negative ? -$total : $total;
}

function tlgc_format_cents(int $cents): string {
	$prefix = $cents < 0 ? '-' : '';
	$cents  = abs($cents);

	return $prefix . 'EUR ' . number_format($cents / 100, 2, ',', '.');
}

function tlgc_arg(array $options, string $name, ?string $default = null): ?string {
	return isset($options[$name]) && is_string($options[$name]) ? $options[$name] : $default;
}

$options = getopt('', array('subtotal:', 'voucher:', 'expected::', 'help'));

if (isset($options['help']) || ! isset($options['subtotal'], $options['voucher'])) {
	echo "Giftcard remainder calculator\n\n";
	echo "Required:\n";
	echo "  --subtotal  Cart/order subtotal including VAT and shipping, before giftcard.\n";
	echo "  --voucher   Giftcard/voucher amount to apply.\n\n";
	echo "Optional:\n";
	echo "  --expected  Expected remaining amount, useful for replaying scenarios.\n\n";
	echo "Examples:\n";
	echo "  php tests/calculate-remainder.php --subtotal=10.95 --voucher=10\n";
	echo "  php tests/calculate-remainder.php --subtotal=10,95 --voucher=10,00 --expected=0,95\n";
	exit(isset($options['help']) ? 0 : 1);
}

$subtotal_cents = tlgc_parse_money_to_cents((string) $options['subtotal']);
$voucher_cents  = tlgc_parse_money_to_cents((string) $options['voucher']);
$used_cents     = min(max($voucher_cents, 0), max($subtotal_cents, 0));
$remainder      = max(0, $subtotal_cents - $used_cents);
$expected       = tlgc_arg($options, 'expected');

echo "Subtotal incl. VAT : " . tlgc_format_cents($subtotal_cents) . PHP_EOL;
echo "Voucher requested  : " . tlgc_format_cents($voucher_cents) . PHP_EOL;
echo "Voucher applied    : " . tlgc_format_cents($used_cents) . PHP_EOL;
echo "Remaining amount   : " . tlgc_format_cents($remainder) . PHP_EOL;
echo "Mollie amount.value: " . number_format($remainder / 100, 2, '.', '') . PHP_EOL;

if (null !== $expected) {
	$expected_cents = tlgc_parse_money_to_cents($expected);
	$matches        = $expected_cents === $remainder;

	echo "Expected remainder : " . tlgc_format_cents($expected_cents) . PHP_EOL;
	echo "Result             : " . ($matches ? 'PASS' : 'FAIL') . PHP_EOL;

	exit($matches ? 0 : 2);
}
