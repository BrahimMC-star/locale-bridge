<?php

/*
 *
 * Licensed under the Apache License, Version 2.0.
 *
 * You may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * @author Zwuiix
 * @link https://github.com/Zwuiix-cmd/locale-bridge
 *
 */

declare(strict_types=1);

const PARAMETER_REGEX = '/{%(.+?)}/';

enum Style : string
{
	case Reset = "\033[0m";
	case Bold = "\033[1m";
	case Dim = "\033[2m";
	case Red = "\033[31m";
	case Green = "\033[32m";
	case Cyan = "\033[36m";
}

final class CliUsageException extends InvalidArgumentException {}

function supports_color() : bool
{
	static $supportsColor = null;

	if ($supportsColor === null) {
		$supportsColor = getenv('NO_COLOR') === false
			&& function_exists('stream_isatty')
			&& stream_isatty(STDOUT);
	}

	return $supportsColor;
}

function format_text(string $message, Style ...$styles) : string
{
	if (!supports_color()) {
		return $message;
	}

	$formatted = '';
	foreach ($styles as $style) {
		$formatted .= $style->value;
	}

	return $formatted . $message . Style::Reset->value;
}

/**
 * @param resource $stream
 */
function print_usage($stream, string $script) : void
{
	$script = basename($script);

	fwrite($stream, <<<TEXT
Usage:
  php {$script} --input=<file.ini> --namespace='Vendor\\Plugin\\lang' --output=<directory>

Options:
  -i, --input       Source INI translation file.
  -n, --namespace   Namespace used by every generated class.
  -o, --output      Directory receiving the generated PHP files.
  -h, --help        Display this help.

Relative paths are resolved from the current working directory.

TEXT);
}

/**
 * @param list<string> $arguments
 *
 * @return array{input: string, namespace: string, output: string}|null
 */
function parse_arguments(array $arguments) : ?array
{
	if (count($arguments) === 1) {
		throw new CliUsageException('Missing required options.');
	}

	$options = [];
	$argumentCount = count($arguments);

	for ($index = 1; $index < $argumentCount; ++$index) {
		$argument = $arguments[$index];

		if ($argument === '-h' || $argument === '--help') {
			if ($argumentCount !== 2) {
				throw new CliUsageException('The help option cannot be combined with other options.');
			}

			return null;
		}

		$value = null;
		if (str_starts_with($argument, '--')) {
			$option = substr($argument, 2);
			$parts = explode('=', $option, 2);
			$optionName = $parts[0];
			$value = $parts[1] ?? null;

			$name = match ($optionName) {
				'input' => 'input',
				'namespace' => 'namespace',
				'output' => 'output',
				default => throw new CliUsageException("Unknown option: --{$optionName}"),
			};
		} else {
			$name = match ($argument) {
				'-i' => 'input',
				'-n' => 'namespace',
				'-o' => 'output',
				default => throw new CliUsageException("Unexpected argument: {$argument}"),
			};
		}

		if (array_key_exists($name, $options)) {
			throw new CliUsageException("Option --{$name} may only be specified once.");
		}

		if ($value === null) {
			if (!isset($arguments[$index + 1]) || str_starts_with($arguments[$index + 1], '-')) {
				throw new CliUsageException("Missing value for --{$name}.");
			}

			$value = $arguments[++$index];
		}

		if ($value === '') {
			throw new CliUsageException("Value for --{$name} cannot be empty.");
		}

		$options[$name] = $value;
	}

	$missing = array_diff(['input', 'namespace', 'output'], array_keys($options));
	if ($missing !== []) {
		throw new CliUsageException('Missing required option(s): --' . implode(', --', $missing) . '.');
	}

	/** @var array{input: string, namespace: string, output: string} $options */
	return $options;
}

function normalize_namespace(string $namespace) : string
{
	$namespace = trim(trim($namespace), '\\');
	if ($namespace === '') {
		throw new CliUsageException('Namespace cannot be empty.');
	}

	foreach (explode('\\', $namespace) as $segment) {
		if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $segment) !== 1) {
			throw new CliUsageException("Invalid namespace segment: {$segment}");
		}
	}

	try {
		token_get_all("<?php\nnamespace {$namespace};\n", TOKEN_PARSE);
	} catch (ParseError $error) {
		throw new CliUsageException("Invalid namespace: {$namespace}", previous: $error);
	}

	return $namespace;
}

