<?php
/**
 * Repair Composer PSR-4 maps after PHP-Scoper.
 *
 * PHP-Scoper rewrites dependency files and classmap entries, but Composer's
 * generated autoload_static.php is kept in the excluded Composer namespace.
 * Its PSR-4 prefixes can therefore remain unscoped and route global Aws,
 * GuzzleHttp, Psr, JmesPath, and Symfony class requests to KAZCODE's scoped
 * files. In a WordPress process with another global Composer tree, that can
 * load the same scoped file twice under incompatible class names.
 *
 * @package Kazcode\WpStorage
 */

declare(strict_types=1);

$root = $argv[1] ?? '';
if ('' === $root || !is_dir($root)) {
	fwrite(STDERR, "Usage: php repair-scoped-composer-autoload.php path/to/plugin-root\n");
	exit(1);
}

$composer_dir = rtrim($root, '/\\') . '/vendor/composer';
$static_file  = $composer_dir . '/autoload_static.php';
$psr4_file    = $composer_dir . '/autoload_psr4.php';
$files_file   = $composer_dir . '/autoload_files.php';

if (!is_file($static_file) || !is_file($psr4_file) || !is_file($files_file)) {
	fwrite(STDERR, "Composer autoload files are missing under {$composer_dir}\n");
	exit(1);
}

$autoload_files = array(
	'kazus_a4a119a56e50fbb293281d9a48007e0e' => array(
		'static' => "__DIR__ . '/..' . '/symfony/polyfill-php80/bootstrap.php'",
		'files'  => "\$vendorDir . '/symfony/polyfill-php80/bootstrap.php'",
	),
	'kazus_5897ea0ac4cccf14d323035e65887801' => array(
		'static' => "__DIR__ . '/..' . '/symfony/polyfill-php82/bootstrap.php'",
		'files'  => "\$vendorDir . '/symfony/polyfill-php82/bootstrap.php'",
	),
	'kazus_0e6d7bf4a5811bfa5cf40c5ccd6fae6a' => array(
		'static' => "__DIR__ . '/..' . '/symfony/polyfill-mbstring/bootstrap.php'",
		'files'  => "\$vendorDir . '/symfony/polyfill-mbstring/bootstrap.php'",
	),
	'kazus_320cde22f66dd4f5d3fd621d3e88b98f' => array(
		'static' => "__DIR__ . '/..' . '/symfony/polyfill-ctype/bootstrap.php'",
		'files'  => "\$vendorDir . '/symfony/polyfill-ctype/bootstrap.php'",
	),
	'kazus_b067bc7112e384b61c701452d53a14a8' => array(
		'static' => "__DIR__ . '/..' . '/mtdowling/jmespath.php/src/JmesPath.php'",
		'files'  => "\$vendorDir . '/mtdowling/jmespath.php/src/JmesPath.php'",
	),
	'kazus_8a9dc1de0ca7e01f3e08231539562f61' => array(
		'static' => "__DIR__ . '/..' . '/aws/aws-sdk-php/src/functions.php'",
		'files'  => "\$vendorDir . '/aws/aws-sdk-php/src/functions.php'",
	),
);

$prefix_dirs = array(
	'Kazcode\\WpStorage\\Vendor\\Aws\\'                           => "__DIR__ . '/..' . '/aws/aws-sdk-php/src'",
	'Kazcode\\WpStorage\\Vendor\\GuzzleHttp\\'                    => "__DIR__ . '/..' . '/guzzlehttp/guzzle/src'",
	'Kazcode\\WpStorage\\Vendor\\GuzzleHttp\\Promise\\'           => "__DIR__ . '/..' . '/guzzlehttp/promises/src'",
	'Kazcode\\WpStorage\\Vendor\\GuzzleHttp\\Psr7\\'              => "__DIR__ . '/..' . '/guzzlehttp/psr7/src'",
	'Kazcode\\WpStorage\\Vendor\\JmesPath\\'                      => "__DIR__ . '/..' . '/mtdowling/jmespath.php/src'",
	'Kazcode\\WpStorage\\Vendor\\Psr\\Http\\Client\\'             => "__DIR__ . '/..' . '/psr/http-client/src'",
	'Kazcode\\WpStorage\\Vendor\\Psr\\Http\\Message\\'            => array(
		"__DIR__ . '/..' . '/psr/http-factory/src'",
		"__DIR__ . '/..' . '/psr/http-message/src'",
	),
	'Kazcode\\WpStorage\\Vendor\\Symfony\\Component\\Filesystem\\' => "__DIR__ . '/..' . '/symfony/filesystem'",
	'Kazcode\\WpStorage\\Vendor\\Symfony\\Polyfill\\Ctype\\'       => "__DIR__ . '/..' . '/symfony/polyfill-ctype'",
	'Kazcode\\WpStorage\\Vendor\\Symfony\\Polyfill\\Mbstring\\'    => "__DIR__ . '/..' . '/symfony/polyfill-mbstring'",
	'Kazcode\\WpStorage\\Vendor\\Symfony\\Polyfill\\Php80\\'       => "__DIR__ . '/..' . '/symfony/polyfill-php80'",
	'Kazcode\\WpStorage\\Vendor\\Symfony\\Polyfill\\Php82\\'       => "__DIR__ . '/..' . '/symfony/polyfill-php82'",
	'Kazcode\\WpStorage\\'                                         => "__DIR__ . '/../..' . '/includes'",
);
ksort($prefix_dirs);

