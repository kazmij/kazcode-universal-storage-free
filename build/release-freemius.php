#!/usr/bin/env php
<?php
declare(strict_types=1);

function usage(): void {
	fwrite(STDERR, <<<TXT
Usage:
  php build/release-freemius.php upload --version VERSION --zip ZIP [--mode pending|beta|released] [--dry-run]
  php build/release-freemius.php status --version VERSION
  php build/release-freemius.php status --tag-id ID
  php build/release-freemius.php promote --tag-id ID --mode beta|released [--dry-run]
  php build/release-freemius.php download --tag-id ID --output ZIP

Required env for API calls:
  KAZUS_FREEMIUS_PRODUCT_ID
  KAZUS_FREEMIUS_BEARER_TOKEN

Side effects:
  upload creates a Freemius Deployment. WordPress plugin uploads are created
  pending first; non-pending modes are applied by a follow-up promote request.
  promote changes the deployment release_mode. Use --dry-run to avoid mutation.

TXT);
}

function options(array $args): array {
	$out = array('_' => array());
	for ($i = 0; $i < count($args); $i++) {
		if (str_starts_with($args[$i], '--')) {
			$key = substr($args[$i], 2);
			$value = $args[$i + 1] ?? null;
			if ($value === null || str_starts_with($value, '--')) {
				$out[$key] = true;
			} else {
				$out[$key] = $value;
				$i++;
			}
		} else {
			$out['_'][] = $args[$i];
		}
	}
	return $out;
}

function fail(string $message): int {
	fwrite(STDERR, "FAIL: {$message}\n");
	return 1;
}

function env_required(string $name): string {
	$value = getenv($name);
	if (!is_string($value) || $value === '') {
		throw new RuntimeException("missing {$name}");
	}
	return $value;
}

function api(string $method, string $path, array $fields = array(), ?string $download = null): array {
	if (!extension_loaded('curl')) {
		throw new RuntimeException('PHP curl extension is required for Freemius API calls.');
	}
	$product = env_required('KAZUS_FREEMIUS_PRODUCT_ID');
	$token = env_required('KAZUS_FREEMIUS_BEARER_TOKEN');
	$ch = curl_init('https://api.freemius.com/v1/products/' . rawurlencode($product) . $path);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $token));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 120);
	if ($download !== null) {
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	}
	if ($fields !== array()) {
		if ($method === 'PUT') {
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . $token, 'Content-Type: application/json'));
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields, JSON_THROW_ON_ERROR));
		} else {
			curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
		}
	}
	$data = curl_exec($ch);
	$code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$error = curl_error($ch);
	curl_close($ch);
	if ($data === false || $code < 200 || $code >= 300) {
		throw new RuntimeException('Freemius API ' . $method . ' ' . $path . " failed HTTP {$code}" . ($error !== '' ? ': ' . $error : ''));
	}
	if ($download !== null) {
		$directory = dirname($download);
		if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
			throw new RuntimeException('unable to create download directory: ' . $directory);
		}
		if (false === file_put_contents($download, (string) $data)) {
			throw new RuntimeException('unable to write downloaded Freemius artifact: ' . $download);
		}
		return array('http' => $code, 'download' => $download);
	}
	$json = json_decode((string) $data, true);
	if (!is_array($json)) {
		throw new RuntimeException('Freemius API response was not JSON.');
	}
	return $json;
}

function find_tag_by_version(array $payload, string $version): ?array {
	foreach (($payload['tags'] ?? array()) as $tag) {
		if (is_array($tag) && (string) ($tag['version'] ?? '') === $version) {
			return $tag;
		}
	}
	return null;
}

function find_tag_by_id(array $payload, string $id): ?array {
	foreach (($payload['tags'] ?? array()) as $tag) {
		if (is_array($tag) && (string) ($tag['id'] ?? $tag['tag_id'] ?? '') === $id) {
			return $tag;
		}
	}
	return null;
}