/**
 * @return array<string, string>
 */
function read_language_file(string $inputFile) : array
{
	if (!is_file($inputFile)) {
		throw new RuntimeException("Input file does not exist or is not a file: {$inputFile}");
	}
	if (!is_readable($inputFile)) {
		throw new RuntimeException("Input file is not readable: {$inputFile}");
	}

	$parseError = null;
	set_error_handler(static function (int $_severity, string $message) use (&$parseError) : bool {
		$parseError = $message;

		return true;
	});

	try {
		$parsed = parse_ini_file($inputFile, false, INI_SCANNER_RAW);
	} finally {
		restore_error_handler();
	}

	if ($parsed === false) {
		$suffix = $parseError !== null ? " ({$parseError})" : '';
		throw new RuntimeException("Failed to parse INI file: {$inputFile}{$suffix}");
	}
	if ($parsed === []) {
		throw new RuntimeException("Input file contains no translations: {$inputFile}");
	}

	$definitions = [];
	foreach ($parsed as $key => $value) {
		$key = (string) $key;
		if ($key === '') {
			throw new RuntimeException('Translation keys cannot be empty.');
		}
		if (!is_string($value)) {
			throw new RuntimeException("Translation {$key} must contain a scalar string value.");
		}

		$definitions[$key] = $value;
	}

	return $definitions;
}

function prepare_output_directory(string $outputDirectory) : string
{
	if (file_exists($outputDirectory) && !is_dir($outputDirectory)) {
		throw new RuntimeException("Output path exists and is not a directory: {$outputDirectory}");
	}
	if (!is_dir($outputDirectory) && !mkdir($outputDirectory, recursive: true) && !is_dir($outputDirectory)) {
		throw new RuntimeException("Failed to create output directory: {$outputDirectory}");
	}
	if (!is_writable($outputDirectory)) {
		throw new RuntimeException("Output directory is not writable: {$outputDirectory}");
	}

	$resolvedPath = realpath($outputDirectory);
	if ($resolvedPath === false) {
		throw new RuntimeException("Failed to resolve output directory: {$outputDirectory}");
	}

	return $resolvedPath;
}

function constantify(string $name) : string
{
	return strtoupper(str_replace(['.', '-'], '_', $name));
}

function functionify(string $name) : string
{
	return str_replace(['.', '-'], '_', $name);
}

function assert_php_identifier(string $identifier, string $kind, string $source) : void
{
	if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $identifier) !== 1) {
		throw new RuntimeException(
			"Translation {$source} produces invalid {$kind} identifier: {$identifier}"
		);
	}
}

/**
 * @return array<int|string, string>
 */
function extract_parameters(string $translationKey, string $translation) : array
{
	$result = preg_match_all(PARAMETER_REGEX, $translation, $matches);
	if ($result === false) {
		throw new RuntimeException(
			"Failed to parse parameters for {$translationKey}: " . preg_last_error_msg()
		);
	}

	$parameters = [];
	$parameterOwners = [];
	$allPositional = true;

	foreach ($matches[1] as $placeholder) {
		$placeholder = (string) $placeholder;
		$isPositional = preg_match('/^(?:0|[1-9][0-9]*)$/D', $placeholder) === 1;
		$parameterName = $isPositional ? 'param' . $placeholder : $placeholder;

		if (!$isPositional) {
			$allPositional = false;
		}

		assert_php_identifier($parameterName, 'parameter', $translationKey);
		if (strtolower($parameterName) === 'this') {
			throw new RuntimeException(
				"Translation {$translationKey} uses the reserved parameter name: {$parameterName}"
			);
		}

		$normalizedName = strtolower($parameterName);
		if (isset($parameterOwners[$normalizedName]) && $parameterOwners[$normalizedName] !== $placeholder) {
			throw new RuntimeException(
				"Translation {$translationKey} maps placeholders {$parameterOwners[$normalizedName]} and "
				. $placeholder . ' to the same parameter $' . $parameterName . '.'
			);
		}
		$parameterOwners[$normalizedName] = $placeholder;

		$arrayKey = $isPositional ? (int) $placeholder : $placeholder;
		$parameters[$arrayKey] = $parameterName;
	}

	if ($allPositional) {
		ksort($parameters, SORT_NUMERIC);
	}

	return $parameters;
}