$prefix_lengths = array();
foreach (array_keys($prefix_dirs) as $prefix) {
	$prefix_lengths[$prefix[0]][$prefix] = strlen($prefix);
}
ksort($prefix_lengths);
foreach ($prefix_lengths as &$group) {
	ksort($group);
}
unset($group);

$quote = static function (string $value): string {
	return var_export($value, true);
};

$render_lengths = static function (array $groups) use ($quote): string {
	$lines = array("array(");
	foreach ($groups as $letter => $prefixes) {
		$lines[] = "\t\t" . $quote((string) $letter) . ' => array(';
		foreach ($prefixes as $prefix => $length) {
			$lines[] = "\t\t\t" . $quote((string) $prefix) . ' => ' . (int) $length . ',';
		}
		$lines[] = "\t\t),";
	}
	$lines[] = "\t)";
	return implode("\n", $lines);
};

$render_static_dirs = static function (array $dirs) use ($quote): string {
	$lines = array("array(");
	foreach ($dirs as $prefix => $paths) {
		$paths = is_array($paths) ? $paths : array($paths);
		$lines[] = "\t\t" . $quote((string) $prefix) . ' => array(';
		foreach ($paths as $path) {
			$lines[] = "\t\t\t" . $path . ',';
		}
		$lines[] = "\t\t),";
	}
	$lines[] = "\t)";
	return implode("\n", $lines);
};

$render_psr4_dirs = static function (array $dirs) use ($quote): string {
	$lines = array("array(");
	foreach ($dirs as $prefix => $paths) {
		$paths = is_array($paths) ? $paths : array($paths);
		$lines[] = '    ' . $quote((string) $prefix) . ' => array(';
		foreach ($paths as $path) {
			$lines[] = '        ' . str_replace("__DIR__ . '/..'", '$vendorDir', str_replace("__DIR__ . '/../..'", '$baseDir', $path)) . ',';
		}
		$lines[] = '    ),';
	}
	$lines[] = ')';
	return implode("\n", $lines);
};

$render_static_files = static function (array $files) use ($quote): string {
	$lines = array("array(");
	foreach ($files as $identifier => $paths) {
		$lines[] = "\t\t" . $quote((string) $identifier) . ' => ' . $paths['static'] . ',';
	}
	$lines[] = "\t)";
	return implode("\n", $lines);
};

$render_autoload_files = static function (array $files) use ($quote): string {
	$lines = array("array(");
	foreach ($files as $identifier => $paths) {
		$lines[] = '    ' . $quote((string) $identifier) . ' => ' . $paths['files'] . ',';
	}
	$lines[] = ')';
	return implode("\n", $lines);
};

$static_contents = file_get_contents($static_file);
if (false === $static_contents) {
	fwrite(STDERR, "Could not read {$static_file}\n");
	exit(1);
}

$static_contents = preg_replace_callback(
	'/public static \$files = .*?;\s+public static \$prefixLengthsPsr4 = /s',
	static function () use ($render_static_files, $autoload_files): string {
		return "public static \$files = " . $render_static_files($autoload_files) . ";\n\n\tpublic static \$prefixLengthsPsr4 = ";
	},
	$static_contents,
	1,
	$file_replacements
);

$static_contents = preg_replace_callback(
	'/public static \$prefixLengthsPsr4 = .*?;\s+public static \$prefixDirsPsr4 = /s',
	static function () use ($render_lengths, $prefix_lengths): string {
		return "public static \$prefixLengthsPsr4 = " . $render_lengths($prefix_lengths) . ";\n\n\tpublic static \$prefixDirsPsr4 = ";
	},
	$static_contents,
	1,
	$length_replacements
);

$static_contents = preg_replace_callback(
	'/public static \$prefixDirsPsr4 = .*?;\s+public static \$classMap = /s',
	static function () use ($render_static_dirs, $prefix_dirs): string {
		return "public static \$prefixDirsPsr4 = " . $render_static_dirs($prefix_dirs) . ";\n\n\tpublic static \$classMap = ";
	},
	$static_contents,
	1,
	$dir_replacements
);

if (1 !== $file_replacements || 1 !== $length_replacements || 1 !== $dir_replacements) {
	fwrite(STDERR, "Could not replace Composer PSR-4 maps in {$static_file}\n");
	exit(1);
}

$psr4_contents = "<?php\n\n// autoload_psr4.php @generated by Composer; repaired after PHP-Scoper for scoped PSR-4 prefixes.\n\n";
$psr4_contents .= "\$vendorDir = dirname(__DIR__);\n";
$psr4_contents .= "\$baseDir = dirname(\$vendorDir);\n\n";
$psr4_contents .= 'return ' . $render_psr4_dirs($prefix_dirs) . ";\n";

$files_contents = "<?php\n\n// autoload_files.php @generated by Composer; repaired after PHP-Scoper with KAZCODE-local file identifiers.\n\n";
$files_contents .= "\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn " . $render_autoload_files($autoload_files) . ";\n";

file_put_contents($static_file, $static_contents);
file_put_contents($psr4_file, $psr4_contents);
file_put_contents($files_file, $files_contents);