function extract_tag_id(array $payload): string {
	$id = $payload['id'] ?? $payload['tag_id'] ?? null;
	if (!is_scalar($id) || (string) $id === '') {
		throw new RuntimeException('Freemius upload response did not include a deployment id.');
	}
	return (string) $id;
}

array_shift($argv);
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
	usage();
	exit(0);
}
$command = array_shift($argv);
$opt = options($argv);
$dry = isset($opt['dry-run']);

try {
	switch ($command) {
		case 'upload':
			$version = (string) ($opt['version'] ?? '');
			$zip = (string) ($opt['zip'] ?? '');
			$mode = (string) ($opt['mode'] ?? 'pending');
			if ($version === '' || $zip === '' || !is_file($zip)) {
				usage();
				exit(2);
			}
			if (!in_array($mode, array('pending', 'beta', 'released'), true)) {
				exit(fail('mode must be pending, beta, or released.'));
			}
			if ($dry) {
				echo "DRY RUN: would check Freemius for duplicate version {$version} and upload {$zip} as mode={$mode}\n";
				exit(0);
			}
			$existing = api('GET', '/tags.json?count=50');
			if (find_tag_by_version($existing, $version) !== null) {
				exit(fail("Freemius deployment {$version} already exists; refusing duplicate upload."));
			}
			$payload = api('POST', '/tags.json', array(
				'file' => new CURLFile($zip, 'application/zip', basename($zip)),
			));
			if ((string) ($payload['version'] ?? $version) !== $version) {
				throw new RuntimeException('Freemius upload response version did not match requested version.');
			}
			if ($mode !== 'pending') {
				$tag_id = extract_tag_id($payload);
				$payload = api('PUT', '/tags/' . rawurlencode($tag_id) . '.json', array('release_mode' => $mode));
				if ((string) ($payload['release_mode'] ?? '') !== $mode) {
					throw new RuntimeException('Freemius promotion response did not match requested release_mode.');
				}
			}
			echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
			exit(0);

		case 'status':
			$version = (string) ($opt['version'] ?? '');
			$tag_id = (string) ($opt['tag-id'] ?? '');
			if ($version === '' && $tag_id === '') {
				usage();
				exit(2);
			}
			if ($tag_id !== '') {
				$payload = api('GET', '/tags.json?count=50');
				$tag = find_tag_by_id($payload, $tag_id);
				if ($tag === null) {
					exit(fail("Freemius deployment tag {$tag_id} not found."));
				}
			} else {
				$payload = api('GET', '/tags.json?count=50');
				$tag = find_tag_by_version($payload, $version);
				if ($tag === null) {
					exit(fail("Freemius deployment {$version} not found."));
				}
			}
			echo json_encode($tag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
			exit(0);

		case 'promote':
			$tag_id = (string) ($opt['tag-id'] ?? '');
			$mode = (string) ($opt['mode'] ?? '');
			if ($tag_id === '' || !in_array($mode, array('beta', 'released'), true)) {
				usage();
				exit(2);
			}
			if ($dry) {
				echo "DRY RUN: would set Freemius tag {$tag_id} release_mode={$mode}\n";
				exit(0);
			}
			$payload = api('PUT', '/tags/' . rawurlencode($tag_id) . '.json', array('release_mode' => $mode));
			if ((string) ($payload['release_mode'] ?? '') !== $mode) {
				throw new RuntimeException('Freemius promotion response did not match requested release_mode.');
			}
			echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
			exit(0);

		case 'download':
			$tag_id = (string) ($opt['tag-id'] ?? '');
			$output = (string) ($opt['output'] ?? '');
			if ($tag_id === '' || $output === '') {
				usage();
				exit(2);
			}
			$result = api('GET', '/tags/' . rawurlencode($tag_id) . '.zip?is_premium=true', array(), $output);
			echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
			exit(0);

		default:
			usage();
			exit(2);
	}
} catch (Throwable $e) {
	exit(fail($e->getMessage()));
}