/**
 * @param array<string, string> $definitions
 */
function validate_definitions(array $definitions) : void
{
	$constantOwners = [];
	$methodOwners = [];

	foreach ($definitions as $key => $translation) {
		$constantName = constantify($key);
		assert_php_identifier($constantName, 'constant', $key);
		if (isset($constantOwners[$constantName])) {
			throw new RuntimeException(
				"Translation keys {$constantOwners[$constantName]} and {$key} generate the same "
				. "constant {$constantName}."
			);
		}
		$constantOwners[$constantName] = $key;

		$methodName = functionify($key);
		assert_php_identifier($methodName, 'method', $key);
		$normalizedMethodName = strtolower($methodName);
		if (isset($methodOwners[$normalizedMethodName])) {
			throw new RuntimeException(
				"Translation keys {$methodOwners[$normalizedMethodName]} and {$key} generate the same "
				. "method {$methodName}()."
			);
		}
		$methodOwners[$normalizedMethodName] = $key;

		extract_parameters($key, $translation);
	}
}

function get_file_header(string $namespace, bool $importTranslatable = false) : string
{
	$header = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

PHP;

	if ($importTranslatable && strcasecmp($namespace, 'pocketmine\\lang') !== 0) {
		$header .= "\nuse pocketmine\\lang\\Translatable;\n";
	}

	return $header . <<<'PHP'

/**
 * This class is generated automatically. Do not modify it by hand.
 *
 * @generated
 */

PHP;
}

/**
 * @param array<string, string> $definitions
 */
function generate_keys(array $definitions, string $namespace) : string
{
	ksort($definitions, SORT_STRING);

	$content = get_file_header($namespace);
	$content .= "final class KnownTranslationKeys\n{\n";
	foreach ($definitions as $key => $_translation) {
		$content .= "\tpublic const " . constantify($key) . ' = ' . var_export($key, true) . ";\n";
	}
	$content .= "}\n";

	return $content;
}

/**
 * @param array<string, string> $definitions
 */
function generate_parameter_info(array $definitions, string $namespace) : string
{
	ksort($definitions, SORT_STRING);

	$content = get_file_header($namespace);
	$content .= "final class KnownTranslationParameterInfo\n{\n";
	$content .= "\tpublic const TABLE = [\n";

	foreach ($definitions as $key => $translation) {
		$parameters = extract_parameters($key, $translation);
		$placeholders = [];
		foreach (array_keys($parameters) as $parameterKey) {
			$placeholders[] = var_export((string) $parameterKey, true);
		}

		$content .= "\t\tKnownTranslationKeys::" . constantify($key)
			. ' => [' . implode(', ', $placeholders) . "],\n";
	}

	$content .= "\t];\n";
	$content .= "}\n";

	return $content;
}

/**
 * @param array<string, string> $definitions
 */
function generate_factory(array $definitions, string $namespace) : string
{
	ksort($definitions, SORT_STRING);

	$content = get_file_header($namespace, true);
	$content .= "final class KnownTranslationFactory\n{\n";

	foreach ($definitions as $key => $translation) {
		$parameters = extract_parameters($key, $translation);
		$signature = implode(
			', ',
			array_map(
				static fn(string $parameterName) : string => 'Translatable|string $' . $parameterName,
				array_values($parameters)
			)
		);

		$content .= "\tpublic static function " . functionify($key)
			. "({$signature}) : Translatable\n\t{\n";

		if ($parameters === []) {
			$content .= "\t\treturn new Translatable(KnownTranslationKeys::"
				. constantify($key) . ");\n";
		} else {
			$content .= "\t\treturn new Translatable(KnownTranslationKeys::"
				. constantify($key) . ", [\n";

			foreach ($parameters as $parameterKey => $parameterName) {
				$arrayKey = is_int($parameterKey)
					? (string) $parameterKey
					: var_export($parameterKey, true);
				$content .= "\t\t\t{$arrayKey} => $" . $parameterName . ",\n";
			}

			$content .= "\t\t]);\n";
		}

		$content .= "\t}\n\n";
	}

	$content .= "}\n";

	return $content;
}

function validate_generated_file(string $filename, string $contents) : void
{
	try {
		token_get_all($contents, TOKEN_PARSE);
	} catch (ParseError $error) {
		throw new RuntimeException(
			"Generated file {$filename} contains invalid PHP: {$error->getMessage()}",
			previous: $error
		);
	}
}

/**
 * @param array<string, string> $files
 *
 * @return list<string>
 */
function write_generated_files(string $outputDirectory, array $files) : array
{
	$temporaryFiles = [];
	$generatedPaths = [];

	try {
		foreach ($files as $filename => $contents) {
			validate_generated_file($filename, $contents);

			$temporaryFile = tempnam($outputDirectory, '.translation-api-');
			if ($temporaryFile === false) {
				throw new RuntimeException("Failed to create a temporary file in {$outputDirectory}");
			}
			$temporaryFiles[$filename] = $temporaryFile;

			$writtenBytes = file_put_contents($temporaryFile, $contents, LOCK_EX);
			if ($writtenBytes === false || $writtenBytes !== strlen($contents)) {
				throw new RuntimeException("Failed to write generated file: {$filename}");
			}
			chmod($temporaryFile, 0666 & ~umask());
		}

		foreach ($temporaryFiles as $filename => $temporaryFile) {
			$destination = $outputDirectory . DIRECTORY_SEPARATOR . $filename;
			if (!@rename($temporaryFile, $destination)) {
				$contents = $files[$filename];
				$writtenBytes = @file_put_contents($destination, $contents, LOCK_EX);
				if ($writtenBytes === false || $writtenBytes !== strlen($contents)) {
					throw new RuntimeException("Failed to replace generated file: {$destination}");
				}
				@unlink($temporaryFile);
			}

			unset($temporaryFiles[$filename]);
			$generatedPaths[] = $destination;
		}
	} finally {
		foreach ($temporaryFiles as $temporaryFile) {
			if (is_file($temporaryFile)) {
				@unlink($temporaryFile);
			}
		}
	}

	return $generatedPaths;
}

/**
 * @param list<string> $arguments
 */
function main(array $arguments) : int
{
	$options = parse_arguments($arguments);
	if ($options === null) {
		print_usage(STDOUT, $arguments[0] ?? 'known-translation-apis.php');

		return 0;
	}

	$namespace = normalize_namespace($options['namespace']);
	$definitions = read_language_file($options['input']);
	validate_definitions($definitions);
	$outputDirectory = prepare_output_directory($options['output']);

	$inputFile = realpath($options['input']);
	if ($inputFile === false) {
		throw new RuntimeException("Failed to resolve input file: {$options['input']}");
	}

	echo "\n";
	echo format_text(' LOCALE-BRIDGE TRANSLATION API GENERATOR ', Style::Bold, Style::Cyan) . "\n\n";
	echo format_text('  Input     : ', Style::Dim) . $inputFile . "\n";
	echo format_text('  Namespace : ', Style::Dim) . $namespace . "\n";
	echo format_text('  Output    : ', Style::Dim) . $outputDirectory . "\n\n";

	$files = [
		'KnownTranslationKeys.php' => generate_keys($definitions, $namespace),
		'KnownTranslationParameterInfo.php' => generate_parameter_info($definitions, $namespace),
		'KnownTranslationFactory.php' => generate_factory($definitions, $namespace),
	];

	$generatedPaths = write_generated_files($outputDirectory, $files);
	foreach ($generatedPaths as $generatedPath) {
		echo format_text('    ✓ ', Style::Green) . basename($generatedPath) . "\n";
	}

	echo "\n" . format_text('  ✓ Translation APIs generated successfully.', Style::Green, Style::Bold) . "\n\n";

	return 0;
}

$arguments = $_SERVER['argv'] ?? [];

try {
	exit(main($arguments));
} catch (CliUsageException $error) {
	fwrite(STDERR, "\n" . format_text('[ERROR] ' . $error->getMessage(), Style::Red, Style::Bold) . "\n\n");
	print_usage(STDERR, $arguments[0] ?? 'known-translation-apis.php');
	exit(2);
} catch (Throwable $error) {
	fwrite(STDERR, "\n" . format_text('[ERROR] ' . $error->getMessage(), Style::Red, Style::Bold) . "\n");
	exit(1);
}
